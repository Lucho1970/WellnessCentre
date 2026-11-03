<?php
declare(strict_types=1);
namespace Wellness\Http;

use Wellness\Service\BrowserSessionPolicy;

/** Pure transport: callers emit the returned header only after a committed operation. */
final class SessionCookie
{
    public static function name(BrowserSessionPolicy $policy, bool $challenge = false): string
    { return '__Host-wc-' . ($challenge ? 'challenge-' : 'session-') . $policy->realm; }

    public static function read(string $header, BrowserSessionPolicy $policy, bool $challenge = false): ?string
    {
        if (strlen($header) > 8192) throw new ApiException(400,'invalid_session_cookie','Invalid session cookie.');
        $found = null; $name = self::name($policy, $challenge);
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if ($pair[0] !== $name) continue;
            if ($found !== null || !preg_match('/^[a-f0-9]{64}$/D', $pair[1] ?? '')) throw new ApiException(400,'invalid_session_cookie','Invalid session cookie.');
            $found = $pair[1];
        }
        return $found;
    }
    public static function header(BrowserSessionPolicy $policy, ?string $secret, bool $challenge = false): string
    {
        if ($secret !== null && !preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new \InvalidArgumentException('Invalid internal cookie secret.');
        return self::name($policy, $challenge) . '=' . ($secret ?? '') . '; Path=/; Secure; HttpOnly; SameSite=Lax'
            . ($secret === null ? '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' : '');
    }
    public static function origin(BrowserSessionPolicy $policy, string $origin, string $host): void
    {
        if ($host !== $policy->host || $origin !== 'https://' . $policy->host) {
            throw new ApiException(403,'session_origin_denied','Use the original portal to continue.');
        }
    }
}
