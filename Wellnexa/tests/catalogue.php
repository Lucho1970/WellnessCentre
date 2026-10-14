<?php
declare(strict_types=1);
require dirname(__DIR__) . '/public/api/catalogue-lib.php';
$checks = 0;
$assert = function (bool $ok) use (&$checks): void { if (!$ok) throw new RuntimeException('Projection assertion failed.'); $checks++; };
$denies = function (callable $fn) use ($assert): void { try { $fn(); } catch (RuntimeException) { $assert(true); return; } throw new RuntimeException('Expected invalid catalogue.'); };
$service = ['slug'=>'synthetic-service','name'=>'Synthetic service','public_summary'=>'Published summary','durations'=>[['minutes'=>'60','price_cents'=>'10000','internal_note'=>'private']],'client_id'=>99,'internal_notes'=>'private'];
$projected = wellnexaCatalogueProjection('services', [$service])[0];
$assert(!isset($projected['client_id']) && !isset($projected['internal_notes']));
$assert($projected['durations'] === [['minutes'=>60,'price_cents'=>10000]]);
$person = ['slug'=>'synthetic-clinician','public_name'=>'Synthetic clinician','booking_practitioner_id'=>'7','services'=>[['slug'=>'synthetic-service','name'=>'Synthetic service','client_details'=>'private']],'email'=>'private@example.test','home_address'=>'private'];
$projected = wellnexaCatalogueProjection('practitioners', [$person])[0];
$assert(!isset($projected['email']) && !isset($projected['home_address']));
$assert($projected['booking_practitioner_id'] === 7);
$assert($projected['services'] === [['slug'=>'synthetic-service','name'=>'Synthetic service']]);
$assert(wellnexaCatalogueProjection('services', []) === []);
foreach ([['appointments', []], ['services', [$service + ['other'=>'x'], ['slug'=>'bad/path','name'=>'bad']]], ['services', [array_replace($service, ['name'=>['private']])]], ['services', [array_replace($service, ['durations'=>[['minutes'=>0,'price_cents'=>1]]])]], ['services', [array_replace($service, ['durations'=>[['minutes'=>60,'price_cents'=>-1]]])]], ['practitioners', [array_replace($person, ['booking_practitioner_id'=>'invalid'])]], ['practitioners', [array_replace($person, ['services'=>[['slug'=>'../private','name'=>'bad']]])]], ['services', array_fill(0,501,$service)]] as [$resource,$rows]) $denies(fn()=>wellnexaCatalogueProjection($resource,$rows));
echo "Public catalogue projection: $checks assertions passed.\n";
