<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Firebase\JWT\JWT;
use Wellness\Auth\ExternalStaffAuthenticator;
use Wellness\Auth\CustomerAuthenticator;
use Wellness\Config;
use Wellness\Http\ApiException;

$tenant='11111111-1111-1111-1111-111111111111';
$api='22222222-2222-2222-2222-222222222222';
$spa='33333333-3333-3333-3333-333333333333';
$clientApi='44444444-4444-4444-4444-444444444444';
$clientSpa='55555555-5555-5555-5555-555555555555';
$config=new Config('test',false,'test',[],'',3306,'','','','workforce','workforce-api','access_as_user',300,
 customerTenantId:$tenant,customerSubdomain:'teststaff',customerApiClientId:$clientApi,customerSpaClientId:$clientSpa,
 staffInvitationsEnabled:true,staffExternalTenantId:$tenant,staffExternalSubdomain:'teststaff',staffExternalApiClientId:$api,staffExternalSpaClientId:$spa);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
if(!$key)throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($key)['rsa'];
$jwk=['kty'=>'RSA','kid'=>'test','n'=>JWT::urlsafeB64Encode($rsa['n']),'e'=>JWT::urlsafeB64Encode($rsa['e'])];
$loader=fn()=>['keys'=>[$jwk]];
$auth=new ExternalStaffAuthenticator($config,$loader);
$claims=['iss'=>"https://$tenant.ciamlogin.com/$tenant/v2.0",'tid'=>$tenant,'aud'=>$api,'azp'=>$spa,'sub'=>'staff-subject','ver'=>'2.0','iat'=>time()-10,'nbf'=>time()-10,'exp'=>time()+300,'scp'=>'access_as_staff'];
$sign=fn(array $c)=>JWT::encode($c,$key,'RS256','test');
$checks=0;
function check(bool $ok): void {global $checks;if(!$ok)throw new RuntimeException('External staff assertion failed');$checks++;}
function denied(callable $action,int $status): void {try{$action();}catch(ApiException $e){check($e->status===$status);return;}throw new RuntimeException('Expected authorization denial');}
$identity=$auth->verify($sign($claims+['roles'=>['Wellness.SuperAdmin'],'email'=>'admin@example.test']));
check($identity->adapter==='entra-external-staff'&&$identity->subject==='staff-subject'&&$identity->directoryRoles===[]);
check($auth->isCandidate($sign($claims)));
check(!$auth->isCandidate('broken')&&!$auth->isCandidate(str_repeat('a',16385)));
foreach(['iss'=>'https://other.test','tid'=>'other','aud'=>$clientApi,'azp'=>$clientSpa,'ver'=>'1.0','sub'=>'','exp'=>time()-120,'iat'=>time()+120,'nbf'=>time()+120] as $field=>$value)denied(fn()=>$auth->verify($sign(array_replace($claims,[$field=>$value]))),401);
foreach(['azp','sub','exp','iat','scp'] as $field){$bad=$claims;unset($bad[$field]);denied(fn()=>$auth->verify($sign($bad)),401);}
denied(fn()=>$auth->verify(null),401);
denied(fn()=>$auth->verify($sign(array_replace($claims,['scp'=>'access_as_client']))),403);
denied(fn()=>$auth->verify($sign(array_replace($claims,['scp'=>'access_as_staff_extra']))),403);
denied(fn()=>$auth->verify(JWT::encode($claims,str_repeat('x',64),'HS256','test')),401);
$other=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
denied(fn()=>$auth->verify(JWT::encode($claims,$other,'RS256','test')),401);
$customerClaims=array_replace($claims,['aud'=>$clientApi,'azp'=>$clientSpa,'scp'=>'access_as_client']);
denied(fn()=>$auth->verify($sign($customerClaims)),401);
check(!$auth->isCandidate($sign($customerClaims)));
$customer=new CustomerAuthenticator($config,$loader);
denied(fn()=>$customer->authenticate($sign($claims)),401);
$disabled=new Config('test',false,'test',[],'',3306,'','','','workforce','api','access_as_user',300);
denied(fn()=>(new ExternalStaffAuthenticator($disabled,$loader))->verify($sign($claims)),503);
check(!(new ExternalStaffAuthenticator($disabled,$loader))->isCandidate($sign($claims)));
$same=new Config('test',false,'test',[],'',3306,'','','','workforce','api','access_as_user',300,
 customerApiClientId:$api,staffInvitationsEnabled:true,staffExternalTenantId:$tenant,staffExternalSubdomain:'teststaff',staffExternalApiClientId:$api,staffExternalSpaClientId:$spa);
denied(fn()=>(new ExternalStaffAuthenticator($same,$loader))->verify($sign($claims)),503);
$rotated=new ExternalStaffAuthenticator($config,fn(bool $refresh)=>['keys'=>$refresh?[$jwk]:[]]);
check($rotated->verify($sign($claims))->subject==='staff-subject');
echo "$checks external staff token checks passed.\n";
