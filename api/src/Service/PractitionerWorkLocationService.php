<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class PractitionerWorkLocationService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public function get(AuthContext $actor): array
    {
        $id = $this->practitioner($actor);
        $query = $this->database->connection()->prepare('SELECT home_address,work_address,work_same_as_home,version FROM practitioner_private_locations WHERE practitioner_id=:id');
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        return $row ? [
            'home_address' => $row['home_address'] === null ? null : json_decode($row['home_address'], true, 32, JSON_THROW_ON_ERROR),
            'work_address' => $row['work_address'] === null ? null : json_decode($row['work_address'], true, 32, JSON_THROW_ON_ERROR),
            'work_same_as_home' => (bool)$row['work_same_as_home'],
            'version' => (int)$row['version'],
        ] : ['home_address' => null, 'work_address' => null, 'work_same_as_home' => false, 'version' => 0];
    }

    public function save(AuthContext $actor, array $body, string $cid): array
    {
        $id = $this->practitioner($actor);
        $same = $body['work_same_as_home'] ?? null;
        $version = $body['version'] ?? null;
        if (!is_bool($same) || !is_int($version) || $version < 0) throw new ApiException(422, 'validation_error', 'Choose a work location and reload outdated settings.');
        $home = self::address($body['home_address'] ?? null, $same, 'home_address');
        $work = $same ? null : self::address($body['work_address'] ?? null, true, 'work_address');
        $pdo = $this->database->connection();
        try {
            $pdo->beginTransaction();
            // Lock the parent as well, including the first save when no settings row exists.
            $lock = $pdo->prepare('SELECT id FROM practitioners WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $current = $pdo->prepare('SELECT version FROM practitioner_private_locations WHERE practitioner_id=:id');
            $current->execute(['id' => $id]);
            if ((int)($current->fetchColumn() ?: 0) !== $version) throw new ApiException(409, 'work_location_changed', 'These settings changed. Reload before saving.');
            $query = $pdo->prepare('INSERT INTO practitioner_private_locations(practitioner_id,home_address,work_address,work_same_as_home,version) VALUES(:id,:home,:work,:same,:version) ON DUPLICATE KEY UPDATE home_address=VALUES(home_address),work_address=VALUES(work_address),work_same_as_home=VALUES(work_same_as_home),version=VALUES(version)');
            $query->execute(['id' => $id, 'home' => $home === null ? null : json_encode($home, JSON_THROW_ON_ERROR), 'work' => $work === null ? null : json_encode($work, JSON_THROW_ON_ERROR), 'same' => (int)$same, 'version' => $version + 1]);
            // Store no addresses or coordinates in audit metadata.
            $this->audit->write($actor->clinicId, $actor, $cid, 'practitioner.work_location.update', 'practitioner', $id, 'success', ['same_as_home' => $same, 'version' => $version + 1]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return $this->get($actor);
    }

    private function practitioner(AuthContext $actor): int
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('practitioner')) throw new ApiException(403, 'forbidden', 'Only practitioners can manage their private work location.');
        $query = $this->database->connection()->prepare('SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.user_id=:user AND u.clinic_id=:clinic AND u.status=\'active\' AND p.active=1');
        $query->execute(['user' => $actor->userId, 'clinic' => $actor->clinicId]);
        $id = $query->fetchColumn();
        if (!$id) throw new ApiException(403, 'forbidden', 'An active practitioner account is required.');
        return (int)$id;
    }

    private static function address(mixed $value, bool $required, string $field): ?array
    {
        if ($value === null && !$required) return null;
        try {
            $address = Delivery::destination(['delivery_mode' => 'mobile', 'destination' => $value]);
            unset($address['instructions']);
            if (!in_array(strtoupper($address['country']), ['CA', 'CANADA'], true)) throw new ApiException(422, 'validation_error', 'Enter a Canadian address.');
            $address['country'] = 'Canada';
            return $address;
        } catch (ApiException) {
            throw new ApiException(422, 'validation_error', 'Enter a complete Canadian address.', [$field => 'Enter a complete Canadian address.']);
        }
    }
}
