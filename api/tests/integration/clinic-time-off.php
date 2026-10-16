<?php
declare(strict_types=1);
// Reuse a synthetic localhost-only two-clinic practitioner and membership setup.
require __DIR__.'/global-practitioners.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\{AddressCoverageService,AuditLogger,AvailabilityService,BookingService};

$checks=0;
$assert=function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=function(callable $fn,string $code)use($assert):void{try{$fn();}catch(ApiException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$_ENV['MAIL_ENABLED']='false';
$pdo->prepare("UPDATE practitioners SET booking_mode='practitioner_managed' WHERE id IN (?,?)")->execute([$local,$targetLocal]);
$zone=new DateTimeZone('America/Toronto');$utc=new DateTimeZone('UTC');
$when=(new DateTimeImmutable('now',$zone))->modify('+14 days')->setTime(10,0);
$date=$when->format('Y-m-d');$weekday=(int)$when->format('N');
$from=$when->setTime(9,0)->setTimezone($utc)->format('Y-m-d H:i:s');
$until=$when->setTime(18,0)->setTimezone($utc)->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO services(clinic_id,slug,name,price_cents,requires_room,lead_time_minutes,booking_horizon_days) VALUES(?,'clinic-leave-test','Clinic leave test',10000,0,0,365)")->execute([$source]);$sourceService=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE services SET requires_room=0,lead_time_minutes=0,booking_horizon_days=365 WHERE id=?')->execute([$service]);
$durationIds=[];$clients=[];
foreach([[$source,$local,$sourceLocation,$sourceService],[$target,$targetLocal,$targetLocation,$service]] as [$clinicId,$personId,$locationId,$serviceId]){
 $pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(?,'leave-test@example.test','Synthetic client','client','active')")->execute([$clinicId]);$clients[$clinicId]=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO service_duration_options(service_id,duration_minutes,price_cents) VALUES(?,60,10000)')->execute([$serviceId]);$durationIds[$clinicId]=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO practitioner_services(practitioner_id,service_id,offers_clinic) VALUES(?,?,1)')->execute([$personId,$serviceId]);
 $pdo->prepare('INSERT INTO service_locations(service_id,location_id) VALUES(?,?)')->execute([$serviceId,$locationId]);
 $pdo->prepare("INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from) VALUES(?,?,?,'09:00:00','18:00:00','2020-01-01')")->execute([$personId,$locationId,$weekday]);
}
$sourceQuery=['service_id'=>$sourceService,'practitioner_id'=>$local,'location_id'=>$sourceLocation,'date_from'=>$date,'date_to'=>$date,'delivery_mode'=>'clinic'];
$targetQuery=array_replace($sourceQuery,['service_id'=>$service,'practitioner_id'=>$targetLocal,'location_id'=>$targetLocation]);
$engine=new AvailabilityService($database);
$slots=fn(array $q):array=>$engine->search($q)['availability'];
$hasStart=fn(array $rows):bool=>(bool)array_filter($rows,fn(array $row):bool=>(new DateTimeImmutable($row['starts_at']))->getTimestamp()===$when->getTimestamp());
$assert($hasStart($slots($sourceQuery))&&$hasStart($slots($targetQuery)),'Both clinics initially have the chosen time');
$pdo->prepare("INSERT INTO time_off(practitioner_id,location_id,starts_at,ends_at,reason_type,created_by) VALUES(?,?,?,?,'vacation',?)")->execute([$local,$sourceLocation,$from,$until,$sourceAuth->userId]);$leave=(int)$pdo->lastInsertId();
$assert($slots($sourceQuery)===[],'Clinic A leave blocks clinic A');
$assert($hasStart($slots($targetQuery)),'Clinic A leave must leave clinic B bookable');
// Current/locked reads used during confirmations must have the same clinic scope.
$pdo->beginTransaction();
$locked=(new ReflectionMethod(AvailabilityService::class,'blocked'))->invoke(new AvailabilityService($database,lockBusyTimes:true),$targetLocal,$targetLocation,$when->setTimezone($utc),$when->modify('+1 hour')->setTimezone($utc));
$pdo->rollBack();$assert(!$locked,'Foreign-clinic leave must not block transactional availability');
$booking=new BookingService($database,new AuditLogger($database),new AddressCoverageService($database,$config));
$payload=['client_id'=>$clients[$target],'location_id'=>$targetLocation,'practitioner_id'=>$targetLocal,'service_id'=>$service,'duration_option_id'=>$durationIds[$target],'delivery_mode'=>'clinic','starts_at'=>$when->format(DATE_ATOM),'quoted_base_price_cents'=>10000,'quoted_mobile_fee_cents'=>0,'idempotency_key'=>'clinic-b-during-clinic-a-leave'];
$booked=$booking->create($targetAuth,$payload,'test');$assert((int)$booked['id']>0,'Clinic B can confirm during clinic A leave');
$sourcePayload=array_replace($payload,['client_id'=>$clients[$source],'location_id'=>$sourceLocation,'practitioner_id'=>$local,'service_id'=>$sourceService,'duration_option_id'=>$durationIds[$source],'idempotency_key'=>'clinic-a-shared-appointment-conflict']);
$pdo->prepare('DELETE FROM time_off WHERE id=?')->execute([$leave]);
$assert(!$hasStart($slots($sourceQuery)),'An actual clinic B appointment still blocks clinic A');
$deny(fn()=>$booking->create($sourceAuth,$sourcePayload,'test'),'schedule_conflict');
$pdo->prepare("UPDATE appointments SET status='canceled_by_clinic' WHERE id=?")->execute([$booked['id']]);
$assert($hasStart($slots($sourceQuery)),'Canceling the appointment releases the shared time');
// Reverse direction: leave belongs to B, and A can still accept a session.
$pdo->prepare("INSERT INTO time_off(practitioner_id,location_id,starts_at,ends_at,reason_type,created_by) VALUES(?,?,?,?,'vacation',?)")->execute([$targetLocal,$targetLocation,$from,$until,$targetAuth->userId]);$leave=(int)$pdo->lastInsertId();
$assert($slots($targetQuery)===[]&&$hasStart($slots($sourceQuery)),'Clinic B leave blocks only clinic B');
$sourcePayload['idempotency_key']='clinic-a-during-clinic-b-leave';
$booked=$booking->create($sourceAuth,$sourcePayload,'test');$assert((int)$booked['id']>0,'Clinic A can confirm during clinic B leave');
$pdo->prepare('DELETE FROM time_off WHERE id=?')->execute([$leave]);
$assert(!$hasStart($slots($targetQuery)),'An actual clinic A appointment still blocks clinic B');
$pdo->prepare("UPDATE appointments SET status='canceled_by_clinic' WHERE id=?")->execute([$booked['id']]);
$pdo->prepare("INSERT INTO imported_calendar_entries(practitioner_id,source,starts_at,ends_at,blocks_booking) VALUES(?,'synthetic',?,?,1)")->execute([$targetLocal,$from,$until]);
$assert($slots($sourceQuery)===[],'Explicit blocking calendar entries remain shared busy time');
echo "$checks clinic-specific time-off and shared appointment checks passed.\n";
