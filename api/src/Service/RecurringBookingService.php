<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Runs the existing booking writers in one clinic-locked transaction. */
final class RecurringBookingService
{
    public function __construct(private readonly Database $database,private readonly BookingService $bookings) {}

    public function create(AuthContext $actor,array $body,string $correlation,bool $apply): array
    {
        BookingService::authorizeOptions($actor);
        if($actor->userType==='client')$body=BookingService::customerPayload($actor,$body);
        if(array_key_exists('series_id',$body)||!is_string($body['starts_at']??null))throw new ApiException(422,'validation_error','A new series requires a start timestamp and must not contain series_id.');
        if(!is_array($body['recurrence']??null))throw new ApiException(422,'invalid_recurrence','A recurrence pattern is required.');
        return $this->transaction($actor,$body,$apply,function()use($actor,$body,$correlation){
            $pdo=$this->database->connection();
            $client=$pdo->prepare("SELECT id FROM users WHERE id=:id AND clinic_id=:clinic AND user_type='client' AND status='active'");
            $client->execute(['id'=>(int)($body['client_id']??0),'clinic'=>$actor->clinicId]);
            if(!$client->fetchColumn())throw new ApiException(422,'invalid_client','Select an active client in this clinic.');
            $eligible=false;
            foreach($this->bookings->options($actor)['combinations'] as $option){
                $matches=true;
                foreach(['location_id','service_id','practitioner_id','duration_option_id'] as $field)$matches=$matches&&(int)$option[$field]===(int)($body[$field]??0);
                if($matches){$eligible=true;break;}
            }
            if(!$eligible)throw new ApiException(422,'invalid_booking','The selected practitioner, service, location, or duration is unavailable.');
            $query=$pdo->prepare('SELECT l.timezone,s.recurrence_allowed FROM services s JOIN locations l ON l.clinic_id=s.clinic_id WHERE s.id=:service AND l.id=:location AND s.clinic_id=:clinic AND s.active=1 AND l.is_bookable=1 FOR UPDATE');
            $query->execute(['service'=>(int)($body['service_id']??0),'location'=>(int)($body['location_id']??0),'clinic'=>$actor->clinicId]);$rule=$query->fetch();
            if(!$rule||!(bool)$rule['recurrence_allowed'])throw new ApiException(422,'recurrence_not_allowed','This service does not allow recurring appointments.');
            $dates=RecurrencePattern::dates($body['starts_at'],(string)$rule['timezone'],$body['recurrence']);
            $insert=$pdo->prepare('INSERT INTO recurring_series(clinic_id,client_id,practitioner_id,service_id,frequency,series_start,series_end,occurrence_limit,timezone) VALUES(:clinic,:client,:practitioner,:service,:frequency,:start,:end,:count,:timezone)');
            $insert->execute(['clinic'=>$actor->clinicId,'client'=>(int)($body['client_id']??0),'practitioner'=>(int)($body['practitioner_id']??0),'service'=>(int)$body['service_id'],'frequency'=>$body['recurrence']['frequency'],'start'=>substr($dates[0]['local_time'],0,10),'end'=>substr($dates[count($dates)-1]['local_time'],0,10),'count'=>count($dates),'timezone'=>$rule['timezone']]);
            $series=(int)$pdo->lastInsertId();$items=[];
            foreach($dates as $index=>$date){
                $base=['local_time'=>$date['local_time'],'starts_at'=>$date['starts_at']];
                if($date['error']!==null){$items[]=$base+['ok'=>false,'code'=>'invalid_local_time','message'=>$date['error']];continue;}
                $payload=$body;$payload['starts_at']=$date['starts_at'];$payload['idempotency_key']='series-'.hash('sha256',$actor->clinicId.':'.$body['idempotency_key'].':'.$index);
                $items[]=$base+$this->occurrence(function()use($actor,$payload,$correlation,$series){
                    $existing=$this->database->connection()->prepare('SELECT id FROM appointments WHERE clinic_id=:clinic AND idempotency_key=:key');
                    $existing->execute(['clinic'=>$actor->clinicId,'key'=>$payload['idempotency_key']]);
                    if($existing->fetchColumn())throw new ApiException(409,'idempotency_conflict','An occurrence request key is already in use.');
                    $row=$this->bookings->create($actor,$payload,$correlation,true);
                    $link=$this->database->connection()->prepare('UPDATE appointments SET recurring_series_id=:series WHERE id=:id AND clinic_id=:clinic AND recurring_series_id IS NULL');
                    $link->execute(['series'=>$series,'id'=>$row['id'],'clinic'=>$actor->clinicId]);
                    if($link->rowCount()!==1)throw new ApiException(409,'idempotency_conflict','An occurrence belongs to another series.');
                    return ['appointment_id'=>(int)$row['id'],'version'=>(int)$row['version'],'subtotal_cents'=>(int)$row['base_price_cents']+(int)$row['mobile_fee_cents']];
                });
            }
            return ['series_id'=>$series,'timezone'=>$rule['timezone'],'items'=>$items];
        });
    }

    /** Only future active occurrences; authorize every row, including individually reassigned ones. */
    public function view(AuthContext $actor,int $series): array
    {
        BookingService::authorizeOptions($actor);
        $query=$this->database->connection()->prepare("SELECT a.id FROM appointments a JOIN recurring_series s ON s.id=a.recurring_series_id AND s.clinic_id=a.clinic_id WHERE a.recurring_series_id=:series AND a.clinic_id=:clinic AND a.starts_at>UTC_TIMESTAMP() AND a.status IN('requested','confirmed','rescheduled') ORDER BY a.starts_at,a.id LIMIT 27");
        $query->execute(['series'=>$series,'clinic'=>$actor->clinicId]);$ids=$query->fetchAll(\PDO::FETCH_COLUMN);
        if(!$ids)throw new ApiException(404,'series_not_found','No future appointments are available in this series.');
        if(count($ids)>26)throw new ApiException(422,'series_too_large','This series must be reviewed by the clinic.');
        $items=[];
        foreach($ids as $id){$row=$this->bookings->manageable($actor,(int)$id);$items[]=['appointment_id'=>(int)$id,'duration_option_id'=>(int)$row['duration_option_id'],'version'=>(int)$row['version'],'starts_at'=>$row['starts_at'],'room_id'=>$row['room_id']===null?null:(int)$row['room_id'],'cancellation_fee_cents'=>(int)CancellationPolicy::preview($row)['fee_cents']];}
        $query=$this->database->connection()->prepare('SELECT COALESCE(s.timezone,(SELECT l.timezone FROM appointments a JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id WHERE a.recurring_series_id=s.id AND a.clinic_id=s.clinic_id ORDER BY a.starts_at,a.id LIMIT 1)) FROM recurring_series s WHERE s.id=:id AND s.clinic_id=:clinic');$query->execute(['id'=>$series,'clinic'=>$actor->clinicId]);
        return ['series_id'=>$series,'timezone'=>$query->fetchColumn()?:'UTC','items'=>$items];
    }

    public function change(AuthContext $actor,int $series,array $body,string $correlation,bool $apply): array
    {
        BookingService::authorizeOptions($actor);
        if(!in_array($body['action']??'', ['cancel','reschedule'],true)||!is_array($body['items']??null)||!array_is_list($body['items'])||count($body['items'])>26||(isset($body['reason'])&&!is_string($body['reason'])))throw new ApiException(422,'validation_error','Review all future appointments and choose cancel or reschedule.');
        $body['series_id']=$series;
        return $this->transaction($actor,$body,$apply,function()use($actor,$series,$body,$correlation){
            $view=$this->view($actor,$series);
            $submitted=$body['items'];
            if(count($submitted)!==count($view['items']))throw new ApiException(409,'series_changed','The future appointments changed. Reload the series.');
            $items=[];
            foreach($view['items'] as $index=>$row){
                $change=$submitted[$index];
                if(!is_array($change)||filter_var($change['appointment_id']??null,FILTER_VALIDATE_INT)!==$row['appointment_id']||filter_var($change['version']??null,FILTER_VALIDATE_INT)!==$row['version'])throw new ApiException(409,'series_changed','The future appointments changed. Reload the series.');
                $payload=['action'=>$body['action'],'version'=>$row['version'],'reason'=>$body['reason']??''];
                if($body['action']==='cancel'){
                    if(filter_var($change['expected_cancellation_fee_cents']??null,FILTER_VALIDATE_INT)!==$row['cancellation_fee_cents'])throw new ApiException(409,'cancellation_fee_changed','Cancellation fees changed. Reload the series.');
                    $payload['expected_cancellation_fee_cents']=$row['cancellation_fee_cents'];
                    // Staff series cancellation is clinic-initiated, with no client fee.
                }else{
                    $payload['starts_at']=$change['starts_at']??'';$payload['room_id']=$row['room_id'];
                }
                $items[]=['appointment_id'=>$row['appointment_id'],'starts_at'=>$body['action']==='reschedule'?($payload['starts_at']??''):$row['starts_at'],'fee_cents'=>$actor->userType==='client'&&$body['action']==='cancel'?$row['cancellation_fee_cents']:0]+$this->occurrence(function()use($actor,$row,$payload,$correlation){
                    $changed=$this->bookings->update($actor,$row['appointment_id'],$payload,$correlation,true);
                    return ['version'=>(int)$changed['version']];
                });
            }
            return ['series_id'=>$series,'timezone'=>$view['timezone'],'items'=>$items];
        });
    }

    private function occurrence(callable $writer): array
    {
        $pdo=$this->database->connection();$pdo->exec('SAVEPOINT recurring_occurrence');
        try{$result=$writer();$pdo->exec('RELEASE SAVEPOINT recurring_occurrence');return ['ok'=>true]+$result;}
        catch(ApiException $e){
            $pdo->exec('ROLLBACK TO SAVEPOINT recurring_occurrence');$pdo->exec('RELEASE SAVEPOINT recurring_occurrence');
            if(!in_array($e->errorCode,['schedule_conflict','room_conflict','outside_booking_window','appointment_time_unchanged'],true))throw $e;
            return ['ok'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage()];
        }
    }

    private function transaction(AuthContext $actor,array $body,bool $apply,callable $writer): array
    {
        $key=$body['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new ApiException(422,'validation_error','An idempotency key of 8–100 ASCII letters, numbers, or . _ : - characters is required.');
        $hashBody=$body;unset($hashBody['preview_token'],$hashBody['address_validation_token']);
        $hash=hash('sha256',json_encode(self::canonical($hashBody),JSON_THROW_ON_ERROR));
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $lock=$pdo->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active' FOR UPDATE");$lock->execute(['clinic'=>$actor->clinicId]);if(!$lock->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $replay=$pdo->prepare('SELECT actor_user_id,request_hash,result_json FROM recurring_booking_requests WHERE clinic_id=:clinic AND idempotency_key=:key');$replay->execute(['clinic'=>$actor->clinicId,'key'=>$key]);
            if($stored=$replay->fetch()){
                if((int)$stored['actor_user_id']!==$actor->userId||$stored['request_hash']!==$hash)throw new ApiException(409,'idempotency_conflict','This key was already used for a different series request.');
                $result=json_decode($stored['result_json'],true,512,JSON_THROW_ON_ERROR);
                // Recheck current access before returning even an immutable replay result.
                foreach($result['items'] as $item)$this->bookings->manageable($actor,(int)$item['appointment_id']);
                $pdo->rollBack();return $result;
            }
            $result=$writer();$ready=!array_filter($result['items'],fn($item)=>!$item['ok']);
            // IDs allocated during a dry run are intentionally excluded from its review token.
            $review=array_map(static function($item){unset($item['appointment_id']);return $item;},$result['items']);
            $token=hash('sha256',$hash.json_encode($review,JSON_THROW_ON_ERROR));
            $result+=['ready'=>$ready,'applied'=>false,'preview_token'=>$token];
            if(!$apply||!$ready){$pdo->rollBack();foreach($result['items'] as &$item)if(!isset($body['series_id']))unset($item['appointment_id']);unset($item);if(!isset($body['series_id']))$result['series_id']=null;return $result;}
            if(!is_string($body['preview_token']??null)||!hash_equals($token,$body['preview_token']))throw new ApiException(409,'series_preview_changed','The dates, prices, or cancellation fees changed. Review the series again.');
            $result['applied']=true;
            $save=$pdo->prepare('INSERT INTO recurring_booking_requests(clinic_id,idempotency_key,actor_user_id,request_hash,result_json) VALUES(:clinic,:key,:actor,:hash,:result)');$save->execute(['clinic'=>$actor->clinicId,'key'=>$key,'actor'=>$actor->userId,'hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            foreach($result['items'] as $item)ImmediateNotificationDispatch::schedule($this->database,(int)$item['appointment_id']);
            return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function canonical(array $value): array
    {
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)if(is_array($item))$item=self::canonical($item);
        return $value;
    }
}
