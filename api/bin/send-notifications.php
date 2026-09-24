<?php
declare(strict_types=1);

use Wellness\Config;
use Wellness\Database;
use Wellness\Service\GraphMailClient;
use Wellness\Service\NotificationWorker;
use Wellness\Service\VoipMsSmsClient;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::loadEnvFile(dirname(__DIR__) . '/.env');

if (!filter_var($_ENV['MAIL_ENABLED'] ?? getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
    fwrite(STDERR, "Mail delivery is disabled (MAIL_ENABLED=false).\n");
    exit(2);
}

try {
    $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
    $mailer = new GraphMailClient($env('MAIL_TENANT_ID'), $env('MAIL_CLIENT_ID'), $env('MAIL_CLIENT_SECRET'), $env('MAIL_FROM_ADDRESS'));
    $portalUrl = $env('CLIENT_PORTAL_URL');
    if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || !str_starts_with($portalUrl, 'https://')) {
        throw new RuntimeException('CLIENT_PORTAL_URL must be an HTTPS URL.');
    }
    $options = getopt('', ['limit::']);
    $limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT) : 20;
    if ($limit === false || $limit < 1 || $limit > 100) throw new RuntimeException('--limit must be between 1 and 100.');
    $sms = filter_var($env('SMS_ENABLED'), FILTER_VALIDATE_BOOL)
        ? new VoipMsSmsClient($env('VOIPMS_API_USERNAME'), $env('VOIPMS_API_PASSWORD'), $env('VOIPMS_FROM_DID'))
        : null;
    $worker = new NotificationWorker((new Database(Config::fromEnvironment()))->connection(), $mailer, $portalUrl, $sms);
    $result = $worker->run($limit);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Notification worker failed: ' . $e->getMessage() . "\n");
    exit(1);
}
