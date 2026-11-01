<?php
declare(strict_types=1);
// Internal router fixture, never a deployed endpoint. Test runner owns the private temp path.
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true' || !is_string(getenv('PILOT_HTTP_FIXTURE')))throw new RuntimeException('Disposable fixture only');
$f=json_decode(file_get_contents(getenv('PILOT_HTTP_FIXTURE')),true,8,JSON_THROW_ON_ERROR);
if(!preg_match('/^wellness_subscription_test_[a-f0-9]{12}$/D',$f['database']))throw new RuntimeException('Disposable database only');
$config=new Wellness\Config('test',false,'test',[],'127.0.0.1',$f['port'],$f['database'],'root','','11111111-1111-1111-1111-111111111111','33333333-3333-3333-3333-333333333333','access_as_user',300,
 staffExternalTenantId:'44444444-4444-4444-4444-444444444444',staffExternalSubdomain:'teststaff',staffExternalApiClientId:'88888888-8888-8888-8888-888888888888',staffExternalSpaClientId:'77777777-7777-7777-7777-777777777777',
 clinicManagementEnabled:true,applicationAdminEnabled:true,applicationAdminHost:'portal.test',externalStaffAuthEnabled:true,practitionerPilotAccessEnabled:true);
$db=new Wellness\Database($config);$api=new Wellness\Api($config,$db);$loader=fn()=>['keys'=>[$f['jwk']]];
(new ReflectionProperty(Wellness\Api::class,'auth'))->setValue($api,new Wellness\Auth\EntraAuthenticator($config,$db,$loader));
$pilot=new Wellness\Service\PractitionerPilotAccessService($db,$config);
(new ReflectionProperty(Wellness\Api::class,'practitionerPilotAccess'))->setValue($api,$pilot);
(new ReflectionProperty(Wellness\Api::class,'externalStaffAuth'))->setValue($api,new Wellness\Auth\ExternalStaffAuthenticator($config,$loader,function($identity)use($api,$pilot):void{
 $clinic=(new ReflectionProperty(Wellness\Api::class,'clinicContext'))->getValue($api);
 $pilot->assertAllowed($identity,$clinic->clinicId);
}));
$api->handle();
