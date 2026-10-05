<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\BookingService;

$service=(new ReflectionClass(BookingService::class))->newInstanceWithoutConstructor();
$actor=static fn(string $type,array $roles): AuthContext=>new AuthContext(1,1,'','','',$type,$roles);
foreach ([
    [$actor('client',[]),[],403],
    [$actor('staff',['accountant']),[],403],
    [$actor('staff',['reception']),['scope'=>'practitioner'],403],
    [$actor('staff',['reception']),['scope'=>'invalid'],422],
] as [$user,$query,$status]) {
    try {$service->getForStaff($user,71,$query,'test');throw new RuntimeException('Unauthorized appointment deep link accepted.');}
    catch (ApiException $e) {if($e->status!==$status)throw $e;}
}

echo "Appointment deep-link authorization tests passed.\n";
