<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\BookingRequest;
use Wellness\Http\ApiException;

$checks=0;
foreach(['tomorrow','2026-02-30T09:00:00Z','2026-10-01T09:00:00','2026-10-01T09:00:01Z',[]] as $invalid){
    try{BookingRequest::timestamp($invalid);throw new RuntimeException('Invalid timestamp accepted');}catch(ApiException $e){if($e->status!==422)throw $e;$checks++;}
}
$start=BookingRequest::timestamp('2026-10-01T09:00:00-04:00')->setTimezone(new DateTimeZone('UTC'));
if($start->format('H:i:s')!=='13:00:00')throw new RuntimeException('Timezone conversion failed');$checks++;
$body=['client_id'=>1,'practitioner_id'=>2,'service_id'=>3,'duration_option_id'=>4,'location_id'=>5,'room_id'=>6];
$row=$body+['created_by'=>7,'starts_at'=>'2026-10-01 13:00:00'];
BookingRequest::assertReplay($row,$body,7,$start);$checks++;
foreach(array_keys($body) as $field){$changed=$body;$changed[$field]=99;try{BookingRequest::assertReplay($row,$changed,7,$start);throw new RuntimeException('Changed replay accepted');}catch(ApiException $e){if($e->status!==409)throw $e;$checks++;}}
try{BookingRequest::assertReplay($row,$body,8,$start);throw new RuntimeException('Different actor replay accepted');}catch(ApiException $e){if($e->status!==409)throw $e;$checks++;}
echo "{$checks} booking request tests passed.\n";
