<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;

final class NotificationWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GraphMailClient $mailer,
        private readonly string $clientPortalUrl,
    ) {}

    /** @return array{sent:int,retry:int,review:int,canceled:int} */
    public function run(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        // The former worker might have reached Graph before stopping. Never resend
        // an expired lease automatically because Graph sendMail is not idempotent.
        $this->pdo->exec("UPDATE notification_events SET status='needs_review',lease_token=NULL,leased_until=NULL,last_error='Worker stopped while sending; delivery outcome is unknown' WHERE status='sending' AND leased_until<UTC_TIMESTAMP()");
        $summary = ['sent' => 0, 'retry' => 0, 'review' => 0, 'canceled' => 0];
        for ($processed = 0; $processed < $limit; $processed++) {
            $event = $this->claim();
            if ($event === null) break;
            try {
                $details = $this->details((int)$event['id']);
                $superseded = $details === null || $this->superseded($details);
                $message = $superseded ? null : AppointmentEmail::compose($details, $this->clientPortalUrl);
                $calendar = $superseded ? null : AppointmentCalendar::compose($details, $this->clientPortalUrl, $details['event_code'] === 'booking_cancellation' ? 'CANCEL' : 'REQUEST', $this->mailer->senderAddress(), (string)$event['recipient_address']);
            } catch (Throwable $e) {
                $this->finish($event, 'needs_review', 'Notification could not be prepared (' . get_class($e) . ').');
                $summary['review']++;
                continue;
            }
            if ($superseded) {
                $this->finish($event, 'canceled', null);
                $summary['canceled']++;
                continue;
            }
            try {
                $this->mailer->send((string)$event['recipient_address'], $message['subject'], $message['body'], $calendar);
            } catch (MailSendException $e) {
                $retry = $e->retryable && !$e->ambiguous && (int)$event['attempt_count'] < 5;
                $this->finish($event, $retry ? 'failed' : 'needs_review', $e->getMessage(), $retry ? max($e->retryAfterSeconds, self::backoff((int)$event['attempt_count'])) : 0);
                $summary[$retry ? 'retry' : 'review']++;
                continue;
            } catch (Throwable $e) {
                // An unexpected transport failure may have happened after Graph accepted
                // the request. Never retry it automatically.
                $this->finish($event, 'needs_review', 'Unexpected mail transport failure (' . get_class($e) . ').');
                $summary['review']++;
                continue;
            }
            $this->finish($event, 'sent', null);
            $summary['sent']++;
        }
        return $summary;
    }

    public static function backoff(int $attempt): int
    {
        return match (min(4, max(1, $attempt))) { 1 => 60, 2 => 300, 3 => 900, default => 3600 };
    }

    private function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->query("SELECT id,recipient_address,attempt_count FROM notification_events WHERE channel='email' AND status IN ('queued','failed') AND scheduled_at<=UTC_TIMESTAMP() AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) AND attempt_count<5 ORDER BY scheduled_at,id LIMIT 1 FOR UPDATE");
            $event = $query->fetch(PDO::FETCH_ASSOC);
            if ($event === false) { $this->pdo->commit(); return null; }
            $token = bin2hex(random_bytes(16));
            $update = $this->pdo->prepare("UPDATE notification_events SET status='sending',attempt_count=attempt_count+1,lease_token=:token,leased_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),next_attempt_at=NULL,last_error=NULL WHERE id=:id");
            $update->execute(['token' => $token, 'id' => $event['id']]);
            $this->pdo->commit();
            $event['lease_token'] = $token;
            $event['attempt_count'] = (int)$event['attempt_count'] + 1;
            return $event;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function details(int $eventId): ?array
    {
        $query = $this->pdo->prepare('SELECT n.id,n.clinic_id,n.appointment_id,n.event_code,a.status appointment_status,a.version,a.starts_at,a.ends_at,l.timezone,c.name clinic_name FROM notification_events n JOIN appointments a ON a.id=n.appointment_id AND a.clinic_id=n.clinic_id JOIN locations l ON l.id=a.location_id JOIN clinics c ON c.id=n.clinic_id WHERE n.id=:id');
        $query->execute(['id' => $eventId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function superseded(array $event): bool
    {
        $code = (string)$event['event_code'];
        $status = (string)$event['appointment_status'];
        if (!in_array($code, ['booking_confirmation','booking_change','booking_cancellation'], true)) return true;
        if ($code === 'booking_cancellation' && !in_array($status, ['canceled_by_client','canceled_by_clinic'], true)) return true;
        if ($code !== 'booking_cancellation' && in_array($status, ['canceled_by_client','canceled_by_clinic'], true)) return true;
        $query = $this->pdo->prepare("SELECT 1 FROM notification_events WHERE appointment_id=:appointment AND id>:event AND event_code IN ('booking_confirmation','booking_change','booking_cancellation') LIMIT 1");
        $query->execute(['appointment' => $event['appointment_id'], 'event' => $event['id']]);
        return $query->fetchColumn() !== false;
    }

    private function finish(array $event, string $status, ?string $error, int $retrySeconds = 0): void
    {
        $nextAttempt = $retrySeconds > 0 ? gmdate('Y-m-d H:i:s', time() + $retrySeconds) : null;
        $statement = $this->pdo->prepare("UPDATE notification_events SET status=:status,lease_token=NULL,leased_until=NULL,last_error=:error,next_attempt_at=:next_attempt,sent_at=CASE WHEN :sent=1 THEN UTC_TIMESTAMP() ELSE sent_at END WHERE id=:id AND status='sending' AND lease_token=:token");
        $statement->execute([
            'status' => $status,
            'error' => $error,
            'next_attempt' => $nextAttempt,
            'sent' => $status === 'sent' ? 1 : 0,
            'id' => $event['id'],
            'token' => $event['lease_token'],
        ]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Notification lease was lost.');
    }
}
