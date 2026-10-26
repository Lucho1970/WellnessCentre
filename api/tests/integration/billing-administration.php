<?php
declare(strict_types=1);
// Includes both engine/concurrency acceptance and its synthetic localhost fixture. No .env.
require __DIR__.'/subscription-foundation.php';

use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\Config;
use Wellness\Http\ApiException;
use Wellness\Service\BillingAdministrationService;

$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/049_access_audit_events.sql'));
$pdo->exec("INSERT INTO application_administrators(id,issuer,subject,display_name,email) VALUES
 (1,'https://operator.test','operator','Synthetic operator','operator@example.test'),
 (2,'https://operator.test','other','Other operator','other@example.test')");
$actor=new ApplicationAdminContext(1,new VerifiedIdentity('synthetic','https://operator.test','operator','test'),'Synthetic operator','operator@example.test');
$billingConfig=new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','trusted-tenant','api','scope',300,
    clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',billingAdminEnabled:true);
$billing=new BillingAdministrationService($database,$billingConfig);
$billingChecks=0;
$expect=function(bool $ok,string $why)use(&$billingChecks):void { if(!$ok)throw new RuntimeException($why);$billingChecks++; };
$ref=fn()=>sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
$post=fn(string $path,array $body)=>$billing->handle($actor,'POST',$path,[],$body+['request_key'=>$ref(),'reason'=>'Reviewed synthetic change'],'billing-test');
$get=fn(string $path,array $query=[])=>$billing->handle($actor,'GET',$path,$query,[],'billing-test');
$reject=function(callable $work,int $status,string $why)use($expect):void {
    try{$work();}catch(ApiException $e){$expect($e->status===$status,$why.': '.$e->errorCode);return;}throw new RuntimeException($why.' was accepted');
};
$off=new BillingAdministrationService($database,new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','','','',300,clinicManagementEnabled:true,applicationAdminEnabled:true));
$reject(fn()=>$off->handle($actor,'GET','',[],[],'test'),503,'Flag defaults off');
$forged=new ApplicationAdminContext(1,new VerifiedIdentity('synthetic','https://operator.test','imposter','test'),'Fake','operator@example.test');
$reject(fn()=>$billing->handle($forged,'GET','',[],[],'test'),403,'Current immutable grant required');
$expect($get('')['plans']===[],'Empty catalogue does not seed prices');
$base=['code'=>'beta-member','display_name'=>'Beta member','scope'=>'practitioner','base_monthly_cents'=>null,'features'=>['booking'=>true,'sms_reminders'=>false]];
$key=$ref();$request=$base+['request_key'=>$key,'reason'=>'Approved synthetic plan'];
$p1=$billing->handle($actor,'POST','/plans',[],$request,'test');
$expect($billing->handle($actor,'POST','/plans',[],$request,'test')===$p1,'Idempotent plan retry');
$reject(fn()=>$billing->handle($actor,'POST','/plans',[],array_replace($request,['display_name'=>'Changed']),'test'),409,'Request key cannot change its payload');
$p2=$post('/plans',array_replace($base,['base_monthly_cents'=>3000,'features'=>['booking'=>true,'sms_reminders'=>true]]));
$expect($p1['version']===1 && $p2['version']===2,'Versioned plans append');
$expect($pdo->query('SELECT base_monthly_cents FROM subscription_plan_versions WHERE id='.$p1['id'])->fetchColumn()===null,'Unknown historical rate remains unknown');
$reject(fn()=>$post('/plans',array_replace($base,['scope'=>'tenant'])),409,'Plan scope cannot change');
foreach([['features'=>['sms_reminders'=>'yes']],['features'=>['read_patient_notes'=>true]],['base_monthly_cents'=>-1],['currency'=>'USD'],['reason'=>'x']] as $bad)
    $reject(fn()=>$post('/plans',array_replace($base,$bad)),422,'Unsafe plan input is rejected');
$expect((int)$pdo->query('SELECT COUNT(*) FROM subscription_plan_versions')->fetchColumn()===2,'Rejected mutations leave no plan versions');
$clinicPlan=$post('/plans',array_replace($base,['code'=>'clinic-beta','display_name'=>'Clinic beta','scope'=>'tenant']));
$reject(fn()=>$post('/clinics/1/subscriptions',['membership_id'=>13,'plan_version_id'=>$p1['id'],'status'=>'pending','timezone'=>'America/Toronto']),422,'Foreign practitioner denied');
$reject(fn()=>$post('/clinics/1/subscriptions',['membership_id'=>1,'plan_version_id'=>$clinicPlan['id'],'status'=>'pending','timezone'=>'America/Toronto']),422,'Subscription and plan scopes must match');
$reject(fn()=>$post('/clinics/1/subscriptions',['membership_id'=>1,'plan_version_id'=>$p1['id'],'status'=>'active','timezone'=>'America/Toronto']),422,'Cannot fabricate paid status');
$pdo->exec("INSERT INTO billing_accounts(clinic_id,membership_id,currency) VALUES(1,2,'USD')");
$reject(fn()=>$post('/clinics/1/subscriptions',['membership_id'=>2,'plan_version_id'=>$p1['id'],'status'=>'pending','timezone'=>'America/Toronto']),409,'Account and plan currencies must match');
$s=$post('/clinics/1/subscriptions',['membership_id'=>1,'plan_version_id'=>$p1['id'],'status'=>'pending','timezone'=>'America/Toronto']);
$reject(fn()=>$post('/clinics/1/subscriptions',['membership_id'=>1,'plan_version_id'=>$p2['id'],'status'=>'pending','timezone'=>'America/Toronto']),409,'Only one open subscription per owner');
$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'activate_trial','version'=>1]);
$rows=$get('/clinics/1')['subscriptions'];$trial=$rows[0];
$expect($trial['status']==='trialing' && $trial['allows_new_activity']===true,'Explicit activation starts usable six-month policy interval');
$reject(fn()=>$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'suspend','version'=>1]),409,'Stale version cannot mutate subscription');
$reject(fn()=>$post('/clinics/2/subscriptions/'.$s['id'],['action'=>'suspend','version'=>2]),404,'Direct foreign subscription ID denied');
$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'suspend','version'=>2]);
$expect(!$get('/clinics/1')['subscriptions'][0]['allows_new_activity'],'Suspended subscription policy denies new activity');
$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'resume','version'=>3]);
$last=(new DateTimeImmutable($trial['trial_ends_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Toronto'))->modify('+2 days')->format('Y-m-d');
$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'extend','version'=>4,'trial_last_day'=>$last]);
$extended=$get('/clinics/1')['subscriptions'][0];
$expect($extended['trial_ends_at']>$trial['trial_ends_at'],'Trial extension uses original timezone and preserves prior audit');
$post('/clinics/1/subscriptions/'.$s['id'],['action'=>'cancel','version'=>5]);
$expect($get('/clinics/1')['subscriptions'][0]['status']==='canceled','Explicit cancellation recorded');
$expect((int)$pdo->query('SELECT COUNT(*) FROM billing_admin_events WHERE action LIKE "subscription.%"')->fetchColumn()===6,'Every subscription mutation audited');
$post('/clinics/1/subscriptions',['membership_id'=>null,'plan_version_id'=>$clinicPlan['id'],'status'=>'pending','timezone'=>'America/Toronto']);
$expect($get('/clinics/1')['subscriptions'][0]['membership_id']===null,'Clinic owner is separate from practitioner');
$pool=$get('/clinics/1',['month'=>'2026-10'])['pool'];
$poolBody=['revision'=>(int)$pool['revision'],'timezone'=>'America/Toronto','monthly_limit_cents'=>20000,'member_limit_cents'=>2000,'max_members'=>10,'status'=>'inactive'];
$post('/clinics/1/pool',$poolBody);
$current=$get('/clinics/1',['month'=>'2026-10']);
$expect($current['summary']['limit_cents']===10000 && $current['pool']['monthly_limit_cents']==20000,'Limit edits do not rewrite an existing month');
$expect($current['summary']['threshold']===100 && $current['summary']['over_limit_cents']===1,'Over-limit cost alert includes provider adjustments');
$expect($current['summary']['overhead_cents']===8401,'Shared overhead is visible');
$reject(fn()=>$post('/clinics/1/pool',$poolBody),409,'Pool revision conflicts');
$reject(fn()=>$post('/clinics/1/members',['revision'=>(int)$current['pool']['revision'],'membership_id'=>11,'action'=>'enroll']),409,'Cohort cap enforced');
$post('/clinics/1/members',['revision'=>(int)$current['pool']['revision'],'membership_id'=>10,'action'=>'remove']);
$current=$get('/clinics/1',['month'=>'2026-10']);
$post('/clinics/1/members',['revision'=>(int)$current['pool']['revision'],'membership_id'=>11,'action'=>'enroll']);
$future=$get('/clinics/1',['month'=>'2028-01']);
$expect($future['summary']['spent_cents']===0 && $future['summary']['limit_cents']===20000,'New month shows new defaults with no carryover');
$post('/clinics/1/overhead',['action'=>'reserve','amount_cents'=>1500,'reference'=>'future-number-rental','month'=>'2028-01']);
$expect($get('/clinics/1',['month'=>'2028-01'])['summary']['reserved_cents']===1500,'Reserve overhead before granting message allowance');
$post('/clinics/1/overhead',['action'=>'settle','amount_cents'=>1700,'reference'=>'future-number-rental','month'=>'2028-01']);
$future=$get('/clinics/1',['month'=>'2028-01']);
$expect($future['summary']['reserved_cents']===0 && $future['summary']['spent_cents']===1700,'Confirm actual overhead without double counting');
$post('/clinics/1/overhead',['action'=>'record','amount_cents'=>20000,'reference'=>'provider-adjustment','month'=>'2028-01']);
$expect($get('/clinics/1',['month'=>'2028-01'])['summary']['over_limit_cents']===1700,'Actual overhead above cap is recorded honestly');
$reject(fn()=>$post('/clinics/1/overhead',['action'=>'reserve','amount_cents'=>1,'reference'=>'over-cap','month'=>'2028-01']),409,'No new commitments beyond cap');
$post('/clinics/1/overhead',['action'=>'correct','amount_cents'=>18000,'expected_actual_cents'=>20000,'reference'=>'provider-adjustment','month'=>'2028-01']);
$expect($get('/clinics/1',['month'=>'2028-01'])['summary']['spent_cents']===19700,'Audited correction replaces a mistaken overhead amount without double counting');
$details=json_decode($pdo->query("SELECT details FROM billing_admin_events WHERE action='sms.overhead.correct' ORDER BY id DESC LIMIT 1")->fetchColumn(),true);
$expect((int)$details['previous_actual_cents']===20000,'Correction retains the previous amount in audit history');
$reject(fn()=>$post('/clinics/1/overhead',['action'=>'correct','amount_cents'=>17000,'expected_actual_cents'=>20000,'reference'=>'provider-adjustment','month'=>'2028-01']),409,'Stale overhead correction cannot overwrite a newer reconciliation');
$post('/clinics/1/overhead',['action'=>'record','amount_cents'=>0,'reference'=>'february-open','month'=>'2028-02']);
$latestPool=$get('/clinics/1')['pool'];
$post('/clinics/1/pool',array_replace($poolBody,['revision'=>(int)$latestPool['revision'],'member_limit_cents'=>3000,'status'=>'active']));
$reject(fn()=>$ledger->reserve(1,11,'snapshot-over',2100,new DateTimeImmutable('2028-02-15T12:00:00Z')),409,'A newly funded member keeps the existing period allowance');
$ledger->reserve(1,11,'snapshot-limit',2000,new DateTimeImmutable('2028-02-15T12:00:00Z'));
$february=$get('/clinics/1',['month'=>'2028-02']);
$funded=array_values(array_filter($february['members'],fn($member)=>(int)$member['membership_id']===11))[0];
$expect((int)$funded['limit_cents']===2000,'Member allowance snapshot is used by both ledger and dashboard');
$reject(fn()=>$get('/clinics/1',['month'=>'2026-13']),422,'Invalid month denied');
$reject(fn()=>$get('/clinics/999'),404,'Unknown clinic denied');
$expect($get('/clinics/2')['members']===[],'Dashboard is clinic scoped');

// Price and financial mutation must roll back if its audit cannot be persisted.
$before=(int)$pdo->query('SELECT COUNT(*) FROM subscription_plan_versions')->fetchColumn();
$pdo->exec('RENAME TABLE billing_admin_events TO retained_billing_admin_events');
try { try{$post('/plans',array_replace($base,['code'=>'audit-failure']));throw new RuntimeException('Missing audit accepted');}catch(PDOException){$expect(true,'Audit failure denies mutation');} }
finally{$pdo->exec('RENAME TABLE retained_billing_admin_events TO billing_admin_events');}
$expect((int)$pdo->query('SELECT COUNT(*) FROM subscription_plan_versions')->fetchColumn()===$before,'Plan/audit/idempotency are atomic');
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
$reject(fn()=>$get(''),403,'Revoked operator cannot read financial data');
$reject(fn()=>$billing->handle($actor,'POST','/plans',[],$request,'test'),403,'Revocation also denies replay of previously valid request');
$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");

// Real HTTP dispatch proves host boundary, signed-token authentication and disabled gating.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$key)throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($key)['rsa'];$jwk=['kty'=>'RSA','kid'=>'billing','n'=>\Firebase\JWT\JWT::urlsafeB64Encode($rsa['n']),'e'=>\Firebase\JWT\JWT::urlsafeB64Encode($rsa['e'])];
$claims=['iss'=>'https://login.microsoftonline.com/trusted-tenant/v2.0','tid'=>'trusted-tenant','aud'=>'api','oid'=>'operator','scp'=>'scope','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
$pdo->exec("UPDATE application_administrators SET issuer='https://login.microsoftonline.com/trusted-tenant/v2.0' WHERE id=1");
$token=\Firebase\JWT\JWT::encode($claims,$key,'RS256','billing');
$fixture=tempnam(sys_get_temp_dir(),'billing-fixture-');$router=tempnam(sys_get_temp_dir(),'billing-router-');
file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk]));
file_put_contents($router,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).'; $f=json_decode(file_get_contents('.var_export($fixture,true).'),true);' . <<<'PHP'
$config=new Wellness\Config('test',false,'test',[],'127.0.0.1',$f['port'],$f['database'],'root','','trusted-tenant','api','scope',300,clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',billingAdminEnabled:!isset($_GET['disabled']));
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);
(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,fn()=>['keys'=>[$f['jwk']]]));
$api->handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);
$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes);
try {
    $http=function(string $host,string $path,?string $bearer=null,?array $body=null)use($address):array {
        $curl=curl_init('http://'.$address.$path);$headers=['Host: '.$host];if($bearer)$headers[]='Authorization: Bearer '.$bearer;
        if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return [$status,is_string($body)?json_decode($body,true):null];
    };
    for($attempt=0;$attempt<30;$attempt++){if($http('portal.test','/api/v1/health')[0])break;usleep(100000);}
    $expect($http('portal.test','/api/v1/application/billing',$token)[0]===200,'HTTP signed operator reads billing');
    $expect($http('portal.test','/api/v1/application/billing/clinics/1?month=2026-10',$token)[0]===200,'HTTP dashboard routing');
    $expect($http('portal.test','/api/v1/application/billing')[0]===401,'HTTP anonymous denied');
    $expect($http('clinic.test','/api/v1/application/billing',$token)[0]===404,'HTTP clinic host cannot use central billing');
    $expect($http('portal.test','/api/v1/application/billing?disabled=1',$token)[0]===503,'HTTP disabled gate');
    $other=\Firebase\JWT\JWT::encode(array_replace($claims,['oid'=>'not-a-central-admin','roles'=>['Wellness.SuperAdmin']]),$key,'RS256','billing');
    $expect($http('portal.test','/api/v1/application/billing',$other)[0]===403,'Clinic Super Admin is not a billing grant');
    $httpBody=array_replace($base,['code'=>'http-plan','request_key'=>$ref(),'reason'=>'Verified HTTP plan creation']);
    $expect($http('portal.test','/api/v1/application/billing/plans',null,$httpBody)[0]===401,'Anonymous mutation denied');
    $expect($http('portal.test','/api/v1/application/billing/plans',$other,$httpBody)[0]===403,'Clinic role cannot change prices');
    $created=$http('portal.test','/api/v1/application/billing/plans',$token,$httpBody);
    $expect($created[0]===200 && ($created[1]['data']['version']??null)===1,'HTTP mutation reaches audited service');
    $expect($http('portal.test','/api/v1/application/billing/plans',$token,$httpBody)[1]['data']===$created[1]['data'],'HTTP retry is idempotent');
    $expect($http('portal.test','/api/v1/application/billing/plans',$token,array_replace($httpBody,['reason'=>str_repeat('x',66000)]))[0]===413,'Billing request bodies are bounded before parsing');
} finally {
    if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    unlink($fixture);unlink($router);
}
echo "$billingChecks billing administration SQL/HTTP checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
