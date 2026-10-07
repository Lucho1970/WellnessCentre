<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;
use Throwable;

final class ClientFormDraftsService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}
    public static function enabled(): bool { return filter_var($_ENV['FORM_TEMPLATE_DRAFTS_ENABLED']??getenv('FORM_TEMPLATE_DRAFTS_ENABLED')?:'false',FILTER_VALIDATE_BOOL); }
    private function guard(AuthContext $actor,bool $storage=true): void {
        if(PHP_SAPI!=='cli')header('Cache-Control: no-store');
        if(!ClientFormsService::enabled())throw new ApiException(503,'forms_disabled','Client forms are not enabled.');
        if($storage&&!self::enabled())throw new ApiException(503,'form_drafts_disabled','Form drafts are not enabled.');
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','practitioner'))throw new ApiException(403,'forbidden','Form author access is required.');
        if(!$actor->hasAnyRole('super_admin','clinic_admin')){
            $s=$this->database->connection()->prepare('SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.id=? AND u.clinic_id=? AND u.status=\'active\' AND p.active=1');$s->execute([$actor->userId,$actor->clinicId]);
            if(!$s->fetchColumn())throw new ApiException(403,'forbidden','Active practitioner access is required.');
        }
    }
    private function row(AuthContext $actor,int $id,bool $lock=false): array {
        $s=$this->database->connection()->prepare('SELECT * FROM form_template_drafts WHERE id=? AND clinic_id=? AND created_by=?'.($lock?' FOR UPDATE':''));$s->execute([$id,$actor->clinicId,$actor->userId]);
        if(!$row=$s->fetch())throw new ApiException(404,'draft_not_found','Draft not found.');return $row;
    }
    private function payload(AuthContext $actor,array $body): array {
        $name=$body['name']??'';$type=$body['form_type']??'';$owner=$body['owner_practitioner_id']??null;$services=$body['service_ids']??[];
        if(!is_string($name)||strlen($name)>190||!in_array($type,['intake','consent','follow_up','questionnaire'],true)||!is_int($owner)||!is_array($services)||!array_is_list($services)||count($services)>100||count(array_unique($services,SORT_REGULAR))!==count($services))throw new ApiException(422,'invalid_form','Check the draft settings.');
        $pdo=$this->database->connection();$s=$pdo->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=? AND u.clinic_id=? AND u.status='active' AND p.active=1".(!$actor->hasAnyRole('super_admin','clinic_admin')?' AND u.id=?':''));$params=[$owner,$actor->clinicId];if(!$actor->hasAnyRole('super_admin','clinic_admin'))$params[]=$actor->userId;$s->execute($params);
        if(!$s->fetchColumn())throw new ApiException(403,'forbidden','Select an authorized active practitioner.');
        foreach($services as $service)if(!is_int($service)||$service<1)throw new ApiException(422,'invalid_form','Select valid services.');
        $previous=$body['previous_template_id']??null;$expected=$body['expected_version']??0;
        if(!is_int($expected)||($previous!==null&&(!is_int($previous)||$previous<1)))throw new ApiException(422,'invalid_form','Check the original template.');
        if($previous!==null){$s=$pdo->prepare('SELECT version FROM form_templates WHERE id=? AND clinic_id=? AND owner_practitioner_id=? AND family_key IS NOT NULL');$s->execute([$previous,$actor->clinicId,$owner]);if(($version=$s->fetchColumn())===false||(int)$version!==$expected)throw new ApiException(404,'form_not_found','Original form not found.');}
        return ['name'=>$name,'form_type'=>$type,'owner_practitioner_id'=>$owner,'definition'=>ClientFormDefinition::definition($body['definition']??null,$type,true),'service_ids'=>$services,'previous_template_id'=>$previous,'expected_version'=>$expected];
    }
    public function list(AuthContext $actor,array $query): array {
        $this->guard($actor);$page=ClientOverviewService::page($query);$offset=($page-1)*20;
        $s=$this->database->connection()->prepare("SELECT id,version,payload,updated_at FROM form_template_drafts WHERE clinic_id=? AND created_by=? AND status='draft' ORDER BY id DESC LIMIT 21 OFFSET $offset");$s->execute([$actor->clinicId,$actor->userId]);$rows=$s->fetchAll();$items=[];
        foreach(array_slice($rows,0,20) as $row){$payload=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);$items[]=['id'=>(int)$row['id'],'version'=>(int)$row['version'],'name'=>$payload['name'],'updated_at'=>$row['updated_at']];}
        return ['items'=>$items,'page'=>$page,'has_more'=>count($rows)>20];
    }
    public function detail(AuthContext $actor,int $id,string $cid): array {
        $this->guard($actor);$row=$this->row($actor,$id);$this->audit->write($actor->clinicId,$actor,$cid,'form.draft.view','form_draft',$id);
        return ['id'=>(int)$row['id'],'version'=>(int)$row['version'],'status'=>$row['status'],'updated_at'=>$row['updated_at'],'payload'=>json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR)];
    }
    public function save(AuthContext $actor,array $body,string $cid,?int $id=null): array {
        $this->guard($actor);$payload=$this->payload($actor,$body);$key=$body['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new ApiException(422,'invalid_form','A draft request key is required.');
        $hash=hash('sha256',json_encode(['payload'=>$payload,'draft_version'=>$body['draft_version']??null],JSON_THROW_ON_ERROR));$pdo=$this->database->connection();
        try{$pdo->beginTransaction();$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$actor->userId]);
            if($id!==null)$row=$this->row($actor,$id,true);
            else{$s=$pdo->prepare('SELECT * FROM form_template_drafts WHERE clinic_id=? AND created_by=? AND creation_key=? FOR UPDATE');$s->execute([$actor->clinicId,$actor->userId,$key]);$row=$s->fetch();}
            if($row&&$row['status']!=='draft')throw new ApiException(409,'draft_changed','This draft has already been published.');
            if($row&&$row['request_key']===$key){if($row['request_hash']!==$hash)throw new ApiException(409,'idempotency_conflict','The key was used for another draft.');$pdo->commit();return ['id'=>(int)$row['id'],'version'=>(int)$row['version'],'status'=>$row['status']];}
            if($row){if($id===null||$row['status']!=='draft'||!is_int($body['draft_version']??null)||$body['draft_version']!==(int)$row['version'])throw new ApiException(409,'draft_changed','The draft changed. Reload it before saving.');
                $id=(int)$row['id'];$pdo->prepare('UPDATE form_template_drafts SET payload=?,version=version+1,request_key=?,request_hash=? WHERE id=?')->execute([json_encode($payload,JSON_THROW_ON_ERROR),$key,$hash,$id]);$version=(int)$row['version']+1;
            }else{$pdo->prepare('INSERT INTO form_template_drafts(clinic_id,created_by,creation_key,request_key,request_hash,payload) VALUES(?,?,?,?,?,?)')->execute([$actor->clinicId,$actor->userId,$key,$key,$hash,json_encode($payload,JSON_THROW_ON_ERROR)]);$id=(int)$pdo->lastInsertId();$version=1;}
            $this->audit->write($actor->clinicId,$actor,$cid,'form.draft.save','form_draft',$id,'success',['version'=>$version]);$pdo->commit();return ['id'=>$id,'version'=>$version,'status'=>'draft'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function validate(AuthContext $actor,array $body): array {
        $this->guard($actor,false);$doc=$body['document']??null;
        if(!is_array($doc)||array_diff(array_keys($doc),['format','format_version','name','form_type','definition'])||($doc['format']??null)!=='wellness-form'||($doc['format_version']??null)!==1||!is_string($doc['name']??null)||strlen($doc['name'])>190||!in_array($doc['form_type']??null,['intake','consent','follow_up','questionnaire'],true))throw new ApiException(422,'invalid_form','Use a supported form JSON export.');
        return ['format'=>'wellness-form','format_version'=>1,'name'=>$doc['name'],'form_type'=>$doc['form_type'],'definition'=>ClientFormDefinition::definition($doc['definition']??null,$doc['form_type'],true)];
    }
    public function import(AuthContext $actor,array $body,string $cid): array {
        $this->guard($actor);$doc=$this->validate($actor,$body);
        return $this->save($actor,['name'=>$doc['name'],'form_type'=>$doc['form_type'],'definition'=>$doc['definition'],'owner_practitioner_id'=>$body['owner_practitioner_id']??null,'service_ids'=>[],'idempotency_key'=>$body['idempotency_key']??null],$cid);
    }
}
