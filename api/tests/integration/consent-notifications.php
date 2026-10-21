<?php
declare(strict_types=1);
// Disposable SQL and loopback HTTP, including all preceding consent regressions.
require __DIR__.'/consent-schedules.php';

use Wellness\Service\{NotificationReviewService,NotificationWorker,GraphMailClient,ConsentLock};

$checks=0;
$pdo->exec("DELETE FROM notification_reviews; DELETE FROM notification_events;
    INSERT INTO staff_notification_preferences(user_id,mobile_phone,sms_requested,email_enabled) VALUES(2,'+14165550101',1,1)
      ON DUPLICATE KEY UPDATE mobile_phone='+14165550101',sms_requested=1,email_enabled=1;
    INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(7,1,'review-admin@example.test','Review administrator','staff','active');
    INSERT INTO user_roles(user_id,role_id) SELECT 7,id FROM roles WHERE code='clinic_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(7,'microsoft','audit-tenant','review-admin');");
$set=function(int $client,int $staff,bool $restricted)use($pdo,$policy,$actor):void{
    $q=$pdo->prepare("SELECT version FROM client_consent_directives WHERE client_id=? AND scope='staff_access' AND target_staff_id=?");$q->execute([$client,$staff]);$version=(int)$q->fetchColumn();
    $policy->save($actor($client,[],[],'client'),$client,['scope'=>'staff_access','target_staff_id'=>$staff,'restricted'=>$restricted,'version'=>$version],'notification-consent-change');
};
$set(101,2,true);$set(104,2,false);
$create=$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,attempt_count,payload) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),0,'{}')");
$notice=function(?int $appointment,int $recipient=2,string $status='needs_review',string $channel='sms',int $clinic=1,string $code='staff_booking_confirmation')use($create,$pdo):int{
    $destination=$channel==='sms'?'+14165550101':($appointment===null?'standalone@example.test':'recipient@example.test');
    $create->execute([$clinic,$appointment,$recipient,$destination,$code,$channel,$status]);return (int)$pdo->lastInsertId();
};
$sent=[];for($i=0;$i<30;$i++)$sent[]=$notice($next,2,'sent','email');
$visible=$notice($next);$otherNotice=$notice($next,3,'sent','email',1,'staff_booking_reassigned_away');
$standalone=$notice(null,104,'queued','email',1,'booking_confirmation');
$hiddenNotices=[];for($i=0;$i<30;$i++)$hiddenNotices[]=$notice($hidden,2,'sent','email');
$hiddenReview=$notice($hidden);$hiddenStandalone=$notice(null,101,'needs_review','email',1,'booking_confirmation');
$unscoped=$notice(null);$foreignReview=$notice($foreign,5,'needs_review','sms',2);$mismatched=$notice($foreign,5);
$adminIds=array_merge($sent,[$visible,$otherNotice,$standalone]);rsort($adminIds);
$ownIds=array_merge($sent,[$visible]);rsort($ownIds);
$resolve=['decision'=>'resolve','outcome'=>'handled_manually','checked_provider_history'=>true,'checked_recipient'=>true];
$retry=['decision'=>'retry','outcome'=>'provider_not_sent','checked_provider_history'=>true,'checked_recipient'=>true];
$clinicAdminToken=$staffToken('review-admin','Wellness.ClinicAdmin');
$review=fn(int $id,array $body,?string $token=null)=>$call('/api/v1/admin/notifications/'.$id.'/review',$token??$adminToken,'POST',$body);
$state=function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT status,attempt_count,scheduled_at,provider_message_id,sent_at FROM notification_events WHERE id=?');$q->execute([$id]);return $q->fetch();};
$reviewCount=fn():int=>(int)$pdo->query('SELECT COUNT(*) FROM notification_reviews')->fetchColumn();
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-notifications-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-notifications-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start notification API');fclose($pipes[0]);
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Notification API ready');
    [$status,$data,$cid,$raw]=$call('/api/v1/admin/notifications',$adminToken);$data=$data['data'];
    $expect($status===200&&$data['total']===33&&$ids($data['items'])===array_slice($adminIds,0,25),'Admin list filters before pagination and total');
    $expect($data['counts']['sent']===31&&$data['counts']['queued']===1&&$data['counts']['needs_review']===1,'Every status count uses the patient filter');
    $expect(!str_contains($raw,'standalone@example.test')||in_array($standalone,$ids($data['items']),true),'Visible standalone message has a proven client recipient');
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];
    $expect(!in_array(['type'=>'notification','id'=>$hiddenReview],$refs,true)&&!in_array(['type'=>'appointment','id'=>$hidden],$refs,true),'Access audit excludes hidden notification and appointment IDs');
    [$status,$data]=$call('/api/v1/admin/notifications?page=2',$adminToken);$expect($status===200&&$ids($data['data']['items'])===array_slice($adminIds,25),'Next admin page has no holes caused by hidden rows');
    [$status,$data]=$call('/api/v1/admin/notifications?channel=sms&status=needs_review&period=last7',$adminToken);$expect($status===200&&$data['data']['total']===1&&$ids($data['data']['items'])===[$visible],'SMS/status/period filtering shares the consent boundary');
    [$status,$data]=$call('/api/v1/admin/notifications?status=sent',$adminToken);$expect($status===200&&$data['data']['total']===31&&$data['data']['counts']['needs_review']===1,'Status selection preserves original all-status summary semantics');
    [$status,$data]=$call('/api/v1/practitioner/notifications?recipient_user_id=3&clinic_id=2',$practitionerToken);$expect($status===200&&$data['data']['total']===31&&$ids($data['data']['items'])===array_slice($ownIds,0,25),'Practitioner sees only own permitted notices despite delegation and submitted IDs');
    [$status,$data]=$call('/api/v1/practitioner/notifications?page=2',$practitionerToken);$expect($status===200&&$ids($data['data']['items'])===array_slice($ownIds,25),'Practitioner pagination counts permitted rows only');
    [$status,$data]=$call('/api/v1/practitioner/notifications?channel=sms&status=needs_review&period=today',$practitionerToken);$expect($status===200&&$ids($data['data']['items'])===[$visible]&&$data['data']['total']===1,'Own-history filters preserve recipient scope');
    $pdo->exec('UPDATE practitioners SET active=0 WHERE id=2');
    try{[$status,$data]=$call('/api/v1/practitioner/notifications',$practitionerToken);$expect($status===200&&$data['data']['items']===[]&&$data['data']['total']===0,'Inactive practitioner profile discloses no contact history');}
    finally{$pdo->exec('UPDATE practitioners SET active=1 WHERE id=2');}
    [$status]=$call('/api/v1/admin/notifications',$practitionerToken);$expect($status===403,'Practitioner cannot use administrator list');
    [$status]=$call('/api/v1/practitioner/notifications',$adminToken);$expect($status===403,'Administrator role does not grant own-practitioner history');
    [$status]=$call('/api/v1/admin/notifications',$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Changing host does not grant foreign-clinic list authority');

    foreach([$hiddenReview,$hiddenStandalone,$unscoped,$foreignReview,$mismatched,999999] as $id){
        $before=$reviewCount();[$status,$data,$cid]=$review($id,$resolve+['client_id'=>104,'clinic_id'=>1]);
        $expect($status===404&&$data['error']['code']==='notification_not_found'&&$reviewCount()===$before,'Hidden/foreign/unscoped/missing review concealed without mutation');
        $expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied review audited without disclosed resources');
    }
    [$status]=$review($hiddenReview,[]);$expect($status===404,'Hidden ID cannot be distinguished with invalid decision fields');
    [$status]=$review($visible,$resolve,$practitionerToken);$expect($status===403,'Practitioner cannot review even their own notice');
    [$status]=$review($visible,[]);$expect($status===422&&$state($visible)['status']==='needs_review','Permitted record still requires review confirmations');
    [$status,$data,$cid]=$review($visible,$resolve);$expect($status===200&&$data['data']['status']==='resolved'&&$reviewCount()===1,'Permitted administrator resolution commits record and evidence');
    $expect($event($cid)[0]['outcome']==='success','Successful review has response access audit');
    [$status]=$review($visible,$resolve);$expect($status===409&&$reviewCount()===1,'Duplicate review cannot append another decision');
    [$status,$data]=$call('/api/v1/admin/notifications?status=resolved',$adminToken);$expect($status===200&&$data['data']['items'][0]['review_outcome']==='handled_manually','Permitted review outcome appears in filtered list');

    $queued=$notice($next);[$status,$data]=$review($queued,$retry);$expect($status===200&&$data['data']['status']==='queued'&&$state($queued)['attempt_count']===0,'Valid retry queues SMS without sending or resetting attempts');
    // Recipient withdrawal does not deny the reviewer access; it must independently prevent retry.
    $set(104,2,true);$deniedRetry=$notice($next);$before=$reviewCount();
    [$status]=$review($deniedRetry,$retry);$expect($status===409&&$state($deniedRetry)['status']==='needs_review'&&$reviewCount()===$before,'Reviewer cannot queue a notice to a consent-restricted staff recipient');
    $mailer=new GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','sender@example.test');
    $sms=new \Wellness\Service\VoipMsSmsClient('sender@example.test','synthetic','4165550100',static function(){throw new RuntimeException('Transport must not be reached');});
    $result=(new NotificationWorker($pdo,$mailer,'https://audit-a.test/client',$sms))->run(100,$next);
    $expect($result['sent']===0&&$result['canceled']===1&&$state($queued)['status']==='canceled','Withdrawal after review cancels queued delivery before transport');
    $set(104,2,false);
    foreach([
        ["UPDATE staff_notification_preferences SET sms_requested=0 WHERE user_id=2","UPDATE staff_notification_preferences SET sms_requested=1 WHERE user_id=2"],
        ["UPDATE staff_notification_preferences SET mobile_phone='+14165550199' WHERE user_id=2","UPDATE staff_notification_preferences SET mobile_phone='+14165550101' WHERE user_id=2"],
        ['UPDATE practitioners SET active=0 WHERE id=2','UPDATE practitioners SET active=1 WHERE id=2'],
        ["UPDATE users SET status='inactive' WHERE id=2","UPDATE users SET status='active' WHERE id=2"],
        ["UPDATE appointments SET practitioner_id=3 WHERE id=$next","UPDATE appointments SET practitioner_id=2 WHERE id=$next"],
    ] as [$disable,$restore]){
        $id=$notice($next);$pdo->exec($disable);$before=$reviewCount();
        try{[$status]=$review($id,$retry);$expect($status===409&&$state($id)['status']==='needs_review'&&$reviewCount()===$before,'Changed recipient authority/destination blocks retry atomically');}
        finally{$pdo->exec($restore);}
    }
    foreach([
        "attempt_count=5","provider_message_id='synthetic-provider-id'","sent_at=UTC_TIMESTAMP()","scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR)","channel='email'",
    ] as $change){$id=$notice($next);$pdo->exec("UPDATE notification_events SET $change WHERE id=$id");$before=$reviewCount();[$status]=$review($id,$retry);$expect($status===409&&$state($id)['status']==='needs_review'&&$reviewCount()===$before,'Existing retry age/provider/channel/attempt safety rule retained');}
    $older=$notice($next);$notice($next);[$status]=$review($older,$retry);$expect($status===409,'Newer notice prevents retry of a superseded event');
    $id=$notice($next);[$status]=$review($id,$resolve,$clinicAdminToken);$expect($status===200,'Clinic Admin can resolve permitted patient notification');

    // Consent changed since the list was loaded: both retry and resolution recheck it.
    $id=$notice($next);$set(104,1,true);$before=$reviewCount();
    foreach([$resolve,$retry] as $decision){[$status]=$review($id,$decision);$expect($status===404&&$state($id)['status']==='needs_review'&&$reviewCount()===$before,'Stale list grants no review authority after withdrawal');}
    $set(104,1,false);
    $pdo->exec("CREATE TRIGGER notification_review_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='notification.review' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic review audit failure'; END IF; END");
    $before=$reviewCount();try{[$status]=$review($id,$resolve);$expect($status===500&&$state($id)['status']==='needs_review'&&$reviewCount()===$before,'Audit failure rolls back review status and evidence');}finally{$pdo->exec('DROP TRIGGER notification_review_audit_failure');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{[$status]=$review($id,$resolve);$expect($status===503&&$state($id)['status']==='needs_review','Concurrent consent lock prevents review from racing withdrawal');}finally{$lock->release();}
    [$status]=$review($id,$resolve);$expect($status===200,'Review succeeds after lock release');

    // Restoring a named instruction cannot override global withdrawal.
    $set(101,0,true);$set(101,1,false);[$status]=$review($hiddenReview,$resolve);$expect($status===404,'Global restriction still overrides named restoration for review');
    $set(101,0,false);[$status]=$review($hiddenReview,$resolve);$expect($status===200,'Restored review permission takes effect on the next request');
    $set(101,0,true);$set(104,1,true);$set(104,2,true);
    [$status,$data]=$call('/api/v1/admin/notifications',$adminToken);$expect($status===200&&$data['data']['items']===[]&&$data['data']['total']===0&&array_sum($data['data']['counts'])===0,'All-restricted administrator list returns empty data and zero counts');
    [$status,$data]=$call('/api/v1/practitioner/notifications',$practitionerToken);$expect($status===200&&$data['data']['items']===[]&&$data['data']['total']===0,'All-restricted practitioner list returns empty data');
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_notification_directives');
    try{
        foreach([['/api/v1/admin/notifications',$adminToken],['/api/v1/practitioner/notifications',$practitionerToken]] as [$path,$token]){[$status,,$cid,$raw]=$call($path,$token);$expect($status===500&&!str_contains($raw,'recipient@example.test'),'Missing consent storage blocks notification disclosure');}
        $before=$reviewCount();[$status]=$review($deniedRetry,$resolve);$expect($status===500&&$state($deniedRetry)['status']==='needs_review'&&$reviewCount()===$before,'Missing policy storage blocks review mutation');
    }finally{$pdo->exec('RENAME TABLE retained_notification_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Filtered notification lists/reviews SQL/HTTP authorization: $checks checks passed.\n";
