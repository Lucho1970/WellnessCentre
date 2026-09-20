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

    public static function authorizeMerge(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin')) throw new ApiException(403,'forbidden','Merging client records requires Super Admin access.');
    }

    public function search(AuthContext $actor, array $query): array
    {
        self::authorize($actor);
        $term=trim((string)($query['q']??''));
        if (strlen($term)>190) throw new ApiException(422,'validation_error','Search must be at most 190 characters.');
        $page=max(1,min(100000,(int)($query['page']??1))); $offset=($page-1)*25;
        $sql="FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id WHERE u.clinic_id=:clinic AND u.user_type='client' AND NOT EXISTS(SELECT 1 FROM client_merge_records cm WHERE cm.duplicate_client_id=u.id)";
        $params=['clinic'=>$actor->clinicId];
        if(($query['status']??'')==='active')$sql.=" AND u.status='active'";
        if ($term!=='') {
            $sql.=" AND (u.display_name LIKE :name ESCAPE '!' OR u.email LIKE :email ESCAPE '!' OR p.phone LIKE :phone ESCAPE '!' OR EXISTS(SELECT 1 FROM client_email_addresses e WHERE e.client_id=u.id AND e.email LIKE :alias ESCAPE '!'))";
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
            $params+=['name'=>$term,'email'=>$term,'phone'=>$term,'alias'=>$term];
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
        $s=$this->database->connection()->prepare("SELECT u.id,u.display_name,u.given_name,u.family_name,u.email,u.status,p.phone,p.preferred_contact,p.date_of_birth,p.emergency_contact_name,p.emergency_contact_phone,p.administrative_notes,a.address_json FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id LEFT JOIN client_contact_addresses a ON a.client_id=u.id WHERE u.id=:id AND u.clinic_id=:clinic AND u.user_type='client'".($lock?' FOR UPDATE':''));
        $s->execute(['id'=>$id,'clinic'=>$actor->clinicId]);$row=$s->fetch();
        if (!$row) throw new ApiException(404,'client_not_found','Client not found.');
        $row['address']=$row['address_json']?json_decode($row['address_json'],true,32,JSON_THROW_ON_ERROR):null;unset($row['address_json']);
        $emails=$this->database->connection()->prepare('SELECT email,is_primary,verified_at,source FROM client_email_addresses WHERE client_id=:id ORDER BY is_primary DESC,id');$emails->execute(['id'=>$id]);$row['email_addresses']=$emails->fetchAll();
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
        $data['date_of_birth']=$dob?:null;
        $address=$body['address']??null;$hasAddress=is_array($address)&&trim((string)($address['address_line1']??''))!=='';
        $data['address']=$hasAddress?Delivery::destination(['delivery_mode'=>'mobile','destination'=>$address]):null;
        return $data;
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
            $s=$pdo->prepare('SELECT client_id FROM client_email_addresses WHERE clinic_id=:clinic AND email=:email AND client_id<>:id FOR UPDATE');$s->execute(['clinic'=>$actor->clinicId,'email'=>$data['email'],'id'=>$id??0]);
            if($s->fetchColumn())throw new ApiException(409,'email_in_use','This email is already used by another account in this clinic.');
            $s=$pdo->prepare('SELECT id FROM users WHERE clinic_id=:clinic AND email=:email AND id<>:id');$s->execute(['clinic'=>$actor->clinicId,'email'=>$data['email'],'id'=>$id??0]);
            if($s->fetchColumn())throw new ApiException(409,'email_in_use','This email is already used by another account in this clinic.');
            if($id===null&&!($body['confirm_possible_duplicate']??false)){
                $candidates=$this->duplicateCandidates($actor,$data);
                if($candidates)throw new ApiException(409,'possible_duplicate','A similar client record already exists. Review it before creating another client.',['candidates'=>$candidates]);
            }
            $params=['given'=>$data['given_name'],'family'=>$data['family_name'],'name'=>$data['display_name'],'email'=>$data['email'],'status'=>$data['status']];
            if($id===null){
                $s=$pdo->prepare("INSERT INTO users(clinic_id,given_name,family_name,display_name,email,status,user_type) VALUES(:clinic,:given,:family,:name,:email,:status,'client')");$s->execute($params+['clinic'=>$actor->clinicId]);$id=(int)$pdo->lastInsertId();$action='client.create';
            }else{
                $s=$pdo->prepare('UPDATE users SET given_name=:given,family_name=:family,display_name=:name,email=:email,status=:status WHERE id=:id AND clinic_id=:clinic');$s->execute($params+['id'=>$id,'clinic'=>$actor->clinicId]);$action='client.update';
            }
            $s=$pdo->prepare('INSERT INTO client_profiles(user_id,phone,preferred_contact,date_of_birth,emergency_contact_name,emergency_contact_phone,administrative_notes) VALUES(:id,:phone,:preferred,:dob,:emergency_name,:emergency_phone,:notes) ON DUPLICATE KEY UPDATE phone=VALUES(phone),preferred_contact=VALUES(preferred_contact),date_of_birth=VALUES(date_of_birth),emergency_contact_name=VALUES(emergency_contact_name),emergency_contact_phone=VALUES(emergency_contact_phone),administrative_notes=VALUES(administrative_notes)');
            $s->execute(['id'=>$id,'phone'=>$data['phone'],'preferred'=>$data['preferred_contact'],'dob'=>$data['date_of_birth'],'emergency_name'=>$data['emergency_contact_name'],'emergency_phone'=>$data['emergency_contact_phone'],'notes'=>$data['administrative_notes']]);
            if($data['address']){$s=$pdo->prepare('INSERT INTO client_contact_addresses(client_id,address_json) VALUES(:id,:address) ON DUPLICATE KEY UPDATE address_json=VALUES(address_json)');$s->execute(['id'=>$id,'address'=>json_encode($data['address'],JSON_THROW_ON_ERROR)]);}
            else{$s=$pdo->prepare('DELETE FROM client_contact_addresses WHERE client_id=:id');$s->execute(['id'=>$id]);}
            $pdo->prepare('UPDATE client_email_addresses SET is_primary=0 WHERE client_id=:id')->execute(['id'=>$id]);
            $s=$pdo->prepare("INSERT INTO client_email_addresses(clinic_id,client_id,email,is_primary,source) VALUES(:clinic,:id,:email,1,'staff') ON DUPLICATE KEY UPDATE is_primary=1");
            $s->execute(['clinic'=>$actor->clinicId,'id'=>$id,'email'=>$data['email']]);
            $this->audit->write($actor->clinicId,$actor,$cid,$action,'client',$id);
            $result=$this->find($actor,$id);$pdo->commit();return $result;
        }catch(\Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof \PDOException&&(int)($e->errorInfo[1]??0)===1062)throw new ApiException(409,'email_in_use','This email is already used by another account in this clinic.');
            throw $e;
        }
    }

    private function duplicateCandidates(AuthContext $actor,array $data): array
    {
        $sql="SELECT u.id,u.display_name,u.email,u.status,p.phone,p.date_of_birth FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id WHERE u.clinic_id=:clinic AND u.user_type='client' AND NOT EXISTS(SELECT 1 FROM client_merge_records cm WHERE cm.duplicate_client_id=u.id) AND LOWER(u.given_name)=LOWER(:given) AND LOWER(u.family_name)=LOWER(:family)";
        $params=['clinic'=>$actor->clinicId,'given'=>$data['given_name'],'family'=>$data['family_name'],'email'=>$data['email']];
        $sql.=' ORDER BY (u.email=:email) DESC';
        if($data['phone']){$sql.=',(p.phone=:phone) DESC';$params['phone']=$data['phone'];}
        if($data['date_of_birth']){$sql.=',(p.date_of_birth=:dob) DESC';$params['dob']=$data['date_of_birth'];}
        $sql.=',u.status="active" DESC,u.id LIMIT 10';
        $s=$this->database->connection()->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    public function mergePreview(AuthContext $actor,int $survivorId,int $duplicateId,string $cid): array
    {
        self::authorizeMerge($actor);
        if($survivorId===$duplicateId)throw new ApiException(422,'validation_error','Choose two different client records.');
        $survivor=$this->find($actor,$survivorId);$duplicate=$this->find($actor,$duplicateId);
        $counts=$this->relationshipCounts($duplicateId);
        $links=$this->customerLinks([$survivorId,$duplicateId]);
        $blocked=count(array_unique(array_column($links,'identity_id')))>1||$this->hasLegacyIdentityConflict($survivorId,$duplicateId);
        $this->audit->write($actor->clinicId,$actor,$cid,'client.merge.preview','client',$duplicateId,'success',['survivor_client_id'=>$survivorId]);
        return ['survivor'=>$survivor,'duplicate'=>$duplicate,'relationship_counts'=>$counts,'customer_links'=>$links,'blocked'=>$blocked,'blocked_reason'=>$blocked?'Both records are linked to different customer sign-ins. Resolve the identity links before merging.':null];
    }

    public function merge(AuthContext $actor,int $survivorId,int $duplicateId,array $body,string $cid): array
    {
        self::authorizeMerge($actor);
        if($survivorId===$duplicateId)throw new ApiException(422,'validation_error','Choose two different client records.');
        $reason=trim((string)($body['reason']??''));
        if(strlen($reason)<5||strlen($reason)>500)throw new ApiException(422,'validation_error','Enter a merge reason between 5 and 500 characters.');
        if(($body['confirmation']??'')!=="MERGE {$duplicateId} INTO {$survivorId}")throw new ApiException(422,'validation_error','Type the requested confirmation exactly before merging.');
        $emailSource=$body['primary_email_source']??'survivor';$profileSource=$body['profile_source']??'survivor';$addressSource=$body['address_source']??'survivor';
        if(!in_array($emailSource,['survivor','duplicate'],true)||!in_array($profileSource,['survivor','duplicate'],true)||!in_array($addressSource,['survivor','duplicate'],true))throw new ApiException(422,'validation_error','Choose which record supplies the primary email, profile, and address.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $lock=$pdo->prepare("SELECT id FROM users WHERE clinic_id=:clinic AND user_type='client' AND id IN(:first,:second) ORDER BY id FOR UPDATE");
            $lock->execute(['clinic'=>$actor->clinicId,'first'=>min($survivorId,$duplicateId),'second'=>max($survivorId,$duplicateId)]);
            if(count($lock->fetchAll())!==2)throw new ApiException(404,'client_not_found','One of the client records was not found.');
            $survivor=$this->find($actor,$survivorId);$duplicate=$this->find($actor,$duplicateId);
            if(!hash_equals($survivor['revision'],(string)($body['survivor_revision']??''))||!hash_equals($duplicate['revision'],(string)($body['duplicate_revision']??'')))throw new ApiException(409,'client_changed','One of these clients changed. Review the merge again before continuing.');
            if($survivor['status']!=='active')throw new ApiException(409,'invalid_survivor','The surviving client must be active.');
            $already=$pdo->prepare('SELECT survivor_client_id FROM client_merge_records WHERE duplicate_client_id=:id');$already->execute(['id'=>$duplicateId]);
            if($already->fetchColumn())throw new ApiException(409,'already_merged','This client was already merged.');
            $links=$this->customerLinks([$survivorId,$duplicateId]);
            if(count(array_unique(array_column($links,'identity_id')))>1||$this->hasLegacyIdentityConflict($survivorId,$duplicateId))throw new ApiException(409,'identity_conflict','Both records are linked to different customer sign-ins. Resolve the identity links before merging.');
            $counts=$this->relationshipCounts($duplicateId);
            if($profileSource==='duplicate')$this->copyProfile($duplicateId,$survivorId);
            if($addressSource==='duplicate')$this->copyAddress($duplicateId,$survivorId);
            $this->moveClientRelationships($duplicateId,$survivorId);
            $pdo->prepare('UPDATE client_email_addresses SET client_id=:survivor,is_primary=0,source="merge" WHERE client_id=:duplicate')->execute(['survivor'=>$survivorId,'duplicate'=>$duplicateId]);
            $pdo->prepare("UPDATE users SET status='inactive',email=:tombstone WHERE id=:duplicate AND clinic_id=:clinic")->execute(['duplicate'=>$duplicateId,'clinic'=>$actor->clinicId,'tombstone'=>"merged-client-{$duplicateId}@invalid.local"]);
            $primaryEmail=$emailSource==='duplicate'?$duplicate['email']:$survivor['email'];
            $pdo->prepare('UPDATE users SET email=:email WHERE id=:survivor AND clinic_id=:clinic')->execute(['email'=>$primaryEmail,'survivor'=>$survivorId,'clinic'=>$actor->clinicId]);
            $pdo->prepare('UPDATE client_email_addresses SET is_primary=(email=:email) WHERE client_id=:survivor')->execute(['email'=>$primaryEmail,'survivor'=>$survivorId]);
            $record=$pdo->prepare('INSERT INTO client_merge_records(clinic_id,survivor_client_id,duplicate_client_id,merged_by,reason,primary_email_source,profile_source,address_source,relationship_counts) VALUES(:clinic,:survivor,:duplicate,:actor,:reason,:email_source,:profile,:address,:counts)');
            $record->execute(['clinic'=>$actor->clinicId,'survivor'=>$survivorId,'duplicate'=>$duplicateId,'actor'=>$actor->userId,'reason'=>$reason,'email_source'=>$emailSource,'profile'=>$profileSource,'address'=>$addressSource,'counts'=>json_encode($counts,JSON_THROW_ON_ERROR)]);
            $this->audit->write($actor->clinicId,$actor,$cid,'client.merge','client',$survivorId,'success',['duplicate_client_id'=>$duplicateId,'merge_record_id'=>(int)$pdo->lastInsertId(),'relationship_counts'=>$counts]);
            $result=$this->find($actor,$survivorId);$pdo->commit();return ['client'=>$result,'merged_client_id'=>$duplicateId,'relationship_counts'=>$counts];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function customerLinks(array $ids): array
    {
        $s=$this->database->connection()->prepare('SELECT identity_id,client_id FROM customer_client_links WHERE client_id IN(:first,:second) ORDER BY client_id');$s->execute(['first'=>$ids[0],'second'=>$ids[1]]);return $s->fetchAll();
    }

    private function relationshipCounts(int $clientId): array
    {
        $tables=['appointments','recurring_series','waitlist_entries','form_submissions','practitioner_client_notes','invoices','consent_records','data_export_requests','client_link_invitations'];$counts=[];
        foreach($tables as $table){$s=$this->database->connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id=:id");$s->execute(['id'=>$clientId]);$counts[$table]=(int)$s->fetchColumn();}
        return $counts;
    }

    private function copyProfile(int $from,int $to): void
    {
        $sql='INSERT INTO client_profiles(user_id,phone,preferred_contact,date_of_birth,emergency_contact_name,emergency_contact_phone,administrative_notes) SELECT :to,phone,preferred_contact,date_of_birth,emergency_contact_name,emergency_contact_phone,administrative_notes FROM client_profiles WHERE user_id=:from ON DUPLICATE KEY UPDATE phone=VALUES(phone),preferred_contact=VALUES(preferred_contact),date_of_birth=VALUES(date_of_birth),emergency_contact_name=VALUES(emergency_contact_name),emergency_contact_phone=VALUES(emergency_contact_phone),administrative_notes=VALUES(administrative_notes)';
        $this->database->connection()->prepare($sql)->execute(['to'=>$to,'from'=>$from]);
    }

    private function copyAddress(int $from,int $to): void
    {
        $this->database->connection()->prepare('DELETE FROM client_contact_addresses WHERE client_id=:to')->execute(['to'=>$to]);
        $sql='INSERT INTO client_contact_addresses(client_id,address_json) SELECT :to,address_json FROM client_contact_addresses WHERE client_id=:from ON DUPLICATE KEY UPDATE address_json=VALUES(address_json)';
        $this->database->connection()->prepare($sql)->execute(['to'=>$to,'from'=>$from]);
    }

    private function moveClientRelationships(int $from,int $to): void
    {
        $pdo=$this->database->connection();
        foreach(['appointments','recurring_series','waitlist_entries','form_submissions','practitioner_client_notes','invoices','consent_records','data_export_requests','client_link_invitations'] as $table)$pdo->prepare("UPDATE {$table} SET client_id=:to WHERE client_id=:from")->execute(['to'=>$to,'from'=>$from]);
        $pdo->prepare('INSERT IGNORE INTO appointment_attendees(appointment_id,user_id,attendee_type) SELECT appointment_id,:to,attendee_type FROM appointment_attendees WHERE user_id=:from')->execute(['to'=>$to,'from'=>$from]);
        $pdo->prepare('DELETE FROM appointment_attendees WHERE user_id=:from')->execute(['from'=>$from]);
        $survivorLink=$pdo->prepare('SELECT identity_id FROM customer_client_links WHERE client_id=:to');$survivorLink->execute(['to'=>$to]);
        if(!$survivorLink->fetchColumn())$pdo->prepare('UPDATE customer_client_links SET client_id=:to WHERE client_id=:from')->execute(['to'=>$to,'from'=>$from]);
        $survivorIdentity=$pdo->prepare('SELECT id FROM identity_links WHERE user_id=:to LIMIT 1');$survivorIdentity->execute(['to'=>$to]);
        if(!$survivorIdentity->fetchColumn())$pdo->prepare('UPDATE identity_links SET user_id=:to WHERE user_id=:from')->execute(['to'=>$to,'from'=>$from]);
    }

    private function hasLegacyIdentityConflict(int $survivorId,int $duplicateId): bool
    {
        $s=$this->database->connection()->prepare('SELECT user_id,COUNT(*) total FROM identity_links WHERE user_id IN(:first,:second) GROUP BY user_id');$s->execute(['first'=>$survivorId,'second'=>$duplicateId]);return count($s->fetchAll())>1;
    }
}
