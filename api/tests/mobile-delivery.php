<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\Delivery;
use Wellness\Service\BookingRequest;
use Wellness\Http\ApiException;

$checks=0;
function check(bool $ok):void { global $checks; if(!$ok)throw new RuntimeException('Mobile assertion failed');$checks++; }
function rejected(callable $call,int $status=422):void { try{$call();}catch(ApiException $error){check($error->status===$status);return;}throw new RuntimeException('Invalid request accepted'); }
$rule=['offers_mobile'=>1,'offers_clinic'=>0,'requires_room'=>1,'travel_buffer_minutes'=>30,'mobile_fee_cents'=>2500,'base_price_cents'=>12000];
check(Delivery::terms($rule,'mobile')===['requires_room'=>false,'travel'=>30,'base'=>12000,'fee'=>2500]);
rejected(fn()=>Delivery::terms($rule,'clinic'));
$rule['offers_clinic']=1;
check(Delivery::terms($rule,'clinic')===['requires_room'=>true,'travel'=>0,'base'=>12000,'fee'=>0]);
$rule['offers_mobile']=0;rejected(fn()=>Delivery::terms($rule,'mobile'));
rejected(fn()=>Delivery::mode(['delivery_mode'=>'video']));
rejected(fn()=>Delivery::destination(['delivery_mode'=>'mobile']));
$address=['address_line1'=>' 123 Test Street ','address_line2'=>'','city'=>'Test City','province'=>'Ontario','postal_code'=>'A1A 1A1','country'=>'Canada','instructions'=>'Side entrance'];
$body=['delivery_mode'=>'mobile','destination'=>$address];
$snapshot=Delivery::destination($body);check($snapshot['address_line1']==='123 Test Street');
foreach(['address_line1','city','province','postal_code','country'] as $field){$invalid=$body;$invalid['destination'][$field]='';rejected(fn()=>Delivery::destination($invalid));}
$invalid=$body;$invalid['destination']['instructions']=str_repeat('x',501);rejected(fn()=>Delivery::destination($invalid));
check(Delivery::destination(['delivery_mode'=>'clinic'])===null);
$start=BookingRequest::timestamp('2026-10-01T13:00:00Z');
$row=['created_by'=>7,'starts_at'=>'2026-10-01 13:00:00','delivery_mode'=>'mobile','destination_snapshot'=>json_encode($snapshot)];
BookingRequest::assertReplay($row,$body,7,$start);check(true);
$changed=$body;$changed['destination']['address_line1']='Another address';rejected(fn()=>BookingRequest::assertReplay($row,$changed,7,$start),409);
rejected(fn()=>BookingRequest::assertReplay($row,['delivery_mode'=>'clinic'],7,$start),409);
echo "{$checks} mobile delivery checks passed.\n";
