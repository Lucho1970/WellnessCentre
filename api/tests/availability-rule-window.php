<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\AvailabilityRuleWindow;
$checks=0;
$rule=['timezone'=>'America/Toronto','valid_from'=>'2035-06-04','valid_until'=>null,'weekday'=>1,'recurrence_interval_weeks'=>2,'start_time'=>'09:00:00','end_time'=>'17:00:00'];
$check=function(array $rule,string $start,string $end,bool $wanted)use(&$checks):void{if(AvailabilityRuleWindow::overlaps($rule,$start,$end)!==$wanted)throw new RuntimeException('Unexpected rule overlap: '.$start);$checks++;};
$check($rule,'2035-06-04 14:00:00','2035-06-04 15:00:00',true);
$check($rule,'2035-06-11 14:00:00','2035-06-11 15:00:00',false);
$check($rule,'2035-06-18 14:00:00','2035-06-18 15:00:00',true);
$check($rule,'2035-06-04 12:00:00','2035-06-04 13:00:00',false);
$check($rule,'2035-06-04 21:00:00','2035-06-04 22:00:00',false);
$check($rule,'2035-06-04 12:59:00','2035-06-04 13:01:00',true);
$check($rule,'2035-06-03 14:00:00','2035-06-03 15:00:00',false);
$check($rule,'2035-06-05 14:00:00','2035-06-05 15:00:00',false);
$check($rule,'2035-06-04 22:00:00','2035-06-18 14:00:00',true);
$check($rule,'2035-06-04 22:00:00','2035-06-18 13:00:00',false);
$check(array_replace($rule,['valid_until'=>'2035-06-17']),'2035-06-18 14:00:00','2035-06-18 15:00:00',false);
$check(array_replace($rule,['valid_until'=>'2035-06-18']),'2035-06-18 14:00:00','2035-06-18 15:00:00',true);
// Effective Wednesday: recurrence still anchors to that week's Monday, not the first occurrence.
$check(array_replace($rule,['valid_from'=>'2035-06-06']),'2035-06-11 14:00:00','2035-06-11 15:00:00',false);
$check(array_replace($rule,['valid_from'=>'2035-06-06']),'2035-06-18 14:00:00','2035-06-18 15:00:00',true);
// Spring gap and autumn fold use the same PHP local-time construction as the slot engine.
$dst=array_replace($rule,['valid_from'=>'2026-03-01','weekday'=>7,'recurrence_interval_weeks'=>1,'start_time'=>'01:00:00','end_time'=>'04:00:00']);
$check($dst,'2026-03-08 06:30:00','2026-03-08 07:30:00',true);
$check($dst,'2026-11-01 05:30:00','2026-11-01 07:30:00',true);
$check($dst,'2026-03-08 08:00:00','2026-03-08 09:00:00',false);
$check(array_replace($rule,['timezone'=>'Pacific/Auckland','weekday'=>2]),'2035-06-04 22:00:00','2035-06-04 23:00:00',true);
$check(array_replace($rule,['recurrence_interval_weeks'=>52]),'2035-06-18 14:00:00','2035-06-18 15:00:00',false);
$check($rule,'9000-01-01 00:00:00','9001-01-01 00:00:00',true);
echo "Availability rule window: $checks checks passed.\n";
