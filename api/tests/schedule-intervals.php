<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\ScheduleIntervals;
$cases=[
    [[],[]],
    [[['start_time'=>'09:00:00','end_time'=>'12:00:00'],['start_time'=>'11:00:00','end_time'=>'17:00:00']],[['start_time'=>'09:00:00','end_time'=>'17:00:00']]],
    [[['start_time'=>'13:00:00','end_time'=>'17:00:00'],['start_time'=>'09:00:00','end_time'=>'13:00:00']],[['start_time'=>'09:00:00','end_time'=>'17:00:00']]],
    [[['start_time'=>'09:00:00','end_time'=>'12:00:00'],['start_time'=>'13:00:00','end_time'=>'17:00:00']],[['start_time'=>'09:00:00','end_time'=>'12:00:00'],['start_time'=>'13:00:00','end_time'=>'17:00:00']]],
];
foreach($cases as $i=>[$input,$expected])if(ScheduleIntervals::merge($input)!==$expected)throw new RuntimeException("Interval test {$i} failed");
echo "4 scheduling interval tests passed.\n";
$zone=new DateTimeZone('America/Toronto');
foreach(['2026-03-08T01:45:00-05:00'=>'2026-03-08T03:15:00-04:00','2026-11-01T01:45:00-04:00'=>'2026-11-01T01:15:00-05:00'] as $input=>$expected){
    $start=(new DateTimeImmutable($input))->setTimezone($zone);
    $end=$start->setTimestamp($start->getTimestamp()+1800);
    if($end->format(DATE_ATOM)!==$expected)throw new RuntimeException('Elapsed duration across daylight saving failed');
}
echo "2 daylight-saving duration checks passed.\n";
