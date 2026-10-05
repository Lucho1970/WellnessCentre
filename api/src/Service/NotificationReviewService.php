<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class NotificationReviewService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin'))
            throw new ApiException(403, 'forbidden', 'Administrator access is required.');
    }

    /** @return array{decision:string,outcome:string} */
    public static function choice(array $body): array
    {
        $decision = $body['decision'] ?? null;
        $outcome = $body['outcome'] ?? null;
        $allowed = [
            'resolve' => ['provider_accepted','handled_manually','no_longer_needed'],
            'retry' => ['provider_not_sent'],
        ];
        if (!is_string($decision) || !is_string($outcome) || !in_array($outcome, $allowed[$decision] ?? [], true))
            throw new ApiException(422, 'validation_error', 'Choose a valid review decision and outcome.');
        if (($body['checked_provider_history'] ?? null) !== true || ($body['checked_recipient'] ?? null) !== true)
            throw new ApiException(422, 'validation_error', 'Confirm that provider history and recipient status were checked.');
        return ['decision' => $decision, 'outcome' => $outcome];
    }

    public function review(AuthContext $actor, int $id, array $body, string $correlationId): array
    {
        self::authorize($actor);
        $choice = self::choice($body);
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare('SELECT n.id,n.appointment_id,n.recipient_user_id,n.recipient_address,n.event_code,n.channel,n.status,n.scheduled_at,n.attempt_count,n.provider_message_id,n.sent_at,a.status appointment_status FROM notification_events n LEFT JOIN appointments a ON a.id=n.appointment_id AND a.clinic_id=n.clinic_id WHERE n.id=:id AND n.clinic_id=:clinic FOR UPDATE');
            $query->execute(['id' => $id, 'clinic' => $actor->clinicId]);
            $event = $query->fetch(PDO::FETCH_ASSOC);
            if (!$event) throw new ApiException(404, 'notification_not_found', 'Notification not found.');
            if ($event['status'] !== 'needs_review') throw new ApiException(409, 'notification_changed', 'This notification is no longer awaiting review. Refresh the list.');

            if ($choice['decision'] === 'retry') {
                $this->assertRetryableSms($pdo, $event);
                $update = $pdo->prepare("UPDATE notification_events SET status='queued',scheduled_at=UTC_TIMESTAMP(),next_attempt_at=NULL,leased_until=NULL,lease_token=NULL WHERE id=:id AND clinic_id=:clinic AND status='needs_review'");
            } else {
                $update = $pdo->prepare("UPDATE notification_events SET status='resolved',next_attempt_at=NULL WHERE id=:id AND clinic_id=:clinic AND status='needs_review'");
            }
            $update->execute(['id' => $id, 'clinic' => $actor->clinicId]);
            if ($update->rowCount() !== 1) throw new ApiException(409, 'notification_changed', 'This notification changed. Refresh the list.');
            $record = $pdo->prepare('INSERT INTO notification_reviews(clinic_id,notification_event_id,reviewer_user_id,decision,outcome) VALUES(:clinic,:event,:reviewer,:decision,:outcome)');
            $record->execute(['clinic' => $actor->clinicId, 'event' => $id, 'reviewer' => $actor->userId, 'decision' => $choice['decision'], 'outcome' => $choice['outcome']]);
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'notification.review', 'notification_event', $id, 'success', $choice);
            $pdo->commit();
            return ['id' => $id, 'status' => $choice['decision'] === 'retry' ? 'queued' : 'resolved', 'review' => $choice];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function assertRetryableSms(PDO $pdo, array $event): void
    {
        if ($event['channel'] !== 'sms' || !in_array($event['event_code'], ['staff_booking_confirmation','staff_booking_change','staff_booking_cancellation'], true)
            || (int)$event['attempt_count'] >= 5 || $event['provider_message_id'] !== null || $event['sent_at'] !== null)
            throw new ApiException(409, 'retry_unavailable', 'This notification cannot be retried automatically.');
        $age = time() - strtotime((string)$event['scheduled_at'] . ' UTC');
        if ($age < 0 || $age >= 3600) throw new ApiException(409, 'retry_expired', 'This SMS is too old for an automatic retry. Handle it manually.');
        $cancellation = $event['event_code'] === 'staff_booking_cancellation';
        $appointmentStatus = (string)($event['appointment_status'] ?? '');
        if ($cancellation ? !in_array($appointmentStatus, ['canceled_by_client','canceled_by_clinic'], true) : !in_array($appointmentStatus, ['requested','confirmed','rescheduled'], true))
            throw new ApiException(409, 'notification_superseded', 'The appointment has changed; do not resend this notice.');
        $newer = $pdo->prepare("SELECT 1 FROM notification_events WHERE appointment_id=:appointment AND id>:id AND recipient_user_id=:recipient AND recipient_address=:address AND channel='sms' AND event_code IN ('staff_booking_confirmation','staff_booking_change','staff_booking_cancellation') LIMIT 1");
        $newer->execute(['appointment' => $event['appointment_id'], 'id' => $event['id'], 'recipient' => $event['recipient_user_id'], 'address' => $event['recipient_address']]);
        if ($newer->fetchColumn() !== false) throw new ApiException(409, 'notification_superseded', 'A newer notice exists for this appointment.');
    }
}
