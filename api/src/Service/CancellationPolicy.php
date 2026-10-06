<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;

final class CancellationPolicy
{
    public static function acknowledge(array $body, array $preview): void
    {
        $value = $body['expected_cancellation_fee_cents'] ?? null;
        if (!is_int($value) || $value < 0) throw new \Wellness\Http\ApiException(422,'cancellation_fee_acknowledgement_required','Review the cancellation fee before confirming.');
        if ($value !== (int)$preview['fee_cents']) throw new \Wellness\Http\ApiException(409,'cancellation_fee_changed','The cancellation fee changed. Review the updated fee before confirming.');
    }

    public static function preview(array $appointment,?DateTimeImmutable $now=null): array
    {
        $now??=new DateTimeImmutable('now',new \DateTimeZone('UTC'));
        $start=new DateTimeImmutable((string)$appointment['starts_at'],new \DateTimeZone('UTC'));
        $window=max(0,(int)($appointment['cancellation_window_minutes']??0));
        $deadline=$start->modify("-{$window} minutes");
        $inside=$now>=$deadline;
        $total=max(0,(int)($appointment['base_price_cents']??0)+(int)($appointment['mobile_fee_cents']??0));
        $type=(string)($appointment['cancellation_fee_type']??'none');
        $value=max(0,(int)($appointment['cancellation_fee_value']??0));
        $fee=0;
        if($inside&&$type==='fixed')$fee=min($total,$value);
        if($inside&&$type==='percentage')$fee=min($total,(int)round($total*$value/10000));
        return [
            'window_minutes'=>$window,
            'deadline'=>$deadline->format(DATE_ATOM),
            'inside_fee_window'=>$inside,
            'fee_type'=>$type,
            'fee_value'=>$value,
            'appointment_total_cents'=>$total,
            'fee_cents'=>$fee,
            'currency'=>(string)($appointment['currency']??'CAD'),
        ];
    }
}
