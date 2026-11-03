<?php
declare(strict_types=1);
namespace Wellness;

use PDO;
use Wellness\Http\ApiException;

/** A guarded HTTP request owns the physical transaction. Existing service
 * transactions retain their own savepoint/rollback boundaries inside it.
 * Outside an explicit request boundary all PDO behavior remains unchanged.
 */
final class SessionConnection extends PDO
{
    private bool $request = false;
    private int $depth = 0;
    private array $denialCounters = [];
    public function requestActive(): bool { return $this->request; }
    public function beginRequest(): void
    {
        if ($this->request || parent::inTransaction()) throw new \LogicException('Request transaction already active.');
        parent::exec('SET SESSION innodb_lock_wait_timeout=5');
        parent::exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        parent::beginTransaction(); $this->request = true; $this->depth = 0; $this->denialCounters=[];
    }
    public function beginTransaction(): bool
    {
        if (!$this->request) return parent::beginTransaction();
        $this->physical(); parent::exec('SAVEPOINT browser_service_' . (++$this->depth)); return true;
    }
    public function commit(): bool
    {
        if (!$this->request) return parent::commit();
        $this->physical(); if ($this->depth < 1) throw new \LogicException('Only the request boundary may commit.');
        parent::exec('RELEASE SAVEPOINT browser_service_' . $this->depth--); return true;
    }
    public function rollBack(): bool
    {
        if (!$this->request) return parent::rollBack();
        $this->physical(); if ($this->depth < 1) throw new \LogicException('Only the request boundary may abort.');
        parent::exec('ROLLBACK TO SAVEPOINT browser_service_' . $this->depth);
        parent::exec('RELEASE SAVEPOINT browser_service_' . $this->depth--); return true;
    }
    public function inTransaction(): bool { return $this->request ? $this->depth > 0 && parent::inTransaction() : parent::inTransaction(); }
    public function checkpointRequest():void { $this->physical();parent::exec('SAVEPOINT browser_request_effects'); }
    public function rollbackEffects():void
    {
        $this->physical();parent::exec('ROLLBACK TO SAVEPOINT browser_request_effects');$this->depth=0;
        // Security counters survive rejected business effects, inside the same
        // admission/audit boundary. Fixed statements only; no general replay API.
        foreach($this->denialCounters as [$kind,$params]){
            $sql=match($kind){
                'customer-rate'=>'INSERT INTO customer_rate_limits(bucket_hash,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1',
                'verification-attempt'=>'UPDATE staff_notification_preferences SET email_code_attempts=email_code_attempts+1 WHERE user_id=? AND email_code_hash=?',
            };
            $this->prepare($sql)->execute($params);
        }
    }
    public function preserveDenialCounter(string $kind,array $params):void
    {
        if(!$this->request || !in_array($kind,['customer-rate','verification-attempt'],true))throw new \LogicException('Invalid denial counter.');
        $this->denialCounters[]=[$kind,$params];
    }
    private function physical(): void
    {
        if (!parent::inTransaction()) throw new ApiException(503,'session_transaction_lost','The request could not be completed. Please retry.');
    }
    public function commitRequest(): void
    {
        $this->physical(); if (!$this->request || $this->depth !== 0) throw new \LogicException('Unfinished service transaction.');
        try { parent::commit(); } finally { $this->request = false; $this->depth = 0; $this->denialCounters=[]; }
    }
    public function abortRequest(): void
    {
        try { if (parent::inTransaction()) parent::rollBack(); }
        finally { $this->request = false; $this->depth = 0; $this->denialCounters=[]; }
    }
}
