<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;

/** Current staff recipient authority and destination checks, shared by review and delivery. */
final class StaffNotificationRecipient
{
    public static function allowed(PDO $pdo,array $event): bool
    {
        // Preferences or contact details may change between booking and worker delivery.
        $away=(string)$event['event_code']==='staff_booking_reassigned_away';
        $payload=$away?json_decode((string)($event['payload']??''),true):null;
        if($away&&(!is_array($payload)||(int)($payload['reassignment_id']??0)<1))return false;
        $assignmentJoin=$away
            ? 'JOIN appointment_reassignments ar ON ar.old_practitioner_id=practitioner.id AND ar.id=:reassignment AND ar.appointment_id=:appointment AND ar.clinic_id=u.clinic_id'
            : 'JOIN appointments a ON a.practitioner_id=practitioner.id AND a.id=:appointment AND a.clinic_id=u.clinic_id';
        $query = $pdo->prepare("SELECT u.email,p.email_enabled,p.email_destination,p.personal_email,p.personal_email_verified_at,p.mobile_phone,p.sms_requested FROM users u JOIN staff_notification_preferences p ON p.user_id=u.id JOIN practitioners practitioner ON practitioner.user_id=u.id AND practitioner.active=1 {$assignmentJoin} WHERE u.id=:recipient AND u.clinic_id=:clinic AND u.user_type='staff' AND u.status='active'");
        $params=['appointment' => $event['appointment_id'], 'recipient' => $event['recipient_user_id'], 'clinic' => $event['clinic_id']];
        if($away)$params['reassignment']=(int)$payload['reassignment_id'];
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $address = (string)$event['recipient_address'];
        if ($event['channel'] === 'sms') return (bool)$row['sms_requested'] && CanadianSmsNumber::isAllowed($address) && hash_equals((string)$row['mobile_phone'], $address);
        return (bool)$row['email_enabled'] && ((in_array($row['email_destination'], ['work', 'both'], true) && strcasecmp($address, (string)$row['email']) === 0)
            || (in_array($row['email_destination'], ['personal', 'both'], true) && $row['personal_email_verified_at'] !== null && strcasecmp($address, (string)$row['personal_email']) === 0));
    }
}
