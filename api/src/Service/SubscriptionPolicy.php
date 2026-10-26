<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;

/** Pure billing rules. These never grant identity, clinic or clinical permissions. */
final class SubscriptionPolicy
{
    public static function trialEndsAt(DateTimeImmutable $activatedAt, string $timezone): DateTimeImmutable
    {
        $local = $activatedAt->setTimezone(new DateTimeZone($timezone));
        // Calendar months, clamped to the last day, rather than PHP's overflowing +6 months.
        $target = $local->modify('first day of this month')->modify('+6 months');
        return $target->setDate((int)$target->format('Y'), (int)$target->format('m'),
            min((int)$local->format('d'), (int)$target->format('t')))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Half-open intervals: access stops exactly at the stored end instant. */
    public static function allowsNewActivity(array $subscription, DateTimeImmutable $now): bool
    {
        $state = $subscription['status'] ?? '';
        $start = self::instant($subscription['starts_at'] ?? null);
        if ($start === null || $now < $start) return false;
        $end = match ($state) {
            'trialing' => self::instant($subscription['trial_ends_at'] ?? null),
            'active' => self::instant($subscription['paid_through_at'] ?? null),
            'past_due' => self::instant($subscription['grace_ends_at'] ?? null),
            default => null,
        };
        if ($end === null || $now >= $end) return false;
        $cancel = $subscription['cancel_at'] ?? null;
        if ($cancel !== null && $cancel !== '') {
            $cancelAt = self::instant($cancel);
            if ($cancelAt === null || $now >= $cancelAt) return false;
        }
        return true;
    }

    public static function hasFeature(array $subscription, array $features, string $feature, DateTimeImmutable $now): bool
    {
        return ($features[$feature] ?? false) === true && self::allowsNewActivity($subscription, $now);
    }

    /** Estimates and provider confirmations arrive here already converted to CAD cents. */
    public static function budgetAllows(int $limit, int $spent, int $reserved, int $estimate): bool
    {
        if (min($limit, $spent, $reserved) < 0 || $estimate <= 0) return false;
        // Subtraction avoids overflowing integer addition with hostile inputs.
        return $spent <= $limit && $reserved <= $limit - $spent && $estimate <= $limit - $spent - $reserved;
    }

    public static function month(DateTimeImmutable $now, string $timezone): string
    {
        return $now->setTimezone(new DateTimeZone($timezone))->format('Y-m-01');
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }
}
