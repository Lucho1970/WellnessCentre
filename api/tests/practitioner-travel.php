<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\PractitionerTravelService;

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$coordinates=PractitionerTravelService::coordinates(['latitude'=>43.7,'longitude'=>-79.4]);
$assert($coordinates===['latitude'=>43.7,'longitude'=>-79.4],'Valid coordinates were changed.');
foreach ([['latitude'=>'43.7','longitude'=>-79.4],['latitude'=>91,'longitude'=>0],['latitude'=>0,'longitude'=>INF]] as $input) {
    try {PractitionerTravelService::coordinates($input);throw new RuntimeException('Invalid coordinates accepted.');}
    catch(ApiException $error) {$assert($error->status===422,'Wrong coordinate error.');}
}
[$minutes,$kilometers]=PractitionerTravelService::parseRoute(['routes'=>[['duration'=>'1251s','distanceMeters'=>12345]]]);
$assert($minutes===21&&$kilometers===12.3,'Traffic route duration or distance was parsed incorrectly.');
foreach ([[],['routes'=>[['duration'=>'invalid','distanceMeters'=>2]]]] as $input) {
    try {PractitionerTravelService::parseRoute($input);throw new RuntimeException('Invalid route accepted.');}
    catch(ApiException $error) {$assert($error->status===422,'Wrong route error.');}
}
$service=(new ReflectionClass(PractitionerTravelService::class))->newInstanceWithoutConstructor();
$client=new AuthContext(1,1,'','','','client',[]);
try {$service->next($client);throw new RuntimeException('Client gained practitioner travel access.');}
catch(ApiException $error) {$assert($error->status===403,'Wrong practitioner access error.');}
try {$service->estimate($client,1,['source'=>'clinic'],'test');throw new RuntimeException('Client gained practitioner route access.');}
catch(ApiException $error) {$assert($error->status===403,'Wrong practitioner access error.');}
echo "Practitioner travel tests passed.\n";
