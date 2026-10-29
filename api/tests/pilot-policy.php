<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Firebase\JWT\JWT;
use Wellness\{Config, Database, PilotDeliveryBlocked, PilotPolicy};
use Wellness\Auth\{CustomerAuthenticator, EntraAuthenticator, ExternalStaffAuthenticator};
use Wellness\Http\ApiException;
use Wellness\Service\{GraphMailClient, VoipMsSmsClient};

$keys=['PILOT_MODE','PILOT_ALLOWED_IDENTITIES','PILOT_ALLOWED_EMAILS','PILOT_ALLOWED_SMS'];
$saved=[];
foreach($keys as $key)$saved[$key]=[array_key_exists($key,$_ENV),$_ENV[$key]??null,getenv($key)];
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$reject=static function(callable $work,string $class,string $why)use($check):Throwable{try{$work();}catch(Throwable $e){$check($e instanceof $class,$why.': '.get_class($e));return $e;}throw new RuntimeException($why.' accepted');};
$set=static function(string $key,string $value):void{$_ENV[$key]=$value;putenv($key.'='.$value);};
try {
    foreach($keys as $key){unset($_ENV[$key]);putenv($key);}
    $check(!PilotPolicy::fromEnvironment()->enabled,'Unset mode preserves existing operation');
    PilotPolicy::fromEnvironment()->assertIdentity('unknown','unknown','unknown');
    PilotPolicy::fromEnvironment()->assertMail('outside@example.test','sender@example.test',null,null);
    $set('PILOT_MODE','true');
    $error=$reject(fn()=>PilotPolicy::fromEnvironment()->assertIdentity('entra-workforce','https://issuer.test','one'),ApiException::class,'Empty list denies identities');
    $check($error->status===403&&$error->errorCode==='pilot_access_denied','Stable pilot access denial');
    $reject(fn()=>PilotPolicy::fromEnvironment()->assertRecipient('email','outside@example.test'),PilotDeliveryBlocked::class,'Empty list denies mail');
    $reject(fn()=>PilotPolicy::fromEnvironment()->assertRecipient('sms','+14165550101'),PilotDeliveryBlocked::class,'Empty list denies SMS');
    foreach(['','yes','TRUE','1','flase','false-ish'] as $value){$set('PILOT_MODE',$value);$reject(fn()=>PilotPolicy::fromEnvironment(),RuntimeException::class,'Invalid mode fails closed');}
    $set('PILOT_MODE','true');
    foreach(['{}','null','false','"test"','[','["*",4]',json_encode(array_fill(0,101,'tester@example.test')),'["Name <tester@example.test>"]','[" tester@example.test"]','["*@example.test"]','["tester@example.test,other@example.test"]'] as $value){
        $set('PILOT_ALLOWED_EMAILS',$value);$e=$reject(fn()=>PilotPolicy::fromEnvironment(),RuntimeException::class,'Invalid mail list');
        $check(!str_contains($e->getMessage(),'tester@'),'Configuration errors do not expose destinations');
    }
    $set('PILOT_ALLOWED_EMAILS','["tester@example.test","sender@example.test","reply@example.test"]');
    foreach(['["4165550101"]','["+12025550101"]','["*"]','[true]'] as $value){$set('PILOT_ALLOWED_SMS',$value);$reject(fn()=>PilotPolicy::fromEnvironment(),RuntimeException::class,'Noncanonical/non-Canadian SMS list');}
    $set('PILOT_ALLOWED_SMS','["+14165550101"]');
    foreach(['[{}]','[{"adapter":"entra-workforce","issuer":"http://issuer.test","subject":"one"}]','[{"adapter":"email","issuer":"https://issuer.test","subject":"one"}]','[{"adapter":"entra-workforce","issuer":"https://issuer.test","subject":""}]','[{"adapter":"entra-workforce","issuer":"https://issuer.test?x=1","subject":"one"}]','[{"adapter":"entra-workforce","issuer":"https://issuer.test","subject":"one","email":"tester@example.test"}]'] as $value){$set('PILOT_ALLOWED_IDENTITIES',$value);$reject(fn()=>PilotPolicy::fromEnvironment(),RuntimeException::class,'Invalid identity configuration');}
    $set('PILOT_ALLOWED_IDENTITIES','[]');
    $policy=PilotPolicy::fromEnvironment();
    $policy->assertMail('tester@EXAMPLE.test','sender@example.test','reply@example.test',"BEGIN:VCALENDAR\r\nORGANIZER:mailto:sender@example.test\r\nATTENDEE;RSVP=FALSE:mailto:tes\r\n ter@example.test\r\nEND:VCALENDAR\r\n");$checks++;
    foreach([
        ['outside@example.test','sender@example.test',null,null],
        ['TESTER@example.test','sender@example.test',null,null],
        ['tester+unapproved@example.test','sender@example.test',null,null],
        ['tester@example.test','outside@example.test',null,null],
        ['tester@example.test','sender@example.test','outside@example.test',null],
        ['tester@example.test','sender@example.test',null,'ATTENDEE:mailto:outside@example.test'],
        ['tester@example.test','sender@example.test',null,'ORGANIZER:mailto:outside%40example.test'],
        ['tester@example.test','sender@example.test',null,'ATTENDEE;DELEGATED-TO="mailto:outside@example.test":mailto:tester@example.test'],
        ['tester@example.test','sender@example.test',null,'ATTENDEE:https://outside.test'],
        ['tester@example.test','sender@example.test',null,str_repeat('x',65537)],
    ] as $args)$reject(fn()=>$policy->assertMail(...$args),PilotDeliveryBlocked::class,'Mail destination/reply/calendar boundary');
    $mailCalls=0;
    $mail=new GraphMailClient('11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','synthetic','sender@example.test',static function($url,$headers,$body,$sending)use(&$mailCalls):array{$mailCalls++;return $sending?[202,0,'']:[200,0,'{"access_token":"synthetic","expires_in":3600}'];});
    $reject(fn()=>$mail->send('outside@example.test','Synthetic','Synthetic'),PilotDeliveryBlocked::class,'Direct mail guard before OAuth');
    $check($mailCalls===0,'Blocked mail makes no OAuth or send call');
    $mail->send('tester@example.test','Synthetic','Synthetic',null,'reply@example.test');$check($mailCalls===2,'Approved mail uses existing token and send flow');
    $reject(fn()=>$mail->forSender('outside@example.test')->send('tester@example.test','Synthetic','Synthetic'),PilotDeliveryBlocked::class,'Cloned sender cannot bypass guard');
    $set('PILOT_ALLOWED_EMAILS','[]');$reject(fn()=>$mail->send('tester@example.test','Synthetic','Synthetic'),PilotDeliveryBlocked::class,'Existing transport rechecks current policy');$check($mailCalls===2,'Revoked recipient never reaches provider');
    $smsCalls=0;$sms=new VoipMsSmsClient('synthetic@example.test','synthetic','4165550100',static function()use(&$smsCalls):array{$smsCalls++;return[200,'{"status":"success","sms":"synthetic-1"}'];});
    $reject(fn()=>$sms->send('+14165550102','Synthetic'),PilotDeliveryBlocked::class,'Direct SMS blocked');$check($smsCalls===0,'Blocked SMS makes no call');
    $check($sms->forSender('4165550199')->send('+14165550101','Synthetic')==='synthetic-1'&&$smsCalls===1,'Approved SMS and sender clone retain checks');
    $set('PILOT_ALLOWED_SMS','[]');$reject(fn()=>$sms->send('+14165550101','Synthetic'),PilotDeliveryBlocked::class,'Existing SMS client rechecks');$check($smsCalls===1,'No call after SMS revocation');

    $config=new Config('test',false,'synthetic',[],'',3306,'','','','11111111-1111-1111-1111-111111111111','22222222-2222-2222-2222-222222222222','access_as_user',300,
        customerTenantId:'33333333-3333-3333-3333-333333333333',customerSubdomain:'synthetic',customerApiClientId:'44444444-4444-4444-4444-444444444444',customerSpaClientId:'55555555-5555-5555-5555-555555555555',
        staffInvitationsEnabled:true,staffExternalTenantId:'66666666-6666-6666-6666-666666666666',staffExternalSubdomain:'syntheticstaff',staffExternalApiClientId:'77777777-7777-7777-7777-777777777777',staffExternalSpaClientId:'88888888-8888-8888-8888-888888888888');
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);if(!$key)throw new RuntimeException('Test RSA key unavailable; configure local OpenSSL.');
    $details=openssl_pkey_get_details($key);$jwk=['kty'=>'RSA','kid'=>'pilot','n'=>JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>JWT::urlsafeB64Encode($details['rsa']['e'])];
    $jwks=static fn()=>['keys'=>[$jwk]];$sign=static fn(array $c)=>JWT::encode($c,$key,'RS256','pilot');
    $base=['ver'=>'2.0','iat'=>time()-5,'nbf'=>time()-5,'exp'=>time()+600];
    $work=$base+['iss'=>'https://login.microsoftonline.com/'.$config->entraTenantId.'/v2.0','tid'=>$config->entraTenantId,'aud'=>$config->entraApiClientId,'oid'=>'workforce-one','sub'=>'workforce-sub','scp'=>'access_as_user'];
    $client=$base+['iss'=>'https://'.$config->customerTenantId.'.ciamlogin.com/'.$config->customerTenantId.'/v2.0','tid'=>$config->customerTenantId,'aud'=>$config->customerApiClientId,'azp'=>$config->customerSpaClientId,'oid'=>'99999999-9999-9999-9999-999999999999','sub'=>'client-api-sub','scp'=>'access_as_client'];
    $external=$base+['iss'=>'https://'.$config->staffExternalTenantId.'.ciamlogin.com/'.$config->staffExternalTenantId.'/v2.0','tid'=>$config->staffExternalTenantId,'aud'=>$config->staffExternalApiClientId,'azp'=>$config->staffExternalSpaClientId,'sub'=>'external-one','scp'=>'access_as_staff'];
    $workAuth=new EntraAuthenticator($config,new Database($config),$jwks);$clientAuth=new CustomerAuthenticator($config,$jwks);$externalAuth=new ExternalStaffAuthenticator($config,$jwks);
    foreach([fn()=>$workAuth->verify($sign($work)),fn()=>$clientAuth->claims($sign($client)),fn()=>$externalAuth->verify($sign($external))] as $verify){$e=$reject($verify,ApiException::class,'Signed unapproved identity denied');$check($e->status===403&&$e->errorCode==='pilot_access_denied','Correct pilot failure, not signature failure');}
    $set('PILOT_ALLOWED_IDENTITIES',json_encode([
        ['adapter'=>'entra-workforce','issuer'=>$work['iss'],'subject'=>$work['oid']],
        ['adapter'=>'entra-customer','issuer'=>$client['iss'],'subject'=>$client['oid']],
        ['adapter'=>'entra-external-staff','issuer'=>$external['iss'],'subject'=>$external['sub']],
    ],JSON_THROW_ON_ERROR));
    $check($workAuth->verify($sign($work))->subject==='workforce-one','Approved workforce verifies without granting DB membership');
    $check($externalAuth->verify($sign($external))->subject==='external-one','Approved external identity verifies');
    $check($clientAuth->claims($sign($client))['sub']==='client-api-sub','Approved client API identity');
    $proof=array_replace($client,['aud'=>$config->customerSpaClientId,'sub'=>'different-pairwise-spa-sub']);
    $check($clientAuth->claims($sign($proof),true)['sub']==='different-pairwise-spa-sub','Client oid gate works across API/SPA pairwise subjects');
    $e=$reject(fn()=>$workAuth->verify($sign(array_replace($work,['oid'=>'other','email'=>'tester@example.test']))),ApiException::class,'Email/name cannot grant pilot access');$check($e->status===403,'No email-based grant');
    $reject(fn()=>$clientAuth->claims($sign(array_replace($client,['aud'=>'wrong-api']))),ApiException::class,'Pilot approval cannot bypass token audience');
    $reject(fn()=>$workAuth->verify(null),ApiException::class,'Pilot approval cannot bypass authentication');
    $set('PILOT_MODE','false');$check($workAuth->verify($sign(array_replace($work,['oid'=>'not-listed'])))->subject==='not-listed','Disabled pilot leaves normal verified identity flow intact');
    echo "Pilot safety policy/transports/identity: $checks checks passed.\n";
} finally {
    foreach($saved as $key=>[$present,$value,$process]){if($present)$_ENV[$key]=$value;else unset($_ENV[$key]);if($process===false)putenv($key);else putenv($key.'='.$process);}
}
