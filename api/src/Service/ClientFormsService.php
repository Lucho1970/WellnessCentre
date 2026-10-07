<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class ClientFormsService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}
    public static function enabled(): bool { return filter_var($_ENV['CLIENT_FORMS_ENABLED']??getenv('CLIENT_FORMS_ENABLED')?:'false',FILTER_VALIDATE_BOOL); }
    private function guard(AuthContext $actor): void {
        if(PHP_SAPI!=='cli')header('Cache-Control: no-store');
        if(!self::enabled())throw new ApiException(503,'forms_disabled','Client forms are not enabled.');
        if($actor->userType!=='client'&&($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner')))throw new ApiException(403,'forbidden','Form access is not permitted.');
    }
    private function operator(AuthContext $actor): bool { return $actor->userType==='staff'&&$actor->hasAnyRole('super_admin','clinic_admin','reception'); }
    private function ownPractitioner(AuthContext $actor): ?int {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('practitioner'))return null;
        $s=$this->database->connection()->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND p.active=1");$s->execute([$actor->userId,$actor->clinicId]);return ($id=$s->fetchColumn())?(int)$id:null;
    }
    private function templateAuthor(AuthContext $actor): void {
        $this->guard($actor);
        if(!$actor->hasAnyRole('super_admin','clinic_admin')&&!$this->ownPractitioner($actor))throw new ApiException(403,'forbidden','Only practitioners and clinic administrators may manage templates.');
    }
    private function template(AuthContext $actor,int $id,bool $lock=false): array {
        $s=$this->database->connection()->prepare('SELECT f.*,u.display_name practitioner_name FROM form_templates f JOIN practitioners p ON p.id=f.owner_practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=f.clinic_id WHERE f.id=? AND f.clinic_id=? AND f.family_key IS NOT NULL'.($lock?' FOR UPDATE':''));$s->execute([$id,$actor->clinicId]);$row=$s->fetch();
        if(!$row||(!$this->operator($actor)&&(int)$row['owner_practitioner_id']!==$this->ownPractitioner($actor)))throw new ApiException(404,'form_not_found','Form not found.');
        return $row;
    }
    private function client(AuthContext $actor,int $id): array {
        $s=$this->database->connection()->prepare("SELECT u.id,u.display_name FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='client' AND u.status='active' AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=u.id)");$s->execute([$id,$actor->clinicId]);$row=$s->fetch();
        if(!$row||($actor->userType==='client'&&$actor->userId!==$id))throw new ApiException(404,'client_not_found','Client not found.');
        if(!$this->operator($actor)&&$actor->userType!=='client'){
            $p=$this->ownPractitioner($actor);if(!$p)throw new ApiException(403,'forbidden','Practitioner access is required.');
            $s=$this->database->connection()->prepare("SELECT 1 WHERE EXISTS(SELECT 1 FROM appointments WHERE clinic_id=? AND client_id=? AND practitioner_id=?) OR EXISTS(SELECT 1 FROM audit_logs WHERE clinic_id=? AND actor_user_id=? AND action='client.create' AND entity_type='client' AND entity_id=? AND outcome='success') OR EXISTS(SELECT 1 FROM client_form_tasks WHERE clinic_id=? AND client_id=? AND practitioner_id=? AND status<>'revoked')");$s->execute([$actor->clinicId,$id,$p,$actor->clinicId,$actor->userId,$id,$actor->clinicId,$id,$p]);
            if(!$s->fetchColumn())throw new ApiException(404,'client_not_found','Client not found.');
        }
        return $row;
    }
    private function task(AuthContext $actor,int $id,bool $lock=false): array {
        $s=$this->database->connection()->prepare('SELECT t.*,f.name,f.form_type,f.version template_version,f.definition,u.display_name practitioner_name FROM client_form_tasks t JOIN form_templates f ON f.id=t.form_template_id AND f.clinic_id=t.clinic_id JOIN practitioners p ON p.id=t.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=t.clinic_id WHERE t.id=? AND t.clinic_id=?'.($lock?' FOR UPDATE':''));$s->execute([$id,$actor->clinicId]);$row=$s->fetch();
        if(!$row||($actor->userType==='client'?(int)$row['client_id']!==$actor->userId:(!$this->operator($actor)&&(int)$row['practitioner_id']!==$this->ownPractitioner($actor))))throw new ApiException(404,'form_not_found','Form not found.');
        $this->client($actor,(int)$row['client_id']);return $row;
    }
    public function templates(AuthContext $actor,array $query): array {
        $this->guard($actor);if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Staff access is required.');
        $page=ClientOverviewService::page($query);$offset=($page-1)*20;$params=[$actor->clinicId];$scope='';
        if(!$this->operator($actor)){$scope=' AND f.owner_practitioner_id=?';$params[]=$this->ownPractitioner($actor)??0;}
        $s=$this->database->connection()->prepare("SELECT f.id,f.family_key,f.name,f.form_type,f.version,f.owner_practitioner_id,f.definition,u.display_name practitioner_name FROM form_templates f JOIN practitioners p ON p.id=f.owner_practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=f.clinic_id WHERE f.clinic_id=? AND f.family_key IS NOT NULL AND f.active=1 $scope ORDER BY f.id DESC LIMIT 21 OFFSET $offset");$s->execute($params);$items=$s->fetchAll();
        foreach($items as &$row){$row['definition']=json_decode($row['definition'],true,32,JSON_THROW_ON_ERROR);$r=$this->database->connection()->prepare('SELECT service_id FROM form_assignments WHERE form_template_id=? AND service_id IS NOT NULL');$r->execute([$row['id']]);$row['service_ids']=array_map('intval',$r->fetchAll(PDO::FETCH_COLUMN));}unset($row);
        $p=$this->database->connection()->prepare("SELECT p.id,u.display_name FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.clinic_id=? AND u.status='active' AND p.active=1 ORDER BY u.display_name");$p->execute([$actor->clinicId]);$practitioners=$p->fetchAll();
        if(!$this->operator($actor))$practitioners=array_values(array_filter($practitioners,fn($p)=>(int)$p['id']===$this->ownPractitioner($actor)));
        $s=$this->database->connection()->prepare('SELECT id,name FROM services WHERE clinic_id=? AND active=1 ORDER BY name');$s->execute([$actor->clinicId]);
        return ['items'=>array_slice($items,0,20),'page'=>$page,'has_more'=>count($items)>20,'practitioners'=>$practitioners,'services'=>$s->fetchAll(),'drafts_enabled'=>ClientFormDraftsService::enabled(),'can_author'=>$actor->hasAnyRole('super_admin','clinic_admin')||$this->ownPractitioner($actor)!==null];
    }
    public function history(AuthContext $actor,int $id): array {
        $this->templateAuthor($actor);$row=$this->template($actor,$id);$s=$this->database->connection()->prepare('SELECT id,name,form_type,version,definition,created_at FROM form_templates WHERE clinic_id=? AND family_key=? ORDER BY version DESC LIMIT 100');$s->execute([$actor->clinicId,$row['family_key']]);$rows=$s->fetchAll();foreach($rows as &$r)$r['definition']=json_decode($r['definition'],true,32,JSON_THROW_ON_ERROR);return ['items'=>$rows];
    }
    public function publish(AuthContext $actor,array $body,string $cid,?int $previous=null): array {
        $this->templateAuthor($actor);$name=$body['name']??null;$type=$body['form_type']??null;
        if(!is_string($name)||trim($name)===''||strlen($name)>190||!in_array($type,['intake','consent','follow_up','questionnaire'],true))throw new ApiException(422,'invalid_form','Enter a form name and type.');
        $definition=ClientFormDefinition::definition($body['definition']??null,$type);$services=$body['service_ids']??[];
        $key=$body['idempotency_key']??null;if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new ApiException(422,'invalid_form','A publication key is required.');
        $hash=hash('sha256',json_encode(['previous'=>$previous,'body'=>$body],JSON_THROW_ON_ERROR));
        if(!is_array($services)||!array_is_list($services)||count($services)>100||count(array_unique($services,SORT_REGULAR))!==count($services))throw new ApiException(422,'invalid_form','Select valid services.');
        $pdo=$this->database->connection();
        try{$pdo->beginTransaction();$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$actor->userId]);
            $r=$pdo->prepare('SELECT id,version,publication_hash,created_by FROM form_templates WHERE clinic_id=? AND publication_key=?');$r->execute([$actor->clinicId,$key]);if($stored=$r->fetch()){if($stored['publication_hash']!==$hash||(int)$stored['created_by']!==$actor->userId)throw new ApiException(409,'idempotency_conflict','This key was used for another publication.');$this->template($actor,(int)$stored['id']);$pdo->commit();return ['id'=>(int)$stored['id'],'version'=>(int)$stored['version']];}
            $draftId=$body['draft_id']??null;
            if($draftId!==null){
                if(!ClientFormDraftsService::enabled())throw new ApiException(503,'form_drafts_disabled','Form drafts are not enabled.');
                if(!is_int($draftId)||$draftId<1||!is_int($body['draft_version']??null))throw new ApiException(422,'invalid_form','Check the draft version.');
                $d=$pdo->prepare('SELECT * FROM form_template_drafts WHERE id=? AND clinic_id=? AND created_by=? FOR UPDATE');$d->execute([$draftId,$actor->clinicId,$actor->userId]);$draft=$d->fetch();
                if(!$draft)throw new ApiException(404,'draft_not_found','Draft not found.');
                if($draft['status']!=='draft'||(int)$draft['version']!==$body['draft_version'])throw new ApiException(409,'draft_changed','The draft changed. Reload it before publishing.');
                $payload=json_decode($draft['payload'],true,32,JSON_THROW_ON_ERROR);
                if(($payload['previous_template_id']??null)!==$previous)throw new ApiException(409,'draft_changed','Publish against the original template.');
            }
            $version=1;$family=bin2hex(random_bytes(16));$owner=filter_var($body['owner_practitioner_id']??null,FILTER_VALIDATE_INT);
            if($previous){$old=$this->template($actor,$previous,true);if(!$old['active']||(int)($body['expected_version']??0)!==(int)$old['version'])throw new ApiException(409,'form_changed','The form changed. Reload it before publishing.');$owner=(int)$old['owner_practitioner_id'];$version=(int)$old['version']+1;$family=$old['family_key'];}
            if(!$owner||(!$actor->hasAnyRole('super_admin','clinic_admin')&&$owner!==$this->ownPractitioner($actor)))throw new ApiException(403,'forbidden','Select your practitioner profile.');
            $p=$pdo->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=? AND u.clinic_id=? AND u.status='active' AND p.active=1 FOR UPDATE");$p->execute([$owner,$actor->clinicId]);if(!$p->fetchColumn())throw new ApiException(422,'invalid_form','Select an active practitioner.');
            foreach($services as $service){if(!is_int($service)||$service<1)throw new ApiException(422,'invalid_form','Select valid services.');$s=$pdo->prepare('SELECT s.id FROM services s JOIN practitioner_services ps ON ps.service_id=s.id WHERE s.id=? AND s.clinic_id=? AND s.active=1 AND ps.practitioner_id=?');$s->execute([$service,$actor->clinicId,$owner]);if(!$s->fetchColumn())throw new ApiException(422,'invalid_form','Select services offered by this practitioner.');}
            $s=$pdo->prepare('INSERT INTO form_templates(clinic_id,owner_practitioner_id,name,form_type,version,definition,family_key,publication_key,publication_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$actor->clinicId,$owner,trim($name),$type,$version,json_encode($definition,JSON_THROW_ON_ERROR),$family,$key,$hash,$actor->userId]);$id=(int)$pdo->lastInsertId();
            if($previous){$pdo->prepare('UPDATE form_templates SET active=0 WHERE id=?')->execute([$previous]);$pdo->prepare('DELETE FROM form_assignments WHERE form_template_id=?')->execute([$previous]);}
            foreach($services as $service)$pdo->prepare('INSERT INTO form_assignments(form_template_id,practitioner_id,service_id,required) VALUES(?,?,?,1)')->execute([$id,$owner,$service]);
            if($draftId!==null)$pdo->prepare("UPDATE form_template_drafts SET status='published',published_template_id=?,version=version+1 WHERE id=?")->execute([$id,$draftId]);
            $this->audit->write($actor->clinicId,$actor,$cid,'form.template.publish','form_template',$id,'success',['version'=>$version]);$pdo->commit();return ['id'=>$id,'version'=>$version];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function list(AuthContext $actor,int $clientId,array $query,string $cid): array {
        $this->guard($actor);$client=$this->client($actor,$clientId);$page=ClientOverviewService::page($query);$offset=($page-1)*25;$params=[$actor->clinicId,$clientId];$scope='';$own=$this->ownPractitioner($actor);
        if($actor->userType==='staff'&&!$this->operator($actor)){$scope=' AND t.practitioner_id=?';$params[]=$own??0;}
        $s=$this->database->connection()->prepare("SELECT t.id,t.status,t.version,t.appointment_id,t.required,t.practitioner_id,t.created_at,t.submitted_at,t.reviewed_at,f.name,f.form_type,f.version template_version,u.display_name practitioner_name FROM client_form_tasks t JOIN form_templates f ON f.id=t.form_template_id AND f.clinic_id=t.clinic_id JOIN practitioners p ON p.id=t.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=t.clinic_id WHERE t.clinic_id=? AND t.client_id=? $scope ORDER BY t.id DESC LIMIT 26 OFFSET $offset");$s->execute($params);$rows=$s->fetchAll();foreach($rows as &$row)$row['can_read_answers']=$actor->userType==='client'||$own===(int)$row['practitioner_id'];
        $this->audit->write($actor->clinicId,$actor,$cid,'form.tasks.view','client',$clientId,'success',['page'=>$page]);return ['client'=>$client,'items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>count($rows)>25];
    }
    public function detail(AuthContext $actor,int $id,string $cid): array {
        $this->guard($actor);$row=$this->task($actor,$id);
        if($actor->userType!=='client'&&$this->ownPractitioner($actor)!==(int)$row['practitioner_id'])throw new ApiException(403,'forbidden','Only the client and assigned practitioner may read answers.');
        $row['definition']=json_decode($row['definition'],true,32,JSON_THROW_ON_ERROR);$row['answers']=null;
        if($row['submission_id']){$s=$this->database->connection()->prepare('SELECT response_data FROM form_submissions WHERE id=? AND client_id=? AND form_template_id=?');$s->execute([$row['submission_id'],$row['client_id'],$row['form_template_id']]);$response=$s->fetchColumn();if($response===false)throw new ApiException(409,'form_changed','The form changed. Reload it.');$row['answers']=json_decode($response,true,32,JSON_THROW_ON_ERROR);}
        $this->audit->write($actor->clinicId,$actor,$cid,'form.answers.view','client_form_task',$id);return $row;
    }
    public function assign(AuthContext $actor,int $clientId,array $body,string $cid): array {
        $this->guard($actor);if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Staff access is required.');$this->client($actor,$clientId);
        $key=$body['idempotency_key']??null;if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key)||!is_int($body['template_id']??null))throw new ApiException(422,'invalid_form','Select a form and assignment key.');
        $pdo=$this->database->connection();try{$pdo->beginTransaction();$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$clientId]);$template=$this->template($actor,$body['template_id']);
            $s=$pdo->prepare('SELECT id,client_id,form_template_id,assigned_by FROM client_form_tasks WHERE clinic_id=? AND assignment_key=?');$s->execute([$actor->clinicId,$key]);if($existing=$s->fetch()){if((int)$existing['client_id']!==$clientId||(int)$existing['form_template_id']!==$body['template_id']||(int)$existing['assigned_by']!==$actor->userId)throw new ApiException(409,'idempotency_conflict','This key was used for another assignment.');$pdo->commit();return ['id'=>(int)$existing['id']];}
            if(!$template['active'])throw new ApiException(409,'form_changed','Choose the current template version.');
            $s=$pdo->prepare('INSERT INTO client_form_tasks(clinic_id,client_id,practitioner_id,form_template_id,assigned_by,assignment_key) VALUES(?,?,?,?,?,?)');$s->execute([$actor->clinicId,$clientId,$template['owner_practitioner_id'],$template['id'],$actor->userId,$key]);$id=(int)$pdo->lastInsertId();$this->audit->write($actor->clinicId,$actor,$cid,'form.assign','client_form_task',$id);$pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function submit(AuthContext $actor,int $id,array $body,string $cid): array {
        $this->guard($actor);if($actor->userType!=='client')throw new ApiException(403,'forbidden','Client access is required.');$pdo=$this->database->connection();
        try{$pdo->beginTransaction();$row=$this->task($actor,$id,true);$definition=json_decode($row['definition'],true,32,JSON_THROW_ON_ERROR);$answers=ClientFormDefinition::answers($definition,$body['answers']??null);
            if($row['submission_id']){$s=$pdo->prepare('SELECT response_data FROM form_submissions WHERE id=?');$s->execute([$row['submission_id']]);if(json_decode($s->fetchColumn(),true,32,JSON_THROW_ON_ERROR)!==$answers)throw new ApiException(409,'form_already_submitted','Submitted answers cannot be overwritten.');$pdo->commit();return ['id'=>$id,'status'=>$row['status']];}
            if($row['status']!=='pending'||!is_int($body['version']??null)||$body['version']!==(int)$row['version']||($body['confirmed']??false)!==true)throw new ApiException(409,'form_changed','Review the current form before submitting.');
            $s=$pdo->prepare("INSERT INTO form_submissions(form_template_id,appointment_id,client_id,response_data,status,submitted_at) VALUES(?,?,?,?,'submitted',UTC_TIMESTAMP())");$s->execute([$row['form_template_id'],$row['appointment_id'],$actor->userId,json_encode($answers,JSON_THROW_ON_ERROR)]);$submission=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE client_form_tasks SET submission_id=?,status='submitted',submitted_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?")->execute([$submission,$id]);
            if(array_filter($definition['questions'],static fn($q)=>$q['type']==='consent'))$pdo->prepare("INSERT INTO consent_records(client_id,purpose_code,policy_version,status,evidence,recorded_at) VALUES(?,?,?,'granted',?,UTC_TIMESTAMP())")->execute([$actor->userId,'form-'.$row['form_template_id'],(string)$row['template_version'],json_encode(['task_id'=>$id,'submission_id'=>$submission],JSON_THROW_ON_ERROR)]);
            $this->audit->write($actor->clinicId,$actor,$cid,'form.submit','client_form_task',$id);$pdo->commit();return ['id'=>$id,'status'=>'submitted'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function transition(AuthContext $actor,int $id,array $body,string $cid): array {
        $this->guard($actor);if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Staff access is required.');$pdo=$this->database->connection();
        try{$pdo->beginTransaction();$row=$this->task($actor,$id,true);$action=$body['action']??'';if(!is_int($body['version']??null)||$body['version']!==(int)$row['version'])throw new ApiException(409,'form_changed','The form changed. Reload it.');
            if($action==='review'&&$row['status']==='submitted'&&$this->ownPractitioner($actor)===(int)$row['practitioner_id']){$pdo->prepare("UPDATE client_form_tasks SET status='reviewed',reviewed_by=?,reviewed_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?")->execute([$actor->userId,$id]);$pdo->prepare("UPDATE form_submissions SET status='reviewed',reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?")->execute([$actor->userId,$row['submission_id']]);}
            elseif($action==='revoke'&&$row['status']==='pending')$pdo->prepare("UPDATE client_form_tasks SET status='revoked',version=version+1 WHERE id=?")->execute([$id]);
            else throw new ApiException(403,'forbidden','This form action is not permitted.');
            $this->audit->write($actor->clinicId,$actor,$cid,'form.'.$action,'client_form_task',$id);$pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    /** Called inside the shared booking transaction; preview/series rollbacks include these tasks. */
    public static function assignForAppointment(PDO $pdo,int $clinic,int $client,int $practitioner,int $service,int $appointment,int $actor): int {
        if(!self::enabled())return 0;
        $s=$pdo->prepare('INSERT INTO client_form_tasks(clinic_id,client_id,practitioner_id,form_template_id,appointment_id,assigned_by,required) SELECT ?,?, ?,f.id,?,?,a.required FROM form_assignments a JOIN form_templates f ON f.id=a.form_template_id WHERE f.clinic_id=? AND f.family_key IS NOT NULL AND f.active=1 AND f.owner_practitioner_id=? AND a.service_id=? AND (a.practitioner_id IS NULL OR a.practitioner_id=?)');$s->execute([$clinic,$client,$practitioner,$appointment,$actor,$clinic,$practitioner,$service,$practitioner]);
        return $s->rowCount();
    }
}
