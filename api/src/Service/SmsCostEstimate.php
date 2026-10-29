<?php
declare(strict_types=1);
namespace Wellness\Service;

/** Conservative integer CAD accounting. A tariff is operator-approved, never a provider quote. */
final class SmsCostEstimate
{
    public static function estimate(string $message,int $microsPerSegment,int $fixedMicros=0): array
    {
        if($message==='' || strlen($message)>160 || preg_match('/[^\x20-\x7e]/',$message)
            || $microsPerSegment<1 || $microsPerSegment>100000000 || $fixedMicros<0 || $fixedMicros>100000000)
            throw new \InvalidArgumentException('Invalid SMS estimate input.');
        // These ASCII characters consume two GSM septets. Backtick is outside GSM-7.
        $unicode=str_contains($message,'`');$units=strlen($message);
        if(!$unicode)foreach(str_split($message) as $character)if(str_contains('^{}\\[~]|',$character))$units++;
        $single=$unicode?70:160;$multipart=$unicode?67:153;
        $segments=$units<=$single?1:(int)ceil($units/$multipart);
        return ['segments'=>$segments,'estimated_cents'=>intdiv($segments*$microsPerSegment+$fixedMicros+9999,10000)];
    }
}
