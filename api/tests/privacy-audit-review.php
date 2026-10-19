<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\PrivacyAuditService;
use Wellness\Http\ApiException;
$checks=0;
$valid=['from'=>'2026-10-01','until'=>'2026-11-01','actor_type'=>'staff','actor_id'=>'12','resource_type'=>'client','resource_id'=>'101','outcome'=>'denied','before'=>'300'];
$parsed=PrivacyAuditService::filters($valid);if($parsed['actor_id']!==12||$parsed['resource_id']!==101||$parsed['before']!==300)throw new RuntimeException('Typed numeric filters missing');$checks++;
foreach([
 ['from'=>'2026-02-30'],['until'=>'2026-11-01junk'],['from'=>[]],['until'=>null],['from'=>'2026-11-01','until'=>'2026-11-01'],['from'=>'2026-11-02'],['from'=>'2026-09-30'],
 ['actor_type'=>'patient'],['actor_type'=>[]],['actor_id'=>'1 OR 1=1'],['actor_id'=>'-1'],['actor_id'=>['1']],['actor_id'=>'9223372036854775808'],['actor_type'=>'','actor_id'=>'1'],
 ['resource_type'=>'payload'],['resource_type'=>''],['resource_id'=>'0'],['resource_id'=>''],['before'=>'1.5'],['before'=>true],['outcome'=>'queued'],['clinic_id'=>2],['q'=>'Private patient name'],['limit'=>99999]
] as $change){try{PrivacyAuditService::filters(array_replace($valid,$change));throw new RuntimeException('Invalid audit filters accepted: '.json_encode($change));}catch(ApiException $e){if($e->status!==422)throw $e;$checks++;}}
$default=PrivacyAuditService::filters([]);$span=(new DateTimeImmutable($default['from']))->diff(new DateTimeImmutable($default['until']))->days;if($span!==7)throw new RuntimeException('Unexpected default range');$checks++;
echo "Privacy audit filter validation: $checks checks passed.\n";
