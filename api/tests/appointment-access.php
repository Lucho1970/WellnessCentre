<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Service\BookingService;
use Wellness\Service\ClientService;
use Wellness\Http\ApiException;

$checks=0;
foreach(['super_admin','clinic_admin','reception','practitioner'] as $role){
    BookingService::authorizeList(new AuthContext(1,1,'','','','staff',[$role]));$checks++;
}
BookingService::authorizeList(new AuthContext(1,1,'','','','client',[]));$checks++;
foreach([['staff',[]],['staff',['accounting']],['unknown',['super_admin']]] as [$type,$roles]){
    try{BookingService::authorizeList(new AuthContext(1,1,'','','',$type,$roles));throw new RuntimeException('Appointment listing accepted an unauthorized actor.');}
    catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
}
// The booking form uses the client-directory boundary: practitioner listing access must not grant directory access.
try{ClientService::authorize(new AuthContext(1,1,'','','','staff',['practitioner']));throw new RuntimeException('Practitioner gained client-directory access.');}
catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
echo "{$checks} appointment access checks passed.\n";
