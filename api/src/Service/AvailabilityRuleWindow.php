<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;

/** Calendar-week phase used by AvailabilityService; no expansion of an open-ended rule. */
final class AvailabilityRuleWindow
{
    public static function overlaps(array $rule,string $bufferStart,string $bufferEnd): bool
    {
        $zone=new DateTimeZone($rule['timezone']);$utc=new DateTimeZone('UTC');
        $start=new DateTimeImmutable($bufferStart,$utc);$end=new DateTimeImmutable($bufferEnd,$utc);
        $from=new DateTimeImmutable($rule['valid_from'],$zone);
        $day=$start->setTimezone($zone)->setTime(0,0);if($day<$from)$day=$from;
        $weekday=(int)$rule['weekday'];$interval=max(1,(int)$rule['recurrence_interval_weeks']);
        $anchor=$from->modify('-'.((int)$from->format('N')-1).' days');
        $candidate=$day->modify('+'.(($weekday-(int)$day->format('N')+7)%7).' days');
        $weeks=intdiv((int)$anchor->diff($candidate)->days,7);$phase=$weeks%$interval;
        if($phase)$candidate=$candidate->modify('+'.(($interval-$phase)*7).' days');
        // If today's window already ended, only the next occurrence could be the first overlap.
        $windowEnd=new DateTimeImmutable($candidate->format('Y-m-d').' '.$rule['end_time'],$zone);
        if($windowEnd<=$start)$candidate=$candidate->modify('+'.$interval.' weeks');
        $date=$candidate->format('Y-m-d');
        if($rule['valid_until']!==null&&$date>$rule['valid_until'])return false;
        $windowStart=new DateTimeImmutable($date.' '.$rule['start_time'],$zone);
        $windowEnd=new DateTimeImmutable($date.' '.$rule['end_time'],$zone);
        return $windowStart<$end&&$windowEnd>$start;
    }
}
