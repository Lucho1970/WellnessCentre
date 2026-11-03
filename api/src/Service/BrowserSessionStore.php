<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Wellness\Http\ApiException;

/** All session/authority/effect/audit work shares one PDO transaction. No auto retry. */
final class BrowserSessionStore
{
    private readonly CustomerSessionStore $customers;
    public function __construct(public readonly PDO $pdo) { $this->customers = new CustomerSessionStore($pdo); }
    public static function stamp(int $now): string { return gmdate('Y-m-d H:i:s', $now); }
    public static function time(string $stamp): int
    {
        $parsed = strtotime($stamp . ' UTC');
        if ($parsed === false) throw new ApiException(503,'session_store_unavailable','Secure sessions are temporarily unavailable.');
        return $parsed;
    }
    public function query(string $sql, array $params = []): \PDOStatement
    { $q = $this->pdo->prepare($sql); $q->execute($params); return $q; }
    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) throw new \LogicException('Session operations must own the transaction.');
        try {
            $this->pdo->beginTransaction(); $result = $operation();
            if (!$this->pdo->inTransaction()) throw new \LogicException('Session effect ended its transaction.');
            $this->pdo->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof \PDOException) throw new ApiException(503,'session_store_unavailable','Secure sessions are temporarily unavailable.');
            throw $error;
        }
    }
    public function epoch(BrowserSessionPolicy $p, string $principal, int $now): int
    {
        $this->query('INSERT INTO browser_session_epochs(scope_hash,principal_hash,generation,updated_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE generation=generation', [$p->scopeHash(),$principal,self::stamp($now)]);
        return (int)$this->query('SELECT generation FROM browser_session_epochs WHERE scope_hash=? AND principal_hash=? FOR UPDATE', [$p->scopeHash(),$principal])->fetchColumn();
    }
    public function revokeAll(BrowserSessionPolicy $p, string $principal, int $now): void
    { $this->query('UPDATE browser_session_epochs SET generation=generation+1,updated_at=? WHERE scope_hash=? AND principal_hash=?',[self::stamp($now),$p->scopeHash(),$principal]); }
    public function insert(BrowserSessionPolicy $p, array $row, array $claims): void
    {
        if ($p->realm === 'client') { $this->customers->insert($row, $claims); return; }
        $columns = ['secret_hash','scope_hash','principal_hash','realm','host','clinic_id','authenticated_at','created_at','last_activity_at','absolute_expires_at','policy_version','issued_epoch'];
        $this->query('INSERT INTO browser_sessions(' . implode(',',$columns) . ') VALUES(' . implode(',',array_fill(0,count($columns),'?')) . ')', array_map(static fn($c) => $row[$c],$columns));
    }
    public function find(BrowserSessionPolicy $p, string $hash, bool $lock = true): ?array
    {
        return $p->realm === 'client' ? $this->customers->find($hash,$lock)
            : ($this->query('SELECT * FROM browser_sessions WHERE secret_hash=?' . ($lock ? ' FOR UPDATE' : ''),[$hash])->fetch(PDO::FETCH_ASSOC) ?: null);
    }
    public function touch(BrowserSessionPolicy $p, string $hash, int $now, bool $revoke = false): void
    {
        // Identifiers come solely from this fixed internal mapping, never a request.
        [$table,$key] = $p->realm === 'client' ? ['customer_sessions','token_hash'] : ['browser_sessions','secret_hash'];
        $column = $revoke ? 'revoked_at' : 'last_activity_at';
        $this->query("UPDATE $table SET $column=? WHERE $key=?",[self::stamp($now),$hash]);
    }
    public function challenge(BrowserSessionPolicy $p, string $hash): ?array
    { return $this->query('SELECT * FROM browser_auth_challenges WHERE secret_hash=? AND scope_hash=? FOR UPDATE',[$hash,$p->scopeHash()])->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function audit(BrowserSessionPolicy $p, ?string $principal, ?int $actor, string $correlation, string $action, string $outcome = 'success', string $reason = '', int $status = 200): void
    {
        if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$correlation)) throw new \InvalidArgumentException('Invalid session correlation.');
        $actorType = $principal === null ? 'anonymous' : match($p->realm) { 'central'=>'application_admin','client'=>'customer_identity',default=>'staff' };
        $metadata = ['realm'=>$p->realm,'scope_hash'=>$p->scopeHash(),'principal_hash'=>$principal,'reason'=>$reason];
        $this->query('INSERT INTO access_audit_events(event_group,part_number,part_count,clinic_id,actor_type,actor_id,correlation_id,action,route_key,http_method,http_status,outcome,metadata) VALUES(?,1,1,?,?,?,?,?,?,?,?,?,?)',
            [bin2hex(random_bytes(16)),$p->clinicId,$actorType,$actor,$correlation,'session.'.$action,'session/'.$p->realm,$action === 'check' ? 'GET' : 'POST',$status,$outcome,json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
    public function rate(BrowserSessionPolicy $p, string $abuseHash, int $now): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$abuseHash)) throw new \InvalidArgumentException('Server-derived abuse key required.');
        $bucket = BrowserSessionPolicy::digest([$p->scopeHash(),$abuseHash,intdiv($now,600)]);
        $attempts = $this->transaction(function () use ($bucket,$now): int {
            $this->query('INSERT INTO customer_rate_limits(bucket_hash,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1',[$bucket,self::stamp($now+600)]);
            return (int)$this->query('SELECT attempts FROM customer_rate_limits WHERE bucket_hash=? FOR UPDATE',[$bucket])->fetchColumn();
        });
        if ($attempts > 20) throw new ApiException(429,'session_rate_limited','Too many sign-in attempts. Please wait.');
    }
}
