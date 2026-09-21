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
$customer=new AuthContext(27,1,'','','','client',[]);
$payload=BookingService::customerPayload($customer,['service_id'=>4]);if($payload['client_id']!==27)throw new RuntimeException('Customer payload did not derive its client ID.');$checks++;
try{BookingService::customerPayload($customer,['client_id'=>28]);throw new RuntimeException('Customer payload accepted a browser client ID.');}catch(ApiException $e){if($e->status!==422)throw $e;$checks++;}
try{BookingService::customerPayload(new AuthContext(1,1,'','','','staff',['super_admin']),[]);throw new RuntimeException('Staff used customer booking payload.');}catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
BookingService::authorizeOptions(new AuthContext(1,1,'','','','client',[]));$checks++;
foreach(['super_admin','clinic_admin','reception','practitioner'] as $role){BookingService::authorizeOptions(new AuthContext(1,1,'','','','staff',[$role]));$checks++;}
foreach([['staff',['accounting']],['unknown',['super_admin']]] as [$type,$roles]){
    try{BookingService::authorizeOptions(new AuthContext(1,1,'','','',$type,$roles));throw new RuntimeException('Booking options accepted an unauthorized actor.');}
    catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
}
foreach(['super_admin','clinic_admin','reception','practitioner'] as $role){BookingService::authorizeBookingClientAddress(new AuthContext(1,1,'','','','staff',[$role]));$checks++;}
foreach([['client',[]],['staff',['accountant']]] as [$type,$roles]){
    try{BookingService::authorizeBookingClientAddress(new AuthContext(1,1,'','','',$type,$roles));throw new RuntimeException('Booking address lookup accepted an unauthorized actor.');}
    catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
}
foreach([['staff',[]],['staff',['accounting']],['unknown',['super_admin']]] as [$type,$roles]){
    try{BookingService::authorizeList(new AuthContext(1,1,'','','',$type,$roles));throw new RuntimeException('Appointment listing accepted an unauthorized actor.');}
    catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
}
foreach(['super_admin','clinic_admin','reception'] as $role){BookingService::authorizeChange(new AuthContext(1,1,'','','','staff',[$role]),false,'');$checks++;}
BookingService::authorizeChange(new AuthContext(1,1,'','','','staff',['practitioner']),true,'practitioner_managed');$checks++;
BookingService::authorizeChange(new AuthContext(1,1,'','','','staff',['practitioner'],['schedule_for_other_practitioners']),false,'clinic_managed');$checks++;
BookingService::authorizeCustomerChange(new AuthContext(27,1,'','','','client',[]),27);$checks++;
try{BookingService::authorizeCustomerChange(new AuthContext(27,1,'','','','client',[]),28);throw new RuntimeException('Client changed another client appointment.');}catch(ApiException $e){if($e->status!==404)throw $e;$checks++;}
foreach([[false,'practitioner_managed'],[true,'clinic_managed']] as [$owns,$mode]){
    try{BookingService::authorizeChange(new AuthContext(1,1,'','','','staff',['practitioner']),$owns,$mode);throw new RuntimeException('Practitioner changed an unauthorized appointment.');}
    catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
}
// The booking form uses the client-directory boundary: practitioner listing access must not grant directory access.
try{ClientService::authorize(new AuthContext(1,1,'','','','staff',['practitioner']));throw new RuntimeException('Practitioner gained client-directory access.');}
catch(ApiException $e){if($e->status!==403)throw $e;$checks++;}
echo "{$checks} appointment access checks passed.\n";
