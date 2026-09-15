<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class BookingService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public function create(AuthContext $actor,array $body,string $correlationId): array
    {
        foreach(['client_id','location_id','practitioner_id','service_id','duration_option_id','starts_at','idempotency_key'] as $field) if(empty($body[$field])) throw new ApiException(422,'validation_error',"{$field} is required.",[$field=>'Required']);
        $clientId=(int)$body['client_id'];
        if($actor->userType==='client'&&$actor->userId!==$clientId)throw new ApiException(403,'forbidden','Clients can only book for themselves.');
        if($actor->userType==='staff'&&!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot create appointments.');
        $startsAt=BookingRequest::timestamp($body['starts_at']);
        if(!is_string($body['idempotency_key'])||strlen($body['idempotency_key'])>100)throw new ApiException(422,'validation_error','Provide an idempotency key of at most 100 characters.');
        $startsUtc=$startsAt->setTimezone(new DateTimeZone('UTC'));$pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            // All booking writers acquire this existing row before reading a snapshot.
            // Serializing confirmations per clinic protects even an entirely empty schedule.
            $lock=$pdo->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active' FOR UPDATE");$lock->execute(['clinic'=>$actor->clinicId]);if(!$lock->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $existing=$pdo->prepare('SELECT * FROM appointments WHERE clinic_id=:clinic AND idempotency_key=:key FOR UPDATE');$existing->execute(['clinic'=>$actor->clinicId,'key'=>$body['idempotency_key']]);if($row=$existing->fetch()){
                BookingRequest::assertReplay($row,$body,$actor->userId,$startsUtc);
                $result=$this->getById($actor,(int)$row['id']);$pdo->commit();return $result;
            }
            $client=$pdo->prepare("SELECT id FROM users WHERE id=:id AND clinic_id=:clinic AND user_type='client' AND status='active'");$client->execute(['id'=>$clientId,'clinic'=>$actor->clinicId]);if(!$client->fetchColumn())throw new ApiException(422,'invalid_client','Select an active client in this clinic.');
            if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')){
                $owner=$pdo->prepare('SELECT id FROM practitioners WHERE id=:id AND user_id=:user');$owner->execute(['id'=>(int)$body['practitioner_id'],'user'=>$actor->userId]);if(!$owner->fetchColumn())throw new ApiException(403,'forbidden','Practitioners can only book their own appointments.');
            }
            $location=$pdo->prepare('SELECT timezone FROM locations WHERE id=:id AND clinic_id=:clinic AND is_bookable=1');$location->execute(['id'=>(int)$body['location_id'],'clinic'=>$actor->clinicId]);$timezone=$location->fetchColumn();if(!$timezone)throw new ApiException(422,'invalid_location','Select a bookable location in this clinic.');
            $sql="SELECT s.buffer_before_minutes,s.buffer_after_minutes,s.lead_time_minutes,s.booking_horizon_days,s.requires_room,d.duration_minutes
                    FROM services s JOIN service_duration_options d ON d.service_id=s.id AND d.active=1 JOIN practitioner_services ps ON ps.service_id=s.id AND ps.practitioner_id=:p AND ps.active=1
                   WHERE s.id=:s AND s.clinic_id=:clinic AND d.id=:d AND s.active=1 FOR UPDATE";
            $statement=$pdo->prepare($sql);$statement->execute(['p'=>(int)$body['practitioner_id'],'s'=>(int)$body['service_id'],'clinic'=>$actor->clinicId,'d'=>(int)$body['duration_option_id']]);$rule=$statement->fetch();
            if(!$rule)throw new ApiException(422,'invalid_booking','The selected practitioner, service, or duration is unavailable.');
            if($rule['requires_room']&&!isset($body['room_id']))throw new ApiException(422,'validation_error','room_id is required for this service.',['room_id'=>'Required']);
            if(!$rule['requires_room']&&isset($body['room_id']))throw new ApiException(422,'validation_error','This service does not use a room.');
            $localDate=$startsUtc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            $availability=(new AvailabilityService($this->database))->search(['service_id'=>$body['service_id'],'practitioner_id'=>$body['practitioner_id'],'location_id'=>$body['location_id'],'date_from'=>$localDate,'date_to'=>$localDate]);
            $matched=false;
            foreach($availability['availability'] as $slot){
                if($slot['duration_option_id']===(int)$body['duration_option_id']&&(new DateTimeImmutable($slot['starts_at']))->getTimestamp()===$startsUtc->getTimestamp()){
                    if($rule['requires_room']&&!in_array((int)$body['room_id'],$slot['available_room_ids'],true))throw new ApiException(409,'room_conflict','The selected room is unavailable or unsuitable.');
                    $matched=true;break;
                }
            }
            if(!$matched)throw new ApiException(409,'schedule_conflict','The requested time is no longer bookable. Refresh availability and choose another time.');
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));if($startsUtc<$now->modify('+'.$rule['lead_time_minutes'].' minutes')||$startsUtc>$now->modify('+'.$rule['booking_horizon_days'].' days'))throw new ApiException(422,'outside_booking_window','The requested time is outside the allowed booking window.');
            $endsUtc=$startsUtc->modify('+'.$rule['duration_minutes'].' minutes');$bufferStart=$startsUtc->modify('-'.$rule['buffer_before_minutes'].' minutes');$bufferEnd=$endsUtc->modify('+'.$rule['buffer_after_minutes'].' minutes');
            $params=['p'=>(int)$body['practitioner_id'],'start'=>$bufferStart->format('Y-m-d H:i:s'),'end'=>$bufferEnd->format('Y-m-d H:i:s')];
            $conflict=$pdo->prepare("SELECT id FROM appointments WHERE practitioner_id=:p AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start FOR UPDATE");$conflict->execute($params);if($conflict->fetch())throw new ApiException(409,'schedule_conflict','The practitioner is no longer available.');
            if(isset($body['room_id'])){$room=$pdo->prepare("SELECT id FROM appointments WHERE room_id=:room AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start FOR UPDATE");$room->execute(['room'=>(int)$body['room_id'],'start'=>$params['start'],'end'=>$params['end']]);if($room->fetch())throw new ApiException(409,'room_conflict','The room is no longer available.');}
            $insert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,room_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,idempotency_key,created_by) VALUES(:clinic,:location,:client,:p,:service,:duration,:room,:starts,:ends,:buffer_start,:buffer_end,'confirmed',:source,:key,:creator)");
            $insert->execute(['clinic'=>$actor->clinicId,'location'=>(int)$body['location_id'],'client'=>$clientId,'p'=>(int)$body['practitioner_id'],'service'=>(int)$body['service_id'],'duration'=>(int)$body['duration_option_id'],'room'=>isset($body['room_id'])?(int)$body['room_id']:null,'starts'=>$startsUtc->format('Y-m-d H:i:s'),'ends'=>$endsUtc->format('Y-m-d H:i:s'),'buffer_start'=>$params['start'],'buffer_end'=>$params['end'],'source'=>$actor->userType==='client'?'public':($actor->hasAnyRole('super_admin','clinic_admin')?'admin':($actor->hasAnyRole('reception')?'reception':'practitioner')),'key'=>$body['idempotency_key'],'creator'=>$actor->userId]);
            $id=(int)$pdo->lastInsertId();$history=$pdo->prepare("INSERT INTO appointment_status_history(appointment_id,to_status,actor_user_id) VALUES(:id,'confirmed',:actor)");$history->execute(['id'=>$id,'actor'=>$actor->userId]);
            $notify=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment_id,u.id,u.email,'booking_confirmation','email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_appointment_id) FROM users u WHERE u.id=:client");$notify->execute(['clinic'=>$actor->clinicId,'appointment_id'=>$id,'payload_appointment_id'=>$id,'client'=>$clientId]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.create','appointment',$id);
            $pdo->commit();return $this->getById($actor,$id);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function list(AuthContext $actor): array
    {
        $sql="SELECT a.id,a.client_id,a.practitioner_id,a.service_id,a.room_id,a.starts_at,a.ends_at,a.status,s.name service_name,u.display_name client_name FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id WHERE a.clinic_id=:clinic";$params=['clinic'=>$actor->clinicId];
        if($actor->userType==='client'){$sql.=' AND a.client_id=:user';$params['user']=$actor->userId;}elseif($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')){$sql.=' AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user)';$params['user']=$actor->userId;}
        $sql.=' ORDER BY a.starts_at DESC LIMIT 200';$statement=$this->database->connection()->prepare($sql);$statement->execute($params);return $statement->fetchAll();
    }

    private function getById(AuthContext $actor,int $id): array
    {
        $statement=$this->database->connection()->prepare('SELECT id,client_id,practitioner_id,service_id,room_id,starts_at,ends_at,status,created_at FROM appointments WHERE id=:id AND clinic_id=:clinic');$statement->execute(['id'=>$id,'clinic'=>$actor->clinicId]);return $statement->fetch()?:[];
    }
}
