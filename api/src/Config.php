<?php
declare(strict_types=1);

namespace Wellness;

use RuntimeException;

final readonly class Config
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $appKey,
        public array $allowedOrigins,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $entraTenantId,
        public string $entraApiClientId,
        public string $entraRequiredScope,
        public int $entraJwksCacheSeconds,
        public string $customerTenantId = '',
        public string $customerSubdomain = '',
        public string $customerApiClientId = '',
        public string $customerSpaClientId = '',
    ) {}

    public static function fromEnvironment(): self
    {
        $value = static fn(string $key, string $default = ''): string => (string)($_ENV[$key] ?? getenv($key) ?: $default);
        $required = static function (string $key) use ($value): string {
            $result = trim($value($key));
            if ($result === '') throw new RuntimeException("Missing required environment variable: {$key}");
            return $result;
        };

        return new self(
            $value('APP_ENV', 'production'),
            filter_var($value('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            $required('APP_KEY'),
            array_values(array_filter(array_map('trim', explode(',', $value('CORS_ALLOWED_ORIGINS'))))),
            $required('DB_HOST'),
            (int)$value('DB_PORT', '3306'),
            $required('DB_NAME'),
            $required('DB_USER'),
            $value('DB_PASSWORD'),
            $value('ENTRA_TENANT_ID'),
            $value('ENTRA_API_CLIENT_ID'),
            $value('ENTRA_REQUIRED_SCOPE', 'access_as_user'),
            max(300, (int)$value('ENTRA_JWKS_CACHE_SECONDS', '3600')),
            $value('CUSTOMER_ENTRA_TENANT_ID'),
            $value('CUSTOMER_ENTRA_SUBDOMAIN'),
            $value('CUSTOMER_ENTRA_API_CLIENT_ID'),
            $value('CUSTOMER_ENTRA_SPA_CLIENT_ID'),
        );
    }

    public static function loadEnvFile(string $path): void
    {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $raw] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || array_key_exists($key, $_ENV) || getenv($key) !== false) continue;
            $value = trim($raw, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
