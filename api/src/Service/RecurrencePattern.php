<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Wellness\Http\ApiException;

/** Calendar recurrence in the location timezone; never silently shift a DST gap. */
final class RecurrencePattern
{
    public static function dates(string $start, string $timezone, array $pattern): array
    {
        $frequency=$pattern['frequency']??'';
        $count=filter_var($pattern['count']??null,FILTER_VALIDATE_INT);
        $until=$pattern['until']??null;
        if(array_diff(array_keys($pattern),['frequency','count','until'])||!in_array($frequency,['weekly','biweekly','monthly'],true)||($count===false&&$until===null)||($count!==false&&$until!==null)||($count!==false&&($count<2||$count>26)))
            throw new ApiException(422,'invalid_recurrence','Choose weekly, every two weeks, or monthly, and either 2–26 appointments or an end date.');
        $zone=new DateTimeZone($timezone);
        $first=BookingRequest::timestamp($start)->setTimezone($zone);
        $anchor=new DateTimeImmutable($first->format('Y-m-d').' 00:00:00',new DateTimeZone('UTC'));
        if($until!==null&&(!is_string($until)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$until)||!($end=DateTimeImmutable::createFromFormat('!Y-m-d',$until))||$end->format('Y-m-d')!==$until||$until<$anchor->format('Y-m-d')))
            throw new ApiException(422,'invalid_recurrence','Choose a valid end date after the first appointment.');
        $rows=[];
        for($i=0;$i<27;$i++){
            if($count!==false&&$i>=$count)break;
            if($frequency==='monthly'){
                $month=$anchor->modify('first day of this month')->modify("+{$i} months");
                $date=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$anchor->format('d'),(int)$month->format('t')));
            }else $date=$anchor->modify('+'.($i*($frequency==='weekly'?7:14)).' days');
            if($until!==null&&$date->format('Y-m-d')>$until)break;
            if($i===26||$date>$anchor->modify('+1 year'))throw new ApiException(422,'invalid_recurrence','Limit the series to 26 appointments within one year.');
            $wall=$date->format('Y-m-d').' '.$first->format('H:i:s');
            $resolved=self::wallTime($wall,$zone);
            // The first time was explicitly selected from availability, including its offset.
            if($i===0)$resolved=$first;
            $rows[]=['local_time'=>$wall,'starts_at'=>$resolved?->format('Y-m-d\TH:i:sP'),'error'=>$resolved===null?'This local time is missing or occurs twice because of daylight saving time. Choose another time.':null];
        }
        if(count($rows)<2)throw new ApiException(422,'invalid_recurrence','The series must contain at least two appointments.');
        return $rows;
    }

    public static function wallTime(string $wall,DateTimeZone $zone): ?DateTimeImmutable
    {
        $naive=new DateTimeImmutable($wall,new DateTimeZone('UTC'));
        $offsets=[];
        $transitions=$zone->getTransitions($naive->getTimestamp()-172800,$naive->getTimestamp()+172800);
        if($transitions===false)$offsets[$zone->getOffset($naive)]=true;
        foreach($transitions?:[] as $transition)$offsets[$transition['offset']]=true;
        $matches=[];
        foreach(array_keys($offsets) as $offset){
            $candidate=$naive->setTimestamp($naive->getTimestamp()-(int)$offset)->setTimezone($zone);
            if($candidate->format('Y-m-d H:i:s')===$wall)$matches[]=$candidate;
        }
        return count($matches)===1?$matches[0]:null;
    }
}
