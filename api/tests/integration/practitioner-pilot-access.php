<?php
declare(strict_types=1);
// Explicit disposable loopback database only; no .env, external identity calls or delivery.
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\Service\PractitionerPilotAccessService;
use Wellness\{Config,Database};
use Wellness\Http\ApiException;
use Firebase\JWT\JWT;

function pilotConfig(int $port,string $name,bool $enabled=true): Config {
    return new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','',
        '11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,
        staffExternalTenantId:'44444444-4444-4444-4444-444444444444',staffExternalSubdomain:'teststaff',
        staffExternalApiClientId:'88888888-8888-8888-8888-888888888888',staffExternalSpaClientId:'77777777-7777-7777-7777-777777777777',
        clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',externalStaffAuthEnabled:true,
        practitionerPilotAccessEnabled:$enabled);
}
function pilotIdentity(string $subject): VerifiedIdentity {
    return new VerifiedIdentity('entra-external-staff','https://44444444-4444-4444-4444-444444444444.ciamlogin.com/44444444-4444-4444-4444-444444444444/v2.0',$subject,'44444444-4444-4444-4444-444444444444');
}
function pilotActor(): ApplicationAdminContext {
    return new ApplicationAdminContext(1,new VerifiedIdentity('entra-workforce','https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0','operator','11111111-1111-1111-1111-111111111111'),'Operator','operator@example.test');
}
if(($argv[1]??'')==='revoke-worker'){
    if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true' || !preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D',$argv[2]??''))throw new RuntimeException('Disposable worker only');
    $config=pilotConfig((int)getenv('RECURRING_TEST_PORT'),$argv[2]);
    $service=new PractitionerPilotAccessService(new Database($config),$config);
    echo 'ready'.PHP_EOL;flush();
    echo json_encode($service->handle(pilotActor(),1,'POST','/'.$argv[3].'/revoke',[],json_decode($argv[4],true,8,JSON_THROW_ON_ERROR),'race'),JSON_THROW_ON_ERROR);exit;
}
require __DIR__.'/subscription-foundation.php';
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/049_access_audit_events.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/058_practitioner_pilot_access.sql'));
$pdo->exec("INSERT INTO clinic_hosts(host,clinic_id) VALUES('wellnexa.test',1),('other.test',2);
 INSERT INTO application_administrators(id,issuer,subject,display_name,email) VALUES(1,'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0','operator','Operator','operator@example.test')");
putenv('PILOT_MODE=true');putenv('PILOT_ALLOWED_EMAILS=[]');putenv('PILOT_ALLOWED_SMS=[]');
putenv('PILOT_ALLOWED_IDENTITIES='.json_encode([['adapter'=>'entra-workforce','issuer'=>pilotActor()->identity->issuer,'subject'=>'operator']]));
$config=pilotConfig($port,$name);$service=new PractitionerPilotAccessService($database,$config);$actor=pilotActor();
// Some deployed PDO drivers return numeric columns as strings. The public contract must not.
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
$checks=0;
$check=function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$deny=function(callable $call,int $status,string $why)use($check):void{try{$call();}catch(ApiException $e){$check($e->status===$status,$why.': '.$e->errorCode);return;}throw new RuntimeException($why.' accepted');};
$uuid=fn()=>vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));
$key=fn(array $body=[])=>$body+['request_key'=>$uuid(),'reason'=>'Synthetic independently checked account'];
$ref=fn(string $subject)=>'WPI1 '.JWT::urlsafeB64Encode(json_encode(['adapter'=>'entra-external-staff','issuer'=>pilotIdentity($subject)->issuer,'subject'=>$subject]));
$admin=fn(string $method,string $tail='',array $body=[],array $query=[],int $clinic=1)=>$service->handle($actor,$clinic,$method,$tail,$query,$body,'synthetic');
$deny(fn()=>(new PractitionerPilotAccessService($database,pilotConfig($port,$name,false)))->handle($actor,1,'GET','',[],[],'off'),503,'Disabled feature fails closed');
$check($admin('GET')['items']===[],'Empty listing');
putenv('PILOT_MODE=false');$deny(fn()=>$admin('GET'),503,'Administration unavailable outside pilot');putenv('PILOT_MODE=true');
putenv('PILOT_ALLOWED_IDENTITIES='.json_encode([['adapter'=>'entra-external-staff','issuer'=>pilotIdentity('legacy')->issuer,'subject'=>'legacy']]));
$deny(fn()=>$service->assertAllowed(pilotIdentity('legacy'),1),503,'Mixed private and managed authority rejected');
putenv('PILOT_ALLOWED_IDENTITIES='.json_encode([['adapter'=>'entra-workforce','issuer'=>$actor->identity->issuer,'subject'=>'operator']]));
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),1),403,'Unknown practitioner denied');
$body=$key(['reference'=>$ref('alice'),'label'=>'Synthetic Alice']);$entry=$admin('POST','',$body);$id=$entry['id'];
$check($entry['status']==='pending' && $entry['version']===1,'Recording does not approve');
$check(is_string($pdo->query('SELECT version FROM practitioner_pilot_access WHERE id='.(int)$id)->fetchColumn()),'Regression exercises string-returning PDO driver');
$detail=$admin('GET',"/$id");
$check(is_int($detail['entry']['id']) && is_int($detail['entry']['clinic_id']) && is_int($detail['entry']['version']),'Review response preserves numeric entry contract');
$check(is_int($detail['events'][0]['id']) && is_int($detail['events'][0]['version']),'Review history preserves numeric event contract');
$listed=$admin('GET')['items'][0];
$check(is_int($listed['id']) && is_int($listed['clinic_id']) && is_int($listed['version']),'List response preserves numeric entry contract');
$check($admin('POST','',$body)==$entry,'Identical record retry stable');
$check((int)$pdo->query('SELECT COUNT(*) FROM staff_memberships')->fetchColumn()===14,'No membership granted');
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),1),403,'Pending identity denied');
$deny(fn()=>$admin('POST','',$key(['reference'=>$ref('alice'),'label'=>'Duplicate'])),409,'Duplicate identity rejected');
$deny(fn()=>$admin('POST','',$key(['reference'=>'WPI1 broken','label'=>'Invalid'])),422,'Malformed reference denied');
$wrong=json_decode(JWT::urlsafeB64Decode(substr($ref('foreign'),5)),true);$wrong['issuer']='https://wrong.test';
$deny(fn()=>$admin('POST','',$key(['reference'=>'WPI1 '.JWT::urlsafeB64Encode(json_encode($wrong)),'label'=>'Foreign'])),422,'Foreign issuer denied');
$deny(fn()=>$admin('POST','',$key(['reference'=>$ref('extra'),'label'=>'Extra','role'=>'super_admin'])),422,'Role injection denied');
$deny(fn()=>$admin('POST',"/$id/approve",$key(['version'=>1])),422,'Approval requires independent verification');
$deny(fn()=>$admin('POST',"/$id/approve",$key(['version'=>'1','identity_verified'=>true])),422,'Version type enforced');
$deny(fn()=>$admin('GET',"/$id",[],[],2),404,'Other clinic cannot read entry');
$deny(fn()=>$admin('GET','',[],['status'=>'invalid']),422,'Invalid status denied');
$deny(fn()=>$admin('GET','',[],['before_id'=>['1']]),422,'Malformed cursor denied');
$deny(fn()=>$admin('GET','',[],['limit'=>'5000']),422,'Unbounded limit rejected');
$approve=$key(['version'=>$detail['entry']['version'],'identity_verified'=>true]);$approved=$admin('POST',"/$id/approve",$approve);
$check($admin('POST',"/$id/approve",$approve)==$approved,'Identical approval retry stable');
$service->assertAllowed(pilotIdentity('alice'),1);$service->release();$check(true,'Exact approved identity allowed');
$deny(fn()=>$service->assertAllowed(pilotIdentity('Alice'),1),403,'Subject is case sensitive');
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),2),403,'Approval cannot cross clinic');
$check(count($admin('GET',"/$id")['events'])===2,'Atomic actor/reason history without duplicate retry event');
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
$deny(fn()=>$admin('GET'),403,'Stale operator grant denied');$deny(fn()=>$admin('POST',"/$id/approve",$approve),403,'Replay rechecks current operator grant');
$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
$pdo->exec('RENAME TABLE practitioner_pilot_events TO retained_pilot_events');
try{try{$admin('POST',"/$id/revoke",$key(['version'=>2]));throw new RuntimeException('Missing event accepted');}catch(PDOException){$check(true,'Missing event fails closed');}}
finally{$pdo->exec('RENAME TABLE retained_pilot_events TO practitioner_pilot_events');}
$check($admin('GET',"/$id")['entry']['status']==='approved','Event failure rolls back approval change');
// A real second process must wait for already-authorized execution before committing revoke.
$service->assertAllowed(pilotIdentity('alice'),1);$pipes=[];$revoke=$key(['version'=>2]);
$worker=proc_open([PHP_BINARY,__FILE__,'revoke-worker',$name,(string)$id,json_encode($revoke)],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
if(!is_resource($worker))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);
$check(trim(fgets($pipes[1]))==='ready','Concurrent decision worker started');usleep(200000);
$check(proc_get_status($worker)['running'],'Revoke waits for active request');$service->release();
$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($worker)!==0)throw new RuntimeException($errors);
$check(json_decode($output,true)['status']==='revoked','Revoke commits after execution releases lock');
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),1),403,'Revocation denies same identity');
$deny(fn()=>$admin('POST',"/$id/approve",$approve),409,'Old approval replay cannot restore revoked access');
$deny(fn()=>$admin('POST',"/$id/revoke",$key(['version'=>2])),409,'Stale version rejected');
$changed=$revoke;$changed['reason']='Changed uncertain request';$deny(fn()=>$admin('POST',"/$id/revoke",$changed),409,'Idempotency key cannot change payload');
$admin('POST',"/$id/approve",$key(['version'=>3,'identity_verified'=>true]));
$pdo->exec('UPDATE product_identities SET status=\'inactive\' WHERE id=(SELECT identity_id FROM practitioner_pilot_access WHERE id='.$id.')');
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),1),403,'Inactive identity denied despite approval');
$admin('POST',"/$id/revoke",$key(['version'=>4]));
$deny(fn()=>$admin('POST',"/$id/approve",$key(['version'=>5,'identity_verified'=>true])),403,'Approval cannot restore an inactive identity');
$pdo->exec('UPDATE product_identities SET status=\'active\' WHERE id=(SELECT identity_id FROM practitioner_pilot_access WHERE id='.$id.')');
$admin('POST',"/$id/approve",$key(['version'=>5,'identity_verified'=>true]));
$pdo->exec("UPDATE clinics SET status='inactive' WHERE id=1");
$deny(fn()=>$service->assertAllowed(pilotIdentity('alice'),1),403,'Inactive clinic cannot admit a practitioner');
$deny(fn()=>$admin('GET'),404,'Inactive clinic cannot be administered');$pdo->exec("UPDATE clinics SET status='active' WHERE id=1");
for($n=0;$n<51;$n++)$admin('POST','',$key(['reference'=>$ref('page-'.$n),'label'=>'Synthetic page '.$n]));
$page=$admin('GET');$check(count($page['items'])===50 && $page['next_before_id']!==null,'List bounded to fifty with cursor');
$check(!isset($page['items'][0]['subject']) && !isset($page['items'][0]['issuer']),'List omits detailed identity tuple until authorized review');
$next=$admin('GET','',[],['before_id'=>(string)$page['next_before_id']]);
$check(count($next['items'])===2 && $next['next_before_id']===null,'Cursor completes without duplicates');
$check(array_intersect(array_column($page['items'],'id'),array_column($next['items'],'id'))===[],'Pages do not overlap');
$check(count($admin('GET','',[],['status'=>'approved'])['items'])===1,'Status filter applied');
$pdo->exec('RENAME TABLE practitioner_pilot_access TO retained_pilot_access');
try{try{$service->assertAllowed(pilotIdentity('alice'),1);throw new RuntimeException('Unavailable authority accepted');}catch(PDOException){$check(true,'Unavailable approval authority denies');}}
finally{$pdo->exec('RENAME TABLE retained_pilot_access TO practitioner_pilot_access');}

// Real HTTP dispatch: signed synthetic tokens, actual JWT verifier, managed callback and audit.
$rsaKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$rsaKey)throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($rsaKey)['rsa'];$jwk=['kty'=>'RSA','kid'=>'pilot','n'=>JWT::urlsafeB64Encode($rsa['n']),'e'=>JWT::urlsafeB64Encode($rsa['e'])];
$fixture=tempnam(sys_get_temp_dir(),'pilot-fixture-');$router=tempnam(sys_get_temp_dir(),'pilot-router-');
file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk]));
// The HTTP router must not execute this test bootstrap or create another database.
file_put_contents($router,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).'; require '.var_export(__DIR__.'/practitioner-pilot-http-fixture.php',true).';');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$environment=getenv();$environment['PILOT_HTTP_FIXTURE']=$fixture;
$process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes,null,$environment);
try{
 $http=function(string $host,string $path,?string $token=null,?array $body=null)use($address):array{
  $curl=curl_init('http://'.$address.'/api/v1/'.$path);$headers=['Host: '.$host];if($token)$headers[]='Authorization: Bearer '.$token;
  if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}
  $responseHeaders=[];curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_HEADERFUNCTION=>function($handle,$line)use(&$responseHeaders){$responseHeaders[]=trim($line);return strlen($line);}]);
  $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,is_string($raw)?json_decode($raw,true):null,$responseHeaders];
 };
 for($attempt=0;$attempt<40;$attempt++){if($http('portal.test','health')[0])break;usleep(100000);}
 $claims=['iss'=>pilotIdentity('alice')->issuer,'tid'=>$config->staffExternalTenantId,'aud'=>$config->staffExternalApiClientId,'azp'=>$config->staffExternalSpaClientId,'sub'=>'alice','ver'=>'2.0','scp'=>'access_as_staff','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
 $sign=fn(array $c)=>JWT::encode($c,$rsaKey,'RS256','pilot');$token=$sign($claims);
 $workforce=$sign(['iss'=>$actor->identity->issuer,'tid'=>$config->entraTenantId,'aud'=>$config->entraApiClientId,'oid'=>'operator','scp'=>'access_as_user','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300]);
 $check($http('portal.test','application/clinics/1/pilot-practitioners')[0]===401,'HTTP anonymous admin denied');
 $check($http('wellnexa.test','application/clinics/1/pilot-practitioners',$workforce)[0]===404,'HTTP central route host restricted');
 $check($http('portal.test','application/clinics/1/pilot-practitioners',$token)[0]===401,'External identity cannot administer pilot');
 $list=$http('portal.test','application/clinics/1/pilot-practitioners',$workforce);
 $check($list[0]===200 && count($list[1]['data']['items'])===50,'HTTP operator listing uses live grant');
 $check(in_array('Cache-Control: no-store',$list[2],true),'HTTP admin response no-store');
 $check(is_int($list[1]['data']['items'][0]['version']),'HTTP JSON list emits numeric versions with string-returning PDO');
 $review=$http('portal.test','application/clinics/1/pilot-practitioners/'.$id,$workforce);
 $check($review[0]===200 && is_int($review[1]['data']['entry']['version']) && is_int($review[1]['data']['events'][0]['version']),'HTTP JSON review and history emit numeric versions');
 $check($http('portal.test','application/me',$workforce)[1]['data']['features']['practitioner_pilot_access']===true,'HTTP feature flag exposed only after admin authentication');
 $denial=$http('wellnexa.test','auth/me',$sign(array_replace($claims,['sub'=>'unapproved'])));
 $check($denial[0]===403 && str_starts_with($denial[1]['error']['fields']['ID'],'WPI1 '),'HTTP verified denied account gets own reference');
 $check($http('wellnexa.test','auth/me',$sign(array_replace($claims,['aud'=>'wrong'])))[0]===401,'HTTP invalid audience denied');
 $check($http('wellnexa.test','auth/me',$token)[0]===403,'Pilot approval alone grants no membership');
 $pdo->prepare('UPDATE product_identities SET adapter=?,issuer=?,subject=? WHERE id=1')->execute(['entra-external-staff',$claims['iss'],'http-member']);
 $pdo->exec("INSERT INTO user_roles(user_id,role_id,location_id) SELECT 1,id,NULL FROM roles WHERE code='practitioner'");
 $member=$admin('POST','',$key(['reference'=>$ref('http-member'),'label'=>'HTTP member']));
 $admin('POST','/'.$member['id'].'/approve',$key(['version'=>1,'identity_verified'=>true]));$memberToken=$sign(array_replace($claims,['sub'=>'http-member']));
 $check($http('wellnexa.test','auth/me',$memberToken)[0]===200,'HTTP approved active practitioner can sign in');
 $pdo->exec("UPDATE users SET status='inactive' WHERE id=1");
 $check($http('wellnexa.test','auth/me',$memberToken)[0]===403,'Pilot approval cannot bypass ordinary Inactive staff access');
 $pdo->exec("UPDATE users SET status='active' WHERE id=1");
 $check($http('wellnexa.test','auth/me',$memberToken)[0]===200,'Explicit ordinary access restoration retains separate pilot decision');
 $check($http('other.test','auth/me',$memberToken)[0]===403,'HTTP same bearer denied for other clinic');
 $memberReview=$http('portal.test','application/clinics/1/pilot-practitioners/'.$member['id'],$workforce);
 $rev=$http('portal.test','application/clinics/1/pilot-practitioners/'.$member['id'].'/revoke',$workforce,$key(['version'=>$memberReview[1]['data']['entry']['version']]));
 $check($rev[0]===200 && $rev[1]['data']['status']==='revoked','HTTP explicit admin revoke succeeds');
 $check($http('wellnexa.test','auth/me',$memberToken)[0]===403,'HTTP same valid bearer denied after revoke');
 $pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
 $check($http('portal.test','application/clinics/1/pilot-practitioners',$workforce)[0]===403,'HTTP same operator bearer denied after grant revocation');
 $audit=implode(' ',array_map(fn($row)=>json_encode($row),$pdo->query('SELECT * FROM access_audit_events')->fetchAll()));
 $check(!str_contains($audit,$memberToken) && !str_contains($audit,'WPI1') && !str_contains($audit,'Synthetic independently checked'),'HTTP audit excludes bearer, support reference and reason bodies');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach([$fixture,$router,$router.'.log'] as $file)if(is_file($file))unlink($file);}
echo "$checks practitioner pilot SQL/HTTP/concurrency checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
