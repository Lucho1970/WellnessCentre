<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\ClinicContext;

/** Server-resolved configuration only. A session never supplies a role or clinic. */
final readonly class BrowserSessionPolicy
{
    public int $idleSeconds;
    public function __construct(
        public string $realm, public string $host, public ?int $clinicId,
        public string $tenant, public string $apiAudience, public string $spaAudience,
        public string $scope, public int $version = 1,
    ) {
        if (!in_array($realm, ['workforce','practitioner','client','central'], true)
            || ClinicContext::normalizeHost($host) !== $host || str_contains($host, ':')
            || ($realm === 'central' ? $clinicId !== null : ($clinicId === null || $clinicId < 1))
            || $version < 1 || $version > 1000000 || !preg_match('/^[a-z_]{1,80}$/D', $scope)) {
            throw new \InvalidArgumentException('Invalid server session policy.');
        }
        foreach ([$tenant,$apiAudience,$spaAudience] as $id) {
            if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id)) throw new \InvalidArgumentException('Invalid session identity configuration.');
        }
        if ($apiAudience === $spaAudience) throw new \InvalidArgumentException('Distinct session audiences required.');
        $this->idleSeconds = $realm === 'client' ? 1800 : 900;
    }
    public function issuer(): string
    {
        $authority = in_array($this->realm, ['workforce','central'], true) ? 'login.microsoftonline.com' : $this->tenant . '.ciamlogin.com';
        return "https://{$authority}/{$this->tenant}/v2.0";
    }
    public function scopeHash(): string { return self::digest([$this->realm,$this->host,$this->clinicId]); }
    public function principalHash(array $verifiedAccess): string
    {
        // Preserve the existing customer's API issuer/sub hash exactly. Workforce
        // uses oid, matching EntraAuthenticator; external staff uses API sub.
        $subject = in_array($this->realm, ['workforce','central'], true) ? $verifiedAccess['oid'] : $verifiedAccess['sub'];
        return self::digest([$verifiedAccess['iss'],$subject]);
    }
    public static function digest(array $parts): string { return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)); }
}
