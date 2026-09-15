<?php
declare(strict_types=1);

namespace Wellness\Service;

final class ScheduleIntervals
{
    /** Merge overlapping and adjacent local working-hour intervals. */
    public static function merge(array $rules): array
    {
        usort($rules, fn(array $a,array $b): int => strcmp($a['start_time'],$b['start_time']));
        $result=[];
        foreach($rules as $rule){
            if($rule['start_time'] >= $rule['end_time']) continue;
            $last=count($result)-1;
            if($last>=0 && $rule['start_time'] <= $result[$last]['end_time']) $result[$last]['end_time']=max($result[$last]['end_time'],$rule['end_time']);
            else $result[]=$rule;
        }
        return $result;
    }
}
