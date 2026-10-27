<?php
declare(strict_types=1);
// Synthetic loopback databases only. No .env, provider requests, mail or real accounts.
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity,StaffMembershipResolver,ExternalStaffAuthenticator};
use Wellness\Service\{PractitionerApplicationService,SubscriptionPolicy};
use Wellness\{Config,Database,ClinicContext};
use Wellness\Http\ApiException;
use Firebase\JWT\JWT;

function applicationConfig(int $port,string $name,bool $activation=true,bool $durable=true,bool $enabled=true): Config {
    return new Config('test',false,'test',[],'127.0.0.1',$port,$name,'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,
        staffExternalTenantId:'44444444-4444-4444-4444-444444444444',staffExternalSubdomain:'teststaff',staffExternalApiClientId:'88888888-8888-8888-8888-888888888888',staffExternalSpaClientId:'77777777-7777-7777-7777-777777777777',
        clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',practitionerApplicationsEnabled:$enabled,practitionerApplicationActivationEnabled:$activation,externalStaffAuthEnabled:$durable);
}
if(($argv[1]??'')==='activation-worker') {
    if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true' || !preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D',$argv[2]??''))throw new RuntimeException('Synthetic worker only');
    $db=new Database(applicationConfig((int)getenv('RECURRING_TEST_PORT'),$argv[2]));
    $subject=new VerifiedIdentity('entra-external-staff','https://44444444-4444-4444-4444-444444444444.ciamlogin.com/44444444-4444-4444-4444-444444444444/v2.0','alice','44444444-4444-4444-4444-444444444444');
    echo json_encode((new PractitionerApplicationService($db,applicationConfig((int)getenv('RECURRING_TEST_PORT'),$argv[2])))->activate(1,$subject,json_decode($argv[3],true),'race'),JSON_THROW_ON_ERROR);exit;
}
require __DIR__.'/subscription-foundation.php';
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/042_global_practitioners.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/047_practitioner_enquiries.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/049_access_audit_events.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/055_practitioner_applications.sql'));
$pdo->exec("INSERT INTO locations(id,clinic_id,name,timezone) VALUES(1,1,'Virtual','America/Toronto'),(2,2,'Foreign','America/Toronto');
 INSERT INTO clinic_hosts(host,clinic_id) VALUES('wellnexa.test',1),('other.test',2);
 INSERT INTO application_administrators(id,issuer,subject,display_name,email) VALUES(1,'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0','operator','Operator','operator@example.test');
 INSERT INTO subscription_plan_versions(id,code,version,scope,currency,features,display_name) VALUES(1,'beta',1,'practitioner','CAD','{}','Beta membership'),(2,'beta',2,'practitioner','CAD','{}','Beta v2');");
$config=applicationConfig($port,$name);$service=new PractitionerApplicationService($database,$config);
$actor=new ApplicationAdminContext(1,new VerifiedIdentity('entra-workforce','https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0','operator',$config->entraTenantId),'Operator','operator@example.test');
$identity=fn(string $subject)=>new VerifiedIdentity('entra-external-staff','https://44444444-4444-4444-4444-444444444444.ciamlogin.com/44444444-4444-4444-4444-444444444444/v2.0',$subject,$config->staffExternalTenantId);
$alice=$identity('alice');$bob=$identity('bob');$checks=0;
$check=function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$denied=function(callable $call,int $status,string $why)use($check):void{try{$call();}catch(ApiException $e){$check($e->status===$status,$why.': '.$e->errorCode);return;}throw new RuntimeException($why.' accepted');};
$uuid=fn()=>sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
$key=fn(array $body=[])=>$body+['request_key'=>$uuid()];
$terms='Synthetic reviewed joining terms for local tests only. Eligible new accounts have six calendar months free. No automatic payment conversion. No patient information belongs in an application.';
$settings=['revision'=>0,'enabled'=>true,'activation_enabled'=>true,'readiness_acknowledged'=>true,'terms_text'=>$terms,'plan_version_id'=>1,'timezone'=>'America/Toronto','reason'=>'Synthetic rollout readiness checked'];
$admin=fn(string $method,string $tail,array $body=[])=>$service->admin($actor,1,$method,$tail,$body,'test');
$check($service->publicInfo(1)['enabled']===false,'Intake starts closed without invented terms');
$check($service->me(1,$alice)['application']===null,'Verified but unprovisioned applicant may check own application');
$off=new PractitionerApplicationService($database,applicationConfig($port,$name,false,false,false));
$check($off->publicInfo(1)['available']===false,'Disabled public entry does not query new application settings');
$denied(fn()=>$off->me(1,$alice),503,'Private feature gate');
$notReady=new PractitionerApplicationService($database,applicationConfig($port,$name,false));
$denied(fn()=>$notReady->admin($actor,1,'POST','/settings',$key($settings),'test'),409,'Activation readiness gate cannot be bypassed');
$noDurable=new PractitionerApplicationService($database,applicationConfig($port,$name,true,false));
$denied(fn()=>$noDurable->admin($actor,1,'POST','/settings',$key($settings),'test'),409,'Durable sign-in required before enabling activation');
$configured=$admin('POST','/settings',$key($settings));$offer=$service->publicInfo(1);
$check($offer['enabled'] && $offer['terms']['version']===1 && $offer['plan']['base_monthly_cents']===null,'Only operator-published terms and unpriced beta offer are exposed');
$profile=['version'=>0,'given_name'=>'Alice','family_name'=>'Applicant','email'=>'alice@example.test','phone'=>'','discipline'=>'Synthetic practice','credentials'=>'Declared synthetic credential','service_area'=>'Toronto, Ontario','services'=>'Synthetic services for local testing only.','enquiry_reference'=>'','terms_id'=>$offer['terms']['id'],'accept_terms'=>true,'information_confirmed'=>true];
$denied(fn()=>$service->submit(1,new VerifiedIdentity('customer','customer','alice','test'),$key($profile),'test'),403,'Customer identity cannot apply as staff');
$denied(fn()=>$service->submit(1,$alice,$key($profile+['role'=>'super_admin']),'test'),422,'Application cannot inject a role');
$denied(fn()=>$service->submit(1,$alice,$key(array_replace($profile,['accept_terms'=>false])),'test'),422,'Explicit terms acceptance required');
$request=$key($profile);$received=$service->submit(1,$alice,$request,'test');$id=$received['id'];
$check($service->submit(1,$alice,$request,'test')===$received,'Uncertain submission retries once');
$check((int)$pdo->query('SELECT COUNT(*) FROM staff_memberships')->fetchColumn()===14,'Applying grants no clinic membership');
$check($service->me(1,$bob)['application']===null,'One identity cannot read another application');
$denied(fn()=>$service->withdraw(2,$alice,$key(['version'=>1]),'test'),404,'Wrong clinic cannot mutate the application');
$denied(fn()=>$service->submit(1,$alice,$key($profile),'test'),409,'Duplicate signup does not create a second application');
$own=$service->me(1,$alice)['application'];
$review=['version'=>1,'decision'=>'approved','review_message'=>'Approved for the local synthetic beta.','reason'=>'Identity and qualifications independently checked','verification_code'=>$own['verification_code'],'applicant_verified'=>true,'credentials_reviewed'=>true,'allow_existing_membership'=>false,'location_id'=>1,'source_enquiry_id'=>null];
$denied(fn()=>$admin('POST',"/$id/review",$key(array_replace($review,['applicant_verified'=>false]))),422,'Reviewer attestation required');
$denied(fn()=>$admin('POST',"/$id/review",$key(array_replace($review,['verification_code'=>'000000000000']))),422,'Independent code must match');
$denied(fn()=>$admin('POST',"/$id/review",$key(array_replace($review,['location_id'=>2]))),422,'Approval location must be in the application clinic');
$admin('POST',"/$id/review",$key(['version'=>1,'decision'=>'changes_requested','review_message'=>'Please clarify services.','reason'=>'More information needed']));
$denied(fn()=>$service->submit(1,$alice,$key(array_replace($profile,['version'=>1])),'test'),409,'Stale applicant revision denied');
$service->submit(1,$alice,$key(array_replace($profile,['version'=>2,'services'=>'Clarified synthetic services.'])),'test');
$detail=$admin('GET',"/$id");$check(count($detail['submissions'])===2,'Revised applications retain immutable submitted history');
$admin('POST',"/$id/review",$key(array_replace($review,['version'=>3])));
$denied(fn()=>$notReady->activate(1,$alice,$key(['version'=>4,'accept_terms'=>true,'terms_id'=>$offer['terms']['id']]),'test'),503,'Approval alone does not bypass activation rollout');
// Preserve this identity's existing global profile and another clinic's independent records.
$identityId=(int)$pdo->query('SELECT identity_id FROM practitioner_applications WHERE id='.$id)->fetchColumn();
$pdo->exec("INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(21,2,'alice-other@example.test','Other clinic name','staff','active')");
$pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,2,21,'active')")->execute([$identityId]);
$pdo->exec("INSERT INTO practitioners(user_id,discipline) VALUES(21,'Other clinic discipline')");$otherP=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,'Original global name','global@example.test')")->execute([$identityId]);$global=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,2,?)')->execute([$global,$otherP]);
$pdo->prepare("INSERT INTO global_practitioner_credentials(global_practitioner_id,name,issuer,issued_on) VALUES(?,'Private credential','Synthetic','2026-01-01')")->execute([$global]);
// Real processes compete to activate the exact same verified application and request key.
$activate=$key(['version'=>4,'accept_terms'=>true,'terms_id'=>$offer['terms']['id']]);$jobs=[];
foreach([1,2] as $_){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'activation-worker',$name,json_encode($activate,JSON_THROW_ON_ERROR)],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);$jobs[]=[$process,$pipes];}
$results=[];foreach($jobs as [$process,$pipes]){$results[]=json_decode(stream_get_contents($pipes[1]),true);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($errors);}
$check($results[0]===$results[1] && $results[0]['status']==='activated','Concurrent activation yields one stable result');
$current=$service->me(1,$alice)['application'];$member=(int)$current['membership_id'];$subscription=(int)$current['subscription_id'];
$check($current['account_available']===true && $current['subscription']['status']==='trialing','Activation creates usable membership and a trial');
$sub=$pdo->query('SELECT * FROM billing_subscriptions WHERE id='.$subscription)->fetch();
$check($sub['trial_ends_at']===SubscriptionPolicy::trialEndsAt(new DateTimeImmutable($sub['starts_at'],new DateTimeZone('UTC')),'America/Toronto')->format('Y-m-d H:i:s'),'Six calendar months start at activation, not application submission');
$check((int)$pdo->query('SELECT COUNT(*) FROM billing_subscriptions WHERE billing_account_id='.(int)$sub['billing_account_id'])->fetchColumn()===1,'No duplicate trial');
$check($pdo->query('SELECT display_name FROM global_practitioners WHERE id='.$global)->fetchColumn()==='Original global name','Existing global profile is never overwritten from an application');
$check((int)$pdo->query('SELECT COUNT(*) FROM global_practitioner_credentials WHERE global_practitioner_id='.$global)->fetchColumn()===1,'Private credentials preserved without copying/sharing');
$check($pdo->query('SELECT discipline FROM practitioners WHERE id='.$otherP)->fetchColumn()==='Other clinic discipline','Other clinic remains unchanged');
$check($current['checklist']===['services'=>false,'availability'=>false,'credentials'=>false,'published'=>false],'No automatic service, credential verification, schedule or public publication');
$check(!(bool)$pdo->query('SELECT 1 FROM sms_budget_members WHERE membership_id='.$member)->fetchColumn(),'SMS funding is never assigned automatically');
$context=ClinicContext::resolve($config,$database,new \Wellness\Http\Request('GET','/',[],['host'=>'wellnexa.test'],[],'test'));
$actorContext=(new StaffMembershipResolver($database))->resolve($alice,$context);
$check($actorContext->roles===['practitioner'],'Activation grants only practitioner access');
$pdo->exec("UPDATE staff_memberships SET status='revoked' WHERE id=$member");
$denied(fn()=>$service->activate(1,$alice,$activate,'test'),403,'Replayed activation cannot revive revoked membership');
$check($service->me(1,$alice)['application']['account_available']===false,'Status page reflects revoked access');
$pdo->exec("UPDATE staff_memberships SET status='active' WHERE id=$member");
// An unrelated same-email account is a conflict, never an identity link.
$requestB=$key(array_replace($profile,['given_name'=>'Bob','email'=>'member2@example.test']));$b=$service->submit(1,$bob,$requestB,'test');$bOwn=$service->me(1,$bob)['application'];
$admin('POST','/'.$b['id'].'/review',$key(array_replace($review,['verification_code'=>$bOwn['verification_code']])));
$denied(fn()=>$service->activate(1,$bob,$key(['version'=>2,'accept_terms'=>true,'terms_id'=>$offer['terms']['id']]),'test'),409,'Same-email account is not rebound');
$check($service->me(1,$bob)['application']['status']==='approved','Activation conflict rolls back without corrupting application state');
$pdo->exec('RENAME TABLE practitioner_application_events TO retained_application_events');
try {try{$service->withdraw(1,$bob,$key(['version'=>2]),'test');throw new RuntimeException('Missing audit accepted');}catch(PDOException){$check(true,'Audit failure denies withdrawal');}}
finally{$pdo->exec('RENAME TABLE retained_application_events TO practitioner_application_events');}
$check($service->me(1,$bob)['application']['status']==='approved','Audit failure rolls back state changes');
$service->withdraw(1,$bob,$key(['version'=>2]),'test');
$denied(fn()=>$admin('POST','/'.$b['id'].'/review',$key(array_replace($review,['version'=>3]))),409,'Withdrawn applications cannot be approved');
$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");$denied(fn()=>$admin('GET',''),403,'Revoked reviewer cannot read applications');$pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
// An existing member is an explicit migration decision, never an automatic new free trial.
$existing=$identity('existing-member');
$pdo->prepare("UPDATE product_identities SET adapter=?,issuer=?,subject=? WHERE id=5")->execute([$existing->adapter,$existing->issuer,$existing->subject]);
$pdo->exec("INSERT INTO user_roles(user_id,role_id,location_id) SELECT 5,id,1 FROM roles WHERE code='practitioner'; INSERT INTO billing_accounts(clinic_id,membership_id) VALUES(1,5)");$account5=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO billing_subscriptions(billing_account_id,plan_version_id,status,starts_at,trial_ends_at,trial_timezone) VALUES(?,1,'trialing',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 10 DAY,'America/Toronto')")->execute([$account5]);$oldTrial=(int)$pdo->lastInsertId();
$oldEnd=$pdo->query('SELECT trial_ends_at FROM billing_subscriptions WHERE id='.$oldTrial)->fetchColumn();
$existingApp=$service->submit(1,$existing,$key(array_replace($profile,['email'=>'member5@example.test'])),'test');$existingId=$existingApp['id'];$existingOwn=$service->me(1,$existing)['application'];
$existingReview=array_replace($review,['verification_code'=>$existingOwn['verification_code']]);
$admin('POST',"/$existingId/review",$key($existingReview));
$denied(fn()=>$service->activate(1,$existing,$key(['version'=>2,'accept_terms'=>true,'terms_id'=>$offer['terms']['id']]),'test'),409,'Existing membership requires an explicit reviewed exception');
$admin('POST',"/$existingId/review",$key(array_replace($existingReview,['version'=>2,'allow_existing_membership'=>true])));
// A changed plan needs reapproval; changed terms require fresh applicant acceptance.
$admin('POST','/settings',$key(array_replace($settings,['revision'=>1,'plan_version_id'=>2,'terms_text'=>$terms.' Updated synthetic joining terms.'])));$updatedOffer=$service->publicInfo(1);
$denied(fn()=>$service->activate(1,$existing,$key(['version'=>3,'accept_terms'=>true,'terms_id'=>$offer['terms']['id']]),'test'),409,'Old accepted terms cannot activate against new terms');
$denied(fn()=>$service->activate(1,$existing,$key(['version'=>3,'accept_terms'=>true,'terms_id'=>$updatedOffer['terms']['id']]),'test'),409,'Changed offer requires reviewer reapproval');
$admin('POST',"/$existingId/review",$key(array_replace($existingReview,['version'=>3,'allow_existing_membership'=>true])));
$service->activate(1,$existing,$key(['version'=>4,'accept_terms'=>true,'terms_id'=>$updatedOffer['terms']['id']]),'test');
$existingOwn=$service->me(1,$existing)['application'];
$check((int)$existingOwn['subscription_id']===$oldTrial && $existingOwn['subscription']['trial_ends_at']===$oldEnd,'Existing valid trial reused without extending free eligibility');
$check((int)$pdo->query('SELECT COUNT(*) FROM billing_subscriptions WHERE billing_account_id='.$account5)->fetchColumn()===1,'Existing membership does not receive a second trial');
$check((int)$existingOwn['accepted_terms_id']===$updatedOffer['terms']['id'] && (int)$pdo->query('SELECT terms_id FROM practitioner_application_submissions WHERE application_id='.$existingId)->fetchColumn()===$offer['terms']['id'],'Activation consent preserves original submitted terms evidence');
$expired=$identity('expired-member');$pdo->prepare("UPDATE product_identities SET adapter=?,issuer=?,subject=? WHERE id=6")->execute([$expired->adapter,$expired->issuer,$expired->subject]);
$pdo->exec("INSERT INTO user_roles(user_id,role_id,location_id) SELECT 6,id,1 FROM roles WHERE code='practitioner'; INSERT INTO billing_accounts(clinic_id,membership_id) VALUES(1,6)");$account6=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO billing_subscriptions(billing_account_id,plan_version_id,status,starts_at,trial_ends_at,trial_timezone) VALUES(?,1,'trialing','2020-01-01','2020-07-01','America/Toronto')")->execute([$account6]);
$expiredApp=$service->submit(1,$expired,$key(array_replace($profile,['email'=>'member6@example.test','terms_id'=>$updatedOffer['terms']['id']])),'test');$expiredOwn=$service->me(1,$expired)['application'];
$admin('POST','/'.$expiredApp['id'].'/review',$key(array_replace($review,['allow_existing_membership'=>true,'verification_code'=>$expiredOwn['verification_code']])));
$denied(fn()=>$service->activate(1,$expired,$key(['version'=>2,'accept_terms'=>true,'terms_id'=>$updatedOffer['terms']['id']]),'test'),409,'Expired subscription requires billing review instead of a new free trial');
// Restore the original offer for the independent HTTP fixture.
$admin('POST','/settings',$key(array_replace($settings,['revision'=>2])));$offer=$service->publicInfo(1);$profile['terms_id']=$offer['terms']['id'];
// Exercise real API dispatch with synthetic signed identities and local JWKs only.
$rsaKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$rsaKey)throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($rsaKey)['rsa'];$jwk=['kty'=>'RSA','kid'=>'applications','n'=>JWT::urlsafeB64Encode($rsa['n']),'e'=>JWT::urlsafeB64Encode($rsa['e'])];
$externalClaims=['iss'=>$alice->issuer,'tid'=>$config->staffExternalTenantId,'aud'=>$config->staffExternalApiClientId,'azp'=>$config->staffExternalSpaClientId,'sub'=>'alice','ver'=>'2.0','scp'=>'access_as_staff','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
$workforceClaims=['iss'=>$actor->identity->issuer,'tid'=>$config->entraTenantId,'aud'=>$config->entraApiClientId,'oid'=>'operator','scp'=>'access_as_user','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300];
$signed=fn(array $claims)=>JWT::encode($claims,$rsaKey,'RS256','applications');
$fixture=tempnam(sys_get_temp_dir(),'applications-fixture-');$router=tempnam(sys_get_temp_dir(),'applications-router-');
file_put_contents($fixture,json_encode(['port'=>$port,'database'=>$name,'jwk'=>$jwk]));
file_put_contents($router,'<?php require '.var_export(dirname(__DIR__,2).'/vendor/autoload.php',true).'; $f=json_decode(file_get_contents('.var_export($fixture,true).'),true);'. <<<'PHP'
$config=new Wellness\Config('test',false,'test',[],'127.0.0.1',$f['port'],$f['database'],'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,
    staffExternalTenantId:'44444444-4444-4444-4444-444444444444',staffExternalSubdomain:'teststaff',staffExternalApiClientId:'88888888-8888-8888-8888-888888888888',staffExternalSpaClientId:'77777777-7777-7777-7777-777777777777',
    clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',practitionerApplicationsEnabled:!isset($_GET['disabled']),practitionerApplicationActivationEnabled:!isset($_GET['held']),externalStaffAuthEnabled:true);
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);$loader=fn()=>['keys'=>[$f['jwk']]];
(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,$loader));
(new ReflectionProperty(Wellness\Api::class,'externalStaffAuth'))->setValue($api,new Wellness\Auth\ExternalStaffAuthenticator($config,$loader));
$api->handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException($errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,$router],[['pipe','r'],['file',$router.'.log','a'],['file',$router.'.log','a']],$pipes);
try {
    $http=function(string $host,string $path,?string $bearer=null,?array $body=null)use($address):array{
        $curl=curl_init('http://'.$address.$path);$headers=['Host: '.$host];if($bearer)$headers[]='Authorization: Bearer '.$bearer;
        if($body!==null){$headers[]='Content-Type: application/json';curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);}
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);$content=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,is_string($content)?json_decode($content,true):null];
    };
    for($attempt=0;$attempt<30;$attempt++){if($http('portal.test','/api/v1/health')[0])break;usleep(100000);}
    $appToken=$signed($externalClaims);$adminToken=$signed($workforceClaims);$pendingToken=$signed(array_replace($externalClaims,['sub'=>'http-applicant']));
    $check($http('wellnexa.test','/api/v1/public/practitioner-applications')[1]['data']['enabled']===true,'HTTP public offer is anonymous');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me')[0]===401,'HTTP own application requires authentication');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$adminToken)[0]===401,'Workforce identity cannot become external applicant');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$signed(array_replace($externalClaims,['aud'=>'client-api'])))[0]===401,'Wrong audience cannot read application');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$signed(array_replace($externalClaims,['azp'=>'client-spa'])))[0]===401,'Wrong SPA cannot read application');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$appToken)[1]['data']['application']['id']===$id,'HTTP applicant sees own application');
    $check($http('other.test','/api/v1/practitioner-applications/me',$appToken)[1]['data']['application']===null,'HTTP clinic host scopes own lookup');
    $check($http('portal.test','/api/v1/practitioner-applications/me',$appToken)[0]===404,'Central host does not expose clinic applicant routes');
    $httpBody=$key(array_replace($profile,['given_name'=>'HTTP','email'=>'http@example.test']));
    $created=$http('wellnexa.test','/api/v1/practitioner-applications/me',$pendingToken,$httpBody);
    $check($created[0]===200 && $created[1]['data']['status']==='submitted','Signed applicant can submit without a staff membership');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$pendingToken,$httpBody)[1]['data']===$created[1]['data'],'HTTP submission replay is stable');
    $check($http('wellnexa.test','/api/v1/auth/me',$pendingToken)[0]===403,'Submission does not grant staff access');
    $check($http('wellnexa.test','/api/v1/clients',$pendingToken)[0]===403,'Unapproved applicant cannot read patient records');
    $check($http('portal.test','/api/v1/application/clinics/1/practitioner-applications',$adminToken)[0]===200,'Central grant can read applications');
    $check($http('portal.test','/api/v1/application/clinics/2/practitioner-applications/'.$id,$adminToken)[0]===404,'Central detail is clinic scoped');
    $check($http('wellnexa.test','/api/v1/application/clinics/1/practitioner-applications',$adminToken)[0]===404,'Clinic host cannot perform central review');
    $check($http('portal.test','/api/v1/application/clinics/1/practitioner-applications',$appToken)[0]===401,'External practitioner cannot obtain central privileges');
    $check($http('portal.test','/api/v1/application/clinics/1/practitioner-applications',$signed(array_replace($workforceClaims,['oid'=>'not-an-operator','roles'=>['Wellness.SuperAdmin']])))[0]===403,'Directory role is not a central grant');
    $check($http('wellnexa.test','/api/v1/public/practitioner-applications?disabled=1')[1]['data']['available']===false,'Disabled HTTP entry exposes no offer');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me?disabled=1',$appToken)[0]===503,'Disabled private application route');
    $check($http('wellnexa.test','/api/v1/auth/me?disabled=1',$appToken)[0]===200,'Durable external sign-in survives closed application feature');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/activate?held=1',$appToken,$activate)[0]===503,'HTTP activation gate is independent');
    $check($http('wellnexa.test','/api/v1/practitioner-applications/me',$pendingToken,array_replace($httpBody,['services'=>str_repeat('x',66000)]))[0]===413,'Applicant body bounded before parsing');
    $audit=$pdo->query("SELECT metadata FROM access_audit_events WHERE actor_type='practitioner_applicant' AND outcome='success' ORDER BY id DESC LIMIT 1")->fetchColumn();$meta=json_decode($audit,true);
    $check(isset($meta['applicant_fingerprint']) && strlen($meta['applicant_fingerprint'])===64 && !str_contains($audit,'http@example.test') && !str_contains($audit,'ciamlogin.com'),'Applicant audit stores fingerprint and resources, never contact details or raw claims');
} finally {
    if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    unlink($fixture);unlink($router);
}
echo "$checks practitioner application SQL/HTTP checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION).".\n";
