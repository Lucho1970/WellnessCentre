<?php
declare(strict_types=1);
// Synthetic SQL + HTTP fixture, including existing contact and access-audit regressions.
require __DIR__.'/access-audit.php';

use Wellness\Service\{ClientConsentService,ConsentAccess,ConsentLock,GraphMailClient,NotificationWorker};
use Wellness\Http\ApiException;

$checks=0;
$pdo->exec('UPDATE customer_sessions SET revoked_at=NULL WHERE identity_id=1');
// Restart the same loopback-only synthetic router with its existing generated keys.
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start consent test API');fclose($pipes[0]);
$patient=fn(string $method='GET',?array $body=null)=>$call('/api/v1/customer/consent',$customerToken,$method,$body,'audit-a.test',true);
$change=fn(string $scope,int $target,bool $restricted,int $version)=>$patient('PUT',['scope'=>$scope,'target_staff_id'=>$target,'restricted'=>$restricted,'version'=>$version]);
$service=new ClientConsentService($database,$audit);
$denies=function(callable $work,int $status)use($expect){try{$work();throw new RuntimeException('Expected authorization denial');}catch(ApiException $e){$expect($e->status===$status,'Expected authorization status '.$status);}};
try {
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}
    $expect($ready,'Consent API ready');
    [$status,$data]=$patient();$expect($status===200&&$data['data']['items']===[],'Patient reads own initially unrestricted directives');
    [$status]=$call('/api/v1/clients/101/consent',$adminToken);$expect($status===403,'Administrator role alone cannot manage consent');
    $pdo->exec("INSERT INTO user_permissions(user_id,permission_id,assigned_by) SELECT 1,id,1 FROM permissions WHERE code='manage_client_consent'");
    [$status,$data]=$call('/api/v1/clients/101/consent',$adminToken);$expect($status===200&&isset($data['data']['staff']),'Explicit privacy-management permission works');
    foreach([
        ['scope'=>'other','target_staff_id'=>0,'restricted'=>true,'version'=>0],
        ['scope'=>'staff_access','target_staff_id'=>-1,'restricted'=>true,'version'=>0],
        ['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>'true','version'=>0],
        ['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>true,'version'=>'0'],
        ['scope'=>'patient_communications','target_staff_id'=>2,'restricted'=>true,'version'=>0],
        ['scope'=>'staff_access','target_staff_id'=>5,'restricted'=>true,'version'=>0],
        ['scope'=>'staff_access','target_staff_id'=>99999,'restricted'=>true,'version'=>0],
    ] as $invalid){[$status]=$patient('PUT',$invalid);$expect($status===422,'Invalid or cross-clinic directive rejected');}
    $expect((int)$pdo->query('SELECT COUNT(*) FROM client_consent_events')->fetchColumn()===0,'Rejected changes leave no directive history');
    $denies(fn()=>$service->list($actor(105,[] ,[],'client',2),101),403);
    $denies(fn()=>$service->list($actor(101,[],[],'client'),104),403);
    $denies(fn()=>$service->list($actor(5,['super_admin'],['manage_client_consent'],'staff',2),101),404);

    [$status,$data, $cid]=$change('staff_access',2,true,0);
    $expect($status===200&&$data['data']['items'][0]['restricted']===true,'Patient restricts named practitioner');
    $expect($event($cid)[0]['actor_type']==='client','Directive change is attributed to verified patient');
    $before=(int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
    foreach(['/api/v1/booking-clients/101/address','/api/v1/forms/tasks/'.$task,'/api/v1/appointments/'.$appointment,'/api/v1/practitioner/appointments/'.$appointment.'/visit-history'] as $path){
        [$status,$data,$cid,$raw]=$call($path,$practitionerToken);
        $expect($status===403&&($data['error']['code']??'')==='consent_restricted'&&!str_contains($raw,'secret-clinical')&&!str_contains($raw,'Synthetic Street'),'Guessed path cannot disclose restricted record');
        $expect($event($cid)[0]['outcome']==='denied','Consent denial has durable access audit');
    }
    foreach(['/api/v1/booking-clients?q=Synthetic','/api/v1/practitioner/clients?q=Synthetic','/api/v1/appointments','/api/v1/practitioner/calendar?start=2026-01-01T00:00:00Z&end=2026-01-03T00:00:00Z'] as $path){
        [$status,$data,,$raw]=$call($path,$practitionerToken);
        $expect($status===200&&!str_contains($raw,'Synthetic Appointment')&&!in_array(101,array_map('intval',array_column($data['data']['items']??$data['data'],'client_id')),true),'Search/calendar excludes restricted patient before pagination: '.$path.' status '.$status);
    }
    [$status]=$call('/api/v1/booking-clients/104/address',$practitionerToken);$expect($status===200,'Unrestricted client access still works');
    [$status]=$call('/api/v1/booking-clients/101/address',$adminToken);$expect($status===200,'Named restriction does not block other authorized staff');
    foreach(['/api/v1/dashboard','/api/v1/practitioner/today','/api/v1/admin/notifications'] as $path){[$status]=$call($path,$practitionerToken);$expect($status===403,'Unfiltered aggregate/list route fails closed');}
    [$status]=$call('/api/v1/appointments',$practitionerToken,'POST',['client_id'=>101,'practitioner_id'=>2]);$expect($status===403,'Restriction checked before booking side effects');
    [$status]=$call('/api/v1/appointments/'.$appointment,$practitionerToken,'PATCH',['status'=>'canceled_by_clinic']);$expect($status===403,'Restricted appointment write denied before validation and mutations');
    [$status]=$call('/api/v1/recurring-series/preview',$practitionerToken,'POST',['client_id'=>101,'practitioner_id'=>2]);$expect($status===403,'Recurring preview cannot bootstrap consent');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===$before,'Denied writes create no appointments');
    $denies(fn()=>$bookings->bookingClientAddress($actor(2,['super_admin','practitioner'],['schedule_for_other_practitioners']),101,'consent-delegated'),404);
    $denies(fn()=>$bookings->assertBookingClientAccess($actor(2,['super_admin'],['schedule_for_other_practitioners']),101),403);
    $denies(fn()=>(new ConsentAccess($pdo))->assertRecipient(1,101,2),403);
    [$status]=$call('/api/v1/customer/profile',$customerToken,'GET',null,'audit-a.test',true);$expect($status===200,'Patient retains own profile access');
    [$status]=$call('/api/v1/customer/forms/'.$task,$customerToken,'GET',null,'audit-a.test',true);$expect($status===200,'Patient retains clinical-answer access');
    [$status]=$change('staff_access',2,false,0);$expect($status===409,'Stale restoration rejected');
    $privacy=$actor(3,['practitioner'],['manage_client_consent']);
    $denies(fn()=>$service->save($actor(2,['practitioner'],['manage_client_consent']),101,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>false,'version'=>1,'instruction_reference'=>'CASE-1'],'consent-self'),403);
    $denies(fn()=>$service->save($privacy,101,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>false,'version'=>1],'consent-proof'),422);
    $service->save($privacy,101,['scope'=>'staff_access','target_staff_id'=>2,'restricted'=>false,'version'=>1,'instruction_reference'=>'CASE-verified-1'],'consent-verified');
    [$status]=$call('/api/v1/booking-clients/101/address',$practitionerToken);$expect($status===200,'Verified restoration re-enables pre-existing authorization');
    $expect($pdo->query("SELECT source FROM client_consent_events WHERE instruction_reference='CASE-verified-1'")->fetchColumn()==='verified_instruction','Staff evidence reference and source retained');
    [$status]=$change('staff_access',0,true,0);$expect($status===200,'Patient withdraws staff access clinic-wide');
    [$status]=$call('/api/v1/clients/101',$adminToken);$expect($status===403,'Global withdrawal overrides administrator role');
    [$status,$data]=$call('/api/v1/clients?q=Synthetic',$adminToken);$expect($status===200&&!in_array(101,array_map('intval',array_column($data['data']['items'],'id')),true),'Administrator directory filters global restriction');
    [$status]=$call('/api/v1/clients/101/consent',$adminToken,'PUT',['scope'=>'staff_access','target_staff_id'=>0,'restricted'=>false,'version'=>1,'instruction_reference'=>'CASE-self']);$expect($status===403,'Privacy manager cannot clear global restriction on themselves');
    [$status]=$change('staff_access',2,true,2);$expect($status===200,'Named restriction can coexist with global restriction');
    [$status]=$change('staff_access',0,false,1);$expect($status===200,'Patient may restore clinic-wide permission');
    [$status]=$call('/api/v1/booking-clients/101/address',$practitionerToken);$expect($status===403,'Restoring global permission does not erase named restriction');
    [$status]=$call('/api/v1/clients/101/merge-preview/104',$adminToken);$expect($status===409,'Merge cannot discard directive or its history');

    // Roll back both policy and evidence if the required consent audit cannot commit.
    $pdo->exec("CREATE TRIGGER consent_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='consent.directive' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic consent audit failure'; END IF; END");
    try{[$status]=$change('patient_communications',0,true,0);$expect($status===500,'Audit failure rejects change');}
    finally{$pdo->exec('DROP TRIGGER consent_audit_failure');}
    $expect((int)$pdo->query("SELECT COUNT(*) FROM client_consent_directives WHERE scope='patient_communications'")->fetchColumn()===0,'No partially recorded restriction after rollback');
    [$status]=$change('patient_communications',0,true,0);$expect($status===200,'Patient withdraws appointment communications separately');
    $policy=new ConsentAccess($pdo);
    $expect(!$policy->notificationAllowed(['clinic_id'=>1,'client_id'=>101,'recipient_user_id'=>101,'event_code'=>'booking_confirmation']),'Patient messages suppressed');
    $expect(!$policy->notificationAllowed(['clinic_id'=>1,'client_id'=>101,'recipient_user_id'=>2,'event_code'=>'staff_booking_confirmation']),'Named staff messages suppressed');
    $expect($policy->notificationAllowed(['clinic_id'=>1,'client_id'=>101,'recipient_user_id'=>3,'event_code'=>'staff_booking_confirmation']),'Other staff notification follows its own permission');
    $pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) VALUES(1,?,101,'client101@example.test','booking_confirmation','email','queued',UTC_TIMESTAMP(),'{}'),(1,?,2,'practitioner@example.test','staff_booking_confirmation','email','queued',UTC_TIMESTAMP(),'{}')")->execute([$appointment,$appointment]);
    // If the privacy check fails, this intentionally unusable transport cannot send any real message.
    $mailer=new GraphMailClient(str_repeat('1',8).'-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic-unusable','sender@example.test');
    $summary=(new NotificationWorker($pdo,$mailer,'https://audit-a.test/client'))->run(100,$appointment);
    $expect($summary['sent']===0&&$summary['canceled']>=2&&$summary['review']===0,'Previously queued messages canceled before composing or transport');
    [$status]=$change('patient_communications',0,false,1);$expect($status===200,'Communication permission may be restored');
    $expect((int)$pdo->query("SELECT COUNT(*) FROM notification_events WHERE appointment_id=$appointment AND status IN ('queued','failed')")->fetchColumn()===0,'Restoration does not resurrect canceled messages');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn()>0,'Withdrawal retains existing clinical records');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM client_consent_events')->fetchColumn()===7,'Complete immutable instruction history');
    // Separate PDO proves a held clinic lock prevents a concurrent policy write/disclosure.
    $second=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $lock=new ConsentLock($pdo);$lock->acquire(1);$other=new ConsentLock($second);
    try{$denies(fn()=>$other->acquire(1),503);}finally{$lock->release();}
    $other->acquire(1);$other->release();$expect(true,'Connection lock released after protected operation');
    [$status]=$change('staff_access',2,false,3);$expect($status===200,'Patient can restore named permission');
    // Missing policy storage must never be interpreted as no restriction.
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_consent_directives');
    try{[$status,,$cid,$raw]=$call('/api/v1/booking-clients?q=Synthetic',$practitionerToken);$expect($status===500&&!str_contains($raw,'example.test'),'Consent storage failure blocks disclosure');}
    finally{$pdo->exec('RENAME TABLE retained_consent_directives TO client_consent_directives');}
} finally {proc_terminate($server);proc_close($server);}
echo "Consent SQL/HTTP authorization acceptance: $checks checks passed.\n";
