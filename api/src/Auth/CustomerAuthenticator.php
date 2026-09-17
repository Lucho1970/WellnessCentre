<?php
declare(strict_types=1);

namespace Wellness\Auth;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;
use Wellness\Config;
use Wellness\Http\ApiException;

/** Identity proof only: deliberately cannot return a staff/local-record AuthContext. */
final class CustomerAuthenticator
{
    public function __construct(private readonly Config $config, private readonly ?Closure $keyLoader = null) {}

    public function authenticate(?string $token): array
    {
        if (!$token) throw new ApiException(401, 'unauthorized', 'Customer sign-in is required.');
        $c = $this->config;
        foreach ([$c->customerTenantId, $c->customerApiClientId, $c->customerSpaClientId] as $id) {
            if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new ApiException(503, 'customer_identity_not_configured', 'Customer sign-in is not configured.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $c->customerSubdomain)) {
            throw new ApiException(503, 'customer_identity_not_configured', 'Customer sign-in is not configured.');
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
            $issuer = "https://{$c->customerTenantId}.ciamlogin.com/{$c->customerTenantId}/v2.0";
            if (($claims['iss'] ?? null) !== $issuer || ($claims['tid'] ?? null) !== $c->customerTenantId
                || ($claims['aud'] ?? null) !== $c->customerApiClientId || ($claims['azp'] ?? null) !== $c->customerSpaClientId
                || ($claims['ver'] ?? null) !== '2.0' || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
                || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)
                || $claims['iat'] > time() + 60 || $claims['exp'] <= $claims['iat'] || !is_string($claims['scp'] ?? null)) {
                throw new \UnexpectedValueException();
            }
            if (!in_array('access_as_client', preg_split('/\s+/', trim($claims['scp'])) ?: [], true)) {
                throw new ApiException(403, 'missing_scope', 'The customer API permission is missing.');
            }
            // No email lookup, local user creation, record claiming, roles or clinical data.
            return ['authenticated' => true, 'authentication_context' => 'customer', 'onboarding_status' => 'not_linked', 'capabilities' => []];
        } catch (ApiException $error) { throw $error; }
        catch (Throwable) { throw new ApiException(401, 'invalid_customer_token', 'Customer authorization is invalid or expired. Please sign in again.'); }
    }

    private function keys(bool $refresh): array
    {
        if ($this->keyLoader) return ($this->keyLoader)($refresh);
        $c = $this->config;
        $dir = dirname(__DIR__, 2) . '/var/cache';
        $file = $dir . '/customer-jwks-' . hash('sha256', $c->customerTenantId . $c->customerSubdomain) . '.json';
        $age = is_file($file) ? time() - (int)filemtime($file) : PHP_INT_MAX;
        // Unknown key IDs can trigger one refresh per minute, not an outbound call per request.
        if ($age < ($refresh ? 60 : $c->entraJwksCacheSeconds)) {
            $cached = json_decode((string)file_get_contents($file), true);
            if (is_array($cached) && is_array($cached['keys'] ?? null)) return $cached;
        }
        $url = "https://{$c->customerSubdomain}.ciamlogin.com/{$c->customerTenantId}/discovery/v2.0/keys";
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $keys = is_string($result) ? json_decode($result, true) : null;
        if ($status !== 200 || !is_array($keys) || !is_array($keys['keys'] ?? null) || $keys['keys'] === []) {
            throw new ApiException(503, 'customer_identity_unavailable', 'Customer identity verification is temporarily unavailable.');
        }
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($file, json_encode($keys, JSON_THROW_ON_ERROR), LOCK_EX);
        return $keys;
    }
}
