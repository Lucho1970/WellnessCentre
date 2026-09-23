<?php
declare(strict_types=1);

// Called by the Azure Consumption Logic App. No application code or secrets
// live in this public directory; both public hosts point to wellness-api.
ini_set('display_errors', '0');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$apiRoot = dirname(__DIR__, 4) . '/wellness-api';
if (!is_file($apiRoot . '/vendor/autoload.php') || !is_file($apiRoot . '/.env')) {
    http_response_code(503);
    exit;
}

try {
    require $apiRoot . '/vendor/autoload.php';
    \Wellness\Config::loadEnvFile($apiRoot . '/.env');
    $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
    $expectedKey = $env('MAIL_TRIGGER_SECRET');
    $providedKey = (string)($_SERVER['HTTP_X_WELLNESS_CRON_KEY'] ?? '');
    if (strlen($expectedKey) < 32 || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(404);
        exit;
    }
    if (!filter_var($env('MAIL_ENABLED'), FILTER_VALIDATE_BOOL)) {
        http_response_code(503);
        exit;
    }

    // Serialize calls and limit them to one worker pass per five minutes.
    umask(0077);
    $lock = @fopen($apiRoot . '/.mail-trigger.lock', 'c+');
    if ($lock === false) throw new \RuntimeException('Mail trigger lock is unavailable.');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(204);
            exit;
        }
        $lastRun = (int)trim((string)stream_get_contents($lock));
        if ($lastRun > 0 && time() - $lastRun < 300) {
            http_response_code(204);
            exit;
        }

        $portalUrl = $env('CLIENT_PORTAL_URL');
        if (!filter_var($portalUrl, FILTER_VALIDATE_URL) || !str_starts_with($portalUrl, 'https://')) {
            throw new \RuntimeException('Client portal URL is invalid.');
        }
        $mailer = new \Wellness\Service\GraphMailClient(
            $env('MAIL_TENANT_ID'),
            $env('MAIL_CLIENT_ID'),
            $env('MAIL_CLIENT_SECRET'),
            $env('MAIL_FROM_ADDRESS')
        );
        $worker = new \Wellness\Service\NotificationWorker(
            (new \Wellness\Database(\Wellness\Config::fromEnvironment()))->connection(),
            $mailer,
            $portalUrl
        );
        // Bound HTTP duration: each Graph operation has a 25-second timeout.
        $result = $worker->run(3);
        rewind($lock);
        if (!ftruncate($lock, 0) || fwrite($lock, (string)time()) === false || !fflush($lock)) {
            throw new \RuntimeException('Mail trigger lock could not be updated.');
        }
        error_log(sprintf(
            'Wellness notification trigger: sent=%d retry=%d review=%d canceled=%d',
            $result['sent'], $result['retry'], $result['review'], $result['canceled']
        ));
        http_response_code(204);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (\Throwable $e) {
    error_log('Wellness notification trigger failed: ' . get_class($e));
    http_response_code(503);
}
