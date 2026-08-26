<?php
declare(strict_types=1);
namespace Wellness;

use JsonException;
use PDO;
use RuntimeException;

final readonly class StaffPrincipal {
  public function __construct(public string $tenantId, public string $objectId, public string $name, public array $roles, public array $scopes) {}
  public function hasRole(string ...$roles): bool { return count(array_intersect($roles, $this->roles)) > 0; }
}

final class EntraTokenValidator {
  private const CLOCK_SKEW = 120;
  public function validate(string $jwt): StaffPrincipal {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new AuthException('invalid_token', 'The access token is malformed.', 401);
    try { $header = json_decode(self::decode($parts[0]), true, 16, JSON_THROW_ON_ERROR); $claims = json_decode(self::decode($parts[1]), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new AuthException('invalid_token', 'The access token is malformed.', 401); }
    if (($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) throw new AuthException('invalid_token', 'The access token signing algorithm is not allowed.', 401);
    $key = $this->signingKey($header['kid']);
    if (openssl_verify($parts[0] . '.' . $parts[1], self::decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) throw new AuthException('invalid_token', 'The access token signature is invalid.', 401);

    $tenant = Config::get('ENTRA_TENANT_ID'); $audience = Config::get('ENTRA_API_CLIENT_ID'); $now = time();
    $issuer = "https://login.microsoftonline.com/$tenant/v2.0";
    if (($claims['ver'] ?? '') !== '2.0' || !hash_equals($issuer, (string)($claims['iss'] ?? ''))) throw new AuthException('invalid_token', 'The token issuer is not trusted.', 401);
    if (!hash_equals($tenant, (string)($claims['tid'] ?? ''))) throw new AuthException('invalid_token', 'The token tenant is not allowed.', 401);
    if (!hash_equals($audience, (string)($claims['aud'] ?? ''))) throw new AuthException('invalid_token', 'The token audience is invalid.', 401);
    if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::CLOCK_SKEW) throw new AuthException('invalid_token', 'The access token has expired.', 401);
    if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::CLOCK_SKEW)) throw new AuthException('invalid_token', 'The access token is not active.', 401);

    $scopes = preg_split('/\s+/', trim((string)($claims['scp'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!in_array(Config::get('ENTRA_REQUIRED_SCOPE', 'access_as_user'), $scopes, true)) throw new AuthException('insufficient_scope', 'The access_as_user delegated scope is required.', 403);
    $oid = (string)($claims['oid'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $oid)) throw new AuthException('invalid_token', 'The access token does not identify a staff user.', 401);
    $roles = is_array($claims['roles'] ?? null) ? array_values(array_filter($claims['roles'], 'is_string')) : [];
    if ($roles === []) throw new AuthException('forbidden', 'No Wellness Centre staff role is assigned.', 403);

    return new StaffPrincipal($tenant, strtolower($oid), (string)($claims['name'] ?? $claims['preferred_username'] ?? 'Staff user'), $roles, $scopes);
  }

  private function signingKey(string $kid): string {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wellness-entra-jwks-' . hash('sha256', Config::get('ENTRA_TENANT_ID')) . '.json';
    $ttl = max(60, (int)Config::get('ENTRA_JWKS_CACHE_SECONDS', '3600'));
    $jwks = is_file($cache) && filemtime($cache) >= time() - $ttl ? file_get_contents($cache) : false;
    if ($jwks === false) { $jwks = $this->downloadJwks(); @file_put_contents($cache, $jwks, LOCK_EX); }
    $key = $this->findKey($jwks, $kid);
    if ($key === null) { $jwks = $this->downloadJwks(); @file_put_contents($cache, $jwks, LOCK_EX); $key = $this->findKey($jwks, $kid); }
    if ($key === null) throw new AuthException('invalid_token', 'The token signing key is unknown.', 401);
    return self::jwkToPem($key);
  }

  private function downloadJwks(): string {
    $url = 'https://login.microsoftonline.com/' . rawurlencode(Config::get('ENTRA_TENANT_ID')) . '/discovery/v2.0/keys';
    $context = stream_context_create(['http'=>['timeout'=>5, 'ignore_errors'=>true], 'ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true]]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') throw new RuntimeException('Unable to retrieve Microsoft Entra signing keys.');
    return $body;
  }

  private function findKey(string $json, string $kid): ?array {
    try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    foreach ($data['keys'] ?? [] as $key) if (is_array($key) && hash_equals($kid, (string)($key['kid'] ?? '')) && ($key['kty'] ?? '') === 'RSA' && ($key['use'] ?? 'sig') === 'sig') return $key;
    return null;
  }

  private static function jwkToPem(array $key): string {
    $modulus = self::decode((string)($key['n'] ?? '')); $exponent = self::decode((string)($key['e'] ?? ''));
    $rsa = self::sequence(self::integer($modulus) . self::integer($exponent));
    $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $der = self::sequence($algorithm . "\x03" . self::length(strlen($rsa) + 1) . "\x00" . $rsa);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
  }
  private static function integer(string $bytes): string { if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\x00" . $bytes; return "\x02" . self::length(strlen($bytes)) . $bytes; }
  private static function sequence(string $bytes): string { return "\x30" . self::length(strlen($bytes)) . $bytes; }
  private static function length(int $length): string { if ($length < 128) return chr($length); $bytes = ltrim(pack('N', $length), "\x00"); return chr(0x80 | strlen($bytes)) . $bytes; }
  private static function decode(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true); if ($decoded === false) throw new AuthException('invalid_token', 'The access token is malformed.', 401); return $decoded; }
}

final class AuthException extends RuntimeException {
  public function __construct(public readonly string $errorCode, string $message, public readonly int $status) { parent::__construct($message); }
}

final class Authorization {
  public const ALL_STAFF = ['Wellness.SuperAdmin','Wellness.ClinicAdmin','Wellness.Reception','Wellness.Practitioner','Wellness.Accountant'];
  public static function requireRole(StaffPrincipal $principal, string ...$roles): void { if (!$principal->hasRole(...$roles)) throw new AuthException('forbidden', 'Your staff role does not permit this action.', 403); }
  public static function requirePractitionerResource(StaffPrincipal $principal, ?string $resourceOid): void {
    if ($principal->hasRole('Wellness.SuperAdmin','Wellness.ClinicAdmin','Wellness.Reception')) return;
    self::requireRole($principal, 'Wellness.Practitioner');
    if ($resourceOid === null || !hash_equals($principal->objectId, strtolower($resourceOid))) throw new AuthException('forbidden', 'Practitioners may only access their own resources.', 403);
  }
}

final class StaffDirectory {
  private const ROLE_MAP = ['super_admin'=>'Wellness.SuperAdmin','clinic_admin'=>'Wellness.ClinicAdmin','reception'=>'Wellness.Reception','practitioner'=>'Wellness.Practitioner','accountant'=>'Wellness.Accountant'];
  public static function requireRegistered(StaffPrincipal $principal): StaffPrincipal {
    $pdo = new PDO(Config::get('DB_DSN'), Config::get('DB_USER'), Config::get('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $statement = $pdo->prepare("SELECT u.display_name, r.name AS role_name FROM staff_accounts s JOIN users u ON u.id=s.user_id JOIN roles r ON r.id=s.role_id WHERE s.entra_tenant_id=:tid AND s.entra_object_id=:oid AND u.user_type='staff' AND u.status='active' LIMIT 1");
    $statement->execute(['tid'=>$principal->tenantId,'oid'=>$principal->objectId]);
    $staff = $statement->fetch();
    if (!$staff) throw new AuthException('forbidden', 'This Microsoft account is not linked to an active staff record.', 403);
    $role = self::ROLE_MAP[(string)$staff['role_name']] ?? null;
    if ($role === null || !in_array($role, $principal->roles, true)) throw new AuthException('forbidden', 'The assigned Entra role does not match the staff record.', 403);
    return new StaffPrincipal($principal->tenantId, $principal->objectId, (string)$staff['display_name'], [$role], $principal->scopes);
  }
}
