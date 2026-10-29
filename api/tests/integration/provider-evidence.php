<?php
declare(strict_types=1);
require __DIR__.'/automated-communications.php';
use Wellness\Config;
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\Service\{ProviderEvidenceService,SmsDeliveryGuard,NotificationWorker,GraphMailClient,VoipMsSmsClient};

$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/057_provider_evidence.sql'));
$config=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',providerEvidenceEnabled:true);
$evidence=new ProviderEvidenceService($database,$config);$checks=0;
$check=function(bool $condition,string $why)use(&$checks):void{if(!$condition)throw new RuntimeException($why);$checks++;};
$deny=function(callable $work,int $status,string $why)use($check):void{try{$work();}catch(\Wellness\Http\ApiException $e){$check($e->status===$status,$why.': '.$e->errorCode);return;}throw new RuntimeException($why.' accepted');};
$header="type,reference,sender_did,destination,occurred_at,currency,amount\n";
$postEvidence=fn(string $path,array $body)=>$evidence->handle($actor,3,'POST',$path,$key($body+['reason'=>'Reviewed synthetic provider evidence']),'test');
$preview=function(string $csv)use($postEvidence):array{return $postEvidence('/preview',['csv'=>$csv,'statement_reference'=>'Synthetic statement','usd_cad_rate'=>'1.370000','fx_date'=>gmdate('Y-m-d'),'fx_reference'=>'Synthetic verified conversion']);};
$apply=fn(array $batch)=>$postEvidence('/'.$batch['id'].'/apply',['preview_hash'=>$batch['preview_hash'],'provider_records_verified'=>true,'conversion_verified'=>true]);
$pdo->exec("UPDATE sms_budget_member_periods SET spent_cents=100,reserved_cents=0 WHERE pool_id=10 AND membership_id=100;
 UPDATE clinic_communications SET cad_micros_per_segment=10000,revision=revision+1 WHERE clinic_id=3");
$sendCount=0;$smsClient=new VoipMsSmsClient('synthetic@example.test','synthetic','4165550100',function()use(&$sendCount):array{$sendCount++;return[200,json_encode(['status'=>'success','sms'=>'evidence-'.$sendCount])];});
$guardedWorker=new NotificationWorker($pdo,$mailer,'https://communications.test/client',$smsClient,false,new SmsDeliveryGuard($pdo,true));
$sent=$create();$check($guardedWorker->run(1,$sent['appointment'])['sent']===1,'Prepared one bounded fake provider delivery');
$csv=$header.'charge,evidence-1,4165550199,+14165550101,'.gmdate('Y-m-d H:i:s').',USD,0.0175';
$caseMismatch=$preview(str_replace('evidence-1','EVIDENCE-1',$csv));$check(!$caseMismatch['can_apply']&&$caseMismatch['rows'][0]['status']==='unmatched','Provider reference matching is byte-exact despite database collation');
$batch=$preview($csv);$check($batch['can_apply']&&$batch['rows'][0]['actual_cents']===3,'Exact match and immutable conversion preview');
$check(!str_contains($pdo->query('SELECT rows_json FROM provider_evidence_batches WHERE id='.$batch['id'])->fetchColumn(),'+14165550101'),'Stored normalized evidence excludes raw destinations');
$check($pdo->query('SELECT actual_cents FROM sms_budget_entries WHERE pool_id=10 AND reference=(SELECT reference FROM sms_notification_deliveries WHERE notification_event_id='.$sent['event'].')')->fetchColumn()===null,'Preview has no financial side effect');
$deny(fn()=>$evidence->handle($actor,2,'GET','/'.$batch['id'],[],'test'),404,'Wrong clinic cannot read evidence');
$deny(fn()=>$postEvidence('/'.$batch['id'].'/apply',['preview_hash'=>$batch['preview_hash'],'provider_records_verified'=>false,'conversion_verified'=>true]),422,'Apply requires provider review');
$request=$key(['preview_hash'=>$batch['preview_hash'],'provider_records_verified'=>true,'conversion_verified'=>true,'reason'=>'Approved exact source and conversion']);
$result=$evidence->handle($actor,3,'POST','/'.$batch['id'].'/apply',$request,'test');$check($result['charged']===1,'Applied exact known provider charge');
$check($evidence->handle($actor,3,'POST','/'.$batch['id'].'/apply',$request,'test')===$result,'Apply replay neither charges nor audits twice');
$check((int)$pdo->query('SELECT actual_cents FROM sms_budget_entries WHERE pool_id=10 AND reference=(SELECT reference FROM sms_notification_deliveries WHERE notification_event_id='.$sent['event'].')')->fetchColumn()===3,'Actual CAD cost replaces original reservation');
$duplicate=$preview($csv);$check($duplicate['rows'][0]['status']==='already_recorded'&&$apply($duplicate)['charged']===0,'Repeated statement is a monetary no-op');
$changedFx=$postEvidence('/preview',['csv'=>$csv,'statement_reference'=>'Different quote','usd_cad_rate'=>'1.400000','fx_date'=>gmdate('Y-m-d'),'fx_reference'=>'Different conversion evidence']);$check(!$changedFx['can_apply']&&$changedFx['rows'][0]['status']==='conflict','New exchange evidence cannot reprice a recorded charge');
$unknown=$preview(str_replace('evidence-1','unknown-id',$csv));$check(!$unknown['can_apply']&&$unknown['rows'][0]['status']==='unmatched','Absent provider ID does not prove no-send');$deny(fn()=>$apply($unknown),409,'Unmatched batch cannot be partially applied');
$wrong=$preview(str_replace('+14165550101','+14165550102',$csv));$check(!$wrong['can_apply'],'Destination mismatch rejected');
$deny(fn()=>$preview(str_replace('4165550199','4165550188',$csv)),422,'Foreign sender cannot import STOP/charges without a match');
// A stale preview cannot overwrite a manual settlement, and a failed audit rolls back money.
$second=$create();$guardedWorker->run(1,$second['appointment']);$secondCsv=str_replace('evidence-1','evidence-2',$csv);$fresh=$preview($secondCsv);
$pdo->exec("UPDATE sms_notification_deliveries SET provider_message_id='evidence-1' WHERE notification_event_id=".$second['event']);
try{$ambiguous=$preview($csv);$check(!$ambiguous['can_apply']&&$ambiguous['rows'][0]['status']==='conflict','Duplicate provider references cannot select an arbitrary message');}finally{$pdo->exec("UPDATE sms_notification_deliveries SET provider_message_id='evidence-2' WHERE notification_event_id=".$second['event']);}
$pdo->exec('RENAME TABLE communication_events TO retained_communication_events');
try{try{$apply($fresh);throw new RuntimeException('Missing evidence audit accepted');}catch(PDOException){$check(true,'Apply fails without its audit');}}finally{$pdo->exec('RENAME TABLE retained_communication_events TO communication_events');}
$check(!(bool)$pdo->query('SELECT 1 FROM provider_evidence_charges WHERE notification_event_id='.$second['event'])->fetchColumn(),'Audit failure rolls back charge evidence');
$check($pdo->query('SELECT actual_cents FROM sms_budget_entries WHERE pool_id=10 AND reference=(SELECT reference FROM sms_notification_deliveries WHERE notification_event_id='.$second['event'].')')->fetchColumn()===null,'Audit failure rolls back the actual monetary settlement');
$check($pdo->query('SELECT status FROM provider_evidence_batches WHERE id='.$fresh['id'])->fetchColumn()==='preview','Audit failure preserves retryable preview');$apply($fresh);
$third=$create();$guardedWorker->run(1,$third['appointment']);$stale=$preview(str_replace('evidence-1','evidence-3',$csv));
$post('/deliveries/'.$third['event'].'/reconcile',['outcome'=>'provider_accepted','actual_cents'=>9,'expected_actual_cents'=>null,'checked_provider_history'=>true]);$deny(fn()=>$apply($stale),409,'Stale preview cannot overwrite manual cost');
$beforeLink=$pdo->query('SELECT spent_cents FROM sms_budget_member_periods WHERE pool_id=10 AND membership_id=100')->fetchColumn();
$linked=$preview(str_replace(['evidence-1','0.0175'],['evidence-3','0.06569'],$csv));$check($linked['can_apply']&&$apply($linked)['charged']===1,'Fresh equal-cost evidence may link a manual settlement without charging twice');
$check($pdo->query('SELECT spent_cents FROM sms_budget_member_periods WHERE pool_id=10 AND membership_id=100')->fetchColumn()===$beforeLink,'Attaching equal-cost evidence does not increase member spending');
// Normalized STOP evidence suppresses the sender across clinic boundaries.
$stopAt=gmdate('Y-m-d H:i:s',time()-120);$stop=$header.'stop,stop-1,4165550199,+14165550101,'.$stopAt.',,';$stopBatch=$preview($stop);$check($apply($stopBatch)['stops']===1,'Imported exact control event');
$before=$sendCount;$held=$create();$check($guardedWorker->run(1,$held['appointment'])['canceled']===1&&$sendCount===$before,'Imported STOP blocks before provider call');
$pdo->exec("INSERT INTO clinics(id,name) VALUES(4,'Shared sender clinic');INSERT INTO clinic_hosts(host,clinic_id) VALUES('shared.test',4);
 INSERT INTO clinic_communications(clinic_id,sms_enabled,sms_from_did,cad_micros_per_segment,tariff_valid_until,opt_out_checked_at) VALUES(4,1,'4165550199',10000,UTC_TIMESTAMP()+INTERVAL 1 DAY,UTC_TIMESTAMP());
 INSERT INTO locations(id,clinic_id,name,timezone) VALUES(4,4,'Virtual','America/Toronto');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(202,4,'shared-staff@example.test','Shared staff','staff','active'),(203,4,'shared-client@example.test','Shared client','client','active');
 INSERT INTO practitioners(id,user_id,discipline) VALUES(202,202,'Synthetic');INSERT INTO user_roles(user_id,role_id,location_id) SELECT 202,id,4 FROM roles WHERE code='practitioner';
 INSERT INTO product_identities(id,adapter,issuer,subject) VALUES(300,'synthetic','test','shared-member');INSERT INTO staff_memberships(id,identity_id,clinic_id,local_user_id,status) VALUES(300,300,4,202,'active');
 INSERT INTO staff_notification_preferences(user_id,sms_requested,mobile_phone) VALUES(202,1,'+14165550101');
 INSERT INTO billing_accounts(id,clinic_id,membership_id) VALUES(300,4,300);INSERT INTO billing_subscriptions(billing_account_id,plan_version_id,status,starts_at,trial_ends_at) VALUES(300,3,'trialing',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 1 MONTH);
 INSERT INTO sms_budget_pools(id,clinic_id,status) VALUES(11,4,'active');INSERT INTO sms_budget_members(pool_id,clinic_id,membership_id) VALUES(11,4,300);
 INSERT INTO services(id,clinic_id,name,slug,price_cents) VALUES(101,4,'Synthetic','synthetic',1000);
 INSERT INTO appointments(clinic_id,location_id,client_id,practitioner_id,service_id,starts_at,ends_at,buffer_starts_at,buffer_ends_at,status,source,created_by) VALUES(4,4,203,202,101,UTC_TIMESTAMP()+INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY+INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 2 DAY,UTC_TIMESTAMP()+INTERVAL 2 DAY+INTERVAL 1 HOUR,'confirmed','practitioner',202)");$sharedAppointment=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,scheduled_at,payload) VALUES(4,?,202,'+14165550101','staff_booking_confirmation','sms',UTC_TIMESTAMP(),'{}')")->execute([$sharedAppointment]);
(new \Wellness\Service\SmsBudgetLedger($pdo))->sharedCost(11,'reviewed-overhead',0,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$check($guardedWorker->run(1,$sharedAppointment)['canceled']===1&&$sendCount===$before,'Shared DID STOP applies to another clinic without granting clinical access');
$clear=['sender_did'=>'4165550199','destination'=>'+14165550101','opt_in_at'=>gmdate('Y-m-d H:i:s',time()-90),'provider_clearance_verified'=>true];
$deny(fn()=>$postEvidence('/clear-stop',array_replace($clear,['opt_in_at'=>$stopAt])),409,'Old consent cannot clear a newer STOP');
$post('/suppression',['number'=>'+14165550101','active'=>true]);$postEvidence('/clear-stop',$clear);$localHeld=$create();$check($guardedWorker->run(1,$localHeld['appointment'])['canceled']===1&&$sendCount===$before,'Provider clearance preserves independent local manual suppression');
$post('/suppression',['number'=>'+14165550101','active'=>false,'opt_in_confirmed'=>true]);$after=$create();$check($guardedWorker->run(1,$after['appointment'])['sent']===1,'Reviewed newer opt-in can clear provider suppression');
$late=$preview(str_replace(['stop-1',$stopAt],['stop-old',gmdate('Y-m-d H:i:s',time()-180)],$stop));$apply($late);
$row=$pdo->query("SELECT last_stop_at,cleared_after FROM provider_sender_stops WHERE sender_did='4165550199'")->fetch();$check($row['last_stop_at']<$row['cleared_after'],'Late older STOP cannot override newer verified consent');
$newStop=$preview(str_replace(['stop-1',$stopAt],['stop-new',gmdate('Y-m-d H:i:s',time()-30)],$stop));$apply($newStop);$before=$sendCount;$held=$create();$check($guardedWorker->run(1,$held['appointment'])['canceled']===1&&$sendCount===$before,'A later STOP restores the suppression');
$deny(fn()=>$postEvidence('/clear-stop',$clear),409,'Stale consent still cannot clear later STOP');
$mixed=$preview(str_replace('evidence-1','missing-charge',$csv)."\n".'stop,should-not-apply,4165550199,+14165550102,'.gmdate('Y-m-d H:i:s',time()-60).',,');$deny(fn()=>$apply($mixed),409,'Mixed invalid batch is atomic');
$check(!(bool)$pdo->query("SELECT 1 FROM provider_sender_stops WHERE number_hash='".hash('sha256','+14165550102')."'")->fetchColumn(),'Rejected batch has no suppression side effect');
$reordered=$preview($stop."\n".substr($csv,strlen($header)));$check($reordered['rows'][0]['status']==='already_recorded'&&$reordered['rows'][1]['status']==='already_recorded','Evidence identity is independent of CSV row order');
// A STOP import in one clinic serializes with another clinic's real worker on the shared DID.
$otherPdo=(new \Wellness\Database($config))->connection();$otherDb=new \Wellness\Database($config);(new ReflectionProperty(\Wellness\Database::class,'connection'))->setValue($otherDb,$otherPdo);$otherEvidence=new ProviderEvidenceService($otherDb,$config);
$raceNumber='+14165550103';$raceCsv=$header.'stop,stop-race,4165550199,'.$raceNumber.','.gmdate('Y-m-d H:i:s').',,';
$raceBatch=$otherEvidence->handle($actor,4,'POST','/preview',$key(['csv'=>$raceCsv,'statement_reference'=>'Concurrent STOP','reason'=>'Provider control event verified']),'test');
$otherConsent=new \Wellness\Service\ConsentLock($otherPdo);$otherSender=new \Wellness\Service\ProviderSenderLock($otherPdo);$otherConsent->acquire(4);$otherSender->acquire(['4165550199']);
$pdo->exec("UPDATE staff_notification_preferences SET mobile_phone='$raceNumber' WHERE user_id=200");$race=$create();$pdo->exec("UPDATE notification_events SET recipient_address='$raceNumber' WHERE id=".$race['event']);
$originalFlag=getenv('PROVIDER_EVIDENCE_ENABLED');$arrayFlag=$_ENV['PROVIDER_EVIDENCE_ENABLED']??null;unset($_ENV['PROVIDER_EVIDENCE_ENABLED']);putenv('PROVIDER_EVIDENCE_ENABLED=true');
$beforeCalls=(int)$pdo->query('SELECT COUNT(*) FROM synthetic_sms_calls')->fetchColumn();$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/automated-communications.php','sms-worker',$name],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fclose($pipes[0]);
try{
    for($i=0;$i<30;$i++){$status=$pdo->query('SELECT status FROM notification_events WHERE id='.$race['event'])->fetchColumn();if($status==='sending')break;usleep(20000);}
    $check($status==='sending','Concurrent worker claimed the event while awaiting shared sender authority');
    $otherEvidence->handle($actor,4,'POST','/'.$raceBatch['id'].'/apply',$key(['preview_hash'=>$raceBatch['preview_hash'],'provider_records_verified'=>true,'conversion_verified'=>true,'reason'=>'Apply provider STOP while another clinic waits']),'test');
}finally{$otherSender->release();$otherConsent->release();}
$workerResult=json_decode(stream_get_contents($pipes[1]),true);$workerError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException($workerError);
$check($workerResult['sent']===0&&$workerResult['canceled']===1&&(int)$pdo->query('SELECT COUNT(*) FROM synthetic_sms_calls')->fetchColumn()===$beforeCalls,'Shared-sender STOP committed before provider submission wins the actual process race');
$pdo->exec("UPDATE staff_notification_preferences SET mobile_phone='+14165550101' WHERE user_id=200");$test=$create();$before=$sendCount;
$defaultWorker=new NotificationWorker($pdo,$mailer,'https://communications.test/client',$smsClient);$check($defaultWorker->run(1,$test['appointment'])['canceled']===1&&$sendCount===$before,'Evidence flag alone cannot fall back to unguarded legacy SMS');
if($originalFlag===false)putenv('PROVIDER_EVIDENCE_ENABLED');else putenv('PROVIDER_EVIDENCE_ENABLED='.$originalFlag);if($arrayFlag!==null)$_ENV['PROVIDER_EVIDENCE_ENABLED']=$arrayFlag;
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");$deny(fn()=>$evidence->handle($actor,3,'GET','',[],'test'),403,'Revoked operator cannot read provider evidence');$deny(fn()=>$evidence->handle($actor,3,'POST','/'.$batch['id'].'/apply',$request,'test'),403,'Revocation blocks replay');$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
// Dispatch real signed tokens through the central host and bounded request parser.
$fixture=tempnam(sys_get_temp_dir(),'provider-fixture-');$router=tempnam(sys_get_temp_dir(),'provider-router-');
file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk]));
file_put_contents($router,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).';$f=json_decode(file_get_contents('.var_export($fixture,true).'),true);'. <<<'PHP'
$config=new Wellness\Config('test',false,'test',[],'127.0.0.1',$f['port'],$f['database'],'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',providerEvidenceEnabled:!isset($_GET['disabled']));
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,fn()=>['keys'=>[$f['jwk']]]));$api->handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes);
try{
    $call=function(string $host,string $path,?string $token=null,?array $body=null)use($address):array{$curl=curl_init('http://'.$address.$path);$headers=['Host: '.$host];if($token)$headers[]='Authorization: Bearer '.$token;if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,is_string($raw)?json_decode($raw,true):null];};
    for($attempt=0;$attempt<30;$attempt++){if($call('portal.test','/api/v1/health')[0])break;usleep(100000);}
    $token=$signed($workforceClaims);$path='/api/v1/application/clinics/3/provider-evidence';
    $check($call('portal.test',$path,$token)[0]===200,'HTTP central administrator reads provider batches');
    $check($call('portal.test',$path)[0]===401,'HTTP anonymous provider access denied');
    $check($call('communications.test',$path,$token)[0]===404,'Clinic host cannot reach provider evidence');
    $check($call('portal.test',$path,$signed(array_replace($workforceClaims,['oid'=>'not-an-operator','roles'=>['Wellness.SuperAdmin']])))[0]===403,'Clinic role cannot become provider authority');
    $check($call('portal.test',$path.'?disabled=1',$token)[0]===503,'HTTP default-off provider gate');
    $check($call('portal.test',$path.'/preview',$token,['csv'=>str_repeat('x',66000)])[0]===413,'Provider body limit applied before parse');
    $request=$key(['csv'=>$csv,'statement_reference'=>'HTTP reviewed statement','usd_cad_rate'=>'1.370000','fx_date'=>gmdate('Y-m-d'),'fx_reference'=>'Synthetic verified conversion','reason'=>'HTTP matching provider evidence']);
    [$status,$response]=$call('portal.test',$path.'/preview',$token,$request);$check($status===200&&$response['data']['rows'][0]['status']==='already_recorded','HTTP normalized preview uses persisted receipt');
    $check($call('portal.test',str_replace('/3/','/2/',$path).'/'.$response['data']['id'],$token)[0]===404,'HTTP provider batch remains clinic scoped');
}finally{if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}unlink($fixture);unlink($router);}
echo "$checks provider evidence SQL/worker/HTTP checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
