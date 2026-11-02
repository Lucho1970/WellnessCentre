<?php
declare(strict_types=1);
namespace WellnessPrototype;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Disposable synthetic feasibility code. Not a production session implementation. */
final class FixtureError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode) { parent::__construct($errorCode); }
}

final class SessionFixture
{
    public const TENANT = '11111111-1111-4111-8111-111111111111';
    public const ISSUER = 'https://login.session.test/' . self::TENANT . '/v2.0';
    private const REALMS = [
        'workforce' => ['22222222-2222-4222-8222-222222222221', '33333333-3333-4333-8333-333333333331', 'access_as_user'],
        'practitioner' => ['22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333332', 'access_as_staff'],
        'client' => ['22222222-2222-4222-8222-222222222223', '33333333-3333-4333-8333-333333333333', 'access_as_client'],
        'central' => ['22222222-2222-4222-8222-222222222224', '33333333-3333-4333-8333-333333333334', 'access_as_user'],
    ];
    public function __construct(private readonly string $runtime) {}

    public static function initial(): array { return ['offset' => 0, 'sessions' => [], 'challenges' => [], 'events' => []]; }

    /** File locking is for this local experiment only; it is not SQL race evidence. */
    private function state(callable $action): mixed
    {
        $file = fopen($this->runtime . '/state.json', 'c+');
        if (!$file || !flock($file, LOCK_EX)) throw new FixtureError(503, 'fixture_store_unavailable');
        try {
            $raw = stream_get_contents($file, 2097153);
            if ($raw === false || strlen($raw) > 2097152) throw new FixtureError(503, 'fixture_store_invalid');
            $state = $raw === '' ? self::initial() : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new FixtureError(503, 'fixture_store_invalid');
            $result = $action($state);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) throw new FixtureError(503, 'fixture_store_unavailable');
            return $result;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    private function secret(): string
    {
        $value = file_get_contents($this->runtime . '/control-secret');
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) throw new FixtureError(503, 'fixture_configuration');
        return $value;
    }
    public function control(string $provided, array $body): array
    {
        if (!hash_equals($this->secret(), $provided)) throw new FixtureError(403, 'fixture_control_denied');
        return $this->state(function (array &$state) use ($body): array {
            switch ($body['action'] ?? '') {
                case 'reset': $state = self::initial(); break;
                case 'advance':
                    if (!is_int($body['seconds'] ?? null) || $body['seconds'] < 0 || $body['seconds'] > 86400) throw new FixtureError(422, 'fixture_clock_invalid');
                    $state['offset'] += $body['seconds']; break;
                case 'revoke':
                    foreach ($state['sessions'] as &$row) {
                        if ($row['host'] === ($body['host'] ?? '') && $row['realm'] === ($body['realm'] ?? '')) $row['revoked'] = true;
                    }
                    unset($row); break;
                case 'snapshot': break;
                default: throw new FixtureError(422, 'fixture_control_invalid');
            }
            return ['now' => time() + $state['offset'], 'state' => $state];
        });
    }
    private static function cookieName(string $realm, bool $challenge = false): string { return '__Host-wc-poc-' . ($challenge ? 'challenge-' : '') . $realm; }
    private static function cookie(string $realm, string $value, bool $challenge = false): void
    {
        if (!setcookie(self::cookieName($realm, $challenge), $value, ['expires' => $value === '' ? 1 : 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'])) throw new FixtureError(503, 'fixture_cookie_failed');
    }
    private static function digest(string $value): string { return hash('sha256', $value); }
    private static function cookieValue(string $realm, bool $challenge = false): string
    {
        $value = $_COOKIE[self::cookieName($realm, $challenge)] ?? '';
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) throw new FixtureError(401, 'session_cookie_required');
        return self::digest($value);
    }
    private function claims(?string $token, string $realm, bool $proof, int $now): array
    {
        if (!is_string($token) || strlen($token) > 16384 || count(explode('.', $token)) !== 3) throw new FixtureError(401, 'token_invalid');
        $oldTime = JWT::$timestamp; $oldLeeway = JWT::$leeway;
        try {
            JWT::$timestamp = $now; JWT::$leeway = 0;
            $key = file_get_contents($this->runtime . '/public.pem');
            if (!is_string($key)) throw new FixtureError(503, 'fixture_configuration');
            $claims = (array)JWT::decode($token, new Key($key, 'RS256'));
        } catch (FixtureError $error) { throw $error; }
        catch (\Throwable) { throw new FixtureError(401, 'token_invalid'); }
        finally { JWT::$timestamp = $oldTime; JWT::$leeway = $oldLeeway; }
        [$spa, $api, $scope] = self::REALMS[$realm];
        if (($claims['iss'] ?? null) !== self::ISSUER || ($claims['tid'] ?? null) !== self::TENANT
            || ($claims['aud'] ?? null) !== ($proof ? $spa : $api)
            || ($claims['azp'] ?? null) !== $spa || ($claims['ver'] ?? null) !== '2.0'
            || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
            || $claims['iat'] > $now + 60 || $claims['exp'] <= $now || $claims['exp'] <= $claims['iat']
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || !is_string($claims['oid'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $claims['oid'])) throw new FixtureError(401, 'token_binding_invalid');
        if (!$proof && (!is_string($claims['scp'] ?? null) || !in_array($scope, explode(' ', $claims['scp']), true))) throw new FixtureError(403, 'scope_required');
        return $claims;
    }
    private function principal(array $claims): string { return self::digest(json_encode([$claims['iss'], $claims['tid'], $claims['oid'], $claims['sub']], JSON_THROW_ON_ERROR)); }
    private function csrf(string $digest): string { return hash_hmac('sha256', 'csrf:' . $digest, $this->secret()); }
    private function lease(array $row, string $digest, int $now): array
    {
        return ['csrf' => $this->csrf($digest), 'idle_expires_at' => $row['last_activity'] + ($row['realm'] === 'client' ? 1800 : 900), 'absolute_expires_at' => $row['absolute_expires'], 'now' => $now];
    }
    private static function origin(string $host): void
    {
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://' . $host) throw new FixtureError(403, 'origin_denied');
    }
    private static function event(array &$state, string $action, string $realm, string $host): void
    {
        $state['events'][] = ['action' => $action, 'realm' => $realm, 'host' => $host];
        $state['events'] = array_slice($state['events'], -100);
    }
    public function dispatch(string $host, string $realm, string $action, string $method, array $body, ?string $bearer): array
    {
        if (!isset(self::REALMS[$realm]) || !in_array($host, ['clinic-a.session.test', 'clinic-b.session.test', 'admin.session.test'], true)) throw new FixtureError(404, 'fixture_scope_unknown');
        if (($host === 'admin.session.test') !== ($realm === 'central')) throw new FixtureError(403, 'realm_host_mismatch');
        if (($action === 'me' ? 'GET' : 'POST') !== $method || !in_array($action, ['challenge','start','me','activity','logout'], true)) throw new FixtureError(404, 'fixture_route_unknown');
        if ($method === 'POST') self::origin($host);
        return $this->state(function (array &$state) use ($host, $realm, $action, $body, $bearer): array {
            $now = time() + $state['offset'];
            if ($action === 'challenge') {
                if (count($state['challenges']) >= 512) throw new FixtureError(429, 'fixture_challenge_limit');
                $nonce = bin2hex(random_bytes(32)); $secret = bin2hex(random_bytes(32));
                $state['challenges'][self::digest($secret)] = ['nonce_hash' => self::digest($nonce), 'host' => $host, 'realm' => $realm, 'created' => $now, 'expires' => $now + 600, 'consumed' => false];
                self::cookie($realm, $secret, true);
                return ['nonce' => $nonce];
            }
            if ($action === 'logout') {
                $hash = self::cookieValue($realm); $row = $state['sessions'][$hash] ?? null;
                if (!$row || $row['host'] !== $host || $row['realm'] !== $realm) throw new FixtureError(401, 'session_binding_invalid');
                if (!hash_equals($this->csrf($hash), $_SERVER['HTTP_X_PROTOTYPE_CSRF'] ?? '')) throw new FixtureError(403, 'csrf_denied');
                $state['sessions'][$hash]['revoked'] = true; self::cookie($realm, ''); self::cookie($realm, '', true);
                self::event($state, 'logout', $realm, $host);
                return ['signed_out' => true];
            }
            $claims = $this->claims($bearer, $realm, false, $now);
            if ($action === 'start') {
                $proof = $this->claims(is_string($body['id_token'] ?? null) ? $body['id_token'] : null, $realm, true, $now);
                $challengeHash = self::cookieValue($realm, true); $challenge = $state['challenges'][$challengeHash] ?? null;
                if (!$challenge || $challenge['consumed'] || $challenge['expires'] <= $now || $challenge['host'] !== $host || $challenge['realm'] !== $realm
                    || !is_string($proof['nonce'] ?? null) || !hash_equals($challenge['nonce_hash'], self::digest($proof['nonce']))
                    || ($proof['oid'] ?? null) !== $claims['oid'] || ($proof['tid'] ?? null) !== $claims['tid']
                    || !is_int($proof['auth_time'] ?? null) || $proof['auth_time'] < $now - 600 || $proof['auth_time'] > $now + 60 || $proof['auth_time'] < $challenge['created'] - 60) throw new FixtureError(401, 'fresh_proof_required');
                if (count($state['sessions']) >= 512) throw new FixtureError(429, 'fixture_session_limit');
                $old = $_COOKIE[self::cookieName($realm)] ?? '';
                if (is_string($old) && isset($state['sessions'][self::digest($old)])) $state['sessions'][self::digest($old)]['revoked'] = true;
                $secret = bin2hex(random_bytes(32)); $hash = self::digest($secret);
                $row = ['host' => $host, 'realm' => $realm, 'principal' => $this->principal($claims), 'authenticated_at' => $proof['auth_time'], 'last_activity' => $now, 'absolute_expires' => $proof['auth_time'] + 28800, 'revoked' => false];
                $state['sessions'][$hash] = $row; $state['challenges'][$challengeHash]['consumed'] = true;
                self::cookie($realm, $secret); self::cookie($realm, '', true); self::event($state, 'start', $realm, $host);
                return $this->lease($row, $hash, $now);
            }
            $hash = self::cookieValue($realm); $row = $state['sessions'][$hash] ?? null;
            if (!$row || $row['host'] !== $host || $row['realm'] !== $realm || !hash_equals($row['principal'], $this->principal($claims))) throw new FixtureError(401, 'session_binding_invalid');
            $idle = $row['realm'] === 'client' ? 1800 : 900;
            if ($row['revoked'] || $row['last_activity'] + $idle <= $now || $row['absolute_expires'] <= $now) throw new FixtureError(401, 'session_ended');
            if ($action === 'activity') {
                if (!hash_equals($this->csrf($hash), $_SERVER['HTTP_X_PROTOTYPE_CSRF'] ?? '')) throw new FixtureError(403, 'csrf_denied');
                $row['last_activity'] = $now; $state['sessions'][$hash] = $row;
            }
            return $this->lease($row, $hash, $now);
        });
    }
}
