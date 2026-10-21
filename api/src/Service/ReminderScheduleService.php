<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Clinic-wide email reminder timing and authorization of existing queue effects. */
final class ReminderScheduleService
{
    private const ALLOWED_MINUTES = [60, 180, 1440, 2880, 4320];

    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin'))
            throw new ApiException(403, 'forbidden', 'Administrator access is required.');
    }

    public static function minutes(array $body): int
    {
        $value = filter_var($body['minutes_before'] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || !in_array($value, self::ALLOWED_MINUTES, true))
            throw new ApiException(422, 'validation_error', 'Select an offered reminder time.');
        return $value;
    }

    /** Match the queue's JSON representation without PHP/MySQL numeric coercion differences. */
    public static function payloadMinutes(mixed $value): ?int
    {
        if(is_int($value)&&in_array($value,self::ALLOWED_MINUTES,true))return $value;
        if(is_string($value))foreach(self::ALLOWED_MINUTES as $minutes)if($value===(string)$minutes)return $minutes;
        return null;
    }

    private function currentActor(AuthContext $actor): void
    {
        self::authorize($actor);
        $q=$this->database->connection()->prepare("SELECT 1 FROM users u JOIN clinics c ON c.id=u.clinic_id AND c.status='active'
            WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
              AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ('super_admin','clinic_admin'))");
        $q->execute([$actor->userId,$actor->clinicId]);if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Administrator access is required.');
    }

    private function rows(AuthContext $actor): array
    {
        $q=$this->database->connection()->prepare("SELECT id,minutes_before,active FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' ORDER BY minutes_before,id");
        $q->execute(['clinic'=>$actor->clinicId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function list(AuthContext $actor): array
    {
        self::authorize($actor);$pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Reminder access requires an independent transaction.');
        $this->currentActor($actor);return $this->rows($actor);
    }

    private function mutation(AuthContext $actor,callable $work): array
    {
        self::authorize($actor);$pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Reminder changes require their own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();$this->lockClinic($pdo,$actor->clinicId);$this->currentActor($actor);
            // New/reenabled settings govern future dispatch, which must never assume policy is absent.
            $q=$pdo->prepare('SELECT 1 FROM client_consent_directives WHERE clinic_id=? LIMIT 1');$q->execute([$actor->clinicId]);
            $work();$result=$this->rows($actor);$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    /** Shared exact predicate for authorization, cancellation and its audit references. */
    private function pending(string $states): string
    {
        return "n.clinic_id=:clinic AND n.event_code='appointment_reminder' AND n.channel='email' AND n.status IN ($states)
            AND JSON_TYPE(JSON_EXTRACT(n.payload,'$.minutes_before')) IN ('INTEGER','STRING')
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT(n.payload,'$.minutes_before'))=BINARY CAST(:minutes AS CHAR)";
    }

    private function authorizePending(AuthContext $actor,int $minutes): void
    {
        $params=['clinic'=>$actor->clinicId,'minutes'=>$minutes];$visible=ConsentAccess::condition($actor,$params,'client.id');
        $where=$this->pending("'queued','failed','sending'");
        $q=$this->database->connection()->prepare("SELECT 1 FROM notification_events n WHERE $where
            AND NOT EXISTS(SELECT 1 FROM appointments a
                JOIN users client ON client.id=a.client_id AND client.clinic_id=a.clinic_id AND client.user_type='client'
                JOIN practitioners p ON p.id=a.practitioner_id
                JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=a.clinic_id AND staff.user_type='staff'
                JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
                JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
                WHERE a.id=n.appointment_id AND a.clinic_id=n.clinic_id AND n.recipient_user_id=client.id
                  AND NOT EXISTS(SELECT 1 FROM client_merge_records merged WHERE merged.duplicate_client_id=client.id) $visible) LIMIT 1");
        $q->execute($params);if($q->fetchColumn())throw new ApiException(403,'reminder_schedule_access_restricted','This reminder change requires an authorized privacy review. No changes were saved.');
    }

    public function create(AuthContext $actor,array $body,string $correlationId): array
    {
        return $this->mutation($actor,function()use($actor,$body,$correlationId):void{
            $minutes=self::minutes($body);$pdo=$this->database->connection();
            $q=$pdo->prepare("SELECT id FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' AND minutes_before=:minutes");
            $q->execute(['clinic'=>$actor->clinicId,'minutes'=>$minutes]);if($q->fetchColumn())throw new ApiException(409,'reminder_exists','This reminder time already exists.');
            $this->assertCapacity($pdo,$actor->clinicId);$this->authorizePending($actor,$minutes);
            $q=$pdo->prepare("INSERT INTO reminder_schedules(clinic_id,event_code,minutes_before,channel,active) VALUES(?,'appointment_reminder',?,'email',1)");$q->execute([$actor->clinicId,$minutes]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'reminder_schedule.create','reminder_schedule',(int)$pdo->lastInsertId(),'success',['minutes_before'=>$minutes]);
        });
    }

    public function setActive(AuthContext $actor,int $id,array $body,string $correlationId): array
    {
        return $this->mutation($actor,function()use($actor,$id,$body,$correlationId):void{
            if(!is_bool($body['active']??null))throw new ApiException(422,'validation_error','active must be a boolean.');
            $active=$body['active'];$pdo=$this->database->connection();
            $q=$pdo->prepare("SELECT minutes_before,active FROM reminder_schedules WHERE id=:id AND clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' FOR UPDATE");
            $q->execute(['id'=>$id,'clinic'=>$actor->clinicId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new ApiException(404,'reminder_not_found','Reminder schedule not found.');
            $minutes=self::minutes($row);if($active&&!(bool)$row['active'])$this->assertCapacity($pdo,$actor->clinicId);
            // Both directions can change eligibility of legacy pending work. Claims made before
            // the worker's consent lock are included through the sending state.
            $this->authorizePending($actor,$minutes);
            $pdo->prepare('UPDATE reminder_schedules SET active=? WHERE id=?')->execute([$active?1:0,$id]);
            if(!$active){
                $where=$this->pending("'queued','failed'");$params=['clinic'=>$actor->clinicId,'minutes'=>$minutes];
                $q=$pdo->prepare("SELECT n.id FROM notification_events n WHERE $where ORDER BY n.id FOR UPDATE");$q->execute($params);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
                $q=$pdo->prepare("UPDATE notification_events n SET status='canceled',next_attempt_at=NULL,last_error='Reminder schedule disabled' WHERE $where");$q->execute($params);
                foreach(array_chunk($ids,200) as $chunk)$this->audit->write($actor->clinicId,$actor,$correlationId,'reminder_schedule.cancel_notifications','reminder_schedule',$id,'success',['notification_ids'=>$chunk]);
            }
            $this->audit->write($actor->clinicId,$actor,$correlationId,'reminder_schedule.update','reminder_schedule',$id,'success',['active'=>$active]);
        });
    }

    private function lockClinic(PDO $pdo,int $clinicId): void
    {
        $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$clinicId]);
        if(!$q->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
    }

    private function assertCapacity(PDO $pdo,int $clinicId): void
    {
        $q=$pdo->prepare("SELECT COUNT(*) FROM reminder_schedules WHERE clinic_id=? AND event_code='appointment_reminder' AND channel='email' AND active=1");$q->execute([$clinicId]);
        if((int)$q->fetchColumn()>=3)throw new ApiException(422,'reminder_limit','Only three reminder times can be active. Disable one first.');
    }
}
