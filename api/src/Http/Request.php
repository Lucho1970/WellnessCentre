<?php
declare(strict_types=1);

namespace Wellness\Http;

final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public array $body,
        public string $correlationId,
    ) {}

    public static function capture(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $raw = file_get_contents('php://input') ?: '';
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) throw new ApiException(400, 'invalid_json', 'The request body must contain valid JSON.');
        $incomingId = trim((string)($headers['X-Correlation-ID'] ?? $headers['x-correlation-id'] ?? ''));
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $_GET,
            array_change_key_case($headers, CASE_LOWER),
            $body,
            preg_match('/^[a-zA-Z0-9-]{8,64}$/', $incomingId) ? $incomingId : self::uuid(),
        );
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $header, $match) ? trim($match[1]) : null;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
