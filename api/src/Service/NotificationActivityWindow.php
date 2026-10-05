<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;

final class NotificationActivityWindow
{
    /** @return array{0:string,1:string} UTC inclusive lower and exclusive upper bounds. */
    public static function bounds(string $period, string $timezone, ?DateTimeImmutable $now = null): array
    {
        $utc = new DateTimeZone('UTC');
        $instant = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $local = $instant->setTimezone(new DateTimeZone($timezone));
        $from = match ($period) {
            'today' => $local->setTime(0, 0)->setTimezone($utc),
            'last7' => $instant->modify('-7 days'),
            'week' => $local->modify('monday this week')->setTime(0, 0)->setTimezone($utc),
            default => throw new \InvalidArgumentException('Unsupported notification activity period.'),
        };
        return [$from->format('Y-m-d H:i:s'), $instant->modify('+1 second')->format('Y-m-d H:i:s')];
    }
}
