<?php
declare(strict_types=1);
// Explicit disposable localhost fixtures only; no private .env or live provider calls.
$pilotKeys=['PILOT_MODE','PILOT_ALLOWED_IDENTITIES','PILOT_ALLOWED_EMAILS','PILOT_ALLOWED_SMS'];$pilotSaved=[];
foreach($pilotKeys as $pilotKey){$pilotSaved[$pilotKey]=[array_key_exists($pilotKey,$_ENV),$_ENV[$pilotKey]??null,getenv($pilotKey)];unset($_ENV[$pilotKey]);putenv($pilotKey);}
try {
    require __DIR__.'/automated-communications.php';
    $pilotChecks=0;$assertPilot=static function(bool $ok,string $why)use(&$pilotChecks):void{if(!$ok)throw new RuntimeException($why);$pilotChecks++;};
    $pilotSet=static function(string $key,string $value):void{$_ENV[$key]=$value;putenv($key.'='.$value);};
    $pilotSet('PILOT_MODE','true');$pilotSet('PILOT_ALLOWED_IDENTITIES','[]');$pilotSet('PILOT_ALLOWED_EMAILS','[]');$pilotSet('PILOT_ALLOWED_SMS','[]');
    $pdo->exec("UPDATE staff_notification_preferences SET email_enabled=1,email_destination='work',sms_requested=1,mobile_phone='+14165550101' WHERE user_id=200");
    $pilotCalls=0;
    $pilotMailer=new \Wellness\Service\GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','global@example.test',function($url,$headers,$body,$mail)use(&$pilotCalls):array{$pilotCalls++;return $mail?[202,0,'']:[200,0,'{"access_token":"synthetic","expires_in":3600}'];});
    $pilotSms=new \Wellness\Service\VoipMsSmsClient('synthetic@example.test','synthetic','4165550100',function()use(&$pilotCalls):array{$pilotCalls++;return[200,'{"status":"success","sms":"synthetic-pilot"}'];});
    $pilotWorker=new \Wellness\Service\NotificationWorker($pdo,$pilotMailer,'https://communications.test/client',$pilotSms,false,new \Wellness\Service\SmsDeliveryGuard($pdo),new \Wellness\Service\ClinicMailRouter($pdo,$pilotMailer));
    $ledgerBefore=$pdo->query('SELECT * FROM sms_budget_entries ORDER BY id')->fetchAll();
    foreach([false,true] as $email){
        $notice=$create($email);$summary=$pilotWorker->run(1,$notice['appointment']);
        $row=$pdo->query('SELECT status,last_error,sent_at,provider_message_id,next_attempt_at FROM notification_events WHERE id='.(int)$notice['event'])->fetch();
        $assertPilot($summary['canceled']===1&&$row['status']==='canceled'&&str_starts_with($row['last_error']??'','Pilot delivery blocked'),'Pilot blocks queued email/SMS before provider attempt');
        $assertPilot($row['sent_at']===null&&$row['provider_message_id']===null&&$row['next_attempt_at']===null,'Blocked message has no acceptance or automatic retry');
        $assertPilot(!(int)$pdo->query('SELECT COUNT(*) FROM sms_notification_deliveries WHERE notification_event_id='.(int)$notice['event'])->fetchColumn(),'Blocked SMS cannot reserve or claim delivery spend');
        $assertPilot($pilotWorker->run(1,$notice['appointment'])['sent']===0,'Blocked event is terminal');
    }
    $assertPilot($ledgerBefore===$pdo->query('SELECT * FROM sms_budget_entries ORDER BY id')->fetchAll(),'Pilot denials leave ledger entries unchanged');
    $assertPilot($pilotCalls===0,'No OAuth/mail/SMS provider callback for blocked queue');
    $pilotSet('PILOT_ALLOWED_EMAILS','["practitioner@example.test","clinic@example.test","clinic-reply@example.test","global@example.test"]');
    $notice=$create(true);$summary=$pilotWorker->run(1,$notice['appointment']);
    $assertPilot($summary['sent']===1&&$pilotCalls===2,'Approved queued mail retains normal sender routing and provider acceptance');
    $pilotSet('PILOT_ALLOWED_EMAILS','[]');
    $pdo->exec("UPDATE billing_subscriptions SET trial_ends_at=UTC_TIMESTAMP()+INTERVAL 29 DAY+INTERVAL 1 HOUR WHERE id=100");
    (new \Wellness\Service\CommunicationNotices($pdo))->scan();
    $beforeNoticeCalls=$pilotCalls;
    $result=(new \Wellness\Service\CommunicationNoticeWorker($pdo,$pilotMailer,'portal.test'))->run(100);
    $assertPilot($result['canceled']>=1&&$pilotCalls===$beforeNoticeCalls,'Operational outbox also blocks non-test destinations');
    $assertPilot((int)$pdo->query("SELECT COUNT(*) FROM communication_notices WHERE status='canceled' AND last_error='Pilot delivery blocked: destination is not approved.'")->fetchColumn()>=1,'Operational block reason is recorded without destination data');
    $notice=$create();$pilotSet('PILOT_MODE','flase');
    try{$pilotWorker->run(1,$notice['appointment']);throw new LogicException('Invalid pilot configuration accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    $assertPilot($pdo->query('SELECT status FROM notification_events WHERE id='.(int)$notice['event'])->fetchColumn()==='queued','Malformed pilot configuration rejected before claiming work');
    $pilotSet('PILOT_MODE','true');

    // Real HTTP/JWT/DB grant checks with synthetic signing keys; no production bypass route.
    $policyIdentities=[['adapter'=>'entra-workforce','issuer'=>$workforceClaims['iss'],'subject'=>$workforceClaims['oid']],['adapter'=>'entra-workforce','issuer'=>$workforceClaims['iss'],'subject'=>'not-an-operator']];
    $fixture=tempnam(sys_get_temp_dir(),'wellness-pilot-');$router=$fixture.'.php';
    $writePolicy=static function(array $ids)use($fixture,$port,$name,$jwk):void{file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk,'pilot_identities'=>$ids],JSON_THROW_ON_ERROR));};$writePolicy($policyIdentities);
    file_put_contents($router,'<?php if(PHP_SAPI!=="cli-server")exit;require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).';$f=json_decode(file_get_contents('.var_export($fixture,true).'),true);'. <<<'PHP'
if(!preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D',$f['database']))throw new RuntimeException('Disposable database only.');
$_ENV['PILOT_MODE']='true';$_ENV['PILOT_ALLOWED_IDENTITIES']=json_encode($f['pilot_identities']);$_ENV['PILOT_ALLOWED_EMAILS']='[]';$_ENV['PILOT_ALLOWED_SMS']='[]';
$config=new Wellness\Config('test',false,'synthetic',[],'127.0.0.1',$f['port'],$f['database'],'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',automatedCommunicationsEnabled:true);
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,fn()=>['keys'=>[$f['jwk']]]));$api->handle();
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes);
    try {
        $requestPilot=static function(string $host,string $path,?string $token=null,?array $body=null)use($address):array{$curl=curl_init('http://'.$address.$path);$headers=['Host: '.$host];if($token)$headers[]='Authorization: Bearer '.$token;if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,is_string($raw)?json_decode($raw,true):null];};
        for($i=0;$i<50;$i++){if($requestPilot('portal.test','/api/v1/health')[0])break;usleep(100000);}
        $token=$signed($workforceClaims);$path='/api/v1/application/clinics/3/communications';
        $assertPilot($requestPilot('portal.test',$path,$token)[0]===200,'Approved pilot operator retains explicit existing grant');
        [$status,$data]=$requestPilot('portal.test',$path,$signed(array_replace($workforceClaims,['oid'=>'unapproved','email'=>'practitioner@example.test','roles'=>['Wellness.SuperAdmin']])));
        $assertPilot($status===403&&$data['error']['code']==='pilot_access_denied','Valid unapproved JWT cannot reach central records');
        [$status,$data]=$requestPilot('portal.test',$path,$signed(array_replace($workforceClaims,['oid'=>'not-an-operator'])));
        $assertPilot($status===403&&$data['error']['code']==='application_admin_required','Pilot identity allowlist never grants application administrator authority');
        $assertPilot($requestPilot('portal.test',$path)[0]===401,'Anonymous protected request remains unauthorized');
        $writePolicy([]);[$status,$data]=$requestPilot('portal.test',$path,$token);
        $assertPilot($status===403&&$data['error']['code']==='pilot_access_denied','Revocation denies previously valid operator token on next request');
        [$status,$data]=$requestPilot('communications.test','/api/v1/public/practitioner-enquiries',null,['full_name'=>'Synthetic']);
        $assertPilot($status===403&&$data['error']['code']==='pilot_access_denied','Anonymous public enquiry submissions paused in pilot');
        $serialized=json_encode($data);$assertPilot(!str_contains($serialized,$workforceClaims['iss'])&&!str_contains($serialized,'pilot_identities'),'Denial response does not expose approved identities');
    } finally {if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}unlink($fixture);unlink($router);}
    echo "$pilotChecks pilot safety SQL/worker/HTTP checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
} finally {
    foreach($pilotSaved as $pilotKey=>[$present,$value,$processValue]){if($present)$_ENV[$pilotKey]=$value;else unset($_ENV[$pilotKey]);if($processValue===false)putenv($pilotKey);else putenv($pilotKey.'='.$processValue);}
}
