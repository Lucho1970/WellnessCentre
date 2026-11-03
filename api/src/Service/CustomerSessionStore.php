<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;

/** Adapter for the existing table; no second client clock or email-based linking. */
final class CustomerSessionStore
{
    public function __construct(private readonly PDO $pdo) {}
    public function insert(array $row, array $verifiedAccess): void
    {
        $q = $this->pdo->prepare('INSERT INTO customer_identities(identity_hash,issuer,subject,created_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $q->execute([$row['principal_hash'],$verifiedAccess['iss'],$verifiedAccess['sub'],$row['created_at']]);
        $q = $this->pdo->prepare('SELECT id FROM customer_identities WHERE identity_hash=?');
        $q->execute([$row['principal_hash']]); $identity = $q->fetchColumn();
        if (!$identity) throw new \RuntimeException('Customer identity storage failed.');
        $q = $this->pdo->prepare('INSERT INTO customer_sessions(token_hash,identity_id,authenticated_at,created_at,last_activity_at,expires_at,clinic_id,scope_hash,principal_hash,host,policy_version,issued_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['secret_hash'],$identity,$row['authenticated_at'],$row['created_at'],$row['last_activity_at'],$row['absolute_expires_at'],$row['clinic_id'],$row['scope_hash'],$row['principal_hash'],$row['host'],$row['policy_version'],$row['issued_epoch']]);
    }
    public function find(string $hash, bool $lock): ?array
    {
        $q = $this->pdo->prepare('SELECT s.*,s.token_hash AS secret_hash,s.expires_at AS absolute_expires_at FROM customer_sessions s WHERE token_hash=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$hash]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
