<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Firebase\JWT\JWT;
use Wellness\Http\ApiException;
use Wellness\Service\BrowserSessionPolicy;

/** Only generated signing keys and example.test identities; no environment file. */
final class SessionTestTokens
{
    public readonly OpenSSLAsymmetricKey $key;
    public array $jwks;
    public int $now;
    public function __construct()
    {
        $this->now = time();
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if (!$key) throw new RuntimeException('Cannot create synthetic signing key.');
        $this->key = $key; $details = openssl_pkey_get_details($key);
        $this->jwks = ['keys'=>[['kty'=>'RSA','kid'=>'session-test','alg'=>'RS256','n'=>JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>JWT::urlsafeB64Encode($details['rsa']['e'])]]];
    }
    public function policy(string $realm = 'practitioner', string $host = 'clinic.example.test', ?int $clinic = 1, int $version = 1): BrowserSessionPolicy
    {
        return new BrowserSessionPolicy($realm,$host,$realm === 'central' ? null : $clinic,
            '11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222','33333333-3333-4333-8333-333333333333',
            match($realm) {'client'=>'access_as_client','practitioner'=>'access_as_staff',default=>'access_as_user'},$version);
    }
    public function claims(BrowserSessionPolicy $p, string $nonce = '', bool $proof = false): array
    {
        $c = ['iss'=>$p->issuer(),'tid'=>$p->tenant,'aud'=>$proof ? $p->spaAudience : $p->apiAudience,'ver'=>'2.0',
            'sub'=>$proof ? 'spa-pairwise-test' : 'api-pairwise-test','oid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'iat'=>$this->now-5,'nbf'=>$this->now-5,'exp'=>$this->now+86400];
        return $proof ? $c+['nonce'=>$nonce,'auth_time'=>$this->now] : $c+['azp'=>$p->spaAudience,'scp'=>$p->scope];
    }
    public function sign(array $claims): string { return JWT::encode($claims,$this->key,'RS256','session-test'); }
}
final class SessionTest
{
    public static int $checks = 0;
    public static function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); self::$checks++; }
    public static function deny(callable $operation, int $status, string $why): void
    {
        try { $operation(); } catch (ApiException $error) { self::check($error->status === $status,$why . ': unexpected status ' . $error->status); return; }
        throw new RuntimeException($why . ': unexpectedly accepted');
    }
}
