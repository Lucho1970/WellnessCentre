<?php
declare(strict_types=1);
namespace Wellness\Auth;

use Closure;
use Firebase\JWT\{JWK,JWT};
use Wellness\Http\ApiException;
use Wellness\Service\BrowserSessionPolicy;

/** No network selection from tokens. Inject the realm's existing, bounded JWKS loader. */
final class LoginProofVerifier
{
    public function __construct(private readonly BrowserSessionPolicy $policy, private readonly Closure $keyLoader) {}

    private function decode(?string $token, bool $proof, int $now): array
    {
        if (!is_string($token) || strlen($token) > 16384 || count(explode('.', $token)) !== 3) self::denied();
        try {
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 16, JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $header['kid'])) self::denied();
            $keys = ($this->keyLoader)(false);
            if (!in_array($header['kid'], array_column($keys['keys'] ?? [], 'kid'), true)) $keys = ($this->keyLoader)(true);
            $keys['keys'] = array_values(array_filter($keys['keys'] ?? [], static fn($key) => is_array($key)
                && ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
            $oldTime = JWT::$timestamp; $oldLeeway = JWT::$leeway;
            try {
                JWT::$timestamp = $now; JWT::$leeway = 0;
                $claims = (array)JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
            } finally { JWT::$timestamp = $oldTime; JWT::$leeway = $oldLeeway; }
        } catch (ApiException $error) { throw $error; }
        catch (\Throwable) { self::denied(); }
        $p = $this->policy;
        if (($claims['iss'] ?? null) !== $p->issuer() || ($claims['tid'] ?? null) !== $p->tenant
            || ($claims['aud'] ?? null) !== ($proof ? $p->spaAudience : $p->apiAudience)
            || (!$proof && ($claims['azp'] ?? null) !== $p->spaAudience)
            || ($proof && array_key_exists('azp', $claims) && $claims['azp'] !== $p->spaAudience)
            || ($claims['ver'] ?? null) !== '2.0' || !is_string($claims['sub'] ?? null)
            || strlen($claims['sub']) < 1 || strlen($claims['sub']) > 255
            || !is_string($claims['oid'] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $claims['oid'])
            || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
            || (array_key_exists('nbf',$claims) && !is_int($claims['nbf']))
            || $claims['iat'] > $now + 60 || $claims['exp'] <= $now || $claims['exp'] <= $claims['iat']) self::denied();
        if (!$proof && (!is_string($claims['scp'] ?? null)
            || !in_array($p->scope, preg_split('/\s+/', trim($claims['scp'])) ?: [], true))) {
            throw new ApiException(403,'session_scope_required','The required API permission is missing.');
        }
        return $claims; // Internal verified claims, never a response or diagnostic.
    }
    public function access(?string $token, int $now): array { return $this->decode($token, false, $now); }
    public function proof(?string $token, array $verifiedAccess, string $nonceHash, int $createdAt, int $now): array
    {
        $proof = $this->decode($token, true, $now);
        if (($proof['oid'] ?? null) !== $verifiedAccess['oid'] || ($proof['tid'] ?? null) !== $verifiedAccess['tid']
            || !is_string($proof['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $proof['nonce'])
            || !hash_equals($nonceHash, hash('sha256', $proof['nonce']))
            || !is_int($proof['auth_time'] ?? null) || $proof['auth_time'] < $now - 600
            || $proof['auth_time'] > $now + 60 || $proof['auth_time'] < $createdAt - 60) self::denied();
        return $proof;
    }
    private static function denied(): never { throw new ApiException(401,'session_sign_in_required','A fresh sign-in is required.'); }
}
