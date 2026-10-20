<?php
declare(strict_types=1);
// Local integration router only. No production .env, remote JWKS or test HTTP bypass.
if(PHP_SAPI!=='cli-server'||getenv('ACCESS_AUDIT_TEST_ALLOW')!=='synthetic-local-only')throw new RuntimeException('Local audit test router only.');
require dirname(__DIR__,2).'/vendor/autoload.php';
use Wellness\{Api,Config,Database};
use Wellness\Auth\{EntraAuthenticator,CustomerAuthenticator};
$name=(string)getenv('ACCESS_AUDIT_TEST_DB');
if(!preg_match('/^wellness_booking_access_test_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Disposable test database required.');
$coverageTest=getenv('ACCESS_AUDIT_TEST_COVERAGE')==='synthetic-local-only';
$config=new Config('test',false,'synthetic-key',[],'127.0.0.1',(int)getenv('RECURRING_TEST_PORT'),$name,'root','',
    'audit-tenant','audit-api','access_as_user',300,
    customerTenantId:'11111111-1111-1111-1111-111111111111',customerSubdomain:'synthetic',
    customerApiClientId:'22222222-2222-2222-2222-222222222222',customerSpaClientId:'33333333-3333-3333-3333-333333333333',
    staffInvitationsEnabled:getenv('ACCESS_AUDIT_TEST_STAFF_INVITATIONS')==='synthetic-local-only',
    customerOnboardingEnabled:true,customerClinicId:1,clientPortalUrl:'https://audit-a.test/',
    clinicHostMap:['audit-a.test'=>1,'audit-b.test'=>2],
    googleMapsApiKey:$coverageTest?'synthetic-coverage':'',addressValidationSigningKey:$coverageTest?str_repeat('c',32):'');
$db=new Database($config);$api=new Api($config,$db);
if($coverageTest){
    $transport=static function(string $url,array $headers,array $payload):array{
        file_put_contents(dirname(__DIR__,3).'/.tmp/consent-coverage-provider.jsonl',json_encode(['url'=>$url,'payload'=>$payload],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if(str_contains($url,'computeRoutes'))return ['routes'=>[['distanceMeters'=>7500]]];
        return ['result'=>['verdict'=>['addressComplete'=>true],'address'=>['postalAddress'=>$payload['address']], 'geocode'=>['location'=>['latitude'=>43.7,'longitude'=>-79.4]]]];
    };
    $coverage=new \Wellness\Service\AddressCoverageService($db,$config,$transport);
    (new ReflectionProperty(Api::class,'addressCoverage'))->setValue($api,$coverage);
    (new ReflectionProperty(Api::class,'bookings'))->setValue($api,new \Wellness\Service\BookingService($db,new \Wellness\Service\AuditLogger($db),$coverage));
}
$jwks=json_decode((string)getenv('ACCESS_AUDIT_TEST_JWKS'),true,32,JSON_THROW_ON_ERROR);
(new ReflectionProperty(Api::class,'auth'))->setValue($api,new EntraAuthenticator($config,$db,static fn()=>$jwks));
(new ReflectionProperty(Api::class,'customerAuth'))->setValue($api,new CustomerAuthenticator($config,static fn(bool $refresh)=>$jwks));
$_ENV['CLIENT_FORMS_ENABLED']='true';
$api->handle();
