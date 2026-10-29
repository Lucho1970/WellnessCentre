<?php
declare(strict_types=1);

namespace Wellness;

use Wellness\Http\ApiException;
use Wellness\Service\CanadianSmsNumber;

/** Additional opt-in deny gates; never an identity, role, membership or consent grant. */
final readonly class PilotPolicy
{
    private function __construct(
        public bool $enabled,
        private array $identities,
        private array $emails,
        private array $numbers,
    ) {}

    public static function fromEnvironment(): self
    {
        $read = static function (string $key, string $default): string {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value === false) return $default;
            if (!is_string($value) || strlen($value) > 65536) throw new \RuntimeException("Invalid {$key} configuration.");
            return trim($value);
        };
        $flag = $read('PILOT_MODE', 'false');
        if (!in_array($flag, ['true', 'false'], true)) throw new \RuntimeException('PILOT_MODE must be true or false.');
        $identities = [];
        foreach (self::list($read('PILOT_ALLOWED_IDENTITIES', '[]'), 'PILOT_ALLOWED_IDENTITIES') as $identity) {
            if (!is_array($identity) || count($identity) !== 3 || array_diff(array_keys($identity), ['adapter', 'issuer', 'subject'])
                || !in_array($identity['adapter'] ?? null, ['entra-workforce', 'entra-external-staff', 'entra-customer'], true)
                || !is_string($identity['issuer'] ?? null) || strlen($identity['issuer']) > 512
                || !filter_var($identity['issuer'], FILTER_VALIDATE_URL) || !str_starts_with($identity['issuer'], 'https://')
                || array_intersect(array_keys(parse_url($identity['issuer']) ?: []), ['user', 'pass', 'query', 'fragment'])
                || !is_string($identity['subject'] ?? null) || $identity['subject'] === '' || strlen($identity['subject']) > 255
                || trim($identity['subject']) !== $identity['subject'] || preg_match('/[\x00-\x20\x7f]/', $identity['subject'])) {
                throw new \RuntimeException('Invalid PILOT_ALLOWED_IDENTITIES configuration.');
            }
            $identities[self::identityKey($identity['adapter'], $identity['issuer'], $identity['subject'])] = true;
        }
        $emails = [];
        foreach (self::list($read('PILOT_ALLOWED_EMAILS', '[]'), 'PILOT_ALLOWED_EMAILS') as $email) {
            if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || trim($email) !== $email
                || str_contains($email, '*')) throw new \RuntimeException('Invalid PILOT_ALLOWED_EMAILS configuration.');
            $emails[self::emailKey($email)] = true;
        }
        $numbers = [];
        foreach (self::list($read('PILOT_ALLOWED_SMS', '[]'), 'PILOT_ALLOWED_SMS') as $number) {
            if (!is_string($number) || !CanadianSmsNumber::isAllowed($number)) throw new \RuntimeException('Invalid PILOT_ALLOWED_SMS configuration.');
            $numbers[$number] = true;
        }
        return new self($flag === 'true', $identities, $emails, $numbers);
    }

    private static function list(string $json, string $key): array
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("Invalid {$key} configuration.");
        }
        // Decode {} separately: associative decoding would otherwise confuse it with [].
        if (!str_starts_with($json, '[') || !is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new \RuntimeException("Invalid {$key} configuration.");
        }
        return $value;
    }

    private static function identityKey(string $adapter, string $issuer, string $subject): string
    {
        return json_encode([$adapter, $issuer, $subject], JSON_THROW_ON_ERROR);
    }

    private static function emailKey(string $email): string
    {
        $at = strrpos($email, '@');
        // Domains are case-insensitive; do not assume local-part aliases or case equivalence.
        return $at === false ? $email : substr($email, 0, $at + 1).strtolower(substr($email, $at + 1));
    }

    /** Counts only: safe for the operator preflight, never an allowlist disclosure. */
    public function summary(): array
    {
        return ['enabled' => $this->enabled, 'identities' => count($this->identities), 'emails' => count($this->emails), 'sms' => count($this->numbers)];
    }

    public function assertIdentity(string $adapter, string $issuer, string $subject): void
    {
        if ($this->enabled && !isset($this->identities[self::identityKey($adapter, $issuer, $subject)])) {
            throw new ApiException(403, 'pilot_access_denied', 'This pilot is limited to approved test accounts.');
        }
    }

    public function assertRecipient(string $channel, string $recipient): void
    {
        if (!$this->enabled) return;
        $allowed = match ($channel) {
            'email' => isset($this->emails[self::emailKey($recipient)]),
            'sms' => isset($this->numbers[$recipient]),
            default => false,
        };
        if (!$allowed) throw new PilotDeliveryBlocked();
    }

    public function assertMail(string $recipient, string $sender, ?string $replyTo, ?string $calendar): void
    {
        if (!$this->enabled) return;
        $this->assertRecipient('email', $recipient);
        // Replies, including calendar responses, must stay within the approved pilot destinations.
        $this->assertRecipient('email', $sender);
        if ($replyTo !== null) $this->assertRecipient('email', $replyTo);
        if ($calendar === null) return;
        if (strlen($calendar) > 65536) throw new PilotDeliveryBlocked();
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $calendar);
        foreach (preg_split('/\r?\n/', (string)$unfolded) as $line) {
            if (preg_match('/^(?:ATTENDEE|ORGANIZER)(?:;[^:\r\n]*)?:mailto:(.+)$/iD', $line, $match)) {
                $this->assertRecipient('email', rawurldecode($match[1]));
            } elseif (preg_match('/^(?:ATTENDEE|ORGANIZER)(?:[;:]|$)|mailto:/i', $line)) {
                // Reject unsupported/delegated calendar address forms rather than guessing recipients.
                throw new PilotDeliveryBlocked();
            }
        }
    }
}
