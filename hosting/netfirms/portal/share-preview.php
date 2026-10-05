<?php
declare(strict_types=1);

use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\CatalogService;

require __DIR__ . '/share-preview-lib.php';

$html = @file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(503);
    exit('The portal is temporarily unavailable.');
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$serviceRoute = preg_match('~^/services/([a-z0-9][a-z0-9-]*)/book/?$~', (string) $path, $serviceMatch) === 1;
$practitionerRoute = preg_match('~^/practitioners/([a-z0-9][a-z0-9-]*)/?$~', (string) $path, $practitionerMatch) === 1;
if (!$serviceRoute && !$practitionerRoute) {
    http_response_code(404);
} else {
    $root = dirname(__DIR__, 2) . '/wellness-api';
    try {
        if (!is_file($root . '/vendor/autoload.php')) throw new RuntimeException('Private application is missing.');
        require $root . '/vendor/autoload.php';
        Config::loadEnvFile($root . '/.env');
        $config = Config::fromEnvironment();
        $originParts = parse_url($config->clientPortalUrl);
        $origin = is_array($originParts) && ($originParts['scheme'] ?? '') === 'https'
            ? 'https://' . ($originParts['host'] ?? '')
            : '';
        if ($origin === 'https://' || $origin === '') throw new RuntimeException('Client portal URL is not configured.');
        $catalog = new CatalogService(new Database($config));
        $site = $catalog->siteConfig();
        if ($serviceRoute) {
            $service = $catalog->publicService($serviceMatch[1]);
            $duration = filter_input(INPUT_GET, 'duration', FILTER_VALIDATE_INT);
            $minutes = is_int($duration) && $duration > 0 ? $duration : null;
            $html = wellnessShareMeta($html, $service, $site, $origin, $minutes);
        } else {
            $person = $catalog->publicPractitioner($practitionerMatch[1]);
            $html = wellnessPractitionerMeta($html, $person, $site, $origin);
        }
    } catch (ApiException $exception) {
        http_response_code($exception->status === 404 ? 404 : 503);
    } catch (Throwable $exception) {
        error_log('Wellness share preview failed: ' . $exception->getMessage());
        http_response_code(503);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $html;
