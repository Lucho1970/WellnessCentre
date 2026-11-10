<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Wellnexa clinic record boundary. Notes are insert/read only through this API. */
final class ClinicCareRecordService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    private function currentStaff(AuthContext $actor,array $roles): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole(...$roles))throw new ApiException(403,'forbidden','Clinic access is required.');
        $marks=implode(',',array_fill(0,count($roles),'?'));
        $q=$this->database->connection()->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
            AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ($marks))");
        $q->execute([$actor->userId,$actor->clinicId,...$roles]);
        if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Clinic access is required.');
    }

    private function client(AuthContext $actor,int $clientId): void
    {
        $q=$this->database->connection()->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='client' AND u.status='active'
            AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=u.id)");
        $q->execute([$clientId,$actor->clinicId]);
        if(!$q->fetchColumn())throw new ApiException(404,'client_not_found','Client not found.');
        (new ConsentAccess($this->database->connection()))->assertClient($actor,$clientId);
    }

    private function practitioner(AuthContext $actor,int $clientId): int
    {
        $this->currentStaff($actor,['practitioner']);
        (new PractitionerPolicyDocumentService($this->database,$this->audit))->requireAccepted($actor);
        $this->client($actor,$clientId);
        $q=$this->database->connection()->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.status='active'
            JOIN client_care_assignments a ON a.practitioner_id=p.id AND a.clinic_id=u.clinic_id AND a.client_id=? AND a.active=1
            WHERE p.user_id=? AND p.active=1");
        $q->execute([$actor->clinicId,$clientId,$actor->userId]);$id=$q->fetchColumn();
        if(!$id)throw new ApiException(403,'care_assignment_required','An active care assignment is required.');
        return (int)$id;
    }

    public function team(AuthContext $actor,int $clientId,string $cid): array
    {
        $lock=new ConsentLock($this->database->connection());$lock->acquire($actor->clinicId);
        try{
            $this->currentStaff($actor,['super_admin','clinic_admin']);$this->client($actor,$clientId);
            $q=$this->database->connection()->prepare("SELECT p.id practitioner_id,u.display_name,u.status,COALESCE(a.active,0) assigned
                FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=:clinic AND u.user_type='staff'
                LEFT JOIN client_care_assignments a ON a.practitioner_id=p.id AND a.clinic_id=u.clinic_id AND a.client_id=:client
                WHERE (p.active=1 AND u.status='active' AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id AND r.code='practitioner' WHERE ur.user_id=u.id))
                   OR a.active=1 ORDER BY u.display_name,p.id LIMIT 501");
            $q->execute(['clinic'=>$actor->clinicId,'client'=>$clientId]);$rows=$q->fetchAll();
            if(count($rows)>500)throw new ApiException(503,'care_team_too_large','Ask the clinic administrator to narrow the care team list.');
            $this->audit->write($actor->clinicId,$actor,$cid,'care_team.view','client',$clientId);
            return ['items'=>$rows];
        }finally{$lock->release();}
    }

    public function setAssignment(AuthContext $actor,int $clientId,int $practitionerId,array $body,string $cid): array
    {
        if(!isset($body['assigned'])||!is_bool($body['assigned']))throw new ApiException(422,'validation_error','Choose whether care is assigned.');
        $assigned=$body['assigned'];$pdo=$this->database->connection();
        $reasons=$assigned?['starting_care','care_transfer','temporary_coverage','access_correction']:['care_completed','care_transfer','temporary_coverage','access_correction','practitioner_departure'];
        $reason=$body['reason_code']??null;
        if(!is_string($reason)||!in_array($reason,$reasons,true)||($body['review_confirmed']??null)!==true)
            throw new ApiException(422,'validation_error','Select a care-change reason and confirm the current care relationship was reviewed.');
        if($pdo->inTransaction())throw new \LogicException('Care assignment requires its own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();$this->currentStaff($actor,['super_admin','clinic_admin']);$this->client($actor,$clientId);
            $roleScope=$assigned?" AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code='practitioner')":'';
            $q=$pdo->prepare("SELECT p.user_id,u.status,p.active FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.user_type='staff'
                WHERE p.id=? $roleScope FOR UPDATE");
            $q->execute([$actor->clinicId,$practitionerId]);$target=$q->fetch();$staff=$target['user_id']??false;
            if(!$staff)throw new ApiException(404,'practitioner_not_found','Practitioner not found.');
            if($assigned&&($target['status']!=='active'||(int)$target['active']!==1))throw new ApiException(409,'practitioner_inactive','Only an active practitioner can be assigned to care.');
            if($assigned&&(new ConsentAccess($pdo))->restricted($actor->clinicId,$clientId,(int)$staff,true))throw new ApiException(403,'consent_restricted','This practitioner is restricted by a patient privacy instruction.');
            $q=$pdo->prepare('SELECT active FROM client_care_assignments WHERE clinic_id=? AND client_id=? AND practitioner_id=? FOR UPDATE');
            $q->execute([$actor->clinicId,$clientId,$practitionerId]);$existing=$q->fetchColumn();
            if($existing!==false&&(bool)$existing===$assigned)throw new ApiException(409,'care_assignment_unchanged','Care assignment changed. Refresh and review it again.');
            if(!$assigned&&$existing===false)throw new ApiException(409,'care_assignment_unchanged','No active assignment exists.');
            if($assigned){
                $q=$pdo->prepare('INSERT INTO client_care_assignments(clinic_id,client_id,practitioner_id,active,assigned_by,assigned_at,ended_by,ended_at)
                    VALUES(?,?,?,1,?,UTC_TIMESTAMP(),NULL,NULL) ON DUPLICATE KEY UPDATE active=1,assigned_by=VALUES(assigned_by),assigned_at=VALUES(assigned_at),ended_by=NULL,ended_at=NULL');
                $q->execute([$actor->clinicId,$clientId,$practitionerId,$actor->userId]);
            }else{
                $q=$pdo->prepare('UPDATE client_care_assignments SET active=0,ended_by=?,ended_at=UTC_TIMESTAMP() WHERE clinic_id=? AND client_id=? AND practitioner_id=? AND active=1');
                $q->execute([$actor->userId,$actor->clinicId,$clientId,$practitionerId]);
            }
            $this->audit->write($actor->clinicId,$actor,$cid,$assigned?'care_team.assign':'care_team.end','client',$clientId,'success',['practitioner_id'=>$practitionerId,'reason_code'=>$reason]);
            $pdo->commit();return ['client_id'=>$clientId,'practitioner_id'=>$practitionerId,'assigned'=>$assigned];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    public function notes(AuthContext $actor,int $clientId,array $query,string $cid): array
    {
        $before=filter_var($query['before_id']??PHP_INT_MAX,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($before===false)throw new ApiException(422,'validation_error','Invalid note cursor.');
        $lock=new ConsentLock($this->database->connection());$lock->acquire($actor->clinicId);
        try{
            $practitionerId=$this->practitioner($actor,$clientId);
            $q=$this->database->connection()->prepare("SELECT n.id,n.note_text,n.created_at,n.visibility,n.practitioner_id,u.display_name author_name
                FROM practitioner_client_notes n JOIN practitioners author ON author.id=n.practitioner_id
                JOIN users u ON u.id=author.user_id AND u.clinic_id=:clinic
                WHERE n.client_id=:client AND n.id<:before AND (n.practitioner_id=:self OR n.visibility='care_team')
                ORDER BY n.id DESC LIMIT 51");
            $q->execute(['clinic'=>$actor->clinicId,'client'=>$clientId,'before'=>$before,'self'=>$practitionerId]);$rows=$q->fetchAll();
            $this->audit->write($actor->clinicId,$actor,$cid,'clinical_notes.view','client',$clientId,'success',['count'=>min(count($rows),50)]);
            return ['items'=>array_slice($rows,0,50),'has_more'=>count($rows)>50];
        }finally{$lock->release();}
    }

    public function addNote(AuthContext $actor,int $clientId,array $body,string $cid): array
    {
        $text=$body['note_text']??null;$visibility=$body['visibility']??'author';$appointment=$body['appointment_id']??null;$requestKey=$body['idempotency_key']??null;
        if(!is_string($text)||trim($text)===''||mb_strlen($text)>10000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text))throw new ApiException(422,'validation_error','Enter a note of at most 10000 characters.');
        if(!in_array($visibility,['author','care_team'],true))throw new ApiException(422,'validation_error','Invalid note visibility.');
        if($appointment!==null&&filter_var($appointment,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new ApiException(422,'validation_error','Invalid appointment.');
        if(!is_string($requestKey)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',$requestKey))throw new ApiException(422,'validation_error','A valid request key is required.');
        $pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Clinical note creation requires its own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();$practitionerId=$this->practitioner($actor,$clientId);
            $existing=$pdo->prepare('SELECT id,client_id,note_text,visibility,appointment_id FROM practitioner_client_notes WHERE practitioner_id=? AND idempotency_key=? FOR UPDATE');
            $existing->execute([$practitionerId,$requestKey]);$previous=$existing->fetch();
            if($previous){
                if((int)$previous['client_id']!==$clientId||$previous['note_text']!==trim($text)||$previous['visibility']!==$visibility||($previous['appointment_id']===null?null:(int)$previous['appointment_id'])!==($appointment===null?null:(int)$appointment))throw new ApiException(409,'request_key_reused','This request key was used for a different note.');
                $pdo->commit();return ['id'=>(int)$previous['id'],'client_id'=>$clientId,'visibility'=>$visibility];
            }
            if($appointment!==null){$q=$pdo->prepare("SELECT 1 FROM appointments WHERE id=? AND clinic_id=? AND client_id=? AND practitioner_id=? AND status NOT IN ('canceled_by_client','canceled_by_clinic')");$q->execute([(int)$appointment,$actor->clinicId,$clientId,$practitionerId]);if(!$q->fetchColumn())throw new ApiException(404,'appointment_not_found','Appointment not found.');}
            $q=$pdo->prepare('INSERT INTO practitioner_client_notes(client_id,practitioner_id,appointment_id,note_text,visibility,idempotency_key) VALUES(?,?,?,?,?,?)');
            $q->execute([$clientId,$practitionerId,$appointment===null?null:(int)$appointment,trim($text),$visibility,$requestKey]);$id=(int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId,$actor,$cid,'clinical_note.create','clinical_note',$id,'success',['client_id'=>$clientId,'visibility'=>$visibility]);
            $pdo->commit();return ['id'=>$id,'client_id'=>$clientId,'visibility'=>$visibility];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
}
