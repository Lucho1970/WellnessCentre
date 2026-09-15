<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use Wellness\Http\ApiException;

final class BookingRequest
{
    public static function timestamp(mixed $value): DateTimeImmutable
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$value))throw new ApiException(422,'validation_error','starts_at must be an ISO-8601 timestamp including seconds and timezone.');
        try{$date=new DateTimeImmutable($value);$errors=DateTimeImmutable::getLastErrors();}catch(\Throwable){throw new ApiException(422,'validation_error','Invalid starts_at timestamp.');}
        if(($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('s')!=='00')throw new ApiException(422,'validation_error','Enter a valid timestamp with zero seconds.');
        return $date;
    }

    public static function assertReplay(array $row,array $body,int $userId,DateTimeImmutable $start): void
    {
        $matches=(int)$row['created_by']===$userId && $row['starts_at']===$start->format('Y-m-d H:i:s');
        foreach(['client_id','location_id','practitioner_id','service_id','duration_option_id','room_id'] as $field)$matches=$matches && (int)($row[$field]??0)===(int)($body[$field]??0);
        if(!$matches)throw new ApiException(409,'idempotency_conflict','This idempotency key was already used for another booking request.');
    }
}
