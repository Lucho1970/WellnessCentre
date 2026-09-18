<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\ClientService;
use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;

$count=0;
$valid=['given_name'=>' Esther ','family_name'=>' Vanderpoel ','email'=>'ESTHER@example.com'];
$data=ClientService::validate($valid);
if($data['display_name']!=='Esther Vanderpoel'||$data['email']!=='esther@example.com'||$data['date_of_birth']!==null||$data['address']!==null)throw new RuntimeException('Normalization failed');$count++;
foreach([
    ['given_name'=>' '],['family_name'=>[]],['email'=>'invalid'],['phone'=>str_repeat('1',41)],
    ['preferred_contact'=>'phone'],['preferred_contact'=>'invalid'],['status'=>'locked'],
    ['date_of_birth'=>'2026-02-30'],['date_of_birth'=>'2999-01-01'],['date_of_birth'=>[]],
    ['administrative_notes'=>str_repeat('x',4001)],['given_name'=>str_repeat('x',100),'family_name'=>str_repeat('y',100)],
] as $change){
    try{ClientService::validate(array_replace($valid,$change));throw new RuntimeException('Invalid client accepted');}
    catch(ApiException $e){if($e->status!==422)throw $e;$count++;}
}
ClientService::validate($valid+['date_of_birth'=>'2000-02-29','preferred_contact'=>'phone','phone'=>'+1 905 555 0100']);$count++;
$address=['address_line1'=>'123 Test Street','address_line2'=>'','city'=>'Test City','province'=>'Ontario','postal_code'=>'A1A 1A1','country'=>'Canada','instructions'=>''];
if(ClientService::validate($valid+['address'=>$address])['address']['postal_code']!=='A1A 1A1')throw new RuntimeException('Address normalization failed');$count++;
try{ClientService::validate($valid+['address'=>array_replace($address,['city'=>''])]);throw new RuntimeException('Incomplete address accepted');}catch(ApiException $e){if($e->status!==422)throw $e;$count++;}
foreach(['super_admin','clinic_admin','reception'] as $role){ClientService::authorize(new AuthContext(1,1,'','test@example.com','Test','staff',[$role]));$count++;}
ClientService::authorizeMerge(new AuthContext(1,1,'','test@example.com','Test','staff',['super_admin']));$count++;
try{ClientService::authorizeMerge(new AuthContext(1,1,'','test@example.com','Test','staff',['clinic_admin']));throw new RuntimeException('Unauthorized merge accepted');}catch(ApiException $e){if($e->status!==403)throw $e;$count++;}
foreach([['staff',['practitioner']],['staff',['accounting']],['client',['super_admin']],['staff',[]]] as [$type,$roles]){
    try{ClientService::authorize(new AuthContext(1,1,'','test@example.com','Test',$type,$roles));throw new RuntimeException('Unauthorized client access accepted');}
    catch(ApiException $e){if($e->status!==403)throw $e;$count++;}
}
echo "{$count} client validation and authorization tests passed.\n";
