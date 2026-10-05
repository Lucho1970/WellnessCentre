<?php
declare(strict_types=1);

namespace Wellness\Auth;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;
use Wellness\Config;
use Wellness\Http\ApiException;

/** Dedicated application trust; client tokens cannot enter this adapter. */
final class ExternalStaffAuthenticator implements IdentityAdapter
{
    public function __construct(private readonly Config $config, private readonly ?Closure $keyLoader = null) {}

    public function isCandidate(?string $token): bool
    {
        // Routing hint only; verify() still authenticates the entire token.
        if (!$this->config->staffInvitationsEnabled || $this->config->staffExternalApiClientId === '' || !$token || strlen($token)>16384) return false;
        try { $parts=explode('.',$token); $claims=json_decode(JWT::urlsafeB64Decode($parts[1]??''),true,16,JSON_THROW_ON_ERROR);return ($claims['aud']??null)===$this->config->staffExternalApiClientId; } catch (Throwable) {return false;}
    }

    public function verify(?string $token): VerifiedIdentity
    {
        if (!$this->config->staffInvitationsEnabled) throw new ApiException(503,'staff_invitations_disabled','Staff invitations are not enabled.');
        if ($this->config->staffExternalApiClientId === $this->config->customerApiClientId
            || $this->config->staffExternalSpaClientId === $this->config->customerSpaClientId
            || $this->config->staffExternalApiClientId === $this->config->staffExternalSpaClientId
            || $this->config->staffExternalApiClientId === $this->config->entraApiClientId) {
            throw new ApiException(503,'staff_identity_not_configured','Use separate staff application registrations.');
        }
        $claims=$this->claims($token);
        return new VerifiedIdentity('entra-external-staff',$claims['iss'],$claims['sub'],$claims['tid']);
    }

    private function claims(?string $token): array
    {
        if (!$token) throw new ApiException(401, 'unauthorized', 'Invited staff sign-in is required.');
        $c = $this->config;
        foreach ([$c->staffExternalTenantId, $c->staffExternalApiClientId, $c->staffExternalSpaClientId] as $id) {
            if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new ApiException(503, 'staff_identity_not_configured', 'Invited staff sign-in is not configured.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $c->staffExternalSubdomain)) {
            throw new ApiException(503, 'staff_identity_not_configured', 'Invited staff sign-in is not configured.');
        }
        try {
            if (strlen($token) > 16384) throw new \UnexpectedValueException();
            $parts = explode('.', $token);
            if (count($parts) !== 3) throw new \UnexpectedValueException();
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || strlen($header['kid']) > 128) throw new \UnexpectedValueException();
            $keys = $this->keys(false);
            if (!in_array($header['kid'], array_column($keys['keys'] ?? [], 'kid'), true)) $keys = $this->keys(true);
            // Never allow a JWK or token to broaden the permitted algorithm.
            $keys['keys'] = array_values(array_filter($keys['keys'] ?? [], static fn($k) =>
                is_array($k) && ($k['kty'] ?? '') === 'RSA' && ($k['alg'] ?? 'RS256') === 'RS256' && ($k['use'] ?? 'sig') === 'sig'));
            $previousLeeway = JWT::$leeway;
            try {
                JWT::$leeway = 60;
                $claims = (array)JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
            } finally { JWT::$leeway = $previousLeeway; }
            $issuer = "https://{$c->staffExternalTenantId}.ciamlogin.com/{$c->staffExternalTenantId}/v2.0";
            if (($claims['iss'] ?? null) !== $issuer || ($claims['tid'] ?? null) !== $c->staffExternalTenantId
                || ($claims['aud'] ?? null) !== $c->staffExternalApiClientId
                || ($claims['azp'] ?? null) !== $c->staffExternalSpaClientId
                || ($claims['ver'] ?? null) !== '2.0' || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
                || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)
                || strlen($claims['sub']) > 255
                || $claims['iat'] > time() + 60 || $claims['exp'] <= $claims['iat'] || !is_string($claims['scp'] ?? null)) {
                throw new \UnexpectedValueException();
            }
            if (!in_array('access_as_staff', preg_split('/\s+/', trim($claims['scp'])) ?: [], true)) {
                throw new ApiException(403, 'missing_scope', 'The staff API permission is missing.');
            }
            return $claims; // Internal only. Never serialize raw token claims to the browser.
        } catch (ApiException $error) { throw $error; }
        catch (Throwable) { throw new ApiException(401, 'invalid_staff_token', 'Invited staff authorization is invalid or expired. Please sign in again.'); }
    }

    private function keys(bool $refresh): array
    {
        if ($this->keyLoader) return ($this->keyLoader)($refresh);
        $c = $this->config;
        $dir = dirname(__DIR__, 2) . '/var/cache';
        $file = $dir . '/external-staff-jwks-' . hash('sha256', $c->staffExternalTenantId . $c->staffExternalSubdomain) . '.json';
        $age = is_file($file) ? time() - (int)filemtime($file) : PHP_INT_MAX;
        // Unknown key IDs can trigger one refresh per minute, not an outbound call per request.
        if ($age < ($refresh ? 60 : $c->entraJwksCacheSeconds)) {
            $cached = json_decode((string)file_get_contents($file), true);
            if (is_array($cached) && is_array($cached['keys'] ?? null)) return $cached;
        }
        $url = "https://{$c->staffExternalSubdomain}.ciamlogin.com/{$c->staffExternalTenantId}/discovery/v2.0/keys";
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $keys = is_string($result) ? json_decode($result, true) : null;
        if ($status !== 200 || !is_array($keys) || !is_array($keys['keys'] ?? null) || $keys['keys'] === []) {
            throw new ApiException(503, 'staff_identity_unavailable', 'Invited staff identity verification is temporarily unavailable.');
        }
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($file, json_encode($keys, JSON_THROW_ON_ERROR), LOCK_EX);
        return $keys;
    }
}

