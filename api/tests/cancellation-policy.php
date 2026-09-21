<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wellness\Service\CancellationPolicy;

$utc=new \DateTimeZone('UTC');
$now=new \DateTimeImmutable('2026-09-21 12:00:00',$utc);
$base=['starts_at'=>'2026-09-22 12:00:00','base_price_cents'=>12000,'mobile_fee_cents'=>2000,'currency'=>'CAD','cancellation_window_minutes'=>1440];
$checks=0;
$expect=function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);$checks++;};

$outside=CancellationPolicy::preview(array_replace($base,['starts_at'=>'2026-09-23 12:00:00','cancellation_fee_type'=>'fixed','cancellation_fee_value'=>5000]),$now);
$expect($outside['fee_cents']===0&&!$outside['inside_fee_window'],'A fee must not apply before the cancellation deadline.');

$fixed=CancellationPolicy::preview($base+['cancellation_fee_type'=>'fixed','cancellation_fee_value'=>5000],$now);
$expect($fixed['fee_cents']===5000&&$fixed['inside_fee_window'],'A fixed fee must apply inside the window.');

$percentage=CancellationPolicy::preview($base+['cancellation_fee_type'=>'percentage','cancellation_fee_value'=>5000],$now);
$expect($percentage['fee_cents']===7000,'A percentage fee must use the snapshotted treatment and On-Site total.');

$capped=CancellationPolicy::preview($base+['cancellation_fee_type'=>'fixed','cancellation_fee_value'=>25000],$now);
$expect($capped['fee_cents']===14000,'A cancellation fee must not exceed the appointment total.');

$none=CancellationPolicy::preview($base+['cancellation_fee_type'=>'none','cancellation_fee_value'=>0],$now);
$expect($none['fee_cents']===0,'A no-fee policy must return zero.');

echo "{$checks} cancellation policy tests passed.\n";
