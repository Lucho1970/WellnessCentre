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
        $requestPath=parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $limit = self::bodyLimit($requestPath);
        // Bound actual bytes even when Content-Length is absent or inaccurate. The extra
        // byte distinguishes an exact-limit request from a truncated oversized body.
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($raw === false) throw new ApiException(400, 'request_read_failed', 'The request body could not be read.');
        if (strlen($raw) > $limit) throw new ApiException(413, 'request_too_large', 'The request is too large.');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) throw new ApiException(400, 'invalid_json', 'The request body must contain valid JSON.');
        $incomingId = trim((string)($headers['X-Correlation-ID'] ?? $headers['x-correlation-id'] ?? ''));
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $requestPath,
            $_GET,
            array_change_key_case($headers, CASE_LOWER),
            $body,
            preg_match('/^[a-zA-Z0-9-]{8,64}$/', $incomingId) ? $incomingId : self::uuid(),
        );
    }

    private static function bodyLimit(string $requestPath): int
    {
        $customer = str_starts_with($requestPath, '/api/v1/customer/') || str_starts_with($requestPath,'/api/v1/public/') || str_starts_with($requestPath,'/api/v1/travel-enquiries') || $requestPath==='/api/v1/profile/discovery-preferences' || str_starts_with($requestPath,'/api/v1/application/billing');
        $serviceImport = str_starts_with($requestPath, '/api/v1/admin/service-imports');
        $customer = $customer || str_contains($requestPath,'/practitioner-applications');
        $customer = $customer || str_contains($requestPath,'/provider-evidence');
        $customer = $customer || preg_match('#^/api/v1/application/clinics/[0-9]+/communications#',$requestPath)===1;
        // Preserve stricter existing policies. The fallback accommodates the existing
        // 1.1 MB encoded branding validator while bounding every other path/method.
        return $customer ? 65536 : ($serviceImport ? 1048576 : 2097152);
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
