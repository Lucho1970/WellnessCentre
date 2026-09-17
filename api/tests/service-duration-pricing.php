<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Wellness\Http\ApiException;
use Wellness\Service\AdminService;
$checks=0;
$assert=function(bool $condition)use(&$checks){if(!$condition)throw new RuntimeException('Duration pricing assertion failed');$checks++;};
$reject=function(array $body)use($assert){try{AdminService::durationOptions($body);}catch(ApiException $error){$assert($error->status===422);return;}throw new RuntimeException('Invalid duration pricing accepted');};
$options=AdminService::durationOptions(['duration_options'=>[['minutes'=>120,'price_cents'=>20000],['minutes'=>60,'price_cents'=>11000],['minutes'=>90,'price_cents'=>15500]]]);
$assert($options===[['minutes'=>60,'price_cents'=>11000],['minutes'=>90,'price_cents'=>15500],['minutes'=>120,'price_cents'=>20000]]);
$assert(AdminService::durationOptions(['durations'=>[60,90],'price_cents'=>10000])===[['minutes'=>60,'price_cents'=>10000],['minutes'=>90,'price_cents'=>10000]]);
foreach([
 [],['duration_options'=>[]],['duration_options'=>[['minutes'=>0,'price_cents'=>100]]],
 ['duration_options'=>[['minutes'=>61,'price_cents'=>100]]],['duration_options'=>[['minutes'=>495,'price_cents'=>100]]],
 ['duration_options'=>[['minutes'=>60,'price_cents'=>-1]]],['duration_options'=>[['minutes'=>60,'price_cents'=>10000001]]],
 ['duration_options'=>[['minutes'=>60,'price_cents'=>100],['minutes'=>60,'price_cents'=>200]]],
 ['duration_options'=>[['minutes'=>'not-a-number','price_cents'=>100]]],['duration_options'=>[['minutes'=>60,'price_cents'=>'12.50']]],
] as $invalid)$reject($invalid);
echo "$checks service duration pricing checks passed.\n";
