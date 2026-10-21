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
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit,private readonly bool $globalPractitionersEnabled=false) {}

    private const ADMIN_ROLES=['super_admin','clinic_admin'];
    private const STAFF_ROLES=['super_admin','clinic_admin','practitioner','reception','accountant'];

    /** Credentials are staff records; this boundary never overrides patient consent. */
    private function access(AuthContext $actor,array $roles,callable $work): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole(...$roles))throw new ApiException(403,'forbidden','Staff access is required.');
        $pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Qualification operations require their own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Staff access is required.');
            $marks=implode(',',array_fill(0,count($roles),'?'));
            $q=$pdo->prepare("SELECT u.id FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
                AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ($marks)) FOR UPDATE");
            $q->execute([$actor->userId,$actor->clinicId,...$roles]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Staff access is required.');
            $result=$work();$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    public function types(AuthContext $actor): array
    {return $this->access($actor,self::STAFF_ROLES,fn()=>$this->typesRecord($actor));}
    public function createType(AuthContext $actor,array $body,string $cid): array
    {return $this->access($actor,self::ADMIN_ROLES,fn()=>$this->createTypeRecord($actor,$body,$cid));}
    public function list(AuthContext $actor,?int $practitionerId=null): array
    {return $this->access($actor,$practitionerId===null?['practitioner']:self::ADMIN_ROLES,fn()=>$this->listRecord($actor,$practitionerId));}
    public function submit(AuthContext $actor,array $body,string $cid,?int $practitionerId=null): array
    {return $this->access($actor,$practitionerId===null?['practitioner']:self::ADMIN_ROLES,fn()=>$this->submitRecord($actor,$body,$cid,$practitionerId));}
    public function review(AuthContext $actor,int $id,array $body,string $cid): array
    {return $this->access($actor,self::ADMIN_ROLES,fn()=>$this->reviewRecord($actor,$id,$body,$cid));}

    private function typesRecord(AuthContext $actor): array
    {
        $this->staff($actor);
        $query = $this->database->connection()->prepare('SELECT id,name,requires_expiry,active FROM qualification_types WHERE clinic_id=:clinic ORDER BY name');
        $query->execute(['clinic' => $actor->clinicId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function createTypeRecord(AuthContext $actor, array $body, string $cid): array
    {
        $this->admin($actor);
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $requiresExpiry = $body['requires_expiry'] ?? null;
        if ($name === '' || strlen($name) > 150 || !is_bool($requiresExpiry)) throw new ApiException(422, 'validation_error', 'Enter a qualification name and expiry requirement.');
        $pdo = $this->database->connection();
        try {
            $query = $pdo->prepare('INSERT INTO qualification_types(clinic_id,name,requires_expiry) VALUES(:clinic,:name,:expiry)');
            $query->execute(['clinic' => $actor->clinicId, 'name' => $name, 'expiry' => $requiresExpiry ? 1 : 0]);
            $id = (int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.type.create', 'qualification_type', $id);
            return ['id' => $id, 'name' => $name, 'requires_expiry' => $requiresExpiry, 'active' => true];
        } catch (Throwable $e) {
            if ((string)$e->getCode() === '23000') throw new ApiException(409, 'qualification_type_exists', 'This qualification type already exists.');
            throw $e;
        }
    }

    private function listRecord(AuthContext $actor, ?int $practitionerId = null): array
    {
        $id = $this->practitionerId($actor, $practitionerId);
        $query = $this->database->connection()->prepare("SELECT q.id,q.practitioner_id,q.qualification_type_id,t.name qualification_name,t.requires_expiry,q.issuer,q.issued_on,q.expires_on,q.status,q.submitted_by,q.reviewed_by,q.reviewed_at,q.review_note,q.created_at FROM practitioner_qualifications q JOIN qualification_types t ON t.id=q.qualification_type_id AND t.clinic_id=q.clinic_id  JOIN users submitter ON submitter.id=q.submitted_by AND submitter.clinic_id=q.clinic_id AND submitter.user_type='staff' LEFT JOIN users reviewer ON reviewer.id=q.reviewed_by AND reviewer.clinic_id=q.clinic_id AND reviewer.user_type='staff' WHERE q.clinic_id=:clinic AND q.practitioner_id=:practitioner AND (q.reviewed_by IS NULL OR reviewer.id IS NOT NULL) ORDER BY q.created_at DESC,q.id DESC");
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

    private function submitRecord(AuthContext $actor, array $body, string $cid, ?int $practitionerId = null): array
    {
        $id = $this->practitionerId($actor, $practitionerId);
        if($this->globalPractitionersEnabled){$q=$this->database->connection()->prepare('SELECT 1 FROM global_practitioner_clinics WHERE practitioner_id=?');$q->execute([$id]);if($q->fetchColumn())throw new ApiException(403,'credential_owner_required','The practitioner must submit credentials from My global credentials and choose to share them with this clinic.');}
        $typeId = filter_var($body['qualification_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $issuer = is_string($body['issuer'] ?? null) ? trim($body['issuer']) : '';
        $issuedOn = $this->date($body['issued_on'] ?? null);
        $expiresOn = $this->date($body['expires_on'] ?? null, true);
        if (!$typeId || $issuer === '' || strlen($issuer) > 150 || $issuedOn === null) throw new ApiException(422, 'validation_error', 'Enter a qualification, issuer, and valid issue date.');
        if ($expiresOn !== null && $expiresOn < $issuedOn) throw new ApiException(422, 'validation_error', 'The expiry date cannot precede the issue date.');
        $pdo = $this->database->connection();
        try {
            $type = $pdo->prepare('SELECT requires_expiry FROM qualification_types WHERE id=:id AND clinic_id=:clinic AND active=1');
            $type->execute(['id' => $typeId, 'clinic' => $actor->clinicId]);
            $requiresExpiry = $type->fetchColumn();
            if ($requiresExpiry === false) throw new ApiException(404, 'qualification_type_not_found', 'Qualification type not found.');
            if ((bool)$requiresExpiry && $expiresOn === null) throw new ApiException(422, 'validation_error', 'An expiry date is required for this qualification.');
            $insert = $pdo->prepare("INSERT INTO practitioner_qualifications(clinic_id,practitioner_id,qualification_type_id,issuer,issued_on,expires_on,status,submitted_by) VALUES(:clinic,:practitioner,:type,:issuer,:issued,:expires,'pending',:actor)");
            $insert->execute(['clinic' => $actor->clinicId, 'practitioner' => $id, 'type' => $typeId, 'issuer' => $issuer, 'issued' => $issuedOn, 'expires' => $expiresOn, 'actor' => $actor->userId]);
            $recordId = (int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.submit', 'practitioner_qualification', $recordId, 'success', ['practitioner_id' => $id, 'qualification_type_id' => $typeId]);
            return ['id' => $recordId, 'status' => 'pending'];
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function reviewRecord(AuthContext $actor, int $id, array $body, string $cid): array
    {
        $this->admin($actor);
        $status = $body['status'] ?? null;
        $note = is_string($body['review_note'] ?? null) ? trim($body['review_note']) : '';
        if (!in_array($status, ['verified', 'rejected', 'revoked'], true) || strlen($note) > 500) throw new ApiException(422, 'validation_error', 'Choose a valid review decision and a note under 500 characters.');
        if ($status !== 'verified' && $note === '') throw new ApiException(422, 'validation_error', 'A review note is required when rejecting or revoking a qualification.');
        $pdo = $this->database->connection();
        try {
            $lookup = $pdo->prepare("SELECT q.status,q.submitted_by,q.expires_on,u.id owner_user_id,t.active type_active,t.requires_expiry FROM practitioner_qualifications q JOIN practitioners p ON p.id=q.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=q.clinic_id AND u.user_type='staff' JOIN qualification_types t ON t.id=q.qualification_type_id AND t.clinic_id=q.clinic_id  JOIN users submitter ON submitter.id=q.submitted_by AND submitter.clinic_id=q.clinic_id AND submitter.user_type='staff' LEFT JOIN users reviewer ON reviewer.id=q.reviewed_by AND reviewer.clinic_id=q.clinic_id AND reviewer.user_type='staff' WHERE q.id=:id AND q.clinic_id=:clinic AND (q.reviewed_by IS NULL OR reviewer.id IS NOT NULL) FOR UPDATE");
            $lookup->execute(['id' => $id, 'clinic' => $actor->clinicId]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ApiException(404, 'qualification_not_found', 'Qualification record not found.');
            if ($status === 'revoked' ? $row['status'] !== 'verified' : $row['status'] !== 'pending') throw new ApiException(409, 'qualification_state_changed', 'Reload the qualification before reviewing it.');
            if ($status === 'verified' && ((int)$row['submitted_by'] === $actor->userId || (int)$row['owner_user_id'] === $actor->userId)) throw new ApiException(403, 'self_review_forbidden', 'Another administrator must review your qualification or a record you submitted.');
            if ($status === 'verified' && (!(bool)$row['type_active'] || ((bool)$row['requires_expiry'] && $row['expires_on']===null))) throw new ApiException(422,'qualification_policy','This clinic requires an active qualification type and its required expiry date.');
            if ($status === 'verified' && $row['expires_on'] !== null && $row['expires_on'] < gmdate('Y-m-d')) throw new ApiException(422, 'qualification_expired', 'An expired qualification cannot be verified.');
            $update = $pdo->prepare('UPDATE practitioner_qualifications SET status=:status,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(),review_note=:note WHERE id=:id AND clinic_id=:clinic');
            $update->execute(['status' => $status, 'actor' => $actor->userId, 'note' => $note === '' ? null : $note, 'id' => $id, 'clinic' => $actor->clinicId]);
            $this->audit->write($actor->clinicId, $actor, $cid, 'qualification.review', 'practitioner_qualification', $id, 'success', ['previous_status' => $row['status'], 'status' => $status]);
            return ['id' => $id, 'status' => $status];
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function practitionerId(AuthContext $actor, ?int $requested): int
    {
        $this->staff($actor);
        if ($requested !== null) $this->admin($actor);
        elseif (!$actor->hasAnyRole('practitioner')) throw new ApiException(403, 'forbidden', 'Practitioner access is required.');
        $sql = 'SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.clinic_id=:clinic AND u.user_type=\'staff\' AND p.id=:requested';
        $params = ['clinic' => $actor->clinicId, 'requested' => $requested];
        if ($requested === null) { $sql = 'SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.clinic_id=:clinic AND u.user_type=\'staff\' AND p.active=1 AND u.id=:actor'; $params = ['clinic' => $actor->clinicId, 'actor' => $actor->userId]; }
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
