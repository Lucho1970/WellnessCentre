<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Wellness\Auth\VerifiedIdentity;
use Wellness\Http\ApiException;

/** Serialize a pilot decision with API requests for that exact clinic/identity. */
final class PractitionerPilotLock
{
    private bool $held = false;
    private readonly string $name;

    public function __construct(private readonly PDO $pdo, int $clinic, VerifiedIdentity $identity)
    {
        $this->name = 'wpi:'.substr(hash('sha256', json_encode([
            (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
            $clinic,$identity->adapter,$identity->issuer,$identity->subject,
        ], JSON_THROW_ON_ERROR)), 0, 60);
    }

    public function acquire(): void
    {
        if ($this->held) return;
        $query = $this->pdo->prepare('SELECT GET_LOCK(?,5)');
        $query->execute([$this->name]);
        if ((int)$query->fetchColumn() !== 1) {
            throw new ApiException(503,'pilot_access_busy','Pilot access is busy. Please try again.');
        }
        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) return;
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$this->name]);
        if ((int)$query->fetchColumn() !== 1) throw new \RuntimeException('Pilot access lock could not be released.');
        $this->held = false;
    }
}
