<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\{Config,Database};
use Wellness\Service\{CommunicationsAdministrationService,CommunicationNotices,CommunicationNoticeWorker,ClinicMailRouter,GraphMailClient,NotificationWorker,SmsDeliveryGuard,VoipMsSmsClient,PractitionerApplicationService};
use Wellness\Auth\{VerifiedIdentity,ApplicationAdminContext};
use Wellness\Http\ApiException;
function communicationsConfig(int $port,string $name): Config {return new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',practitionerApplicationsEnabled:true,automatedCommunicationsEnabled:true,smsBudgetEnforcementEnabled:true);}
if(($argv[1]??'')==='sms-worker'){
    if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true'||!preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D',$argv[2]??''))throw new RuntimeException('Synthetic workers only');
    $db=new Database(communicationsConfig((int)getenv('RECURRING_TEST_PORT'),$argv[2]));$pdo=$db->connection();
    $mail=new GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','unused@example.test',fn()=>throw new RuntimeException('No email in SMS race'));
    $sms=new VoipMsSmsClient('synthetic@example.test','synthetic','4165550100',function(string $query)use($pdo):array{parse_str($query,$values);$pdo->prepare('INSERT INTO synthetic_sms_calls(sender,destination) VALUES(?,?)')->execute([$values['did'],$values['dst']]);return[200,'{"status":"success","sms":"synthetic-accepted"}'];});
    echo json_encode((new NotificationWorker($pdo,$mail,'https://communications.test/client',$sms,false,new SmsDeliveryGuard($pdo)))->run(1),JSON_THROW_ON_ERROR);exit;
}
require __DIR__.'/practitioner-applications.php';
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/050_consent_directives.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/056_automated_communications.sql'));
$config=communicationsConfig($port,$name);$comm=new CommunicationsAdministrationService($database,$config);$checks=0;
$check=function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$denied=function(callable $call,int $status,string $why)use($check):void{try{$call();}catch(ApiException $e){$check($e->status===$status,$why.': '.$e->errorCode);return;}throw new RuntimeException($why.' accepted');};
$pdo->exec("INSERT INTO clinics(id,name) VALUES(3,'Synthetic communications');INSERT INTO clinic_hosts(host,clinic_id) VALUES('communications.test',3);
 INSERT INTO locations(id,clinic_id,name,timezone) VALUES(3,3,'Virtual','America/Toronto');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(200,3,'practitioner@example.test','Private Staff','staff','active'),(201,3,'patient@example.test','Private Patient','client','active');
 INSERT INTO practitioners(id,user_id,discipline) VALUES(200,200,'Synthetic');
 INSERT INTO user_roles(user_id,role_id,location_id) SELECT 200,id,3 FROM roles WHERE code='practitioner';
 INSERT INTO product_identities(id,adapter,issuer,subject) VALUES(100,'synthetic','test','communications-member');
 INSERT INTO staff_memberships(id,identity_id,clinic_id,local_user_id,status) VALUES(100,100,3,200,'active');
 INSERT INTO staff_notification_preferences(user_id,email_enabled,sms_requested,mobile_phone) VALUES(200,1,1,'+14165550101');
 INSERT INTO subscription_plan_versions(id,code,version,scope,currency,display_name,features) VALUES(3,'sms-beta',1,'practitioner','CAD','SMS beta','{\"sms_reminders\":true}');
 INSERT INTO billing_accounts(id,clinic_id,membership_id) VALUES(100,3,100);
 INSERT INTO billing_subscriptions(id,billing_account_id,plan_version_id,status,starts_at,trial_ends_at) VALUES(100,100,3,'trialing',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 6 MONTH);
 INSERT INTO sms_budget_pools(id,clinic_id,status) VALUES(10,3,'active');INSERT INTO sms_budget_members(pool_id,clinic_id,membership_id) VALUES(10,3,100);
 INSERT INTO services(id,clinic_id,name,slug,price_cents) VALUES(100,3,'Private Service','synthetic',10000);
 CREATE TABLE synthetic_sms_calls(id INT AUTO_INCREMENT PRIMARY KEY,sender VARCHAR(10),destination VARCHAR(20));");
$get=fn()=>$comm->handle($actor,3,'GET','',[],'test');$post=fn(string $path,array $body)=>$comm->handle($actor,3,'POST',$path,$key($body+['reason'=>'Verified synthetic communication operation']),'test');
$check(!$get()['settings']['sms_enabled'],'Settings start closed');
$settings=['revision'=>0,'email_enabled'=>true,'sms_enabled'=>true,'mail_from'=>'clinic@example.test','reply_to'=>'clinic-reply@example.test','alert_email'=>'operator@example.test','sms_from_did'=>'4165550199','cad_micros_per_segment'=>6000000,'fixed_cad_micros'=>0,'tariff_valid_until'=>gmdate('Y-m-d H:i:s',time()+86400),'senders_verified'=>true,'opt_out_reviewed'=>true];
$denied(fn()=>$post('/settings',array_replace($settings,['senders_verified'=>false])),422,'Sender confirmation required');
$denied(fn()=>$post('/settings',array_replace($settings,['opt_out_reviewed'=>false])),422,'Provider opt-out review required');
$denied(fn()=>$post('/settings',array_replace($settings,['sms_from_did'=>'2025550100'])),422,'Non-Canadian sender rejected');
$post('/settings',$settings);$denied(fn()=>$post('/settings',$settings),409,'Stale settings revision rejected');
(new \Wellness\Service\SmsBudgetLedger($pdo))->sharedCost(10,'synthetic-overhead-reviewed',0,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$create=function(bool $email=false)use($pdo):array{
    $pdo->exec("INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(3,3,201,200,100,UTC_TIMESTAMP()+INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY+INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY+INTERVAL 1 HOUR,'confirmed','practitioner',200)");$appointment=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,scheduled_at,payload) VALUES(3,?,200,?,'staff_booking_confirmation',?,UTC_TIMESTAMP(),'{}')")->execute([$appointment,$email?'practitioner@example.test':'+14165550101',$email?'email':'sms']);return['appointment'=>$appointment,'event'=>(int)$pdo->lastInsertId()];
};
$one=$create();$two=$create();$jobs=[];
foreach([1,2] as $_){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'sms-worker',$name],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$process,$pipes];}
foreach($jobs as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($error.$output);}
$check((int)$pdo->query('SELECT COUNT(*) FROM synthetic_sms_calls')->fetchColumn()===1,'Concurrent workers cannot spend the same member allowance');
$check($pdo->query('SELECT sender FROM synthetic_sms_calls LIMIT 1')->fetchColumn()==='4165550199','Uses explicit clinic sender instead of global default');
$delivery=$get()['deliveries'][0];$check($delivery['status']==='submitted'&&(int)$delivery['estimated_cents']===600,'Provider acceptance retains a committed estimate pending real cost');
$reconcile=['outcome'=>'provider_accepted','actual_cents'=>1200,'expected_actual_cents'=>null,'checked_provider_history'=>true];
$denied(fn()=>$comm->handle($actor,2,'POST','/deliveries/'.$delivery['id'].'/reconcile',$key($reconcile+['reason'=>'Wrong clinic attempted']),'test'),404,'Foreign delivery denied');
$request=$key($reconcile+['reason'=>'Provider final cost checked']);$result=$comm->handle($actor,3,'POST','/deliveries/'.$delivery['id'].'/reconcile',$request,'test');
$check($comm->handle($actor,3,'POST','/deliveries/'.$delivery['id'].'/reconcile',$request,'test')===$result,'Reconciliation retry is idempotent');
$check((int)$pdo->query('SELECT spent_cents FROM sms_budget_member_periods WHERE pool_id=10 AND membership_id=100')->fetchColumn()===1200,'Records real over-estimate and over-cap costs honestly');
$calls=0;$mails=[];$mailer=new GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','global@example.test',function($url,$headers,$body,$mail)use(&$mails):array{if(!$mail)return[200,0,'{"access_token":"synthetic","expires_in":3600}'];$mails[]=[$url,json_decode($body,true)];return[202,0,''];});
$sms=new VoipMsSmsClient('synthetic@example.test','synthetic','4165550100',function()use(&$calls):array{$calls++;throw new \Wellness\Service\SmsSendException('Synthetic timeout');});
$worker=new NotificationWorker($pdo,$mailer,'https://communications.test/client',$sms,false,new SmsDeliveryGuard($pdo),new ClinicMailRouter($pdo,$mailer));
$blocked=$create();$email=$create(true);$check($worker->run(1,$blocked['appointment'])['canceled']===1&&$calls===0,'Over-cap stops SMS before provider transport');
$check($worker->run(1,$email['appointment'])['sent']===1&&count($mails)===1,'Eligible email remains independent when SMS is capped');
$check(str_contains($mails[0][0],'clinic%40example.test')&&$mails[0][1]['message']['replyTo'][0]['emailAddress']['address']==='clinic-reply@example.test','Email uses the exact clinic sender and reply routing');
$pdo->exec("UPDATE staff_notification_preferences SET personal_email='private-notifications@example.test',personal_email_verified_at=UTC_TIMESTAMP() WHERE user_id=200");
$routing=new ClinicMailRouter($pdo,$mailer);
$check($routing->route(3,$email['appointment'],false)['reply_to']==='clinic-reply@example.test','Private staff notification email is not exposed as patient Reply-To');
$reply=['membership_id'=>100,'email'=>'reviewed-patient-replies@example.test','active'=>true,'sharing_confirmed'=>true,'version'=>0];
$denied(fn()=>$post('/reply-address',array_replace($reply,['sharing_confirmed'=>false])),422,'Patient-facing email requires explicit sharing approval');
$denied(fn()=>$comm->handle($actor,2,'POST','/reply-address',$key($reply+['reason'=>'Foreign practitioner attempted']),'test'),404,'Reply address assignment is clinic-scoped');
$post('/reply-address',$reply);$check($routing->route(3,$email['appointment'],false)['reply_to']===$reply['email'],'Reviewed patient-facing address is used explicitly');
$denied(fn()=>$post('/reply-address',array_replace($reply,['active'=>false])),409,'Stale reply-address version cannot overwrite a newer decision');
$post('/reply-address',array_replace($reply,['active'=>false,'version'=>1]));$check($routing->route(3,$email['appointment'],false)['reply_to']==='clinic-reply@example.test','Disabling an override restores clinic Reply-To');
$post('/deliveries/'.$delivery['id'].'/reconcile',['outcome'=>'correction','actual_cents'=>100,'expected_actual_cents'=>1200,'checked_provider_history'=>true]);
$denied(fn()=>$post('/deliveries/'.$delivery['id'].'/reconcile',['outcome'=>'correction','actual_cents'=>50,'expected_actual_cents'=>1200,'checked_provider_history'=>true]),409,'Stale correction denied');
$unknown=$create();$check($worker->run(1,$unknown['appointment'])['review']===1&&$calls===1,'Timeout remains for review without automatic resend');
$check((int)$pdo->query('SELECT reserved_cents FROM sms_budget_member_periods WHERE pool_id=10 AND membership_id=100')->fetchColumn()===600,'Ambiguous provider result retains commitment');
$pdo->exec("UPDATE notification_events SET status='queued' WHERE id=".$unknown['event']);$check($worker->run(1,$unknown['appointment'])['review']===1&&$calls===1,'Queue retry cannot bypass one-attempt ledger claim');
$post('/deliveries/'.$unknown['event'].'/reconcile',['outcome'=>'provider_not_sent','actual_cents'=>0,'expected_actual_cents'=>null,'checked_provider_history'=>true]);
foreach([
    ["UPDATE clinic_communications SET tariff_valid_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE clinic_id=3","UPDATE clinic_communications SET tariff_valid_until=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE clinic_id=3"],
    ["UPDATE clinic_communications SET opt_out_checked_at=UTC_TIMESTAMP()-INTERVAL 25 HOUR WHERE clinic_id=3","UPDATE clinic_communications SET opt_out_checked_at=UTC_TIMESTAMP() WHERE clinic_id=3"],
    ["UPDATE billing_subscriptions SET status='suspended' WHERE id=100","UPDATE billing_subscriptions SET status='trialing' WHERE id=100"],
    ["UPDATE staff_memberships SET status='revoked' WHERE id=100","UPDATE staff_memberships SET status='active' WHERE id=100"],
    ["UPDATE billing_subscriptions SET plan_version_id=1 WHERE id=100","UPDATE billing_subscriptions SET plan_version_id=3 WHERE id=100"],
    ["DELETE FROM user_roles WHERE user_id=200","INSERT INTO user_roles(user_id,role_id,location_id) SELECT 200,id,3 FROM roles WHERE code='practitioner'"],
    ["DELETE FROM sms_budget_entries WHERE pool_id=10 AND membership_id IS NULL","INSERT INTO sms_budget_entries(pool_id,period_start,reference,estimated_cents,actual_cents,status) SELECT 10,period_start,'synthetic-overhead-reviewed',0,0,'settled' FROM sms_budget_periods WHERE pool_id=10 LIMIT 1"],
    ["INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(3,201,'staff_access',200,1,1)","DELETE FROM client_consent_directives WHERE clinic_id=3"],
] as [$disable,$restore]){$test=$create();$pdo->exec($disable);try{$summary=$worker->run(1,$test['appointment']);$check($summary['canceled']===1&&$calls===1,'Current cost/opt-out/subscription/membership/consent preflight blocks transport');}finally{$pdo->exec($restore);}}
$post('/suppression',['number'=>'+14165550101','active'=>true]);$test=$create();$check($worker->run(1,$test['appointment'])['canceled']===1&&$calls===1,'Suppressed destination is not sent');
$denied(fn()=>$post('/suppression',['number'=>'+14165550101','active'=>false]),422,'Unblock requires renewed opt-in');$post('/suppression',['number'=>'+14165550101','active'=>false,'opt_in_confirmed'=>true]);
// Outbox notices are generic and carry no application declarations or patient information.
$outbox=new CommunicationNotices($pdo);$pdo->exec("UPDATE billing_subscriptions SET trial_ends_at=UTC_TIMESTAMP()+INTERVAL 30 DAY WHERE id=100");$outbox->scan();$outbox->scan();
$check((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE subscription_id=100 AND threshold=30")->fetchColumn()===2,'Trial window deduplicates one notice per audience');
$noticeWorker=new CommunicationNoticeWorker($pdo,$mailer,'portal.test');$noticeWorker->run(100);
$check((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE subscription_id=100 AND status='sent'")->fetchColumn()===2,'Due membership notices use current recipients');
$outbox->scan();$check((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE subscription_id=100")->fetchColumn()===2,'No duplicate trial notices after success');
$pdo->exec("UPDATE billing_subscriptions SET trial_ends_at=UTC_TIMESTAMP()+INTERVAL 14 DAY WHERE id=100");$outbox->scan();$pdo->exec("UPDATE billing_subscriptions SET trial_ends_at=UTC_TIMESTAMP()+INTERVAL 6 MONTH WHERE id=100");
$check($noticeWorker->run(100)['canceled']===2,'Extended trials cancel stale reminder recipes');
$pdo->exec("UPDATE sms_budget_member_periods SET spent_cents=1000,reserved_cents=0 WHERE pool_id=10 AND membership_id=100");$outbox->scan();
$check((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE kind='budget' AND membership_id=100")->fetchColumn()===6,'50/80/100 percent alerts cover member and operator');
$noticeWorker->run(100);$outbox->scan();$check((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE kind='budget' AND membership_id=100")->fetchColumn()===6,'Budget alerts do not duplicate after sending');
$pdo->exec("INSERT INTO communication_notices(clinic_id,reference,kind,recipient_kind,status,attempt_count,leased_until) VALUES(3,'expired-lease','budget','operator','sending',1,UTC_TIMESTAMP()-INTERVAL 1 SECOND)");$noticeWorker->run(1);
$check($pdo->query("SELECT status FROM communication_notices WHERE reference='expired-lease'")->fetchColumn()==='needs_review','Expired mail lease is never resent automatically');
$reviewNotice=$pdo->query("SELECT id,attempt_count FROM communication_notices WHERE reference='expired-lease'")->fetch();
$denied(fn()=>$post('/notices/'.$reviewNotice['id'].'/review',['attempt_count'=>1,'decision'=>'retry','checked_provider_history'=>true,'provider_not_sent'=>false]),422,'Retry requires explicit proof of no-send');
$post('/notices/'.$reviewNotice['id'].'/review',['attempt_count'=>1,'decision'=>'resolve','checked_provider_history'=>true]);
$pdo->exec('RENAME TABLE communication_events TO retained_communication_events');
try{try{$post('/suppression',['number'=>'+14165550101','active'=>true]);throw new RuntimeException('Audit failure accepted');}catch(PDOException){$check(true,'Audit failure blocks suppression mutation');}}finally{$pdo->exec('RENAME TABLE retained_communication_events TO communication_events');}
$check($get()['suppressed_count']===0,'Audit failure rolls back the operational write');
// Application outbox creation is part of the application transaction, not a post-commit mail call.
$comm->handle($actor,1,'POST','/settings',$key(array_replace($settings,['sms_enabled'=>false,'revision'=>0,'reason'=>'Synthetic application email setup'])),'test');
$apps=new PractitionerApplicationService($database,$config);$applicant=new VerifiedIdentity('entra-external-staff','https://synthetic.test','notice-applicant','test');
$applicationRequest=$key(array_replace($profile,['email'=>'applicant-notice@example.test','credentials'=>'Private declared credential never belongs in mail.']));
$application=$apps->submit(1,$applicant,$applicationRequest,'test');
$check((int)$pdo->query('SELECT COUNT(*) FROM communication_notices WHERE application_id='.(int)$application['id'])->fetchColumn()===2,'Submitted application queues applicant and operator notices atomically');
$apps->submit(1,$applicant,$applicationRequest,'test');$check((int)$pdo->query('SELECT COUNT(*) FROM communication_notices WHERE application_id='.(int)$application['id'])->fetchColumn()===2,'Application request replay creates no duplicate mail recipes');
$timedOutMail=new GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','global@example.test',function($url,$headers,$body,$mail):array{if(!$mail)return[200,0,'{"access_token":"synthetic","expires_in":3600}'];throw new \Wellness\Service\MailSendException('Synthetic timeout',false,true);});
$check((new CommunicationNoticeWorker($pdo,$timedOutMail,'portal.test'))->run(1)['review']===1,'Unknown email provider outcome requires human review');
$needs=$pdo->query("SELECT id,attempt_count FROM communication_notices WHERE application_id=".(int)$application['id']." AND status='needs_review'")->fetch();
$comm->handle($actor,1,'POST','/notices/'.$needs['id'].'/review',$key(['attempt_count'=>(int)$needs['attempt_count'],'decision'=>'retry','checked_provider_history'=>true,'provider_not_sent'=>true,'reason'=>'Provider positively confirmed no send']),'test');
$noticeWorker->run(100);$applicationMail=end($mails)[1]['message'];
$check(!str_contains(json_encode($applicationMail),'Private declared credential')&&!str_contains(json_encode($applicationMail),'Private Patient'),'Application emails contain no submitted credentials or patient information');
$pdo->exec('RENAME TABLE communication_events TO retained_communication_events');
try{try{$apps->submit(1,new VerifiedIdentity('entra-external-staff','https://synthetic.test','rollback-applicant','test'),$key(array_replace($profile,['email'=>'rollback@example.test'])),'test');throw new RuntimeException('Missing outbox audit accepted');}catch(PDOException){$check(true,'Outbox audit failure rejects application');}}finally{$pdo->exec('RENAME TABLE retained_communication_events TO communication_events');}
$check(!(bool)$pdo->query("SELECT id FROM practitioner_applications WHERE email='rollback@example.test'")->fetchColumn(),'Failed outbox work rolls back applicant state and consent submission');
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");$denied(fn()=>$get(),403,'Revoked operator denied');
$denied(fn()=>$comm->handle($actor,3,'POST','/deliveries/'.$delivery['id'].'/reconcile',$request,'test'),403,'Revocation also blocks idempotent replay');$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
// Real signed-token HTTP dispatch, host boundary and body limits for the new central routes.
$fixture=tempnam(sys_get_temp_dir(),'communications-fixture-');$router=tempnam(sys_get_temp_dir(),'communications-router-');
file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk]));
file_put_contents($router,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).';$f=json_decode(file_get_contents('.var_export($fixture,true).'),true);'. <<<'PHP'
$config=new Wellness\Config('test',false,'test',[],'127.0.0.1',$f['port'],$f['database'],'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',automatedCommunicationsEnabled:!isset($_GET['disabled']),smsBudgetEnforcementEnabled:!isset($_GET['disabled']));
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,fn()=>['keys'=>[$f['jwk']]]));$api->handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes);
try{
    // The inherited HTTP closure captures the address by value: use a new bound client here.
    $call=function(string $host,string $path,?string $token=null,?array $body=null)use($address):array{$curl=curl_init('http://'.$address.$path);$headers=['Host: '.$host];if($token)$headers[]='Authorization: Bearer '.$token;if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,is_string($raw)?json_decode($raw,true):null];};
    for($attempt=0;$attempt<30;$attempt++){if($call('portal.test','/api/v1/health')[0])break;usleep(100000);}
    $token=$signed($workforceClaims);$path='/api/v1/application/clinics/3/communications';
    $check($call('portal.test',$path,$token)[0]===200,'HTTP central administrator reads communications');
    $check($call('portal.test',$path)[0]===401,'HTTP anonymous denied');
    $check($call('communications.test',$path,$token)[0]===404,'Clinic host cannot reach global communications');
    $check($call('portal.test',$path,$signed(array_replace($workforceClaims,['oid'=>'not-an-operator','roles'=>['Wellness.SuperAdmin']])))[0]===403,'Clinic role cannot become central communications authority');
    $check($call('portal.test',$path.'?disabled=1',$token)[0]===503,'HTTP default-off communications gates');
    $check($call('portal.test',$path.'/settings',$token,['reason'=>str_repeat('x',66000)])[0]===413,'Communications request bodies bounded before parse');
}finally{if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}unlink($fixture);unlink($router);}
echo "$checks automated communication SQL/worker checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
