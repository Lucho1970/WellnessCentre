<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class NotificationSchedulerHealth
{
    public function __construct(private readonly Database $database) {}

    public static function start(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO notification_scheduler_state(id,status,last_started_at,last_completed_at)
            VALUES(1,'running',UTC_TIMESTAMP(),NULL)
            ON DUPLICATE KEY UPDATE status='running',last_started_at=UTC_TIMESTAMP(),last_completed_at=NULL");
    }

    public static function succeed(PDO $pdo, array $counts): void
    {
        $statement = $pdo->prepare("UPDATE notification_scheduler_state SET status='succeeded',last_completed_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),last_error_class=NULL,
            sent_count=:sent,retry_count=:retry,review_count=:review,canceled_count=:canceled WHERE id=1");
        $statement->execute([
            'sent' => (int)($counts['sent'] ?? 0),
            'retry' => (int)($counts['retry'] ?? 0),
            'review' => (int)($counts['review'] ?? 0),
            'canceled' => (int)($counts['canceled'] ?? 0),
        ]);
    }

    public static function fail(PDO $pdo, Throwable $error): void
    {
        $statement = $pdo->prepare("UPDATE notification_scheduler_state SET status='failed',last_completed_at=UTC_TIMESTAMP(),last_failure_at=UTC_TIMESTAMP(),last_error_class=:class WHERE id=1");
        $statement->execute(['class' => substr(get_class($error), 0, 190)]);
    }

    public function summary(AuthContext $actor): array
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin')) {
            throw new ApiException(403, 'forbidden', 'Administrator access is required.');
        }
        $pdo = $this->database->connection();
        $record = $pdo->query('SELECT status,last_started_at,last_completed_at,last_success_at,last_failure_at FROM notification_scheduler_state WHERE id=1')->fetch() ?: null;
        $overdue = $pdo->prepare("SELECT COUNT(*) FROM notification_events WHERE clinic_id=:clinic AND status IN ('queued','failed')
            AND GREATEST(scheduled_at,COALESCE(next_attempt_at,scheduled_at)) < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");
        $overdue->execute(['clinic' => $actor->clinicId]);
        return self::assess($record, (int)$overdue->fetchColumn());
    }

    public static function assess(?array $record, int $overdue, ?DateTimeImmutable $now = null): array
    {
        $instant = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $state = 'healthy';
        if ($record === null) $state = $overdue > 0 ? 'overdue' : 'not_observed';
        elseif ($record['status'] === 'failed') $state = 'failed';
        elseif ($record['status'] === 'running' && self::age($record['last_started_at'], $instant) > 300) $state = 'stuck';
        elseif ($record['status'] === 'running') $state = 'running';
        elseif (self::age($record['last_success_at'], $instant) > 2700) $state = 'stale';
        elseif ($overdue > 0) $state = 'overdue';
        return [
            'state' => $state,
            'last_status' => $record['status'] ?? null,
            'last_started_at' => $record['last_started_at'] ?? null,
            'last_completed_at' => $record['last_completed_at'] ?? null,
            'last_success_at' => $record['last_success_at'] ?? null,
            'last_failure_at' => $record['last_failure_at'] ?? null,
            'overdue_count' => $overdue,
            'stale_after_minutes' => 45,
            'overdue_after_minutes' => 30,
        ];
    }

    private static function age(?string $utcDate, int $now): int
    {
        if ($utcDate === null) return PHP_INT_MAX;
        $timestamp = strtotime($utcDate . ' UTC');
        return $timestamp === false ? PHP_INT_MAX : max(0, $now - $timestamp);
    }
}
