<?php
declare(strict_types=1);
require __DIR__.'/access-audit.php';
use Wellness\Service\{ReminderScheduleService,ClientConsentService,ConsentLock,NotificationWorker,GraphMailClient};
use Wellness\Http\ApiException;
$checks=0;$service=new ReminderScheduleService($database,$audit);$policy=new ClientConsentService($database,$audit);
$pdo->exec("DELETE FROM notification_reviews;DELETE FROM notification_events;DELETE FROM reminder_schedules;
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','reminder-admin');
    INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';
    INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO locations(id,clinic_id,name,timezone) VALUES(2,2,'Foreign location','America/Toronto');
    INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(2,2,'foreign','Foreign service',100);");
$set=function(int $client,int $target,bool $restricted,string $scope='staff_access')use($pdo,$policy,$actor):void{$q=$pdo->prepare('SELECT version FROM client_consent_directives WHERE client_id=? AND scope=? AND target_staff_id=?');$q->execute([$client,$scope,$target]);$policy->save($actor($client,[],[],'client'),$client,['scope'=>$scope,'target_staff_id'=>$target,'restricted'=>$restricted,'version'=>(int)$q->fetchColumn()],'reminder-test-directive');};
$rule=function(int $minutes=60,int $active=1,int $clinic=1,string $channel='email')use($pdo):int{$pdo->prepare("INSERT INTO reminder_schedules(clinic_id,event_code,minutes_before,channel,active) VALUES(?,'appointment_reminder',?,?,?)")->execute([$clinic,$minutes,$channel,$active]);return (int)$pdo->lastInsertId();};
$visit=function(int $client=104,int $clinic=1,?int $location=null,?int $svc=null)use($pdo):int{$start=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d H:i:s');$end=(new DateTimeImmutable($start))->modify('+1 hour')->format('Y-m-d H:i:s');$pdo->prepare("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(?,?,?,?,?,?,?,?,?,'confirmed','admin',1)")->execute([$clinic,$location??$clinic,$client,$clinic===1?2:5,$svc??$clinic,$start,$end,$start,$end]);return (int)$pdo->lastInsertId();};
$notice=function(?int $appointment,mixed $minutes=60,string $status='queued',int $clinic=1,?int $recipient=null,string $channel='email',string $code='appointment_reminder')use($pdo):int{
    $q=$pdo->prepare('SELECT client_id FROM appointments WHERE id=?');$q->execute([$appointment]);$recipient??=(int)($q->fetchColumn()?:104);
    $pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,next_attempt_at,payload,lease_token,leased_until) VALUES(?,?,?,CONCAT('client',?,'@example.test'),?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),?,CASE WHEN ?='sending' THEN REPEAT('a',32) ELSE NULL END,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->execute([$clinic,$appointment,$recipient,$recipient,$code,$channel,$status,json_encode(['minutes_before'=>$minutes,'appointment_version'=>1,'private_note'=>'Private synthetic reminder'],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$status]);return (int)$pdo->lastInsertId();
};
$snapshot=static fn():array=>[$pdo->query('SELECT * FROM reminder_schedules ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM notification_events ORDER BY id')->fetchAll(),$pdo->query('SELECT id,status,version FROM appointments ORDER BY id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$state=static function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM notification_events WHERE id=?');$q->execute([$id]);return $q->fetch();};
$denied=function(callable $work,int $status=403)use($expect):void{try{$work();throw new RuntimeException('Expected reminder denial');}catch(ApiException $e){$expect($e->status===$status,'Expected reminder authorization status '.$status);}};
$set(101,1,true);$set(101,3,true);$visible=$visit();$hidden=$visit(101);$foreignVisit=$visit(105,2);
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-reminders-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-reminders-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start reminder API');fclose($pipes[0]);
$clinicToken=$staffToken('reminder-admin','Wellness.ClinicAdmin');$path='/api/v1/admin/reminder-schedules';
$write=fn(string $method,?array $body=null,string $suffix='',?string $token=null)=>$call($path.$suffix,$token??$adminToken,$method,$body);
$blocked=function(string $method,?array $body=null,string $suffix='',?string $token=null)use($write,$expect,$snapshot,$event):void{$before=$snapshot();[$status,$data,$cid,$raw]=$write($method,$body,$suffix,$token);$expect($status===403&&$data['error']['code']==='reminder_schedule_access_restricted','Affected restricted or unresolvable reminders deny the whole operation');$expect($snapshot()===$before,'Denied reminder change preserves schedules, queue, appointments and business audits');$expect(!str_contains($raw,'client_id')&&!str_contains($raw,'@example.test')&&!str_contains($raw,'Private synthetic')&&!str_contains($raw,'affected_count'),'No patient identity, payload or affected-count disclosure');$expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied attempt centrally audited without disclosed patient resources');};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Reminder API ready');
    [$status,$data,$cid]=$write('GET');$expect($status===200&&$data['data']===[],'Patient-free configuration readable under unrelated restrictions');
    [$status,$data,$cid]=$write('POST',['minutes_before'=>60,'clinic_id'=>2]);$id=(int)$data['data'][0]['id'];$expect($status===201&&count($data['data'])===1,'Empty timing creation allowed and submitted clinic override ignored');
    $expect(json_decode($event($cid)[0]['metadata'],true)['resources']===[['type'=>'reminder_schedule','id'=>$id]],'Settings disclosure records schedule IDs with correct audit type');
    foreach([false,true] as $active){[$status]=$write('PATCH',['active'=>$active],'/'.$id,$clinicToken);$expect($status===200,'Clinic Admin may change empty timing despite unrelated restrictions');}
    $blockedId=$notice($hidden);$allowedId=$notice($visible);
    foreach([true,false] as $active)$blocked('PATCH',['active'=>$active],'/'.$id);
    $blocked('PATCH',['active'=>false],'/'.$id,$clinicToken);
    $newHidden=$notice($hidden,180);$blocked('POST',['minutes_before'=>180]);
    $inactive=$rule(180,0);$blocked('PATCH',['active'=>true],'/'.$inactive);$blocked('PATCH',['active'=>false],'/'.$inactive);
    // Claims outside the consent lock can already be sending when a configuration request starts.
    $pdo->exec("UPDATE notification_events SET status='sending',leased_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id IN($blockedId,$newHidden)");
    $blocked('PATCH',['active'=>false],'/'.$id);$blocked('PATCH',['active'=>true],'/'.$inactive);
    $pdo->exec('DELETE FROM notification_events');$notice($hidden,'60');$blocked('PATCH',['active'=>false],'/'.$id);
    $set(101,0,true);$set(101,1,false);$blocked('PATCH',['active'=>false],'/'.$id);$set(101,0,false);$set(101,1,true);
    // No page-size cap may hide a restricted final row.
    $pdo->exec('DELETE FROM notification_events');$pdo->beginTransaction();$pending=[];for($i=0;$i<205;$i++)$pending[]=$notice($visible,60,$i%2?'failed':'queued');$tail=$notice($hidden);$pdo->commit();$blocked('PATCH',['active'=>false],'/'.$id);
    $set(101,1,false);[$status,$data,$cid]=$write('PATCH',['active'=>false],'/'.$id);$expect($status===200,'Restoration permits complete authorized cancellation');
    foreach(array_merge($pending,[$tail]) as $eventId)$expect($state($eventId)['status']==='canceled'&&$state($eventId)['next_attempt_at']===null,'Every pending reminder canceled without a cap');
    $q=$pdo->prepare("SELECT metadata FROM audit_logs WHERE correlation_id=? AND action='reminder_schedule.cancel_notifications' ORDER BY id");$q->execute([$cid]);$audited=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $json)$audited=array_merge($audited,json_decode($json,true)['notification_ids']);$expect($audited===array_merge($pending,[$tail]),'Chunked mutation audit records every canceled notification ID');
    [$status]=$write('PATCH',['active'=>true],'/'.$id);$expect($status===200&&$state($tail)['status']==='canceled','Reenable never resurrects canceled work');
    $set(101,1,true);$pdo->exec('DELETE FROM notification_events');
    $untouched=[$notice($hidden,180),$notice($hidden,60,'sent'),$notice($hidden,60,'needs_review'),$notice($hidden,60,'queued',1,null,'sms'),$notice($hidden,60,'queued',1,null,'email','booking_confirmation'),$notice($foreignVisit,60,'queued',2)];
    $ok=$notice($visible);$sending=$notice($visible,60,'sending');$before=array_map($state,$untouched);
    [$status]=$write('PATCH',['active'=>false],'/'.$id);$expect($status===200&&$state($ok)['status']==='canceled'&&$state($sending)['status']==='sending','Authorized cancellation leaves sending lease to its worker');$expect(array_map($state,$untouched)===$before,'Other timings/channels/codes/clinics and terminal/review states are untouched');
    foreach([null,$foreignVisit,$visit(105),$visit(107),$visit(104,1,2),$visit(104,1,1,2)] as $badVisit){$pdo->exec('DELETE FROM notification_events');$notice($badVisit);$blocked('PATCH',['active'=>false],'/'.$id);}
    $badPractitioner=$visit();$pdo->exec("UPDATE appointments SET practitioner_id=5 WHERE id=$badPractitioner");$pdo->exec('DELETE FROM notification_events');$notice($badPractitioner);$blocked('PATCH',['active'=>false],'/'.$id);
    $pdo->exec('DELETE FROM notification_events');$notice($visible,60,'queued',1,101);$blocked('PATCH',['active'=>false],'/'.$id);
    // Strict parser: ambiguous numeric representations cannot become eligible in the worker.
    $pdo->exec('DELETE FROM notification_events');$workerVisit=$visit();$bad=[];foreach(['6e1','60junk','060',60.0,true,[]] as $value)$bad[]=$notice($workerVisit,$value);
    [$status]=$write('PATCH',['active'=>true],'/'.$id);$expect($status===200,'Malformed non-dispatchable timing payloads do not gain eligibility');
    $pdo->exec('UPDATE notification_events SET next_attempt_at=NULL');
    // An uninitialized mailer is a sentinel: it cannot initialize a transport or contact a provider.
    $mailer=(new ReflectionClass(GraphMailClient::class))->newInstanceWithoutConstructor();$worker=new NotificationWorker($pdo,$mailer,'https://audit-a.test/client');
    $summary=$worker->run(20,$workerVisit);$expect($summary['canceled']===count($bad)&&$summary['sent']===0&&$summary['review']===0,'Malformed payloads canceled before composing or invoking mail transport');
    $communications=$visit();$notice($communications);$pdo->exec('UPDATE notification_events SET next_attempt_at=NULL');$set(104,0,true,'patient_communications');$summary=$worker->run(20,$communications);$expect($summary['canceled']===1&&$summary['sent']===0,'Patient communication withdrawal still prevents dispatch');$set(104,0,false,'patient_communications');
    $pdo->exec('DELETE FROM notification_events');$foreignRule=$rule(60,1,2);$smsRule=$rule(60,1,1,'sms');
    foreach([$foreignRule,$smsRule,999999] as $target){[$status]=$write('PATCH',['active'=>false],'/'.$target);$expect($status===404,'Foreign, unsupported-channel and missing settings concealed');}
    [$status]=$write('GET',null,'?clinic_id=2');$expect($status===200,'Client-supplied clinic does not widen configuration list');
    $foreignRows=$service->list($actor(5,['super_admin'],[],'staff',2));$expect(array_column($foreignRows,'id')===[$foreignRule],'Separate clinic administrator reads only that clinic settings');
    foreach([[$practitionerToken,'GET',null],[$practitionerToken,'POST',['minutes_before'=>2880]],[$practitionerToken,'PATCH',['active'=>false]]] as [$token,$method,$body]){[$status]=$write($method,$body,$method==='PATCH'?'/'.$id:'',$token);$expect($status===403,'Practitioner cannot read or edit reminder configuration');}
    foreach([['reception'],['accountant'],['application_admin']] as $roles)$denied(fn()=>$service->create($actor(1,$roles,['review_privacy_audit']),['minutes_before'=>2880],'bad-role'));
    $denied(fn()=>$service->list($actor(101,['super_admin'],[],'client')));$denied(fn()=>$service->create($actor(2,['super_admin']),['minutes_before'=>2880],'forged-admin'));
    foreach(['users','clinics'] as $table){$pdo->exec("UPDATE $table SET status='inactive' WHERE id=1");try{$denied(fn()=>$service->create($operator,['minutes_before'=>2880],'inactive'));$denied(fn()=>$service->list($operator));}finally{$pdo->exec("UPDATE $table SET status='active' WHERE id=1");}}
    $pdo->exec('DELETE FROM user_roles WHERE user_id=1');try{$denied(fn()=>$service->list($operator));}finally{$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");}
    $pdo->beginTransaction();try{try{$service->create($operator,['minutes_before'=>2880],'nested');throw new RuntimeException('Nested mutation accepted');}catch(LogicException){$expect($pdo->inTransaction(),'Nested rejection preserves caller transaction');}}finally{$pdo->rollBack();}
    [$status]=$write('POST',['minutes_before'=>60]);$expect($status===409,'Duplicate time still rejected');[$status]=$write('PATCH',['active'=>'false'],'/'.$id);$expect($status===422,'Boolean validation preserved');
    [$status]=$write('POST',['minutes_before'=>2880]);$expect($status===201,'New permitted timing created');[$status]=$write('POST',['minutes_before'=>4320]);$expect($status===201,'Third permitted timing created');[$status]=$write('PATCH',['active'=>true],'/'.$inactive);$expect($status===422,'Three-active timing capacity remains enforced');
    $pdo->exec("DELETE FROM reminder_schedules WHERE clinic_id=1 AND id<>$id;UPDATE reminder_schedules SET active=1 WHERE id=$id");$allowed=$notice($visible);
    foreach(['reminder_schedule.cancel_notifications','reminder_schedule.update'] as $action){$pdo->exec("CREATE TRIGGER fail_reminder_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='$action' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");try{$before=$snapshot();[$status]=$write('PATCH',['active'=>false],'/'.$id);$expect($status===500&&$snapshot()===$before,'Failed cancellation/final audit rolls back queue, settings and all earlier audits');}finally{$pdo->exec('DROP TRIGGER fail_reminder_audit');}}
    $pdo->exec("CREATE TRIGGER fail_reminder_all_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");try{foreach([['POST',['minutes_before'=>180],''],['PATCH',['active'=>true],'/'.$id]] as [$method,$body,$suffix]){$before=$snapshot();[$status]=$write($method,$body,$suffix);$expect($status===500&&$snapshot()===$before,'Failed create/enable audit rolls back mutation');}}finally{$pdo->exec('DROP TRIGGER fail_reminder_all_audit');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{$before=$snapshot();[$status]=$write('PATCH',['active'=>false],'/'.$id);$expect($status===503&&$snapshot()===$before,'Consent/worker lock contention prevents side effects');}finally{$lock->release();}
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_reminder_directives');try{foreach([['POST',['minutes_before'=>180],''],['PATCH',['active'=>true],'/'.$id],['PATCH',['active'=>false],'/'.$id]] as [$method,$body,$suffix]){$before=$snapshot();[$status]=$write($method,$body,$suffix);$expect($status===500&&$snapshot()===$before,'Unavailable policy storage fails closed for every mutation');}[$status]=$write('GET');$expect($status===200,'Patient-free settings remain readable when policy storage is unavailable');}finally{$pdo->exec('RENAME TABLE retained_reminder_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Reminder schedule SQL/HTTP authorization: $checks checks passed.\n";
