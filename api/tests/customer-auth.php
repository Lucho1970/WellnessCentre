<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Wellness\Auth\CustomerAuthenticator;
use Wellness\Config;
use Wellness\Http\ApiException;

$config = new Config('test', false, 'test', [], '', 3306, '', '', '', 'staff', 'staff-api', 'access_as_user', 3600,
    '0a3841c6-b244-410d-821f-bbd9ccd1b5e2', 'copihuewellnessclientsdev', '08542bbb-09cc-4737-979b-ca61c7eec70d', '7a522317-d74f-4ccb-9805-8e4b912c02ab');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$key) throw new RuntimeException('Cannot create test RSA key.');
$details = openssl_pkey_get_details($key);
$jwk = ['kty' => 'RSA', 'kid' => 'test-key', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
$auth = new CustomerAuthenticator($config, static fn(bool $refresh) => ['keys' => [$jwk]]);
$claims = ['iss' => "https://{$config->customerTenantId}.ciamlogin.com/{$config->customerTenantId}/v2.0",
    'tid' => $config->customerTenantId, 'aud' => $config->customerApiClientId, 'azp' => $config->customerSpaClientId,
    'sub' => 'immutable-customer', 'ver' => '2.0', 'iat' => time() - 10, 'nbf' => time() - 10, 'exp' => time() + 600, 'scp' => 'access_as_client'];
$sign = static fn(array $c, string $kid = 'test-key') => JWT::encode($c, $key, 'RS256', $kid);
$checks = 0;
$reject = static function (?string $token, int $status = 401) use ($auth, &$checks) {
    try { $auth->authenticate($token); throw new RuntimeException('Invalid customer token accepted'); }
    catch (ApiException $e) { if ($e->status !== $status) throw $e; $checks++; }
};
$expected = ['authenticated' => true, 'authentication_context' => 'customer', 'onboarding_status' => 'not_linked', 'capabilities' => []];
foreach ([$claims, $claims + ['email' => 'staff@example.test', 'roles' => ['Wellness.SuperAdmin'], 'user_id' => 1]] as $c) {
    if ($auth->authenticate($sign($c)) !== $expected) throw new RuntimeException('Claims granted local record or staff access');
    $checks++;
}
$reject(null); $reject('bad'); $reject(str_repeat('x', 16385));
foreach (['iss' => 'https://login.microsoftonline.com/staff/v2.0', 'tid' => 'staff', 'aud' => $config->customerSpaClientId,
    'azp' => 'another-client', 'ver' => '1.0', 'sub' => '', 'exp' => time() - 120, 'iat' => time() + 300, 'nbf' => time() + 300] as $field => $value) {
    $reject($sign(array_replace($claims, [$field => $value])));
}
foreach (['sub', 'exp', 'iat', 'azp'] as $field) { $copy = $claims; unset($copy[$field]); $reject($sign($copy)); }
$reject($sign(array_replace($claims, ['scp' => 'access_as_user'])), 403);
$reject($sign(array_replace($claims, ['scp' => 'access_as_client_extra'])), 403);
$reject($sign($claims, 'unknown'));
$reject(JWT::encode($claims, str_repeat('a', 64), 'HS256', 'test-key'));
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$reject(JWT::encode($claims, $other, 'RS256', 'test-key'));
$rotated = new CustomerAuthenticator($config, static fn(bool $refresh) => ['keys' => $refresh ? [$jwk] : []]);
if ($rotated->authenticate($sign($claims)) !== $expected) throw new RuntimeException('Key rotation failed');
$checks++;
$unavailable = new CustomerAuthenticator($config, static function () { throw new ApiException(503, 'customer_identity_unavailable', 'Unavailable'); });
try { $unavailable->authenticate($sign($claims)); throw new RuntimeException('Outage accepted'); }
catch (ApiException $e) { if ($e->status !== 503) throw $e; $checks++; }
echo "$checks customer authentication checks passed.\n";

// A cryptographically valid customer token cannot enter any workforce route.
// The invalid database config makes an accidental local-record lookup fail the test.
$staff = new \Wellness\Auth\EntraAuthenticator($config, new \Wellness\Database($config), static fn() => ['keys' => [$jwk]]);
try { $staff->authenticate($sign($claims + ['roles' => ['Wellness.SuperAdmin']])); throw new RuntimeException('Customer gained staff access'); }
catch (ApiException $e) { if ($e->errorCode !== 'invalid_token_claims') throw $e; }
echo "1 customer-to-staff boundary check passed.\n";
