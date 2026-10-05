<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Http\ApiException;
use Wellness\Service\AdminService;

$service=(new ReflectionClass(AdminService::class))->newInstanceWithoutConstructor();
$check=new ReflectionMethod(AdminService::class,'assertTimeOffImpactMatches');
$affected=[['id'=>'71'],['id'=>'72']];
$check->invoke($service,['expected_affected_appointment_ids'=>[72,71]],$affected);
$check->invoke($service,[],$affected); // Legacy callers still receive the server-side impact count.

foreach ([
    [['expected_affected_appointment_ids'=>[71]],409],
    [['expected_affected_appointment_ids'=>[71,71]],422],
    [['expected_affected_appointment_ids'=>['invalid',72]],422],
] as [$body,$status]) {
    try {$check->invoke($service,$body,$affected);throw new RuntimeException('Invalid impact acknowledgement accepted.');}
    catch (ApiException $e) {if($e->status!==$status)throw $e;}
}

echo "Time-off impact review tests passed.\n";
