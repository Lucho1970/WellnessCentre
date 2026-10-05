<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Keep reminder changes in the same transaction as the appointment change. */
final class AppointmentReminderQueue
{
    public static function replace(PDO $pdo, int $clinicId, int $appointmentId, int $clientId, string $startsAtUtc, int $version): void
    {
        $cancel = $pdo->prepare("UPDATE notification_events SET status='canceled',next_attempt_at=NULL,last_error='Appointment changed before reminder' WHERE clinic_id=:clinic AND appointment_id=:appointment AND event_code='appointment_reminder' AND status IN ('queued','failed')");
        $cancel->execute(['clinic' => $clinicId, 'appointment' => $appointmentId]);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $schedules = $pdo->prepare("SELECT minutes_before FROM reminder_schedules WHERE clinic_id=:clinic AND event_code='appointment_reminder' AND channel='email' AND active=1 ORDER BY minutes_before DESC");
        $schedules->execute(['clinic' => $clinicId]);
        $insert = $pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) SELECT :clinic,:appointment,u.id,u.email,'appointment_reminder','email','queued',:scheduled,JSON_OBJECT('appointment_id',:payload_appointment,'appointment_version',:version,'minutes_before',:minutes) FROM users u WHERE u.id=:client AND u.clinic_id=:user_clinic AND u.user_type='client' AND u.status='active' AND u.email<>''");
        foreach ($schedules->fetchAll(PDO::FETCH_COLUMN) as $minutes) {
            $scheduled = self::scheduledAt($startsAtUtc, (int)$minutes, $now);
            // A reminder whose lead time has already passed is not useful; the
            // confirmation/change message is sent immediately instead.
            if ($scheduled === null) continue;
            $insert->execute([
                'clinic' => $clinicId, 'appointment' => $appointmentId,
                'scheduled' => $scheduled,
                'payload_appointment' => $appointmentId, 'version' => $version,
                'minutes' => (int)$minutes, 'client' => $clientId, 'user_clinic' => $clinicId,
            ]);
        }
    }

    public static function scheduledAt(string $startsAtUtc, int $minutesBefore, DateTimeImmutable $now): ?string
    {
        if ($minutesBefore < 1) return null;
        $start = new DateTimeImmutable($startsAtUtc, new DateTimeZone('UTC'));
        $scheduled = $start->modify("-{$minutesBefore} minutes");
        return $scheduled > $now ? $scheduled->format('Y-m-d H:i:s') : null;
    }

    public static function cancel(PDO $pdo, int $clinicId, int $appointmentId): void
    {
        $cancel = $pdo->prepare("UPDATE notification_events SET status='canceled',next_attempt_at=NULL,last_error='Appointment canceled before reminder' WHERE clinic_id=:clinic AND appointment_id=:appointment AND event_code='appointment_reminder' AND status IN ('queued','failed')");
        $cancel->execute(['clinic' => $clinicId, 'appointment' => $appointmentId]);
    }

    public static function cancelForTerminal(PDO $pdo, int $clinicId, int $appointmentId): void
    {
        $cancel = $pdo->prepare("UPDATE notification_events SET status='canceled',next_attempt_at=NULL,last_error='Appointment closed before reminder' WHERE clinic_id=:clinic AND appointment_id=:appointment AND event_code='appointment_reminder' AND status IN ('queued','failed')");
        $cancel->execute(['clinic' => $clinicId, 'appointment' => $appointmentId]);
    }
}
