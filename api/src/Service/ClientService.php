<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class ClientService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin','clinic_admin','reception')) throw new ApiException(403,'forbidden','Client administration requires administrator or reception access.');
    }

    public function search(AuthContext $actor, array $query): array
    {
        self::authorize($actor);
        $term=trim((string)($query['q']??''));
        if (strlen($term)>190) throw new ApiException(422,'validation_error','Search must be at most 190 characters.');
        $page=max(1,min(100000,(int)($query['page']??1))); $offset=($page-1)*25;
        $sql="FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id WHERE u.clinic_id=:clinic AND u.user_type='client'";
        $params=['clinic'=>$actor->clinicId];
        if(($query['status']??'')==='active')$sql.=" AND u.status='active'";
        if ($term!=='') {
            $sql.=" AND (u.display_name LIKE :name ESCAPE '!' OR u.email LIKE :email ESCAPE '!' OR p.phone LIKE :phone ESCAPE '!')";
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
            $params+=['name'=>$term,'email'=>$term,'phone'=>$term];
        }
        $s=$this->database->connection()->prepare('SELECT u.id,u.display_name,u.email,u.status,p.phone '.$sql." ORDER BY u.display_name,u.id LIMIT 26 OFFSET {$offset}");
        $s->execute($params);$rows=$s->fetchAll();$more=count($rows)>25;
        return ['items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>$more];
    }

    public function get(AuthContext $actor,int $id,string $cid): array
    {
        self::authorize($actor);$row=$this->find($actor,$id);
        $this->audit->write($actor->clinicId,$actor,$cid,'client.view','client',$id);
        return $row;
    }

    private function find(AuthContext $actor,int $id,bool $lock=false): array
    {
        $s=$this->database->connection()->prepare("SELECT u.id,u.display_name,u.given_name,u.family_name,u.email,u.status,p.phone,p.preferred_contact,p.date_of_birth,p.emergency_contact_name,p.emergency_contact_phone,p.administrative_notes FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id WHERE u.id=:id AND u.clinic_id=:clinic AND u.user_type='client'".($lock?' FOR UPDATE':''));
        $s->execute(['id'=>$id,'clinic'=>$actor->clinicId]);$row=$s->fetch();
        if (!$row) throw new ApiException(404,'client_not_found','Client not found.');
        $row['revision']=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));return $row;
    }

    public static function validate(array $body): array
    {
        $limits=['given_name'=>100,'family_name'=>100,'email'=>190,'phone'=>40,'emergency_contact_name'=>150,'emergency_contact_phone'=>40,'administrative_notes'=>4000];
        $data=[];
        foreach($limits as $field=>$limit){
            if(isset($body[$field])&&!is_string($body[$field]))throw new ApiException(422,'validation_error',"{$field} must be text.");
            $value=trim($body[$field]??'');
            if(strlen($value)>$limit)throw new ApiException(422,'validation_error',"{$field} exceeds {$limit} characters.");
            $data[$field]=$value===''?null:$value;
        }
        if(!$data['given_name']||!$data['family_name'])throw new ApiException(422,'validation_error','First and last name are required.');
        $data['display_name']=$data['given_name'].' '.$data['family_name'];
        if(strlen($data['display_name'])>150)throw new ApiException(422,'validation_error','The full name must be at most 150 characters.');
        if(!$data['email']||!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new ApiException(422,'validation_error','Enter a valid email address.');
        $data['email']=strtolower($data['email']);
        $data['status']=$body['status']??'active';
        if(!in_array($data['status'],['active','inactive'],true))throw new ApiException(422,'validation_error','Select active or inactive status.');
        $data['preferred_contact']=$body['preferred_contact']??'email';
        if(!in_array($data['preferred_contact'],['email','phone'],true))throw new ApiException(422,'validation_error','Select email or phone as the contact preference.');
        if($data['preferred_contact']==='phone'&&!$data['phone'])throw new ApiException(422,'validation_error','A phone number is required for phone contact.');
        $dob=$body['date_of_birth']??null;
        if($dob!==null&&$dob!==''){
            $date=is_string($dob)?\DateTimeImmutable::createFromFormat('!Y-m-d',$dob):false;
            if(!$date||$date->format('Y-m-d')!==$dob||$dob>gmdate('Y-m-d'))throw new ApiException(422,'validation_error','Enter a valid date of birth that is not in the future.');
        }
        $data['date_of_birth']=$dob?:null;return $data;
    }

    public function save(AuthContext $actor,array $body,string $cid,?int $id=null): array
    {
        self::authorize($actor);$data=self::validate($body);$pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            if($id!==null){
                $current=$this->find($actor,$id,true);
                if(!is_string($body['revision']??null)||!hash_equals($current['revision'],$body['revision']))throw new ApiException(409,'client_changed','This client was changed by another staff member. Reopen the record before saving.');
            }
            $s=$pdo->prepare('SELECT id FROM users WHERE clinic_id=:clinic AND email=:email AND id<>:id');$s->execute(['clinic'=>$actor->clinicId,'email'=>$data['email'],'id'=>$id??0]);
            if($s->fetchColumn())throw new ApiException(409,'email_in_use','This email is already used by another account in this clinic.');
            $params=['given'=>$data['given_name'],'family'=>$data['family_name'],'name'=>$data['display_name'],'email'=>$data['email'],'status'=>$data['status']];
            if($id===null){
                $s=$pdo->prepare("INSERT INTO users(clinic_id,given_name,family_name,display_name,email,status,user_type) VALUES(:clinic,:given,:family,:name,:email,:status,'client')");$s->execute($params+['clinic'=>$actor->clinicId]);$id=(int)$pdo->lastInsertId();$action='client.create';
            }else{
                $s=$pdo->prepare('UPDATE users SET given_name=:given,family_name=:family,display_name=:name,email=:email,status=:status WHERE id=:id AND clinic_id=:clinic');$s->execute($params+['id'=>$id,'clinic'=>$actor->clinicId]);$action='client.update';
            }
            $s=$pdo->prepare('INSERT INTO client_profiles(user_id,phone,preferred_contact,date_of_birth,emergency_contact_name,emergency_contact_phone,administrative_notes) VALUES(:id,:phone,:preferred,:dob,:emergency_name,:emergency_phone,:notes) ON DUPLICATE KEY UPDATE phone=VALUES(phone),preferred_contact=VALUES(preferred_contact),date_of_birth=VALUES(date_of_birth),emergency_contact_name=VALUES(emergency_contact_name),emergency_contact_phone=VALUES(emergency_contact_phone),administrative_notes=VALUES(administrative_notes)');
            $s->execute(['id'=>$id,'phone'=>$data['phone'],'preferred'=>$data['preferred_contact'],'dob'=>$data['date_of_birth'],'emergency_name'=>$data['emergency_contact_name'],'emergency_phone'=>$data['emergency_contact_phone'],'notes'=>$data['administrative_notes']]);
            $this->audit->write($actor->clinicId,$actor,$cid,$action,'client',$id);
            $result=$this->find($actor,$id);$pdo->commit();return $result;
        }catch(\Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof \PDOException&&(int)($e->errorInfo[1]??0)===1062)throw new ApiException(409,'email_in_use','This email is already used by another account in this clinic.');
            throw $e;
        }
    }
}
