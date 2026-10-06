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
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit,private readonly AddressCoverageService $addressCoverage) {}

    public function createForCustomer(AuthContext $actor,array $body,string $correlationId): array
    {
        return $this->create($actor,self::customerPayload($actor,$body),$correlationId);
    }

    public static function customerPayload(AuthContext $actor,array $body): array
    {
        if($actor->userType!=='client')throw new ApiException(403,'forbidden','Only a linked client can use this booking route.');
        if(array_key_exists('client_id',$body))throw new ApiException(422,'validation_error','client_id is assigned from the signed-in client and must not be submitted.',['client_id'=>'Unexpected']);
        $body['client_id']=$actor->userId;
        return $body;
    }

    public function create(AuthContext $actor,array $body,string $correlationId): array
    {
        foreach(['client_id','location_id','practitioner_id','service_id','duration_option_id','starts_at','idempotency_key'] as $field) if(empty($body[$field])) throw new ApiException(422,'validation_error',"{$field} is required.",[$field=>'Required']);
        $mode=Delivery::mode($body);$destination=Delivery::destination($body);
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
            if($mode==='mobile')$this->addressCoverage->verifyBooking($actor,$body,$destination);
            $practitionerOnly=$actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')&&!$actor->hasPermission('schedule_for_other_practitioners');
            $client=$pdo->prepare("SELECT id FROM users WHERE id=:id AND clinic_id=:clinic AND user_type='client' AND status='active'");$client->execute(['id'=>$clientId,'clinic'=>$actor->clinicId]);if(!$client->fetchColumn())throw new ApiException(422,'invalid_client','Select an active client in this clinic.');
            if($practitionerOnly){
                $owner=$pdo->prepare("SELECT id FROM practitioners WHERE id=:id AND user_id=:user AND active=1 AND booking_mode='practitioner_managed'");$owner->execute(['id'=>(int)$body['practitioner_id'],'user'=>$actor->userId]);if(!$owner->fetchColumn())throw new ApiException(403,'forbidden','Practitioners can only book their own practitioner-managed appointments.');
            }
            $location=$pdo->prepare('SELECT timezone FROM locations WHERE id=:id AND clinic_id=:clinic AND is_bookable=1');$location->execute(['id'=>(int)$body['location_id'],'clinic'=>$actor->clinicId]);$timezone=$location->fetchColumn();if(!$timezone)throw new ApiException(422,'invalid_location','Select a bookable location in this clinic.');
            $sql="SELECT ps.offers_mobile,ps.offers_clinic,ps.travel_buffer_minutes,ps.mobile_fee_cents,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,s.buffer_before_minutes,s.buffer_after_minutes,s.lead_time_minutes,s.booking_horizon_days,s.cancellation_window_minutes,s.cancellation_fee_type,s.cancellation_fee_value,s.requires_room,d.duration_minutes
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
            $insert=$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,room_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,idempotency_key,created_by,cancellation_window_minutes,cancellation_fee_type,cancellation_fee_value) VALUES(:clinic,:location,:client,:p,:service,:duration,:room,:starts,:ends,:buffer_start,:buffer_end,'confirmed',:source,:key,:creator,:cancel_window,:fee_type,:fee_value)");
            $insert->execute(['clinic'=>$actor->clinicId,'location'=>(int)$body['location_id'],'client'=>$clientId,'p'=>(int)$body['practitioner_id'],'service'=>(int)$body['service_id'],'duration'=>(int)$body['duration_option_id'],'room'=>isset($body['room_id'])?(int)$body['room_id']:null,'starts'=>$startsUtc->format('Y-m-d H:i:s'),'ends'=>$endsUtc->format('Y-m-d H:i:s'),'buffer_start'=>$params['start'],'buffer_end'=>$params['end'],'source'=>$actor->userType==='client'?'public':($actor->hasAnyRole('super_admin','clinic_admin')?'admin':($actor->hasAnyRole('reception')?'reception':'practitioner')),'key'=>$body['idempotency_key'],'creator'=>$actor->userId,'cancel_window'=>(int)$rule['cancellation_window_minutes'],'fee_type'=>$rule['cancellation_fee_type'],'fee_value'=>(int)$rule['cancellation_fee_value']]);
            $id=(int)$pdo->lastInsertId();
            $snapshot=$pdo->prepare('UPDATE appointments SET delivery_mode=:mode,destination_snapshot=:destination,travel_buffer_minutes=:travel,base_price_cents=:base,mobile_fee_cents=:fee,coverage_confirmed_by=:actor WHERE id=:id');
            $snapshot->execute(['mode'=>$mode,'destination'=>$destination?json_encode($destination,JSON_THROW_ON_ERROR):null,'travel'=>$terms['travel'],'base'=>$terms['base'],'fee'=>$terms['fee'],'actor'=>$mode==='mobile'?$actor->userId:null,'id'=>$id]);
            $history=$pdo->prepare("INSERT INTO appointment_status_history(appointment_id,to_status,actor_user_id) VALUES(:id,'confirmed',:actor)");$history->execute(['id'=>$id,'actor'=>$actor->userId]);
            $notify=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment_id,u.id,u.email,'booking_confirmation','email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_appointment_id) FROM users u WHERE u.id=:client");$notify->execute(['clinic'=>$actor->clinicId,'appointment_id'=>$id,'payload_appointment_id'=>$id,'client'=>$clientId]);
            AppointmentReminderQueue::replace($pdo,$actor->clinicId,$id,$clientId,$startsUtc->format('Y-m-d H:i:s'),1);
            StaffNotificationQueue::enqueue($pdo,$actor->clinicId,$id,(int)$body['practitioner_id'],'booking_confirmation');
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.create','appointment',$id);
            $pdo->commit();
            ImmediateNotificationDispatch::schedule($this->database, $id);
            return $this->getById($actor,$id);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function options(AuthContext $actor,array $query=[]): array
    {
        self::authorizeOptions($actor);
        $practitionerScope=(($query['scope']??'')==='practitioner'||($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')))&&!$actor->hasPermission('schedule_for_other_practitioners');
        if($practitionerScope&&!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner scope requires the practitioner role.');
        $pdo=$this->database->connection();
        $s=$pdo->prepare("SELECT l.id location_id,l.name location_name,l.timezone,s.id service_id,s.name service_name,s.requires_room,ps.offers_mobile,ps.offers_clinic,ps.travel_buffer_minutes,ps.mobile_fee_cents,ps.mobile_radius_km,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,p.id practitioner_id,u.display_name practitioner_name,d.id duration_option_id,d.duration_minutes
            FROM services s JOIN service_locations sl ON sl.service_id=s.id AND sl.active=1
            JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id AND l.is_bookable=1
            JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1
            JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1
            JOIN users u ON u.id=p.user_id AND u.clinic_id=s.clinic_id AND u.status='active'
            JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.location_id=l.id AND pl.active=1
            JOIN service_duration_options d ON d.service_id=s.id AND d.active=1
            WHERE s.clinic_id=:clinic AND s.active=1 AND (ps.offers_clinic=1 OR ps.offers_mobile=1)".($practitionerScope?" AND p.user_id=:user AND p.booking_mode='practitioner_managed'":'')." ORDER BY l.name,s.name,u.display_name,d.duration_minutes");
        $params=['clinic'=>$actor->clinicId];if($practitionerScope)$params['user']=$actor->userId;$s->execute($params);$combinations=$s->fetchAll();
        $s=$pdo->prepare('SELECT r.id,r.name,r.location_id FROM rooms r JOIN locations l ON l.id=r.location_id WHERE l.clinic_id=:clinic AND r.is_bookable=1 ORDER BY r.name');$s->execute(['clinic'=>$actor->clinicId]);
        $rooms=$s->fetchAll();$locationIds=array_values(array_unique(array_map(fn($row)=>(int)$row['location_id'],$combinations)));$defaultLocationId=count($locationIds)===1?$locationIds[0]:null;
        if(($query['scope']??'')==='practitioner'&&$actor->hasAnyRole('practitioner')&&$locationIds){
            $base=$pdo->prepare('SELECT pl.location_id FROM practitioner_locations pl JOIN practitioners p ON p.id=pl.practitioner_id JOIN locations l ON l.id=pl.location_id WHERE p.user_id=:user AND pl.active=1 AND l.clinic_id=:clinic AND l.is_bookable=1 ORDER BY pl.location_id');$base->execute(['user'=>$actor->userId,'clinic'=>$actor->clinicId]);
            foreach($base->fetchAll(PDO::FETCH_COLUMN) as $candidate)if(in_array((int)$candidate,$locationIds,true)){$defaultLocationId=(int)$candidate;break;}
        }
        if($defaultLocationId===null&&$locationIds)$defaultLocationId=min($locationIds);
        return ['combinations'=>$combinations,'rooms'=>$rooms,'default_location_id'=>$defaultLocationId];
    }

    public static function authorizeOptions(AuthContext $actor): void
    {
        if($actor->userType==='client')return;
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot view booking options.');
    }

    public function customerAvailability(AuthContext $actor,array $query): array
    {
        if($actor->userType!=='client')throw new ApiException(403,'forbidden','Only a linked client can use this availability route.');
        $mode=Delivery::mode($query);$location=(int)($query['location_id']??0);$service=(int)($query['service_id']??0);$practitioner=(int)($query['practitioner_id']??0);
        if(!$location||!$service||!$practitioner)throw new ApiException(422,'validation_error','service_id, practitioner_id, and location_id are required.');
        $column=$mode==='mobile'?'offers_mobile':'offers_clinic';
        $statement=$this->database->connection()->prepare("SELECT 1 FROM services s JOIN service_locations sl ON sl.service_id=s.id AND sl.location_id=:location AND sl.active=1 JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id AND l.is_bookable=1 JOIN practitioner_services ps ON ps.service_id=s.id AND ps.practitioner_id=:practitioner AND ps.active=1 JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1 JOIN users u ON u.id=p.user_id AND u.clinic_id=s.clinic_id AND u.status='active' JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.location_id=l.id AND pl.active=1 WHERE s.id=:service AND s.clinic_id=:clinic AND s.active=1 AND ps.{$column}=1");
        $statement->execute(['location'=>$location,'practitioner'=>$practitioner,'service'=>$service,'clinic'=>$actor->clinicId]);
        if(!$statement->fetchColumn())throw new ApiException(404,'service_not_available','That practitioner does not offer this service at the selected location.');
        return (new AvailabilityService($this->database))->search($query);
    }

    public function bookingClients(AuthContext $actor,array $query=[]): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot search booking clients.');
        $practitionerScope=(($query['scope']??'')==='practitioner'||($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')))&&!$actor->hasPermission('schedule_for_other_practitioners');
        if($practitionerScope&&!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner scope requires the practitioner role.');
        $term=trim((string)($query['q']??''));$birthdate=trim((string)($query['date_of_birth']??''));
        if($term!==''&&(strlen($term)<2||strlen($term)>190))throw new ApiException(422,'validation_error','Search must contain between 2 and 190 characters.');
        if($birthdate!==''&&!self::validDate($birthdate))throw new ApiException(422,'validation_error','Birthdate must be a valid date.',['date_of_birth'=>'Invalid date']);
        if($term===''&&$birthdate==='')throw new ApiException(422,'validation_error','Enter a birthdate or at least 2 search characters.');
        $sql="SELECT DISTINCT u.id,u.display_name,u.email,p.phone FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id WHERE u.clinic_id=:clinic AND u.user_type='client' AND u.status='active'";
        $params=['clinic'=>$actor->clinicId];
        if($birthdate!==''){$sql.=' AND p.date_of_birth=:birthdate';$params['birthdate']=$birthdate;}
        if($term!==''){$escaped='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';$sql.=" AND (u.display_name LIKE :name ESCAPE '!' OR u.email LIKE :email ESCAPE '!' OR p.phone LIKE :phone ESCAPE '!')";$params+=['name'=>$escaped,'email'=>$escaped,'phone'=>$escaped];}
        $sql.=' ORDER BY u.display_name,u.id LIMIT 26';
        $statement=$this->database->connection()->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll();$more=count($rows)>25;
        return ['items'=>array_slice($rows,0,25),'has_more'=>$more];
    }

    public function bookingClientAddress(AuthContext $actor,int $clientId,string $correlationId): array
    {
        self::authorizeBookingClientAddress($actor);
        $statement=$this->database->connection()->prepare("SELECT a.address_json FROM users u LEFT JOIN client_contact_addresses a ON a.client_id=u.id WHERE u.id=:client AND u.clinic_id=:clinic AND u.user_type='client' AND u.status='active'");
        $statement->execute(['client'=>$clientId,'clinic'=>$actor->clinicId]);$row=$statement->fetch();
        if(!$row)throw new ApiException(404,'client_not_found','The active client was not found.');
        $address=null;
        if(is_string($row['address_json']??null)&&$row['address_json']!==''){
            $decoded=json_decode($row['address_json'],true);
            if(is_array($decoded))$address=Delivery::destination(['delivery_mode'=>'mobile','destination'=>$decoded]);
            else error_log("Invalid saved client address correlation_id={$correlationId}");
        }
        $this->audit->write($actor->clinicId,$actor,$correlationId,'client.booking_address.view','client',$clientId);
        return ['address'=>$address];
    }

    public static function authorizeBookingClientAddress(AuthContext $actor): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot view a booking address.');
    }

    public function updateAvailability(AuthContext $actor,int $id,array $query): array
    {
        $appointment=$this->appointment($actor,$id,false);$this->assertManage($actor,$appointment);
        $result=(new AvailabilityService($this->database))->search([
            'delivery_mode'=>$appointment['delivery_mode'],'service_id'=>$appointment['service_id'],'practitioner_id'=>$appointment['practitioner_id'],'location_id'=>$appointment['location_id'],
            'date_from'=>$query['date_from']??null,'date_to'=>$query['date_to']??null,
        ],$id);
        $currentStart=(new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC')))->getTimestamp();
        $result['availability']=array_values(array_filter($result['availability'],fn($slot)=>(new DateTimeImmutable($slot['starts_at']))->getTimestamp()!==$currentStart));
        return $result;
    }

    public function cancellationPreview(AuthContext $actor,int $id): array
    {
        $appointment=$this->appointment($actor,$id,false);$this->assertManage($actor,$appointment);
        return CancellationPolicy::preview($appointment);
    }

    public function update(AuthContext $actor,int $id,array $body,string $correlationId): array
    {
        $action=$body['action']??'';
        if($action==='reassign')return $this->reassign($actor,$id,$body,$correlationId);
        if(!in_array($action,['reschedule','cancel'],true))throw new ApiException(422,'validation_error','Select reschedule, cancel, or reassign.');
        $version=(int)($body['version']??0);if($version<1)throw new ApiException(422,'validation_error','version is required.',['version'=>'Required']);
        $reason=trim((string)($body['reason']??''));if(strlen($reason)>1000)throw new ApiException(422,'validation_error','Reason must be at most 1000 characters.');
        $requested=null;if($action==='reschedule'){if(empty($body['starts_at']))throw new ApiException(422,'validation_error','starts_at is required.',['starts_at'=>'Required']);$requested=BookingRequest::timestamp($body['starts_at'])->setTimezone(new DateTimeZone('UTC'));}
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();$lock=$pdo->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active' FOR UPDATE");$lock->execute(['clinic'=>$actor->clinicId]);if(!$lock->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $appointment=$this->appointment($actor,$id,true);$this->assertManage($actor,$appointment);
            if((int)$appointment['version']!==$version){
                $canceledStatus=$actor->userType==='client'?'canceled_by_client':'canceled_by_clinic';
                $replayed=(int)$appointment['version']===$version+1&&(($action==='cancel'&&$appointment['status']===$canceledStatus)||($action==='reschedule'&&$appointment['status']==='rescheduled'&&(new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC')))->getTimestamp()===$requested?->getTimestamp()));
                if($replayed){$pdo->commit();return $appointment;}throw new ApiException(409,'appointment_changed','This appointment changed. Refresh it before making another change.');
            }
            if(!in_array($appointment['status'],['requested','confirmed','rescheduled'],true))throw new ApiException(409,'appointment_not_editable','This appointment can no longer be changed.');
            if(new DateTimeImmutable($appointment['ends_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC')))throw new ApiException(409,'appointment_not_editable','Past appointments cannot be rescheduled or canceled.');
            if($action==='reschedule'&&(new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC')))->getTimestamp()===$requested?->getTimestamp())throw new ApiException(422,'appointment_time_unchanged','Choose a different time to reschedule this appointment.');
            $from=$appointment['status'];
            if($action==='cancel'){
                $preview=CancellationPolicy::preview($appointment);$fee=0;
                if($actor->userType==='client'){
                    if(array_key_exists('apply_cancellation_fee',$body)||array_key_exists('adjusted_fee_cents',$body))throw new ApiException(422,'validation_error','Clients cannot alter the cancellation fee.');
                    CancellationPolicy::acknowledge($body, $preview);
                    $fee=(int)$preview['fee_cents'];
                }elseif((bool)($body['apply_cancellation_fee']??false)){
                    if(!$actor->hasAnyRole('super_admin','clinic_admin','reception'))throw new ApiException(403,'forbidden','Your role cannot assess a client cancellation fee.');
                    $fee=(int)$preview['fee_cents'];
                }
                if(array_key_exists('adjusted_fee_cents',$body)){
                    if(!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Administrator access is required to adjust a cancellation fee.');
                    if(filter_var($body['adjusted_fee_cents'],FILTER_VALIDATE_INT)===false||(int)$body['adjusted_fee_cents']<0||(int)$body['adjusted_fee_cents']>(int)$preview['fee_cents'])throw new ApiException(422,'validation_error','The adjusted fee must be between zero and the calculated policy fee.',['adjusted_fee_cents'=>'Invalid fee']);
                    if($reason==='')throw new ApiException(422,'validation_error','A reason is required to adjust or waive a cancellation fee.',['reason'=>'Required']);
                    $fee=(int)$body['adjusted_fee_cents'];
                    if($fee!==(int)$preview['fee_cents']){$adjust=$pdo->prepare('INSERT INTO cancellation_adjustments(appointment_id,original_fee_cents,adjusted_fee_cents,reason,authorized_by) VALUES(:appointment,:original,:adjusted,:reason,:actor)');$adjust->execute(['appointment'=>$id,'original'=>(int)$preview['fee_cents'],'adjusted'=>$fee,'reason'=>$reason,'actor'=>$actor->userId]);}
                }
                $canceledStatus=$actor->userType==='client'?'canceled_by_client':'canceled_by_clinic';
                $statement=$pdo->prepare('UPDATE appointments SET status=:status,cancellation_fee_cents=:fee,version=version+1 WHERE id=:id AND version=:version');$statement->execute(['status'=>$canceledStatus,'fee'=>$fee,'id'=>$id,'version'=>$version]);
                $this->history($id,$from,$canceledStatus,$actor->userId,$reason,$fee);$event='booking_cancellation';$audit='appointment.cancel';
            }else{
                $location=$pdo->prepare('SELECT timezone FROM locations WHERE id=:id AND clinic_id=:clinic AND is_bookable=1');$location->execute(['id'=>$appointment['location_id'],'clinic'=>$actor->clinicId]);$timezone=$location->fetchColumn();if(!$timezone)throw new ApiException(422,'invalid_location','The appointment location is unavailable.');
                $date=$requested->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');$availability=(new AvailabilityService($this->database))->search(['delivery_mode'=>$appointment['delivery_mode'],'service_id'=>$appointment['service_id'],'practitioner_id'=>$appointment['practitioner_id'],'location_id'=>$appointment['location_id'],'date_from'=>$date,'date_to'=>$date],$id);
                $slot=null;foreach($availability['availability'] as $candidate)if((int)$candidate['duration_option_id']===(int)$appointment['duration_option_id']&&(new DateTimeImmutable($candidate['starts_at']))->getTimestamp()===$requested->getTimestamp()){$slot=$candidate;break;}
                if(!$slot)throw new ApiException(409,'schedule_conflict','The requested time is no longer bookable. Refresh availability and choose another time.');
                $roomId=array_key_exists('room_id',$body)&&$body['room_id']!==null?(int)$body['room_id']:null;
                if($appointment['room_id']!==null){if(!$roomId)throw new ApiException(422,'validation_error','room_id is required for this appointment.',['room_id'=>'Required']);if(!in_array($roomId,$slot['available_room_ids'],true))throw new ApiException(409,'room_conflict','The selected room is unavailable or unsuitable.');}elseif($roomId!==null)throw new ApiException(422,'validation_error','This appointment does not use a room.');
                $newEnd=(new DateTimeImmutable($slot['ends_at']))->setTimezone(new DateTimeZone('UTC'));$oldStart=new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC'));$oldEnd=new DateTimeImmutable($appointment['ends_at'],new DateTimeZone('UTC'));$oldBufferStart=new DateTimeImmutable($appointment['buffer_starts_at'],new DateTimeZone('UTC'));$oldBufferEnd=new DateTimeImmutable($appointment['buffer_ends_at'],new DateTimeZone('UTC'));$before=max(0,$oldStart->getTimestamp()-$oldBufferStart->getTimestamp());$after=max(0,$oldBufferEnd->getTimestamp()-$oldEnd->getTimestamp());
                $statement=$pdo->prepare("UPDATE appointments SET room_id=:room,starts_at=:starts,ends_at=:ends,buffer_starts_at=:buffer_start,buffer_ends_at=:buffer_end,status='rescheduled',version=version+1 WHERE id=:id AND version=:version");$statement->execute(['room'=>$roomId,'starts'=>$requested->format('Y-m-d H:i:s'),'ends'=>$newEnd->format('Y-m-d H:i:s'),'buffer_start'=>$requested->modify('-'.$before.' seconds')->format('Y-m-d H:i:s'),'buffer_end'=>$newEnd->modify('+'.$after.' seconds')->format('Y-m-d H:i:s'),'id'=>$id,'version'=>$version]);
                $this->history($id,$from,'rescheduled',$actor->userId,$reason);$event='booking_change';$audit='appointment.reschedule';
            }
            if($action==='cancel')AppointmentReminderQueue::cancel($pdo,$actor->clinicId,$id);
            else AppointmentReminderQueue::replace($pdo,$actor->clinicId,$id,(int)$appointment['client_id'],$requested->format('Y-m-d H:i:s'),$version+1);
            $notify=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment,u.id,u.email,:event,'email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_id) FROM users u WHERE u.id=:client");$notify->execute(['clinic'=>$actor->clinicId,'appointment'=>$id,'event'=>$event,'payload_id'=>$id,'client'=>$appointment['client_id']]);
            StaffNotificationQueue::enqueue($pdo,$actor->clinicId,$id,(int)$appointment['practitioner_id'],$event);
            $this->audit->write($actor->clinicId,$actor,$correlationId,$audit,'appointment',$id,'success',$reason===''?[]:['reason'=>$reason]);$result=$this->appointment($actor,$id,false);$pdo->commit();
            ImmediateNotificationDispatch::schedule($this->database, $id);
            return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function authorizeList(AuthContext $actor): void
    {
        if($actor->userType==='client')return;
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot view appointments.');
    }

    public function list(AuthContext $actor,array $query=[]): array
    {
        self::authorizeList($actor);
        $sql="SELECT a.delivery_mode,a.destination_snapshot,a.travel_buffer_minutes,a.base_price_cents,a.mobile_fee_cents,a.cancellation_fee_cents,a.currency,a.id,a.client_id,a.practitioner_id,a.service_id,a.duration_option_id,a.room_id,a.starts_at,a.ends_at,a.status,a.version,s.name service_name,u.display_name client_name,pu.display_name practitioner_name,l.name location_name,l.timezone,r.name room_name FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id JOIN practitioners p ON p.id=a.practitioner_id JOIN users pu ON pu.id=p.user_id JOIN locations l ON l.id=a.location_id LEFT JOIN rooms r ON r.id=a.room_id WHERE a.clinic_id=:clinic";$params=['clinic'=>$actor->clinicId];
        $practitionerScope=(($query['scope']??'')==='practitioner'||($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')))&&!$actor->hasPermission('schedule_for_other_practitioners');
        if(($query['scope']??'')==='practitioner'&&!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner scope requires the practitioner role.');
        if($actor->userType==='client'){$sql.=' AND a.client_id=:user';$params['user']=$actor->userId;}elseif($practitionerScope){$sql.=' AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user)';$params['user']=$actor->userId;}
        $view=$query['view']??'all';
        if(!in_array($view,['all','upcoming','past','needs_outcome'],true))throw new ApiException(422,'validation_error','Invalid appointment view.');
        if($view==='upcoming')$sql.=' AND a.ends_at>=UTC_TIMESTAMP()';
        if($view==='past')$sql.=' AND a.ends_at<UTC_TIMESTAMP()';
        if($view==='needs_outcome')$sql.=" AND a.ends_at<UTC_TIMESTAMP() AND a.status IN ('confirmed','rescheduled')";
        if(($query['show_canceled']??'1')==='0')$sql.=" AND a.status NOT IN ('canceled_by_client','canceled_by_clinic')";
        $page=max(1,min(100000,(int)($query['page']??1)));$offset=($page-1)*50;
        $sql.=$view==='upcoming'?' ORDER BY a.starts_at ASC,a.id ASC':' ORDER BY a.starts_at DESC,a.id DESC';
        $sql.=" LIMIT 50 OFFSET {$offset}";$statement=$this->database->connection()->prepare($sql);$statement->execute($params);$rows=$statement->fetchAll();
        if(array_filter($rows,fn($row)=>$row['delivery_mode']==='mobile'))$this->audit->write($actor->clinicId,$actor,bin2hex(random_bytes(16)),'appointment.destination.view','appointment',null,'success',['page'=>$page]);
        return $rows;
    }

    public static function authorizeReassign(AuthContext $actor): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Clinic administrator access is required to change an appointment practitioner.');
    }

    /** Available same-time, same-price practitioners for an existing in-clinic booking. */
    public function reassignmentOptions(AuthContext $actor,int $id): array
    {
        self::authorizeReassign($actor);
        $appointment=$this->appointment($actor,$id,false);
        $this->assertReassignable($appointment);
        return $this->reassignmentCandidates($actor,$appointment);
    }

    private function reassign(AuthContext $actor,int $id,array $body,string $correlationId): array
    {
        self::authorizeReassign($actor);
        $version=filter_var($body['version']??null,FILTER_VALIDATE_INT);
        $newPractitionerId=filter_var($body['practitioner_id']??null,FILTER_VALIDATE_INT);
        $reason=trim((string)($body['reason']??''));
        if($version===false||$version<1||$newPractitionerId===false||$newPractitionerId<1||$reason===''||strlen($reason)>1000)throw new ApiException(422,'validation_error','A practitioner, current version, and reason are required.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $lock=$pdo->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active' FOR UPDATE");$lock->execute(['clinic'=>$actor->clinicId]);
            if(!$lock->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $appointment=$this->appointment($actor,$id,true);
            if((int)$appointment['version']!==$version){
                if((int)$appointment['version']===$version+1&&(int)$appointment['practitioner_id']===$newPractitionerId){
                    $replay=$pdo->prepare('SELECT 1 FROM appointment_reassignments WHERE clinic_id=:clinic AND appointment_id=:appointment AND new_practitioner_id=:practitioner AND actor_user_id=:actor AND reason=:reason ORDER BY id DESC LIMIT 1');
                    $replay->execute(['clinic'=>$actor->clinicId,'appointment'=>$id,'practitioner'=>$newPractitionerId,'actor'=>$actor->userId,'reason'=>$reason]);
                    if($replay->fetchColumn()){$pdo->commit();return $appointment;}
                }
                throw new ApiException(409,'appointment_changed','This appointment changed. Refresh it before making another change.');
            }
            $this->assertReassignable($appointment);
            if((int)$appointment['practitioner_id']===$newPractitionerId)throw new ApiException(422,'practitioner_unchanged','Choose a different practitioner.');
            $chosen=null;
            foreach($this->reassignmentCandidates($actor,$appointment) as $candidate)if((int)$candidate['practitioner_id']===$newPractitionerId){$chosen=$candidate;break;}
            if($chosen===null)throw new ApiException(409,'reassignment_unavailable','That practitioner cannot take this appointment at the current time, room, and price. Refresh the choices.');
            $statement=$pdo->prepare('UPDATE appointments SET practitioner_id=:practitioner,version=version+1 WHERE id=:id AND clinic_id=:clinic AND version=:version');
            $statement->execute(['practitioner'=>$newPractitionerId,'id'=>$id,'clinic'=>$actor->clinicId,'version'=>$version]);
            if($statement->rowCount()!==1)throw new ApiException(409,'appointment_changed','This appointment changed. Refresh it before making another change.');
            $record=$pdo->prepare('INSERT INTO appointment_reassignments(clinic_id,appointment_id,old_practitioner_id,new_practitioner_id,actor_user_id,reason) VALUES(:clinic,:appointment,:old,:new,:actor,:reason)');
            $record->execute(['clinic'=>$actor->clinicId,'appointment'=>$id,'old'=>(int)$appointment['practitioner_id'],'new'=>$newPractitionerId,'actor'=>$actor->userId,'reason'=>$reason]);
            $reassignmentId=(int)$pdo->lastInsertId();
            AppointmentReminderQueue::replace($pdo,$actor->clinicId,$id,(int)$appointment['client_id'],(string)$appointment['starts_at'],$version+1);
            $notify=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment,u.id,u.email,'booking_change','email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_id,'change_type','practitioner_reassignment','practitioner_name',:name) FROM users u WHERE u.id=:client");
            $notify->execute(['clinic'=>$actor->clinicId,'appointment'=>$id,'payload_id'=>$id,'name'=>$chosen['practitioner_name'],'client'=>$appointment['client_id']]);
            StaffNotificationQueue::enqueue($pdo,$actor->clinicId,$id,(int)$appointment['practitioner_id'],'booking_reassigned_away',$reassignmentId);
            StaffNotificationQueue::enqueue($pdo,$actor->clinicId,$id,$newPractitionerId,'booking_confirmation');
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.reassign','appointment',$id,'success',['old_practitioner_id'=>(int)$appointment['practitioner_id'],'new_practitioner_id'=>$newPractitionerId,'reason'=>$reason]);
            $result=$this->appointment($actor,$id,false);$pdo->commit();
            ImmediateNotificationDispatch::schedule($this->database,$id);
            return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function assertReassignable(array $appointment): void
    {
        if($appointment['delivery_mode']!=='clinic')throw new ApiException(422,'reassignment_unsupported','On-Site appointments need a separate travel and coverage review before reassignment.');
        if(!in_array($appointment['status'],['requested','confirmed','rescheduled'],true)||strtotime((string)$appointment['starts_at'].' UTC')<=time())throw new ApiException(409,'appointment_not_editable','Only future active appointments can be reassigned.');
        if($appointment['base_price_cents']===null)throw new ApiException(422,'reassignment_unsupported','The appointment needs a saved price before practitioner reassignment.');
    }

    private function reassignmentCandidates(AuthContext $actor,array $appointment): array
    {
        $pdo=$this->database->connection();
        $query=$pdo->prepare("SELECT p.id practitioner_id,u.display_name practitioner_name,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,s.requires_room,s.buffer_before_minutes,s.buffer_after_minutes,l.timezone FROM services s JOIN service_duration_options d ON d.service_id=s.id AND d.id=:duration AND d.active=1 JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1 AND ps.offers_clinic=1 JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1 JOIN users u ON u.id=p.user_id AND u.clinic_id=s.clinic_id AND u.status='active' JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.location_id=:location_pl AND pl.active=1 JOIN service_locations sl ON sl.service_id=s.id AND sl.location_id=:location_sl AND sl.active=1 JOIN locations l ON l.id=:location_l AND l.clinic_id=s.clinic_id AND l.is_bookable=1 WHERE s.id=:service AND s.clinic_id=:clinic AND s.active=1 AND p.id<>:old ORDER BY u.display_name,p.id LIMIT 100");
        $query->execute(['duration'=>$appointment['duration_option_id'],'location_pl'=>$appointment['location_id'],'location_sl'=>$appointment['location_id'],'location_l'=>$appointment['location_id'],'service'=>$appointment['service_id'],'clinic'=>$actor->clinicId,'old'=>$appointment['practitioner_id']]);
        $result=[];$start=strtotime((string)$appointment['starts_at'].' UTC');$end=strtotime((string)$appointment['ends_at'].' UTC');
        foreach($query->fetchAll() as $row){
            if((int)$row['base_price_cents']!==(int)$appointment['base_price_cents'])continue;
            if((bool)$row['requires_room']!==($appointment['room_id']!==null))continue;
            if($start-strtotime((string)$appointment['buffer_starts_at'].' UTC')!==(int)$row['buffer_before_minutes']*60)continue;
            if(strtotime((string)$appointment['buffer_ends_at'].' UTC')-$end!==(int)$row['buffer_after_minutes']*60)continue;
            $date=(new DateTimeImmutable((string)$appointment['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$row['timezone']))->format('Y-m-d');
            $availability=(new AvailabilityService($this->database))->search(['delivery_mode'=>'clinic','service_id'=>$appointment['service_id'],'practitioner_id'=>$row['practitioner_id'],'location_id'=>$appointment['location_id'],'date_from'=>$date,'date_to'=>$date],(int)$appointment['id'],false,true);
            foreach($availability['availability'] as $slot){
                if((int)$slot['duration_option_id']!==(int)$appointment['duration_option_id']||strtotime((string)$slot['starts_at'])!==$start||strtotime((string)$slot['ends_at'])!==$end)continue;
                if($appointment['room_id']!==null&&!in_array((int)$appointment['room_id'],$slot['available_room_ids'],true))continue;
                $result[]=['practitioner_id'=>(int)$row['practitioner_id'],'practitioner_name'=>(string)$row['practitioner_name']];
                break;
            }
        }
        return $result;
    }

    /** One clinic-scoped appointment for a staff deep link, including paginated-out bookings. */
    public function getForStaff(AuthContext $actor,int $id,array $query,string $correlationId): array
    {
        if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Staff access is required.');
        self::authorizeList($actor);
        $scope=$query['scope']??'';
        if($scope!==''&&$scope!=='practitioner')throw new ApiException(422,'validation_error','Invalid appointment scope.');
        if($scope==='practitioner'&&!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner scope requires the practitioner role.');
        $sql="SELECT a.delivery_mode,a.destination_snapshot,a.travel_buffer_minutes,a.base_price_cents,a.mobile_fee_cents,a.cancellation_fee_cents,a.currency,a.id,a.client_id,a.practitioner_id,a.service_id,a.duration_option_id,a.room_id,a.starts_at,a.ends_at,a.status,a.version,s.name service_name,u.display_name client_name,u.email client_email,cp.phone client_phone,cp.preferred_contact client_preferred_contact,pu.display_name practitioner_name,l.name location_name,l.timezone,r.name room_name FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id LEFT JOIN client_profiles cp ON cp.user_id=u.id JOIN practitioners p ON p.id=a.practitioner_id JOIN users pu ON pu.id=p.user_id JOIN locations l ON l.id=a.location_id LEFT JOIN rooms r ON r.id=a.room_id WHERE a.id=:id AND a.clinic_id=:clinic";
        $params=['id'=>$id,'clinic'=>$actor->clinicId];
        $ownOnly=(($scope==='practitioner')||($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')))&&!$actor->hasPermission('schedule_for_other_practitioners');
        if($ownOnly){$sql.=' AND p.user_id=:user';$params['user']=$actor->userId;}
        $statement=$this->database->connection()->prepare($sql);$statement->execute($params);$row=$statement->fetch();
        if(!$row)throw new ApiException(404,'appointment_not_found','Appointment not found.');
        $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.client_contact.view','appointment',$id);
        if($row['delivery_mode']==='mobile')$this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.destination.view','appointment',$id);
        return $row;
    }

    /** Appointments and time off for the signed-in practitioner's visible calendar range only. */
    public function practitionerCalendar(AuthContext $actor,array $query): array
    {
        self::authorizePractitionerCalendar($actor);
        [$start,$end]=self::calendarRange($query);
        $sql="SELECT 'appointment' kind,a.id,a.starts_at,a.ends_at,a.status,a.delivery_mode,s.name service_name,u.display_name client_name,l.name location_name,l.timezone FROM appointments a JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1 JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id WHERE a.clinic_id=:clinic AND a.starts_at<:end AND a.ends_at>:start ORDER BY a.starts_at,a.id LIMIT 1001";
        $statement=$this->database->connection()->prepare($sql);
        $statement->execute(['user'=>$actor->userId,'clinic'=>$actor->clinicId,'start'=>$start,'end'=>$end]);
        $rows=$statement->fetchAll();
        if(count($rows)>1000)throw new ApiException(422,'calendar_range_too_busy','Too many appointments in this calendar range.');
        $timeOff=$this->database->connection()->prepare("SELECT 'time_off' kind,t.id,t.starts_at,t.ends_at,t.reason_type,l.name location_name FROM time_off t JOIN practitioners p ON p.id=t.practitioner_id AND p.user_id=:user AND p.active=1 JOIN users u ON u.id=p.user_id AND u.clinic_id=:clinic LEFT JOIN locations l ON l.id=t.location_id AND l.clinic_id=u.clinic_id WHERE t.starts_at<:end AND t.ends_at>:start ORDER BY t.starts_at,t.id LIMIT 1001");
        $timeOff->execute(['user'=>$actor->userId,'clinic'=>$actor->clinicId,'start'=>$start,'end'=>$end]);
        $rows=array_merge($rows,$timeOff->fetchAll());
        if(count($rows)>1000)throw new ApiException(422,'calendar_range_too_busy','Too many calendar entries in this range.');
        usort($rows,static fn(array $a,array $b): int=>[$a['starts_at'],$a['kind'],$a['id']]<=>[$b['starts_at'],$b['kind'],$b['id']]);
        return $rows;
    }

    /** Downloadable calendar event, limited to the linked client's own appointment. */
    public function customerCalendar(AuthContext $actor,int $id,string $portalUrl): array
    {
        if($actor->userType!=='client')throw new ApiException(403,'forbidden','Client access is required.');
        $statement=$this->database->connection()->prepare('SELECT id,clinic_id,version,starts_at,ends_at,status FROM appointments WHERE id=:id AND clinic_id=:clinic AND client_id=:client');
        $statement->execute(['id'=>$id,'clinic'=>$actor->clinicId,'client'=>$actor->userId]);
        $appointment=$statement->fetch();
        if(!$appointment)throw new ApiException(404,'appointment_not_found','Appointment not found.');
        if(in_array($appointment['status'],['canceled_by_client','canceled_by_clinic'],true))throw new ApiException(409,'appointment_canceled','Canceled appointments cannot be added to a calendar.');
        return ['filename'=>'appointment-'.$id.'.ics','content'=>AppointmentCalendar::compose($appointment,$portalUrl)];
    }

    public static function authorizePractitionerCalendar(AuthContext $actor): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner access is required.');
    }

    /** @return array{string,string} UTC SQL boundaries, end exclusive. */
    public static function calendarRange(array $query): array
    {
        $values=[];
        foreach(['start','end'] as $key){
            $raw=$query[$key]??null;
            if(!is_string($raw)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',$raw))throw new ApiException(422,'validation_error','Calendar range must use UTC ISO timestamps.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));
            if($date===false||$date->format('Y-m-d\TH:i:s\Z')!==$raw)throw new ApiException(422,'validation_error','Invalid calendar date.');
            $values[]=$date;
        }
        if($values[1]<=$values[0]||$values[1]->getTimestamp()-$values[0]->getTimestamp()>62*86400)throw new ApiException(422,'validation_error','Calendar range must be between one second and 62 days.');
        return [$values[0]->format('Y-m-d H:i:s'),$values[1]->format('Y-m-d H:i:s')];
    }

    private function appointment(AuthContext $actor,int $id,bool $lock): array
    {
        $statement=$this->database->connection()->prepare('SELECT id,clinic_id,location_id,client_id,practitioner_id,service_id,duration_option_id,room_id,delivery_mode,starts_at,ends_at,buffer_starts_at,buffer_ends_at,base_price_cents,mobile_fee_cents,currency,cancellation_window_minutes,cancellation_fee_type,cancellation_fee_value,cancellation_fee_cents,status,version,created_at FROM appointments WHERE id=:id AND clinic_id=:clinic'.($lock?' FOR UPDATE':''));$statement->execute(['id'=>$id,'clinic'=>$actor->clinicId]);$row=$statement->fetch();if(!$row)throw new ApiException(404,'appointment_not_found','Appointment not found.');return $row;
    }

    public static function authorizeChange(AuthContext $actor,bool $ownsPractitioner,string $bookingMode): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','practitioner'))throw new ApiException(403,'forbidden','Your role cannot change appointments.');
        if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin','reception')&&!$actor->hasPermission('schedule_for_other_practitioners')&&(!$ownsPractitioner||$bookingMode!=='practitioner_managed'))throw new ApiException(403,'forbidden','Practitioners can only change their own practitioner-managed appointments.');
    }

    public static function authorizeCustomerChange(AuthContext $actor,int $clientId): void
    {
        if($actor->userType!=='client'||$actor->userId!==$clientId)throw new ApiException(404,'appointment_not_found','Appointment not found.');
    }

    private function getById(AuthContext $actor,int $id): array{return $this->appointment($actor,$id,false);}

    private function assertManage(AuthContext $actor,array $appointment): void
    {
        if($actor->userType==='client'){self::authorizeCustomerChange($actor,(int)$appointment['client_id']);return;}
        $statement=$this->database->connection()->prepare('SELECT booking_mode FROM practitioners WHERE id=:practitioner AND user_id=:user AND active=1');$statement->execute(['practitioner'=>$appointment['practitioner_id'],'user'=>$actor->userId]);$mode=$statement->fetchColumn();
        self::authorizeChange($actor,$mode!==false,(string)($mode?:''));
    }

    private function history(int $appointmentId,string $from,string $to,int $actorUserId,string $reason,int $fee=0): void
    {
        $statement=$this->database->connection()->prepare('INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id,reason,fee_triggered_cents) VALUES(:appointment,:from_status,:to_status,:actor,:reason,:fee)');$statement->execute(['appointment'=>$appointmentId,'from_status'=>$from,'to_status'=>$to,'actor'=>$actorUserId,'reason'=>$reason===''?null:$reason,'fee'=>$fee]);
    }

    private static function validDate(string $value): bool
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d')===$value;
    }
}
