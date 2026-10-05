<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\AppointmentLogisticsNotesService;

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$actor=static fn(string $type,array $roles): AuthContext=>new AuthContext(7,1,'','','Test Staff',$type,$roles);
$assert(AppointmentLogisticsNotesService::authorize($actor('staff',['practitioner']))===true,'Practitioner must be restricted to own appointments.');
$assert(AppointmentLogisticsNotesService::authorize($actor('staff',['clinic_admin']))===false,'Clinic administrator should see clinic appointments.');
$assert(AppointmentLogisticsNotesService::authorize($actor('staff',['reception']))===false,'Reception should see clinic appointments.');
foreach([$actor('client',[]),$actor('staff',['accountant'])] as $denied){
    try{AppointmentLogisticsNotesService::authorize($denied);throw new RuntimeException('Unauthorized notes access accepted.');}
    catch(ApiException $error){$assert($error->status===403,'Wrong access denial.');}
}
$assert(AppointmentLogisticsNotesService::validateNote(['note'=>"  Use side entrance.\nCall on arrival.  "])==="Use side entrance.\nCall on arrival.",'Logistics note should be trimmed without losing line breaks.');
foreach([[],['note'=>'  '],['note'=>str_repeat('x',501)],['note'=>"bad\x00note"],['note'=>['not text']]] as $invalid){
    try{AppointmentLogisticsNotesService::validateNote($invalid);throw new RuntimeException('Invalid logistics note accepted.');}
    catch(ApiException $error){$assert($error->status===422,'Wrong validation status.');}
}
echo "Appointment logistics note tests passed.\n";
