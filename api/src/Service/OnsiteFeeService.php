<?php
declare(strict_types=1);
namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class OnsiteFeeService
{
    public function __construct(private readonly Database $database) {}

    private function appointment(AuthContext $actor, int $id, bool $lock = false): array
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('practitioner', 'super_admin', 'clinic_admin')) throw new ApiException(403, 'forbidden', 'Travel fee access is not permitted.');
        $q = $this->database->connection()->prepare('SELECT a.*,p.user_id practitioner_user_id FROM appointments a JOIN practitioners p ON p.id=a.practitioner_id WHERE a.id=? AND a.clinic_id=?'.($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id, $actor->clinicId]);
        $a = $q->fetch();
        if (!$a) throw new ApiException(404, 'appointment_not_found', 'Appointment not found.');
        SessionReceiptService::assertAccess($actor, $a);
        return $a;
    }

    private function billed(int $clinic, int $id): bool
    {
        $q = $this->database->connection()->prepare("SELECT id FROM invoices WHERE clinic_id=? AND appointment_id=? AND status NOT IN ('void','refunded') LIMIT 1");
        $q->execute([$clinic, $id]);
        return (bool)$q->fetchColumn();
    }

    public function context(AuthContext $actor, int $id): array
    {
        $a = $this->appointment($actor, $id);
        return ['version'=>(int)$a['version'], 'fee_cents'=>(int)$a['mobile_fee_cents'],
            'assessed_fee_cents'=>(int)($a['mobile_assessed_fee_cents'] ?? $a['mobile_fee_cents']),
            'waived'=>$a['mobile_fee_waived_at'] !== null, 'reason'=>$a['mobile_fee_waiver_reason'],
            'can_waive'=>$a['delivery_mode']==='mobile' && (int)$a['mobile_fee_cents']>0 && in_array($a['status'], ['confirmed','rescheduled','completed'], true) && !$this->billed($actor->clinicId, $id)];
    }

    public function waive(AuthContext $actor, int $id, array $body, string $correlation): array
    {
        $reason = $body['reason'] ?? null;
        if (!is_string($reason) || trim($reason)==='' || mb_strlen($reason)>300 || preg_match('/[\x00-\x1f\x7f]/', $reason) || !is_int($body['version'] ?? null)) throw new ApiException(422, 'validation_error', 'Enter a waiver reason of up to 300 characters and refresh the appointment version.');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            // Same lock order as booking and receipt issuance, so payment cannot race a waiver.
            $pdo->prepare('SELECT id FROM clinics WHERE id=? FOR UPDATE')->execute([$actor->clinicId]);
            $a = $this->appointment($actor, $id, true);
            if ((int)$a['version'] !== $body['version']) throw new ApiException(409, 'appointment_changed', 'Refresh the appointment before waiving the fee.');
            if ($a['delivery_mode']!=='mobile' || (int)$a['mobile_fee_cents']<1 || !in_array($a['status'], ['confirmed','rescheduled','completed'], true) || $this->billed($actor->clinicId, $id)) throw new ApiException(409, 'travel_fee_locked', 'Only an unbilled confirmed or completed On-Site session can have its travel fee waived.');
            $pdo->prepare('UPDATE appointments SET mobile_assessed_fee_cents=COALESCE(mobile_assessed_fee_cents,mobile_fee_cents),mobile_fee_cents=0,mobile_fee_waived_by=?,mobile_fee_waived_at=UTC_TIMESTAMP(),mobile_fee_waiver_reason=?,version=version+1 WHERE id=? AND clinic_id=?')->execute([$actor->userId, trim($reason), $id, $actor->clinicId]);
            (new AuditLogger($this->database))->write($actor->clinicId, $actor, $correlation, 'appointment.travel_fee_waive', 'appointment', $id, 'success', ['previous_fee_cents'=>(int)$a['mobile_fee_cents'], 'reason'=>trim($reason)]);
            $pdo->commit();
            return $this->context($actor, $id);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
