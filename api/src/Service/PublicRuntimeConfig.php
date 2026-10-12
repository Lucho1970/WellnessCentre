<?php
declare(strict_types=1);

namespace Wellness\Service;

use RuntimeException;

final class PublicRuntimeConfig
{
    public static function fromEnvironment(?string $websiteUrl=null): array
    {
        $url = $websiteUrl ?? trim((string)($_ENV['PUBLIC_WEBSITE_URL'] ?? getenv('PUBLIC_WEBSITE_URL') ?: ''));
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('PUBLIC_WEBSITE_URL must be an HTTP(S) base URL.');
        }
        // Explicit allowlist: no other environment values reach the browser.
        return ['publicWebsiteUrl' => rtrim($url, '/') . '/'];
    }

    public static function respond(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        try {
            $websiteUrl=null;
            if (filter_var($_ENV['CLINIC_MANAGEMENT_ENABLED']??getenv('CLINIC_MANAGEMENT_ENABLED')?:'false',FILTER_VALIDATE_BOOL)) {
                $config=\Wellness\Config::fromEnvironment();$database=new \Wellness\Database($config);
                $context=\Wellness\ClinicContext::resolve($config,$database,\Wellness\Http\Request::capture());
                $query=$database->connection()->prepare('SELECT website_url FROM clinics WHERE id=?');$query->execute([$context->clinicId]);
                $websiteUrl=$query->fetchColumn()?:'https://'.$context->host.'/';
            }
            echo json_encode(self::fromEnvironment($websiteUrl), JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            error_log('Public runtime configuration: ' . $error->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Website configuration is unavailable.']);
        }
    }
}
