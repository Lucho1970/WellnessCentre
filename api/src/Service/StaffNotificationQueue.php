<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;

final class StaffNotificationQueue
{
    public static function enqueue(PDO $pdo, int $clinicId, int $appointmentId, int $practitionerId, string $eventCode): void
    {
        if (!in_array($eventCode, ['booking_confirmation', 'booking_change', 'booking_cancellation'], true)) throw new \InvalidArgumentException('Unsupported appointment event.');
        $query = $pdo->prepare("SELECT u.id,u.email,pref.personal_email,pref.email_destination FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=:clinic AND u.user_type='staff' AND u.status='active' JOIN staff_notification_preferences pref ON pref.user_id=u.id AND pref.email_enabled=1 WHERE p.id=:practitioner AND p.active=1");
        $query->execute(['clinic' => $clinicId, 'practitioner' => $practitionerId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $addresses = [];
        if (in_array($row['email_destination'], ['work', 'both'], true)) $addresses[] = (string)$row['email'];
        if (in_array($row['email_destination'], ['personal', 'both'], true) && $row['personal_email'] !== null) {
            // Never trust a saved preference alone: verification must still exist.
            $verified = $pdo->prepare('SELECT 1 FROM staff_notification_preferences WHERE user_id=:user AND personal_email=:email AND personal_email_verified_at IS NOT NULL');
            $verified->execute(['user' => $row['id'], 'email' => $row['personal_email']]);
            if ($verified->fetchColumn()) $addresses[] = (string)$row['personal_email'];
        }
        $insert = $pdo->prepare("INSERT INTO notification_events(clinic_id,appointment_id,recipient_user_id,recipient_address,event_code,channel,status,scheduled_at,payload) VALUES(:clinic,:appointment,:recipient,:address,:event,'email','queued',UTC_TIMESTAMP(),JSON_OBJECT('appointment_id',:payload_id))");
        foreach (array_unique($addresses) as $address) {
            $insert->execute(['clinic' => $clinicId, 'appointment' => $appointmentId, 'recipient' => $row['id'], 'address' => $address, 'event' => 'staff_' . $eventCode, 'payload_id' => $appointmentId]);
        }
        // sms_requested is intentionally not queued while transport approval is pending.
    }
}
