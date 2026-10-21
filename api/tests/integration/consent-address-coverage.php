<?php
declare(strict_types=1);
// Loopback API + injected synthetic maps transport; never calls a real provider.
require __DIR__.'/consent-client-registration.php';
use Wellness\Config;
use Wellness\Service\{AddressCoverageService,ConsentLock};

$checks=0;
$set(101,0,false);$set(101,1,true);$set(101,2,true);$set(101,3,false);$set(104,0,false);$set(104,1,false);$set(104,2,false);
$pdo->exec("DELETE up FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=2 AND p.code IN('schedule_for_other_practitioners','approve_onsite_service_area');
    INSERT IGNORE INTO user_permissions(user_id,permission_id) SELECT 2,id FROM permissions WHERE code='approve_onsite_service_area';
    UPDATE practitioners SET booking_mode='practitioner_managed',active=1 WHERE id IN(2,3);
    UPDATE locations SET address_line1='1 Synthetic Base',city='Toronto',province='ON',postal_code='M5V 1A1' WHERE id=1;
    UPDATE practitioner_services SET offers_mobile=1,mobile_radius_km=50,mobile_free_radius_km=NULL,mobile_fee_cents=1500 WHERE service_id=1 AND practitioner_id IN(2,3);
    DELETE FROM onsite_area_approvals;");
$destination=['address_line1'=>'2 Synthetic Visit','address_line2'=>'','city'=>'Toronto','province'=>'ON','postal_code'=>'M5V 1A1','country'=>'Canada','instructions'=>'PRIVATE-VISIT-INSTRUCTIONS'];
$base=['client_id'=>104,'location_id'=>1,'service_id'=>1,'practitioner_id'=>2,'destination'=>$destination];
$log=dirname(__DIR__,3).'/.tmp/consent-coverage-provider.jsonl';file_put_contents($log,'');
$calls=static fn():int=>count(file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
$snapshot=static fn():array=>[$pdo->query('SELECT * FROM onsite_area_approvals ORDER BY id')->fetchAll(),(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()];
$env['ACCESS_AUDIT_TEST_COVERAGE']='synthetic-local-only';
$server=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/access-audit-router.php'],[0=>['pipe','r'],1=>['file',dirname(__DIR__,3).'/.tmp/consent-coverage-http.stdout.log','a'],2=>['file',dirname(__DIR__,3).'/.tmp/consent-coverage-http.stderr.log','a']],$pipes,null,$env);
if(!is_resource($server))throw new RuntimeException('Cannot start coverage API');fclose($pipes[0]);
$coverageCall=fn(string $action,array $body,?string $token=null)=>$call('/api/v1/address-coverage/'.$action,$token??$practitionerToken,'POST',$body);
$blocked=function(string $action,array $body,int $wanted=404,?string $token=null)use($coverageCall,$expect,$calls,$snapshot,$event):void{
    $before=$snapshot();$count=$calls();[$status,$data,$cid,$raw]=$coverageCall($action,$body,$token);
    $expect($status===$wanted,'Coverage denial status for '.$action.': expected '.$wanted.', got '.$status);
    $expect($calls()===$count&&$snapshot()===$before,'Denied coverage operation calls no provider and changes no approval or business audit');
    $expect(!str_contains($raw,'Synthetic Visit')&&!str_contains($raw,'PRIVATE-')&&!str_contains($raw,'distance_quote'),'Denied response contains no address, private origin or proof');
    if(in_array($status,[401,403,404],true))$expect($event($cid)[0]['outcome']==='denied'&&json_decode($event($cid)[0]['metadata'],true)['resources']===[],'Denied coverage access is audited without disclosed records');
};
try{
    $ready=false;for($i=0;$i<100;$i++){$connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($connection){fclose($connection);$ready=true;break;}usleep(20000);}$expect($ready,'Coverage API ready');
    [$status,$data,$cid]=$coverageCall('validate',$base);$proof=$data['data']['token']??'';
    $expect($status===200&&$calls()===3&&$data['data']['covered']===true,'Permitted related patient validation succeeds despite unrelated restriction');
    $sent=file_get_contents($log);$expect(!str_contains($sent,'PRIVATE-VISIT-INSTRUCTIONS')&&!str_contains($sent,'client_id')&&!str_contains($sent,'Permitted Patient'),'Provider payload contains address/routing fields without patient identifiers or visit instructions');
    $expect(!str_contains($event($cid)[0]['metadata'],'Synthetic Visit')&&!str_contains($event($cid)[0]['metadata'],$proof),'Validation audit omits address and proof contents');
    $decode=static function(string $token):array{return json_decode(base64_decode(strtr(explode('.',$token)[0],'-_','+/')),true,32,JSON_THROW_ON_ERROR);};
    $expect($decode($proof)['client_id']===104,'Validation proof is bound to its patient');
    $approvedBody=$base+['address_validation_token'=>$proof];
    [$status,$data]=$coverageCall('approve',$approvedBody);$expect($status===200&&$data['data']['approved']===true,'Permitted practitioner with approval capability can persist approval');
    [$status,$data]=$coverageCall('approval',$base);$expect($status===200&&$data['data']['approved']===true,'Authorized status lookup returns the saved approval');
    $expect($calls()===3,'Approval and status use the existing proof and make no further provider requests');
    foreach([101,9001,105,106,107,999999] as $client)foreach(['validate','approval','approve','revoke'] as $action)$blocked($action,array_replace($approvedBody,['client_id'=>$client]));
    // Ordinary scope and target practitioner management remain separate requirements.
    foreach(['validate','approval','approve','revoke'] as $action)$blocked($action,array_replace($approvedBody,['practitioner_id'=>3]),403);
    foreach(['validate','approval','approve','revoke'] as $action)$blocked($action,array_replace($approvedBody,['location_id'=>2]),422);
    $missing=$base;unset($missing['client_id']);$blocked('validate',$missing,422);
    foreach([0,true,'104.9'] as $invalid)$blocked('validate',array_replace($base,['client_id'=>$invalid]),422);
    $blocked('approve',$approvedBody,403,$otherToken); // No approve capability and no client scope.
    // A valid proof cannot be rebound to a different permitted patient or staff actor.
    [$status,$data]=$coverageCall('validate',$base,$adminToken);$adminProof=$data['data']['token'];
    $blocked('approve',array_replace($base,['client_id'=>9001,'address_validation_token'=>$adminProof]),422,$adminToken);
    $blocked('approve',$base+['address_validation_token'=>$proof],422,$adminToken);
    $config=new Config('test',false,'synthetic-key',[],'127.0.0.1',$port,$name,'root','','tenant','api','scope',300,googleMapsApiKey:'synthetic',addressValidationSigningKey:str_repeat('c',32));
    $directCalls=0;$service=new AddressCoverageService($database,$config,function()use(&$directCalls):array{$directCalls++;throw new RuntimeException('Unauthorized provider invocation');});
    $denies(fn()=>$service->validate($actor(),array_replace($base,['client_id'=>101])),404);
    $denies(fn()=>$service->verifyBooking($actor(),array_replace($approvedBody,['client_id'=>101]),$destination),404);
    $expect($directCalls===0,'Direct validation/verification calls enforce authorization before transport');
    // Withdrawal after quote or approval must apply to every action and replay.
    $set(104,2,true);foreach(['validate','approval','approve','revoke'] as $action)$blocked($action,$approvedBody);
    $blocked('validate',$base,403,$adminToken); // Actor is permitted, selected practitioner recipient is restricted.
    $set(104,2,false);$set(104,0,true);$set(104,1,false);$blocked('validate',$base,404,$adminToken);$set(104,0,false);
    [$status,$data]=$coverageCall('approval',$base);$expect($status===200&&$data['data']['approved']===true,'Restoration re-enables authorized access without rewriting existing approvals');
    // Patient coverage is an outgoing use, not permission to contact a restricted practitioner.
    $customerCoverage=fn(array $body)=>$call('/api/v1/customer/address-coverage/validate',$customerToken,'POST',$body,'audit-a.test',true);
    $count=$calls();[$status]=$customerCoverage(array_replace($base,['client_id'=>104]));$expect($status===403&&$calls()===$count,'Patient cannot submit another patient ID');
    $own=$base;unset($own['client_id']);[$status]=$customerCoverage($own);$expect($status===403&&$calls()===$count,'Patient self-context does not override the selected recipient restriction');
    $own['practitioner_id']=3;[$status,$data]=$customerCoverage($own);$expect($status===200&&$decode($data['data']['token'])['client_id']===101,'Patient may validate their own address for another permitted recipient');
    $expect(!isset($data['data']['distance_km']),'Patient response preserves private-origin distance minimization');
    // Existing transactions cannot use an old consistent-read snapshot to ignore new withdrawal.
    $otherPdo=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();$pdo->query('SELECT COUNT(*) FROM client_consent_directives')->fetchColumn();
    $otherPdo->exec("UPDATE client_consent_directives SET restricted=1 WHERE client_id=104 AND target_staff_id=2 AND scope='staff_access'");
    try{try{$service->verifyBooking($actor(),$approvedBody,$destination);throw new RuntimeException('Stale snapshot bypassed withdrawal');}catch(\Wellness\Http\ApiException $e){$expect(in_array($e->status,[403,404],true),'Current policy rejects stale transaction authorization');}}finally{$pdo->rollBack();$otherPdo->exec("UPDATE client_consent_directives SET restricted=0 WHERE client_id=104 AND target_staff_id=2 AND scope='staff_access'");}
    $expect($directCalls===0,'Current authorization read rejects stale transaction snapshot without provider calls');
    // Inactive recipient/profile and revoked assignment fail before private origin/provider access.
    foreach([['UPDATE users SET status=\'inactive\' WHERE id=2','UPDATE users SET status=\'active\' WHERE id=2'],['UPDATE practitioners SET active=0 WHERE id=2','UPDATE practitioners SET active=1 WHERE id=2'],['UPDATE practitioner_locations SET active=0 WHERE practitioner_id=2 AND location_id=1','UPDATE practitioner_locations SET active=1 WHERE practitioner_id=2 AND location_id=1']] as [$disable,$restore]){
        $pdo->exec($disable);try{$blocked('validate',$base,422,$adminToken);}finally{$pdo->exec($restore);}
    }
    // Approval/revocation business audit failure must roll back the corresponding write.
    [$status]=$coverageCall('revoke',$base);$expect($status===200,'Authorized revocation succeeds');
    $pdo->exec("CREATE TRIGGER fail_coverage_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{$blocked('approve',$approvedBody,500);}finally{$pdo->exec('DROP TRIGGER fail_coverage_audit');}
    [$status]=$coverageCall('approve',$approvedBody);$expect($status===200,'Authorized approval can be recreated');
    $pdo->exec("CREATE TRIGGER fail_coverage_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    try{$blocked('revoke',$base,500);}finally{$pdo->exec('DROP TRIGGER fail_coverage_audit');}
    $lock=new ConsentLock($pdo);$lock->acquire(1);try{$blocked('validate',$base,503);$blocked('revoke',$base,503);}finally{$lock->release();}
    $pdo->exec('RENAME TABLE client_consent_directives TO retained_coverage_directives');
    try{foreach(['validate','approval','approve','revoke'] as $action)$blocked($action,$approvedBody,500);}
    finally{$pdo->exec('RENAME TABLE retained_coverage_directives TO client_consent_directives');}
}finally{proc_terminate($server);proc_close($server);}
echo "Address coverage SQL/HTTP authorization: $checks checks passed.\n";
