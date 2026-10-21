<?php
declare(strict_types=1);
require __DIR__.'/access-audit.php';
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\Service\{PractitionerEnquiryService,ClientConsentService,ConsentLock,MailSendException};
use Wellness\Http\ApiException;
$checks=0;
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/040_application_administration.sql'));
$pdo->exec("CREATE TABLE IF NOT EXISTS clinic_hosts(host VARCHAR(253) PRIMARY KEY,clinic_id BIGINT UNSIGNED NOT NULL UNIQUE,FOREIGN KEY(clinic_id) REFERENCES clinics(id));INSERT INTO clinic_hosts(host,clinic_id) VALUES('wellnexa.copihue.ca',1)");
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/047_practitioner_enquiries.sql'));
$issuer='https://login.microsoftonline.com/audit-tenant/v2.0';
$pdo->prepare("INSERT INTO application_administrators(id,issuer,subject,display_name,email) VALUES(1,?,'central-reviewer','Synthetic central admin','central@example.test')")->execute([$issuer]);
$platform=new ApplicationAdminContext(1,new VerifiedIdentity('microsoft',$issuer,'central-reviewer','audit-tenant'),'Central','central@example.test');
$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 3,id FROM roles WHERE code='clinic_admin';INSERT INTO user_roles(user_id,role_id) SELECT 5,id FROM roles WHERE code='super_admin';
    INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject) VALUES(3,'microsoft','audit-tenant','enquiry-admin')");
$_ENV['MAIL_ENABLED']='false';$sent=[];$service=new PractitionerEnquiryService($database,function(...$args)use(&$sent,$pdo,$expect):void{
    $expect(!$pdo->inTransaction(),'Provider is called after the claim transaction commits');
    $expect((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='practitioner_enquiry.notification_claim' AND metadata LIKE '%sending%'")->fetchColumn()>0,'An attributed sending claim exists before provider contact');$sent[]=$args;
});
$add=function(int $clinic,int $n,string $email='applicant@example.test')use($pdo):int{
    $pdo->prepare('INSERT INTO practitioner_enquiries(clinic_id,reference,request_hash,full_name,email,discipline,service_area,message) VALUES(?,?,?,?,?,?,?,?)')->execute([$clinic,'10000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT),hash('sha256',(string)$n),'Private synthetic applicant '.$n,$email,'Test discipline','Test area','Private synthetic recruitment message']);return (int)$pdo->lastInsertId();
};
$id=$add(1,1,'client101@example.test');$foreign=$add(2,2,'foreign-private@example.test');for($i=3;$i<=29;$i++)$add(1,$i);
$policy=new ClientConsentService($database,$audit);foreach([0,1,2] as $target)$policy->save($actor(101,[],[],'client'),101,['scope'=>'staff_access','target_staff_id'=>$target,'restricted'=>true,'version'=>0],'enquiry-policy');
$patients=fn()=>array_map(fn($table)=>$pdo->query("SELECT * FROM $table ORDER BY 1")->fetchAll(),['users','practitioners','appointments','client_consent_directives','client_profiles','client_contact_addresses','staff_invitations']);$patientBefore=$patients();
$snapshot=fn()=>[$pdo->query('SELECT * FROM practitioner_enquiries ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM practitioner_enquiry_settings ORDER BY clinic_id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$deny=function(callable $work,int $status=403)use($expect,$snapshot,&$sent):void{$before=$snapshot();$count=count($sent);try{$work();throw new RuntimeException('Expected enquiry refusal');}catch(ApiException $e){$expect($e->status===$status,'Enquiry refusal '.$status.' got '.$e->status.'/'.$e->errorCode);}$expect($snapshot()===$before&&count($sent)===$count,'Denied operation preserves inboxes, settings, audits and provider calls');};
$ops=fn($who,$clinic=1,$target=null)=>[fn()=>$service->list($who,$clinic),fn()=>$service->update($who,$clinic,$target??$id,['status'=>'closed','revision'=>1],'denied'),fn()=>$service->retryNotification($who,$clinic,$target??$id,'denied')];
foreach([$actor(2),$actor(2,['super_admin']),$actor(3,['reception']),$actor(3,['accountant']),$actor(3,[],['review_privacy_audit']),$actor(101,['super_admin'],[],'client'),$actor(1,['super_admin'],[],'application_admin'),$actor(5,['super_admin']),$actor(6,['super_admin']),$actor(99999,['super_admin'])] as $bad)foreach($ops($bad) as $op)$deny($op);
foreach([1,3] as $uid){$who=$uid===1?$operator:$actor(3,['clinic_admin']);$pdo->exec("UPDATE users SET status='inactive' WHERE id=$uid");foreach($ops($who) as $op)$deny($op);$pdo->exec("UPDATE users SET status='active' WHERE id=$uid");}
$pdo->exec("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=1 AND r.code='super_admin'");foreach($ops($operator) as $op)$deny($op);$pdo->exec("INSERT INTO user_roles(user_id,role_id) SELECT 1,id FROM roles WHERE code='super_admin'");
$pdo->exec("UPDATE clinics SET status='inactive' WHERE id=1");foreach($ops($operator) as $op)$deny($op,404);$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
$forged=new ApplicationAdminContext(1,new VerifiedIdentity('microsoft',$issuer,'wrong-subject','audit-tenant'),'Wrong','wrong@example.test');
foreach($ops($forged) as $op)$deny($op);$deny(fn()=>$service->saveSettings($forged,1,['revision'=>1,'enabled'=>true,'recipient_email'=>'bad-target@example.test'],'denied'));
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");foreach($ops($platform) as $op)$deny($op);$deny(fn()=>$service->saveSettings($platform,1,['revision'=>1,'enabled'=>true,'recipient_email'=>'bad-target@example.test'],'denied'));$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
foreach([$foreign,99999] as $target){$deny(fn()=>$service->update($operator,1,$target,['status'=>'closed','revision'=>1],'foreign'),404);$deny(fn()=>$service->retryNotification($operator,1,$target,'foreign'),404);}
foreach([['status'=>'invalid','revision'=>1],['status'=>'closed','revision'=>0],['status'=>'closed','revision'=>'1']] as $bad)$deny(fn()=>$service->update($operator,1,$id,$bad,'invalid'),422);
$deny(fn()=>$service->list($operator,1,['page'=>0]),422);
$list=$service->list($operator,1);$expect(count($list['items'])===25&&$list['has_more']&&count($service->list($operator,1,['page'=>2])['items'])===3,'Authorized pagination is scoped before slicing');
$expect($service->list($platform,2)['items'][0]['email']==='foreign-private@example.test','Current central grant explicitly selects clinic B without local staff membership');
$settings=$service->saveSettings($platform,1,['revision'=>1,'enabled'=>true,'recipient_email'=>'delivery@example.test'],'settings');$expect($settings['revision']===2,'Current central grant maintains recipient configuration');
$deny(fn()=>$service->saveSettings($platform,1,['revision'=>1,'enabled'=>false,'recipient_email'=>'stale@example.test'],'stale'),409);
$expect($service->retryNotification($operator,1,$id,'disabled')['notification_status']==='disabled'&&count($sent)===0,'Disabled mail commits an audited state without provider contact');
$_ENV['MAIL_ENABLED']='true';
$expect($service->retryNotification($actor(3,['clinic_admin']),1,$id,'send')['notification_status']==='sent'&&count($sent)===1,'Current Clinic Admin can notify an independently authorized recruitment enquiry');
$expect($sent[0][0]==='delivery@example.test'&&!str_contains(json_encode($sent),'client101@example.test')&&!str_contains(json_encode($sent),'Private synthetic'),'Provider payload excludes applicant contacts, credentials and message text');
foreach(['sent','sending','needs_review'] as $state){$pdo->prepare('UPDATE practitioner_enquiries SET notification_status=? WHERE id=?')->execute([$state,$id]);$expect($service->retryNotification($operator,1,$id,'no-resend')['notification_status']==='unchanged'&&count($sent)===1,'Sent or uncertain outcomes cannot be resent');}
$pdo->prepare("UPDATE practitioner_enquiries SET notification_status='failed' WHERE id=?")->execute([$id]);
$known=new PractitionerEnquiryService($database,fn()=>throw new MailSendException('Synthetic confirmed refusal',true));$expect($known->retryNotification($operator,1,$id,'known-failure')['notification_status']==='failed','Confirmed provider refusal remains retryable');
$unknown=new PractitionerEnquiryService($database,fn()=>throw new RuntimeException('Synthetic unknown outcome'));$expect($unknown->retryNotification($operator,1,$id,'unknown-outcome')['notification_status']==='needs_review','Untyped exception after transport entry requires review');
$pdo->prepare("UPDATE practitioner_enquiries SET notification_status='pending' WHERE id=?")->execute([$id]);
$pdo->exec("CREATE TRIGGER enquiry_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic enquiry audit unavailable'");
try{foreach([fn()=>$service->update($operator,1,$id,['status'=>'closed','revision'=>1],'rollback'),fn()=>$service->saveSettings($platform,1,['revision'=>2,'enabled'=>false,'recipient_email'=>'rollback@example.test'],'rollback'),fn()=>$service->retryNotification($operator,1,$id,'rollback')] as $op){$before=$snapshot();$count=count($sent);try{$op();throw new RuntimeException('Expected business audit failure');}catch(PDOException $e){}$expect($snapshot()===$before&&count($sent)===$count,'Failed audit rolls back update/settings/claim before provider contact');}}finally{$pdo->exec('DROP TRIGGER enquiry_audit_failure');}
$afterSend=new PractitionerEnquiryService($database,function()use($pdo):void{$pdo->exec("CREATE TRIGGER enquiry_result_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic result audit unavailable'");});
try{try{$afterSend->retryNotification($operator,1,$id,'result-failure');throw new RuntimeException('Expected result audit failure');}catch(PDOException $e){}$expect($pdo->query("SELECT notification_status FROM practitioner_enquiries WHERE id=$id")->fetchColumn()==='sending','Result storage failure preserves the committed uncertain claim');}finally{$pdo->exec('DROP TRIGGER enquiry_result_audit_failure');}
$expect($service->retryNotification($operator,1,$id,'after-result-failure')['notification_status']==='unchanged'&&count($sent)===1,'Unstored provider outcome cannot be retried');
$_ENV['MAIL_ENABLED']='false';
$expect($service->notify(1,$id)['notification_status']==='unchanged','Trusted automatic delivery uses the same no-resend boundary');
$auto=$pdo->query("SELECT correlation_id,actor_user_id FROM audit_logs WHERE action='practitioner_enquiry.notification_claim' AND correlation_id LIKE 'enquiry-%' ORDER BY id DESC LIMIT 1")->fetch();
$expect($auto&&strlen($auto['correlation_id'])<=36&&$auto['actor_user_id']===null,'System claim has a legacy-width correlation ID and no fabricated staff actor');
$start=function(array $serverEnv)use($address):mixed{$process=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/enquiries-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/enquiries-http.stderr.log','a']],$pipes,null,$serverEnv);if(!is_resource($process))throw new RuntimeException('Cannot start enquiry API');fclose($pipes[0]);for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($socket){fclose($socket);return $process;}usleep(20000);}throw new RuntimeException('Enquiry API did not start');};
$server=$start($env);
try{
    [$status,$data,$cid]=$call('/api/v1/admin/practitioner-enquiries',$adminToken);$expect($status===200&&count($data['data']['items'])===25,'Inbox works under unrelated/global patient restrictions');
    $refs=json_decode($event($cid)[0]['metadata'],true)['resources'];$ids=array_map('intval',array_column($data['data']['items'],'id'));$logged=array_column(array_filter($refs,fn($r)=>$r['type']==='practitioner_enquiry'),'id');sort($ids);sort($logged);$expect($ids===$logged,'Access audit identifies every disclosed enquiry');
    $expect(!str_contains(json_encode($event($cid)),'applicant@example.test')&&!str_contains(json_encode($event($cid)),'Private synthetic'),'Enquiry access audit excludes applicant content');
    foreach([['GET','',null],['PATCH',"/$id",['status'=>'closed','revision'=>1]],['POST',"/$id/notify",null]] as [$method,$suffix,$body]){[$status,$data,$cid]=$call('/api/v1/admin/practitioner-enquiries'.$suffix,$practitionerToken,$method,$body);$expect($status===403&&$event($cid)[0]['outcome']==='denied','All enquiry routes deny and audit practitioner access');}
    foreach([$foreign,99999] as $target){foreach([['PATCH',['status'=>'closed','revision'=>1],''],['POST',null,'/notify']] as [$method,$body,$suffix]){[$status,$data,$cid,$raw]=$call("/api/v1/admin/practitioner-enquiries/$target$suffix",$adminToken,$method,$body);$expect($status===404&&$event($cid)[0]['outcome']==='denied'&&!str_contains($raw,'foreign-private'),'Foreign/missing targets are uniformly concealed and audited');}}
    [$status,$data]=$call("/api/v1/admin/practitioner-enquiries/$id",$staffToken('enquiry-admin','Wellness.ClinicAdmin'),'PATCH',['status'=>'contacted','revision'=>1,'clinic_id'=>2,'client_id'=>101]);$expect($status===200&&$data['data']['revision']===2,'Clinic Admin changes only routed enquiry despite extra body IDs');
    [$status,$data]=$call("/api/v1/admin/practitioner-enquiries/$id",$adminToken,'PATCH',['status'=>'closed','revision'=>1]);$expect($status===409,'Concurrent/stale status cannot overwrite a newer revision');
    [$status,$data,$cid]=$call("/api/v1/admin/practitioner-enquiries/$id/notify",$adminToken,'POST');$expect($status===200&&$data['data']['notification_status']==='unchanged','HTTP notification command respects uncertain claim');
    [$status]=$call('/api/v1/admin/practitioner-enquiries',$adminToken,'GET',null,'audit-b.test');$expect($status===403,'Host switching does not transfer local inbox scope');
    $pdo->exec('RENAME TABLE access_audit_events TO enquiry_retained_access_audit');try{[$status,$data,,$raw]=$call('/api/v1/admin/practitioner-enquiries',$adminToken);$expect($status===503&&!str_contains($raw,'Private synthetic'),'Audit sink failure prevents recruitment record disclosure');}finally{$pdo->exec('RENAME TABLE enquiry_retained_access_audit TO access_audit_events');}
    [$status,$data,$cid]=$call('/api/v1/booking-clients/101/address',$practitionerToken);$expect($status===403&&$data['error']['code']==='consent_restricted','Recruitment authority does not restore restricted patient address access');
}finally{proc_terminate($server);proc_close($server);}
$env['ACCESS_AUDIT_TEST_ENQUIRIES']='synthetic-local-only';$server=$start($env);$centralToken=$staffToken('central-reviewer','Wellness.SuperAdmin');$centralHost='portal.copihue.ca';$base='/api/v1/application/clinics/2/practitioner-enquiries';
try{
    [$status,$data,$cid]=$call($base,$centralToken,'GET',null,$centralHost);$expect($status===200&&count($data['data']['items'])===1&&$event($cid)[0]['actor_type']==='application_admin','Central HTTP inbox requires an independent current global grant');
    [$status,$data]=$call($base,$adminToken,'GET',null,$centralHost);$expect($status===403,'Local Super Admin role does not grant central inbox access');
    [$status,$data]=$call($base.'/settings',$centralToken,'PUT',['revision'=>0,'enabled'=>true,'recipient_email'=>'clinic-b@example.test'],$centralHost);$expect($status===200&&$data['data']['revision']===1,'Central settings explicitly target clinic B');
    [$status,$data]=$call($base.'/'.$foreign,$centralToken,'PATCH',['revision'=>1,'status'=>'closed'],$centralHost);$expect($status===200,'Current central grant can update its selected clinic enquiry');
    [$status,$data]=$call($base.'/'.$foreign.'/notify',$centralToken,'POST',null,$centralHost);$expect($status===200&&$data['data']['notification_status']==='disabled','Central retry remains mail-disabled in the local HTTP fixture');
    $pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");foreach([['GET','',null],['PUT','/settings',['revision'=>1,'enabled'=>true,'recipient_email'=>'attempt@example.test']],['PATCH','/'.$foreign,['revision'=>2,'status'=>'new']],['POST','/'.$foreign.'/notify',null]] as [$method,$suffix,$body]){[$status,$data,$cid]=$call($base.$suffix,$centralToken,$method,$body,$centralHost);$expect($status===403&&$event($cid)[0]['outcome']==='denied','Revoked global grant rejects every central enquiry operation');}$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
}finally{proc_terminate($server);proc_close($server);}
$connection=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$blocking=new ConsentLock($connection);$blocking->acquire(1);try{$deny(fn()=>$service->list($operator,1),503);$deny(fn()=>$service->retryNotification($operator,1,$id,'busy'),503);}finally{$blocking->release();}
$pdo->beginTransaction();try{foreach($ops($operator) as $op){try{$op();throw new RuntimeException('Nested enquiry operation accepted');}catch(LogicException $e){$expect($pdo->inTransaction(),'Nested refusal preserves caller transaction');}}}finally{$pdo->rollBack();}
// Ignore only staff timestamps intentionally changed by the stale-account fixtures above.
$beforeClients=array_filter($patientBefore[0],fn($u)=>$u['user_type']==='client');$after=$patients();$expect(array_filter($after[0],fn($u)=>$u['user_type']==='client')===$beforeClients&&array_slice($after,1)===array_slice($patientBefore,1),'Recruitment review preserves patient records, identities, invitations, appointments and consent');
echo "Consent-authorized practitioner enquiries: $checks checks passed; local synthetic SQL/HTTP and injected mail only.\n";
