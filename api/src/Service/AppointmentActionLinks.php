<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;

/** Single-purpose navigation links; the token never authorizes an appointment mutation. */
final class AppointmentActionLinks
{
    public function __construct(private readonly PDO $pdo, private readonly bool $enabled, private readonly ?AuditLogger $audit = null) {}

    public static function url(string $portalUrl, string $token): string
    {
        $parts = parse_url($portalUrl);
        if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('#/client/?$#D', $parts['path'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new \InvalidArgumentException('Invalid appointment link configuration.');
        return rtrim($portalUrl, '/') . '/appointment#token=' . $token;
    }

    /** New messages/retries get new tokens. Cancellation notices and staff messages get none. */
    public function issue(array $event, string $portalUrl): ?string
    {
        if (!$this->enabled || !in_array($event['event_code'] ?? '', ['booking_confirmation','booking_change','appointment_reminder'], true)) return null;
        $token = bin2hex(random_bytes(32));
        $url = self::url($portalUrl, $token);
        // Recheck the authoritative appointment and recipient at issuance, not just worker snapshots.
        $query = $this->pdo->prepare("INSERT INTO appointment_action_links(clinic_id,appointment_id,client_id,appointment_version,notification_event_id,token_hash,expires_at)
            SELECT a.clinic_id,a.id,a.client_id,a.version,n.id,:hash,LEAST(a.starts_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))
            FROM notification_events n JOIN appointments a ON a.id=n.appointment_id AND a.clinic_id=n.clinic_id
            JOIN users u ON u.id=a.client_id AND u.clinic_id=a.clinic_id AND u.user_type='client' AND u.status='active'
            JOIN clinics c ON c.id=a.clinic_id AND c.status='active'
            WHERE n.id=:event AND n.channel='email' AND n.event_code IN ('booking_confirmation','booking_change','appointment_reminder')
            AND n.status='sending' AND n.recipient_user_id=u.id AND n.recipient_address=u.email
            AND a.version=:version AND a.status IN ('requested','confirmed','rescheduled') AND a.starts_at>UTC_TIMESTAMP()");
        $query->execute(['hash'=>hash('sha256', $token),'event'=>(int)$event['id'],'version'=>(int)$event['version']]);
        return $query->rowCount() === 1 ? $url : null;
    }

    public function resolve(AuthContext $actor, array $body, string $correlationId): array
    {
        $this->assertEnabled();
        if ($actor->userType !== 'client') throw new ApiException(403, 'forbidden', 'Client sign-in is required.');
        $token = $body['token'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) $this->unavailable();
        $query = $this->pdo->prepare("SELECT a.id,a.recurring_series_id,a.starts_at,a.ends_at,a.status,a.version,a.room_id,a.duration_option_id,a.delivery_mode,
            s.name service,pu.display_name practitioner,l.name location,l.timezone,r.name room_name
            FROM appointment_action_links link
            JOIN appointments a ON a.id=link.appointment_id AND a.clinic_id=link.clinic_id AND a.client_id=link.client_id AND a.version=link.appointment_version
            JOIN users u ON u.id=a.client_id AND u.clinic_id=a.clinic_id AND u.user_type='client' AND u.status='active'
            JOIN clinics c ON c.id=a.clinic_id AND c.status='active'
            JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
            JOIN practitioners p ON p.id=a.practitioner_id JOIN users pu ON pu.id=p.user_id AND pu.clinic_id=a.clinic_id
            JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
            LEFT JOIN rooms r ON r.id=a.room_id AND r.location_id=a.location_id
            WHERE link.token_hash=:hash AND link.clinic_id=:clinic AND link.client_id=:client
            AND link.revoked_at IS NULL AND link.expires_at>UTC_TIMESTAMP()
            AND a.status IN ('requested','confirmed','rescheduled') AND a.starts_at>UTC_TIMESTAMP()");
        $query->execute(['hash'=>hash('sha256', $token),'clinic'=>$actor->clinicId,'client'=>$actor->userId]);
        $appointment = $query->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) $this->unavailable();
        $this->audit?->write($actor->clinicId,$actor,$correlationId,'appointment.action_link.open','appointment',(int)$appointment['id']);
        return ['appointment'=>$appointment];
    }

    /** Revokes existing links only; later confirmation/reminder messages may issue new links. */
    public function revoke(AuthContext $actor, int $appointmentId, string $correlationId): array
    {
        $this->assertEnabled();
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin','clinic_admin','reception')) {
            throw new ApiException(403,'forbidden','Clinic administration access is required to revoke appointment links.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id FROM appointments WHERE id=:appointment AND clinic_id=:clinic FOR UPDATE');
            $query->execute(['appointment'=>$appointmentId,'clinic'=>$actor->clinicId]);
            if (!$query->fetchColumn()) throw new ApiException(404,'appointment_not_found','Appointment not found.');
            $query = $this->pdo->prepare('UPDATE appointment_action_links SET revoked_at=UTC_TIMESTAMP() WHERE appointment_id=:appointment AND clinic_id=:clinic AND revoked_at IS NULL');
            $query->execute(['appointment'=>$appointmentId,'clinic'=>$actor->clinicId]);
            $this->audit?->write($actor->clinicId,$actor,$correlationId,'appointment.action_links.revoke','appointment',$appointmentId);
            $this->pdo->commit();
            return ['revoked'=>true];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) throw new ApiException(503,'appointment_links_disabled','Appointment links are not enabled. Open My appointments after signing in.');
    }

    private function unavailable(): never
    {
        throw new ApiException(404,'appointment_link_unavailable','This appointment link is unavailable. Sign in with the account linked to this booking, or open My appointments.');
    }
}
