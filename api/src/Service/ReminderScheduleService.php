<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Clinic-wide email reminder timing; changes affect future bookings only. */
final class ReminderScheduleService
{
    private const ALLOWED_MINUTES = [60, 180, 1440, 2880, 4320];

    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin'))
            throw new ApiException(403, 'forbidden', 'Administrator access is required.');
    }

    public static function minutes(array $body): int
    {
        $value = filter_var($body['minutes_before'] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || !in_array($value, self::ALLOWED_MINUTES, true))
            throw new ApiException(422, 'validation_error', 'Select an offered reminder time.');
        return $value;
    }

    public function list(AuthContext $actor): array
    {
        self::authorize($actor);
        $query = $this->database->connection()->prepare("SELECT id,minutes_before,active FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' ORDER BY minutes_before,id");
        $query->execute(['clinic' => $actor->clinicId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(AuthContext $actor, array $body, string $correlationId): array
    {
        self::authorize($actor);
        $minutes = self::minutes($body);
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $this->lockClinic($pdo, $actor->clinicId);
            $existing = $pdo->prepare("SELECT id FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' AND minutes_before=:minutes");
            $existing->execute(['clinic' => $actor->clinicId, 'minutes' => $minutes]);
            if ($existing->fetchColumn()) throw new ApiException(409, 'reminder_exists', 'This reminder time already exists.');
            $this->assertCapacity($pdo, $actor->clinicId);
            $insert = $pdo->prepare("INSERT INTO reminder_schedules(clinic_id,event_code,minutes_before,channel,active) VALUES(:clinic,'appointment_reminder',:minutes,'email',1)");
            $insert->execute(['clinic' => $actor->clinicId, 'minutes' => $minutes]);
            $id = (int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'reminder_schedule.create', 'reminder_schedule', $id, 'success', ['minutes_before' => $minutes]);
            $pdo->commit();
            return $this->list($actor);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public function setActive(AuthContext $actor, int $id, array $body, string $correlationId): array
    {
        self::authorize($actor);
        if (!is_bool($body['active'] ?? null)) throw new ApiException(422, 'validation_error', 'active must be a boolean.');
        $active = $body['active'];
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $this->lockClinic($pdo, $actor->clinicId);
            $query = $pdo->prepare("SELECT minutes_before,active FROM reminder_schedules WHERE id=:id AND clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' FOR UPDATE");
            $query->execute(['id' => $id, 'clinic' => $actor->clinicId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ApiException(404, 'reminder_not_found', 'Reminder schedule not found.');
            if ($active && !(bool)$row['active']) $this->assertCapacity($pdo, $actor->clinicId);
            $update = $pdo->prepare('UPDATE reminder_schedules SET active=:active WHERE id=:id');
            $update->execute(['active' => $active ? 1 : 0, 'id' => $id]);
            if (!$active) {
                // Cancel pending work as well as preventing future booking writes.
                $cancel = $pdo->prepare("UPDATE notification_events SET status='canceled',next_attempt_at=NULL,last_error='Reminder schedule disabled' WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' AND status IN ('queued','failed') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.minutes_before')) AS UNSIGNED)=:minutes");
                $cancel->execute(['clinic' => $actor->clinicId, 'minutes' => (int)$row['minutes_before']]);
            }
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'reminder_schedule.update', 'reminder_schedule', $id, 'success', ['active' => $active]);
            $pdo->commit();
            return $this->list($actor);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function lockClinic(PDO $pdo, int $clinicId): void
    {
        $lock = $pdo->prepare('SELECT id FROM clinics WHERE id=:clinic FOR UPDATE');
        $lock->execute(['clinic' => $clinicId]);
        if (!$lock->fetchColumn()) throw new ApiException(404, 'clinic_not_found', 'Clinic not found.');
    }

    private function assertCapacity(PDO $pdo, int $clinicId): void
    {
        $count = $pdo->prepare("SELECT COUNT(*) FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' AND active=1");
        $count->execute(['clinic' => $clinicId]);
        if ((int)$count->fetchColumn() >= 3) throw new ApiException(422, 'reminder_limit', 'Only three reminder times can be active. Disable one first.');
    }
}
