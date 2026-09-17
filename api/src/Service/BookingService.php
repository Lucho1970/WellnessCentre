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
        $mode=Delivery::mode($body);$destination=Delivery::destination($body);
        if($mode==='mobile'&&($actor->userType!=='staff'||($body['coverage_confirmed']??false)!==true))throw new ApiException(422,'coverage_required','An authorized staff member must verify the visit address and coverage before booking.');
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
            $sql="SELECT ps.offers_mobile,ps.offers_clinic,ps.travel_buffer_minutes,ps.mobile_fee_cents,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,s.buffer_before_minutes,s.buffer_after_minutes,s.lead_time_minutes,s.booking_horizon_days,s.requires_room,d.duration_minutes
                    FROM services s JOIN service_duration_options d ON d.service_id=s.id AND d.active=1 JOIN practitioner_services ps ON ps.service_id=s.id AND ps.practitioner_id=:p AND ps.active=1
                   WHERE s.id=:s AND s.clinic_id=:clinic AND d.id=:d AND s.active=1 FOR UPDATE";
            $statement=$pdo->prepare($sql);$statement->execute(['p'=>(int)$body['practitioner_id'],'s'=>(int)$body['service_id'],'clinic'=>$actor->clinicId,'d'=>(int)$body['duration_option_id']]);$rule=$statement->fetch();
            if(!$rule)throw new ApiException(422,'invalid_booking','The selected practitioner, service, or duration is unavailable.');
            $terms=Delivery::terms($rule,$mode);
            if(isset($body['quoted_base_price_cents'])&&((int)$body['quoted_base_price_cents']!==$terms['base']||(int)($body['quoted_mobile_fee_cents']??0)!==$terms['fee']))throw new ApiException(409,'price_changed','Pricing changed. Close and reopen booking to review the current price.');
            if($terms['requires_room']&&!isset($body['room_id']))throw new ApiException(422,'validation_error','room_id is required for this service.',['room_id'=>'Required']);
            if(!$terms['requires_room']&&isset($body['room_id']))throw new ApiException(422,'validation_error','This service does not use a room.');
            $localDate=$startsUtc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            $availability=(new AvailabilityService($this->database))->search(['delivery_mode'=>$mode,'service_id'=>$body['service_id'],'practitioner_id'=>$body['practitioner_id'],'location_id'=>$body['location_id'],'date_from'=>$localDate,'date_to'=>$localDate]);
            $matched=false;
            foreach($availability['availability'] as $slot){
                if($slot['duration_option_id']===(int)$body['duration_option_id']&&(new DateTimeImmutable($slot['starts_at']))->getTimestamp()===$startsUtc->getTimestamp()){
                    if($terms['requires_room']&&!in_array((int)$body['room_id'],$slot['available_room_ids'],true))throw new ApiException(409,'room_conflict','The selected room is unavailable or unsuitable.');
                    $matched=true;break;
                }
            }
            if(!$matched)throw new ApiException(409,'schedule_conflict','The requested time is no longer bookable. Refresh availability and choose another time.');
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));if($startsUtc<$now->modify('+'.$rule['lead_time_minutes'].' minutes')||$startsUtc>$now->modify('+'.$rule['booking_horizon_days'].' days'))throw new ApiException(422,'outside_booking_window','The requested time is outside the allowed booking window.');
            $endsUtc=$startsUtc->modify('+'.$rule['duration_minutes'].' minutes');$bufferStart=$startsUtc->modify('-'.((int)$rule['buffer_before_minutes']+$terms['travel']).' minutes');$bufferEnd=$endsUtc->modify('+'.((int)$rule['buffer_after_minutes']+$terms['travel']).' minutes');
            $params=['p'=>(int)$body['practitioner_id'],'start'=>$bufferStart->format('Y-m-d H:i:s'),'end'=>$bufferEnd->format('Y-m-d H:i:s')];
            $conflict=$pdo->prepare("SELECT id FROM appointments WHERE practitioner_id=:p AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start FOR UPDATE");$conflict->execute($params);if($conflict->fetch())throw new ApiException(409,'schedule_conflict','The practitioner is no longer available.');
            if(isset($body['room_id'])){$room=$pdo->prepare("SELECT id FROM appointments WHERE room_id=:room AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start FOR UPDATE");$room->execute(['room'=>(int)$body['room_id'],'start'=>$params['start'],'end'=>$params['end']]);if($room->fetch())throw new ApiException(409,'room_conflict','The room is no longer available.');}
            $insert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,room_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,idempotency_key,created_by) VALUES(:clinic,:location,:client,:p,:service,:duration,:room,:starts,:ends,:buffer_start,:buffer_end,'confirmed',:source,:key,:creator)");
            $insert->execute(['clinic'=>$actor->clinicId,'location'=>(int)$body['location_id'],'client'=>$clientId,'p'=>(int)$body['practitioner_id'],'service'=>(int)$body['service_id'],'duration'=>(int)$body['duration_option_id'],'room'=>isset($body['room_id'])?(int)$body['room_id']:null,'starts'=>$startsUtc->format('Y-m-d H:i:s'),'ends'=>$endsUtc->format('Y-m-d H:i:s'),'buffer_start'=>$params['start'],'buffer_end'=>$params['end'],'source'=>$actor->userType==='client'?'public':($actor->hasAnyRole('super_admin','clinic_admin')?'admin':($actor->hasAnyRole('reception')?'reception':'practitioner')),'key'=>$body['idempotency_key'],'creator'=>$actor->userId]);
            $id=(int)$pdo->lastInsertId();
            $snapshot=$pdo->prepare('UPDATE appointments SET delivery_mode=:mode,destination_snapshot=:destination,travel_buffer_minutes=:travel,base_price_cents=:base,mobile_fee_cents=:fee,coverage_confirmed_by=:actor WHERE id=:id');
            $snapshot->execute(['mode'=>$mode,'destination'=>$destination?json_encode($destination,JSON_THROW_ON_ERROR):null,'travel'=>$terms['travel'],'base'=>$terms['base'],'fee'=>$terms['fee'],'actor'=>$mode==='mobile'?$actor->userId:null,'id'=>$id]);
            $history=$pdo->prepare("INSERT INTO appointment_status_history(appointment_id,to_status,actor_user_id) VALUES(:id,'confirmed',:actor)");$history->execute(['id'=>$id,'actor'=>$actor->userId]);
            $notify=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment_id,u.id,u.email,'booking_confirmation','email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_appointment_id) FROM users u WHERE u.id=:client");$notify->execute(['clinic'=>$actor->clinicId,'appointment_id'=>$id,'payload_appointment_id'=>$id,'client'=>$clientId]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.create','appointment',$id);
            $pdo->commit();return $this->getById($actor,$id);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function options(AuthContext $actor): array
    {
        ClientService::authorize($actor);
        $pdo=$this->database->connection();
        $s=$pdo->prepare("SELECT l.id location_id,l.name location_name,l.timezone,s.id service_id,s.name service_name,s.requires_room,ps.offers_mobile,ps.offers_clinic,ps.travel_buffer_minutes,ps.mobile_fee_cents,ps.mobile_radius_km,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,p.id practitioner_id,u.display_name practitioner_name,d.id duration_option_id,d.duration_minutes
            FROM services s JOIN service_locations sl ON sl.service_id=s.id AND sl.active=1
            JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id AND l.is_bookable=1
            JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1
            JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1
            JOIN users u ON u.id=p.user_id AND u.clinic_id=s.clinic_id AND u.status='active'
            JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.location_id=l.id AND pl.active=1
            JOIN service_duration_options d ON d.service_id=s.id AND d.active=1
            WHERE s.clinic_id=:clinic AND s.active=1 AND (ps.offers_clinic=1 OR ps.offers_mobile=1) ORDER BY l.name,s.name,u.display_name,d.duration_minutes");
        $s->execute(['clinic'=>$actor->clinicId]);$combinations=$s->fetchAll();
        $s=$pdo->prepare('SELECT r.id,r.name,r.location_id FROM rooms r JOIN locations l ON l.id=r.location_id WHERE l.clinic_id=:clinic AND r.is_bookable=1 ORDER BY r.name');$s->execute(['clinic'=>$actor->clinicId]);
        return ['combinations'=>$combinations,'rooms'=>$s->fetchAll()];
    }

    public static function authorizeList(AuthContext $actor): void
    {
        if($actor->userType==='client')return;
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot view appointments.');
    }

    public function list(AuthContext $actor,array $query=[]): array
    {
        self::authorizeList($actor);
        $sql="SELECT a.delivery_mode,a.destination_snapshot,a.travel_buffer_minutes,a.base_price_cents,a.mobile_fee_cents,a.currency,a.id,a.client_id,a.practitioner_id,a.service_id,a.room_id,a.starts_at,a.ends_at,a.status,s.name service_name,u.display_name client_name,pu.display_name practitioner_name,l.name location_name,l.timezone,r.name room_name FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id JOIN practitioners p ON p.id=a.practitioner_id JOIN users pu ON pu.id=p.user_id JOIN locations l ON l.id=a.location_id LEFT JOIN rooms r ON r.id=a.room_id WHERE a.clinic_id=:clinic";$params=['clinic'=>$actor->clinicId];
        if($actor->userType==='client'){$sql.=' AND a.client_id=:user';$params['user']=$actor->userId;}elseif($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')){$sql.=' AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user)';$params['user']=$actor->userId;}
        $view=$query['view']??'all';
        if(!in_array($view,['all','upcoming','past'],true))throw new ApiException(422,'validation_error','Invalid appointment view.');
        if($view==='upcoming')$sql.=' AND a.ends_at>=UTC_TIMESTAMP()';
        if($view==='past')$sql.=' AND a.ends_at<UTC_TIMESTAMP()';
        $page=max(1,min(100000,(int)($query['page']??1)));$offset=($page-1)*50;
        $sql.=$view==='upcoming'?' ORDER BY a.starts_at ASC,a.id ASC':' ORDER BY a.starts_at DESC,a.id DESC';
        $sql.=" LIMIT 50 OFFSET {$offset}";$statement=$this->database->connection()->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll();
        if(array_filter($rows,fn($row)=>$row['delivery_mode']==='mobile'))$this->audit->write($actor->clinicId,$actor,bin2hex(random_bytes(16)),'appointment.destination.view','appointment',null,'success',['page'=>$page]);
        return $rows;
    }

    private function getById(AuthContext $actor,int $id): array
    {
        $statement=$this->database->connection()->prepare('SELECT id,client_id,practitioner_id,service_id,room_id,starts_at,ends_at,status,created_at FROM appointments WHERE id=:id AND clinic_id=:clinic');$statement->execute(['id'=>$id,'clinic'=>$actor->clinicId]);return $statement->fetch()?:[];
    }
}
