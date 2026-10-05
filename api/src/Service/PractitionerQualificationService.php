<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class PractitionerQualificationService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public function types(AuthContext $actor): array
    {
        $this->staff($actor);
        $query = $this->database->connection()->prepare('SELECT id,name,requires_expiry,active FROM qualification_types WHERE clinic_id=:clinic ORDER BY name');
        $query->execute(['clinic' => $actor->clinicId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createType(AuthContext $actor, array $body, string $cid): array
    {
        $this->admin($actor);
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $requiresExpiry = $body['requires_expiry'] ?? null;
        if ($name === '' || strlen($name) > 150 || !is_bool($requiresExpiry)) throw new ApiException(422, 'validation_error', 'Enter a qualification name and expiry requirement.');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare('INSERT INTO qualification_types(clinic_id,name,requires_expiry) VALUES(:clinic,:name,:expiry)');
            $query->execute(['clinic' => $actor->clinicId, 'name' => $name, 'expiry' => $requiresExpiry ? 1 : 0]);
            $id = (int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.type.create', 'qualification_type', $id);
            $pdo->commit();
            return ['id' => $id, 'name' => $name, 'requires_expiry' => $requiresExpiry, 'active' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string)$e->getCode() === '23000') throw new ApiException(409, 'qualification_type_exists', 'This qualification type already exists.');
            throw $e;
        }
    }

    public function list(AuthContext $actor, ?int $practitionerId = null): array
    {
        $id = $this->practitionerId($actor, $practitionerId);
        $query = $this->database->connection()->prepare('SELECT q.id,q.practitioner_id,q.qualification_type_id,t.name qualification_name,t.requires_expiry,q.issuer,q.issued_on,q.expires_on,q.status,q.submitted_by,q.reviewed_by,q.reviewed_at,q.review_note,q.created_at FROM practitioner_qualifications q JOIN qualification_types t ON t.id=q.qualification_type_id AND t.clinic_id=q.clinic_id WHERE q.clinic_id=:clinic AND q.practitioner_id=:practitioner ORDER BY q.created_at DESC,q.id DESC');
        $query->execute(['clinic' => $actor->clinicId, 'practitioner' => $id]);
        $today = new DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $expiry = $row['expires_on'] ? new DateTimeImmutable($row['expires_on'], new \DateTimeZone('UTC')) : null;
            $days = $expiry ? (int)$today->diff($expiry)->format('%r%a') : null;
            $row['days_until_expiry'] = $days;
            $row['renewal_warning'] = $row['status'] === 'verified' && $days !== null && $days <= 90;
        }
        return $rows;
    }

    public function submit(AuthContext $actor, array $body, string $cid, ?int $practitionerId = null): array
    {
        $id = $this->practitionerId($actor, $practitionerId);
        $typeId = filter_var($body['qualification_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $issuer = is_string($body['issuer'] ?? null) ? trim($body['issuer']) : '';
        $issuedOn = $this->date($body['issued_on'] ?? null);
        $expiresOn = $this->date($body['expires_on'] ?? null, true);
        if (!$typeId || $issuer === '' || strlen($issuer) > 150 || $issuedOn === null) throw new ApiException(422, 'validation_error', 'Enter a qualification, issuer, and valid issue date.');
        if ($expiresOn !== null && $expiresOn < $issuedOn) throw new ApiException(422, 'validation_error', 'The expiry date cannot precede the issue date.');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $type = $pdo->prepare('SELECT requires_expiry FROM qualification_types WHERE id=:id AND clinic_id=:clinic AND active=1');
            $type->execute(['id' => $typeId, 'clinic' => $actor->clinicId]);
            $requiresExpiry = $type->fetchColumn();
            if ($requiresExpiry === false) throw new ApiException(404, 'qualification_type_not_found', 'Qualification type not found.');
            if ((bool)$requiresExpiry && $expiresOn === null) throw new ApiException(422, 'validation_error', 'An expiry date is required for this qualification.');
            $insert = $pdo->prepare("INSERT INTO practitioner_qualifications(clinic_id,practitioner_id,qualification_type_id,issuer,issued_on,expires_on,status,submitted_by) VALUES(:clinic,:practitioner,:type,:issuer,:issued,:expires,'pending',:actor)");
            $insert->execute(['clinic' => $actor->clinicId, 'practitioner' => $id, 'type' => $typeId, 'issuer' => $issuer, 'issued' => $issuedOn, 'expires' => $expiresOn, 'actor' => $actor->userId]);
            $recordId = (int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.submit', 'practitioner_qualification', $recordId, 'success', ['practitioner_id' => $id, 'qualification_type_id' => $typeId]);
            $pdo->commit();
            return ['id' => $recordId, 'status' => 'pending'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function review(AuthContext $actor, int $id, array $body, string $cid): array
    {
        $this->admin($actor);
        $status = $body['status'] ?? null;
        $note = is_string($body['review_note'] ?? null) ? trim($body['review_note']) : '';
        if (!in_array($status, ['verified', 'rejected', 'revoked'], true) || strlen($note) > 500) throw new ApiException(422, 'validation_error', 'Choose a valid review decision and a note under 500 characters.');
        if ($status !== 'verified' && $note === '') throw new ApiException(422, 'validation_error', 'A review note is required when rejecting or revoking a qualification.');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            $lookup = $pdo->prepare('SELECT status,submitted_by,expires_on FROM practitioner_qualifications WHERE id=:id AND clinic_id=:clinic FOR UPDATE');
            $lookup->execute(['id' => $id, 'clinic' => $actor->clinicId]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ApiException(404, 'qualification_not_found', 'Qualification record not found.');
            if ($status === 'revoked' ? $row['status'] !== 'verified' : $row['status'] !== 'pending') throw new ApiException(409, 'qualification_state_changed', 'Reload the qualification before reviewing it.');
            if ($status === 'verified' && (int)$row['submitted_by'] === $actor->userId) throw new ApiException(403, 'self_review_forbidden', 'Another administrator must review a qualification you submitted.');
            if ($status === 'verified' && $row['expires_on'] !== null && $row['expires_on'] < gmdate('Y-m-d')) throw new ApiException(422, 'qualification_expired', 'An expired qualification cannot be verified.');
            $update = $pdo->prepare('UPDATE practitioner_qualifications SET status=:status,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(),review_note=:note WHERE id=:id AND clinic_id=:clinic');
            $update->execute(['status' => $status, 'actor' => $actor->userId, 'note' => $note === '' ? null : $note, 'id' => $id, 'clinic' => $actor->clinicId]);
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.review', 'practitioner_qualification', $id, 'success', ['previous_status' => $row['status'], 'status' => $status]);
            $pdo->commit();
            return ['id' => $id, 'status' => $status];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function practitionerId(AuthContext $actor, ?int $requested): int
    {
        $this->staff($actor);
        if ($requested !== null) $this->admin($actor);
        elseif (!$actor->hasAnyRole('practitioner')) throw new ApiException(403, 'forbidden', 'Practitioner access is required.');
        $sql = 'SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.clinic_id=:clinic AND p.id=:requested';
        $params = ['clinic' => $actor->clinicId, 'requested' => $requested];
        if ($requested === null) { $sql = 'SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.clinic_id=:clinic AND u.id=:actor'; $params = ['clinic' => $actor->clinicId, 'actor' => $actor->userId]; }
        $query = $this->database->connection()->prepare($sql);
        $query->execute($params);
        $id = $query->fetchColumn();
        if (!$id) throw new ApiException(404, 'practitioner_not_found', 'Practitioner not found.');
        return (int)$id;
    }

    private function date(mixed $input, bool $optional = false): ?string
    {
        if ($optional && ($input === null || $input === '')) return null;
        if (!is_string($input)) throw new ApiException(422, 'validation_error', 'Enter a valid date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if (!$date || $date->format('Y-m-d') !== $input) throw new ApiException(422, 'validation_error', 'Enter a valid date.');
        return $input;
    }

    private function staff(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff') throw new ApiException(403, 'forbidden', 'Staff access is required.');
    }

    private function admin(AuthContext $actor): void
    {
        $this->staff($actor);
        if (!$actor->hasAnyRole('super_admin', 'clinic_admin')) throw new ApiException(403, 'forbidden', 'Clinic administrator access is required.');
    }
}
