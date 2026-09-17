<?php
declare(strict_types=1);

namespace Wellness\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use PDO;
use Throwable;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

final class EntraAuthenticator
{
    public function __construct(private readonly Config $config, private readonly Database $database, private readonly ?\Closure $keyLoader = null) {}

    public function authenticate(?string $token): AuthContext
    {
        if (!$token) throw new ApiException(401, 'unauthorized', 'A bearer access token is required.');
        if ($this->config->entraTenantId === '' || $this->config->entraApiClientId === '') {
            throw new ApiException(503, 'identity_not_configured', 'Microsoft Entra authentication is not configured.');
        }

        try {
            JWT::$leeway = 60;
            $claims = (array)JWT::decode($token, JWK::parseKeySet($this->jwks(), 'RS256'));
        } catch (ApiException $error) {
            throw $error;
        } catch (ExpiredException) {
            throw new ApiException(401, 'token_expired', 'The access token has expired.');
        } catch (BeforeValidException) {
            throw new ApiException(401, 'token_not_yet_valid', 'The access token is not yet valid.');
        } catch (SignatureInvalidException) {
            throw new ApiException(401, 'invalid_token_signature', 'The access token signature is invalid.');
        } catch (Throwable $error) {
            error_log('Entra token validation failed: ' . $error::class . ': ' . $error->getMessage());
            throw new ApiException(401, 'invalid_token', 'The access token is invalid or expired.');
        }

        $tenant = (string)($claims['tid'] ?? '');
        $audience = $claims['aud'] ?? '';
        $issuer = rtrim((string)($claims['iss'] ?? ''), '/');
        $expectedIssuer = "https://login.microsoftonline.com/{$this->config->entraTenantId}/v2.0";
        $validAudiences = [$this->config->entraApiClientId, 'api://' . $this->config->entraApiClientId];
        $scopes = preg_split('/\s+/', trim((string)($claims['scp'] ?? ''))) ?: [];

        if ($tenant !== $this->config->entraTenantId || !in_array($audience, $validAudiences, true) || $issuer !== $expectedIssuer) {
            throw new ApiException(401, 'invalid_token_claims', 'The token tenant, issuer, or audience is invalid.');
        }
        if (!in_array($this->config->entraRequiredScope, $scopes, true)) {
            throw new ApiException(403, 'missing_scope', 'The token does not contain the required API scope.');
        }

        $objectId = (string)($claims['oid'] ?? $claims['sub'] ?? '');
        if ($objectId === '') throw new ApiException(401, 'invalid_token_claims', 'The token has no stable user identifier.');
        $roleMap=['Wellness.SuperAdmin'=>'super_admin','Wellness.ClinicAdmin'=>'clinic_admin','Wellness.Reception'=>'reception','Wellness.Practitioner'=>'practitioner','Wellness.Accountant'=>'accountant'];
        $directoryRoles=array_values(array_filter(array_map(static fn($role)=>$roleMap[(string)$role]??null,(array)($claims['roles']??[]))));
        return $this->loadUser($tenant, $objectId, $directoryRoles);
    }

    private function loadUser(string $tenantId, string $objectId, array $directoryRoles): AuthContext
    {
        $sql = "SELECT u.id,u.clinic_id,u.email,u.display_name,u.user_type,u.status,
                       GROUP_CONCAT(DISTINCT r.code ORDER BY r.code) AS roles
                  FROM identity_links i JOIN users u ON u.id=i.user_id
             LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
                 WHERE i.provider='microsoft' AND i.tenant_id=:tenant AND i.provider_subject=:subject
              GROUP BY u.id,u.clinic_id,u.email,u.display_name,u.user_type,u.status";
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute(['tenant' => $tenantId, 'subject' => $objectId]);
        $user = $statement->fetch();
        if (!$user) throw new ApiException(403, 'staff_not_provisioned', 'This Microsoft account has not been provisioned in the Wellness Centre.');
        if ($user['status'] !== 'active') throw new ApiException(403, 'account_inactive', 'This account is not active.');
        $databaseRoles=$user['roles'] ? explode(',', $user['roles']) : [];$effectiveRoles=array_values(array_intersect($databaseRoles,$directoryRoles));
        if($effectiveRoles===[])throw new ApiException(403,'role_assignment_mismatch','No application role is assigned in both Microsoft Entra and the Wellness Centre.');
        return new AuthContext((int)$user['id'], (int)$user['clinic_id'], $objectId, $user['email'], $user['display_name'], $user['user_type'], $effectiveRoles);
    }

    private function jwks(): array
    {
        if ($this->keyLoader) return ($this->keyLoader)();
        $cacheDir = dirname(__DIR__, 2) . '/var/cache';
        $cacheFile = $cacheDir . '/entra-jwks.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - $this->config->entraJwksCacheSeconds) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) return $cached;
        }
        $url = "https://login.microsoftonline.com/{$this->config->entraTenantId}/discovery/v2.0/keys";
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        $result = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $jwks = is_string($result) ? json_decode($result, true) : null;
        if ($status !== 200 || !is_array($jwks) || !isset($jwks['keys'])) throw new ApiException(503, 'identity_unavailable', 'Unable to retrieve identity signing keys.');
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0700, true);
        file_put_contents($cacheFile, json_encode($jwks, JSON_THROW_ON_ERROR), LOCK_EX);
        return $jwks;
    }
}
