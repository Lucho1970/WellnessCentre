<?php
declare(strict_types=1);
namespace Wellness\Service;

use Closure;
use PDO;
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\ApiException;

/** Inert until explicitly composed with a current-authority callback and an HTTP adapter.
 * Authority and effects MUST use the supplied transaction, with epoch-before-authority
 * lock order. This class is not connected to any existing application route.
 */
final class BrowserSessionService
{
    public function __construct(
        private readonly BrowserSessionStore $store, private readonly BrowserSessionPolicy $policy,
        private readonly LoginProofVerifier $verifier, private readonly Closure $authority,
        private readonly ?Closure $clock = null,
    ) {}
    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    private static function secret(): string { return bin2hex(random_bytes(32)); }
    private static function hash(?string $secret): string
    {
        if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D',$secret)) self::denied();
        return hash('sha256',$secret);
    }
    private function csrf(string $secret): string { return hash_hmac('sha256','csrf:'.$this->policy->scopeHash(),$secret); }
    private function checkCsrf(string $secret, ?string $csrf): void
    {
        if (!is_string($csrf) || !hash_equals($this->csrf($secret),$csrf)) throw new ApiException(403,'session_csrf_denied','Refresh the original portal and try again.');
    }
    private static function denied(): never { throw new ApiException(401,'session_required','Please sign in again.'); }
    private function actor(array $claims): BrowserSessionAuthority
    {
        $actor = ($this->authority)($this->store->pdo,$this->policy,$claims);
        if($actor instanceof BrowserSessionAuthority)return $actor;
        if ($actor !== null && (!is_int($actor) || $actor < 1)) throw new \LogicException('Invalid current session authority.');
        return new BrowserSessionAuthority($actor);
    }
    private function operation(string $correlation, ?string $principal, callable $operation): mixed
    {
        try { return $this->store->transaction($operation); }
        catch (ApiException $error) {
            if ($error->status < 500) {
                // Persist a denial after rolling back rejected changes. Failure to
                // persist required audit replaces the denial with an unavailable error.
                $this->denial($correlation,$principal,$error);
            }
            throw $error;
        }
    }
    private function denial(string $correlation, ?string $principal, ApiException $error): void
    {
        $reason = preg_match('/^[a-z_]{1,80}$/D',$error->errorCode) ? $error->errorCode : 'authorization_denied';
        $this->store->transaction(fn() => $this->store->audit($this->policy,$principal,null,$correlation,'deny','denied',$reason,$error->status));
    }
    private function initialAccess(?string $bearer, string $correlation): array
    {
        try { return $this->verifier->access($bearer,$this->now()); }
        catch (ApiException $error) { if ($error->status < 500) $this->denial($correlation,null,$error); throw $error; }
    }
    private function createChallenge(string $purpose, ?array $session = null): BrowserSessionGrant
    {
        $secret = self::secret(); $nonce = self::secret(); $now = $this->now();
        $this->store->query('INSERT INTO browser_auth_challenges(secret_hash,nonce_hash,scope_hash,purpose,session_hash,action_name,target_hash,target_version,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [self::hash($secret),hash('sha256',$nonce),$this->policy->scopeHash(),$purpose,$session['secret_hash'] ?? null,
                $session ? 'session.revoke_all' : null,$session['principal_hash'] ?? null,$session['issued_epoch'] ?? null,
                BrowserSessionStore::stamp($now),BrowserSessionStore::stamp($now+600)]);
        return new BrowserSessionGrant($secret,['nonce'=>$nonce,'expires_at'=>$now+600]);
    }
    public function challenge(string $serverAbuseHash): BrowserSessionGrant
    {
        $this->store->rate($this->policy,$serverAbuseHash,$this->now());
        return $this->store->transaction(fn() => $this->createChallenge('login'));
    }
    private function consumeChallenge(?string $cookie, string $purpose, array $claims, ?string $proof, ?array $session = null): array
    {
        $hash = self::hash($cookie); $row = $this->store->challenge($this->policy,$hash); $now = $this->now();
        if (!$row || $row['purpose'] !== $purpose || $row['consumed_at'] !== null
            || BrowserSessionStore::time($row['expires_at']) <= $now
            || ($purpose === 'action' && ($row['session_hash'] !== $session['secret_hash']
                || $row['action_name'] !== 'session.revoke_all'
                || $row['target_hash'] !== $session['principal_hash'] || (int)$row['target_version'] !== (int)$session['issued_epoch']))) self::denied();
        $verified = $this->verifier->proof($proof,$claims,$row['nonce_hash'],BrowserSessionStore::time($row['created_at']),$now);
        $this->store->query('UPDATE browser_auth_challenges SET consumed_at=? WHERE secret_hash=?',[BrowserSessionStore::stamp($now),$hash]);
        return $verified;
    }
    public function start(?string $bearer, ?string $proof, ?string $challengeCookie, string $correlation, ?string $previousCookie = null): BrowserSessionGrant
    {
        $claims = $this->initialAccess($bearer,$correlation); $principal = $this->policy->principalHash($claims);
        return $this->operation($correlation,$principal,function () use ($bearer,$proof,$claims,$principal,$challengeCookie,$previousCookie,$correlation): BrowserSessionGrant {
            $epoch = $this->store->epoch($this->policy,$principal,$this->now());
            $claims = $this->verifier->access($bearer,$this->now()); // Recheck expiry after a lock wait.
            $fresh = $this->consumeChallenge($challengeCookie,'login',$claims,$proof);
            $actor = $this->actor($claims); $now = $this->now(); $secret = self::secret();
            if ($claims['exp'] <= $now || $fresh['exp'] <= $now || $fresh['auth_time'] < $now-600) self::denied();
            if ($previousCookie !== null) {
                $oldHash = self::hash($previousCookie);
                // Do not acquire another principal's session lock after this epoch.
                $old = $this->store->find($this->policy,$oldHash,false);
                if ($old && $old['scope_hash'] === $this->policy->scopeHash() && $old['principal_hash'] === $principal) {
                    $this->store->find($this->policy,$oldHash);
                    $this->store->touch($this->policy,$oldHash,$now,true);
                }
            }
            $row = ['secret_hash'=>self::hash($secret),'scope_hash'=>$this->policy->scopeHash(),'principal_hash'=>$principal,
                'realm'=>$this->policy->realm,'host'=>$this->policy->host,'clinic_id'=>$this->policy->clinicId,
                'authenticated_at'=>BrowserSessionStore::stamp($fresh['auth_time']),'created_at'=>BrowserSessionStore::stamp($now),
                'last_activity_at'=>BrowserSessionStore::stamp($now),'absolute_expires_at'=>BrowserSessionStore::stamp($fresh['auth_time']+28800),
                'policy_version'=>$this->policy->version,'issued_epoch'=>$epoch] + $actor->columns();
            $this->store->insert($this->policy,$row,$claims);
            $this->store->audit($this->policy,$principal,$actor->actorId,$correlation,'start');
            return new BrowserSessionGrant($secret,$this->metadata($row,$secret));
        });
    }
    private function metadata(array $row, string $cookie): array
    {
        return ['csrf'=>$this->csrf($cookie),'idle_expires_at'=>BrowserSessionStore::time($row['last_activity_at'])+$this->policy->idleSeconds,
            'absolute_expires_at'=>BrowserSessionStore::time($row['absolute_expires_at']),'policy_version'=>$this->policy->version];
    }
    private function locked(string $hash, string $principal, int $epoch): array
    {
        $row = $this->store->find($this->policy,$hash); $now = $this->now();
        if (!$row || $row['scope_hash'] !== $this->policy->scopeHash() || $row['principal_hash'] !== $principal
            || $row['host'] !== $this->policy->host || ($row['clinic_id'] === null ? null : (int)$row['clinic_id']) !== $this->policy->clinicId
            || (int)$row['policy_version'] !== $this->policy->version || (int)$row['issued_epoch'] !== $epoch
            || $row['revoked_at'] !== null || BrowserSessionStore::time($row['absolute_expires_at']) <= $now
            || BrowserSessionStore::time($row['last_activity_at']) + $this->policy->idleSeconds <= $now) self::denied();
        return $row;
    }
    /** The effect is a trusted server operation, not a request callback or SQL string. */
    public function protect(?string $bearer, ?string $cookie, ?string $csrf, bool $mutation, string $correlation, Closure $effect, bool $activity = false): mixed
    {
        $claims = $this->initialAccess($bearer,$correlation); $principal = $this->policy->principalHash($claims);
        return $this->operation($correlation,$principal,function () use ($bearer,$cookie,$csrf,$mutation,$correlation,$effect,$activity,$principal): mixed {
            $hash = self::hash($cookie);
            $epoch = $this->store->epoch($this->policy,$principal,$this->now());
            $row = $this->locked($hash,$principal,$epoch); $claims = $this->verifier->access($bearer,$this->now());
            if ($mutation || $activity) $this->checkCsrf($cookie,$csrf);
            $actor = $this->actor($claims);
            $row = $this->locked($hash,$principal,$epoch); // Authority lookup may have waited.
            $this->verifier->access($bearer,$this->now());
            if ($activity) {
                $row['last_activity_at'] = BrowserSessionStore::stamp($this->now());
                $this->store->touch($this->policy,$hash,$this->now());
            }
            $result = $effect($this->store->pdo,$row,$claims,$actor->actorId,$actor);
            $this->locked($hash,$principal,$epoch); // An interrupted/long effect cannot slide a deadline.
            $this->verifier->access($bearer,$this->now());
            $this->store->audit($this->policy,$principal,$actor->actorId,$correlation,$mutation ? 'effect' : 'check');
            return $result;
        });
    }
    public function me(?string $bearer, ?string $cookie, string $correlation): array
    { return $this->protect($bearer,$cookie,null,false,$correlation,fn(PDO $pdo,array $row) => $this->metadata($row,$cookie)); }
    public function activity(?string $bearer, ?string $cookie, ?string $csrf, string $correlation): array
    { return $this->protect($bearer,$cookie,$csrf,true,$correlation,fn(PDO $pdo,array $row) => $this->metadata($row,$cookie),true); }
    public function logout(?string $cookie, ?string $csrf, string $correlation): void
    {
        $this->operation($correlation,null,function () use ($cookie,$csrf,$correlation): void {
            $hash = self::hash($cookie); $this->checkCsrf($cookie,$csrf);
            $row = $this->store->find($this->policy,$hash,false);
            if (!$row || $row['scope_hash'] !== $this->policy->scopeHash()) self::denied();
            $this->store->epoch($this->policy,$row['principal_hash'],$this->now());
            $this->store->find($this->policy,$hash);
            $this->store->touch($this->policy,$hash,$this->now(),true);
            $this->store->audit($this->policy,$row['principal_hash'],null,$correlation,'logout');
        });
    }
    public function actionChallenge(?string $bearer, ?string $cookie, ?string $csrf, string $correlation): BrowserSessionGrant
    {
        return $this->protect($bearer,$cookie,$csrf,true,$correlation,fn(PDO $pdo,array $row) => $this->createChallenge('action',$row));
    }
    public function actionProof(?string $bearer, ?string $cookie, ?string $csrf, ?string $proof, ?string $challengeCookie, string $correlation): array
    {
        return $this->protect($bearer,$cookie,$csrf,true,$correlation,function (PDO $pdo,array $row,array $claims) use ($proof,$challengeCookie): array {
            $fresh = $this->consumeChallenge($challengeCookie,'action',$claims,$proof,$row); $now = $this->now();
            if ($fresh['auth_time'] < $now - 300) self::denied();
            $secret = self::secret(); $expires = min($now+300,$fresh['auth_time']+300);
            $this->store->query('INSERT INTO browser_action_proofs(secret_hash,scope_hash,principal_hash,session_hash,action_name,target_hash,target_version,authenticated_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',
                [self::hash($secret),$this->policy->scopeHash(),$row['principal_hash'],$row['secret_hash'],'session.revoke_all',$row['principal_hash'],$row['issued_epoch'],BrowserSessionStore::stamp($fresh['auth_time']),BrowserSessionStore::stamp($expires)]);
            return ['action_proof'=>$secret,'expires_at'=>$expires]; // One use, purpose/target/version bound; memory only.
        });
    }
    public function revokeAll(?string $bearer, ?string $cookie, ?string $csrf, ?string $actionProof, string $correlation): void
    {
        $this->protect($bearer,$cookie,$csrf,true,$correlation,function (PDO $pdo,array $row,array $claims,?int $actor) use ($actionProof,$correlation): void {
            $hash = self::hash($actionProof); $now = $this->now();
            $proof = $this->store->query('SELECT * FROM browser_action_proofs WHERE secret_hash=? FOR UPDATE',[$hash])->fetch(PDO::FETCH_ASSOC);
            if (!$proof || $proof['scope_hash'] !== $this->policy->scopeHash() || $proof['principal_hash'] !== $row['principal_hash']
                || $proof['session_hash'] !== $row['secret_hash'] || $proof['action_name'] !== 'session.revoke_all'
                || $proof['target_hash'] !== $row['principal_hash'] || (int)$proof['target_version'] !== (int)$row['issued_epoch']
                || $proof['consumed_at'] !== null || BrowserSessionStore::time($proof['expires_at']) <= $now) self::denied();
            $this->store->query('UPDATE browser_action_proofs SET consumed_at=? WHERE secret_hash=?',[BrowserSessionStore::stamp($now),$hash]);
            $this->store->revokeAll($this->policy,$row['principal_hash'],$now);
            $this->store->audit($this->policy,$row['principal_hash'],$actor,$correlation,'revoke_all');
        });
    }
}
