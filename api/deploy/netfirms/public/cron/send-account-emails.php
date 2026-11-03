<?php
declare(strict_types=1);

// Optional, authenticated HTTPS trigger for hosts without private PHP cron.
// The actual worker, environment and lock remain outside the public root.
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
    $expected = $env('ACCOUNT_EMAIL_TRIGGER_SECRET');
    $provided = (string)($_SERVER['HTTP_X_WELLNESS_ACCOUNT_EMAIL_KEY'] ?? '');
    if (strlen($expected) < 32 || !hash_equals($expected, $provided)) {
        http_response_code(404);
        exit;
    }
    \Wellness\Service\CommitSafeEmail::requireConfigured();

    umask(0077);
    $lock = @fopen($apiRoot . '/.account-email-trigger.lock', 'c+');
    if ($lock === false) throw new \RuntimeException('Account email trigger lock is unavailable.');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(204);
            exit;
        }
        $lastRun = (int)trim((string)stream_get_contents($lock));
        if ($lastRun > 0 && time() - $lastRun < 60) {
            http_response_code(204);
            exit;
        }

        $mailer = new \Wellness\Service\GraphMailClient(
            $env('MAIL_TENANT_ID'),
            $env('MAIL_CLIENT_ID'),
            $env('MAIL_CLIENT_SECRET'),
            $env('MAIL_FROM_ADDRESS')
        );
        $pdo = (new \Wellness\Database(\Wellness\Config::fromEnvironment()))->connection();
        // One provider attempt per request bounds HTTP duration and duplicate exposure.
        $result = (new \Wellness\Service\CommitSafeEmail($pdo))->run($mailer, 1);
        rewind($lock);
        if (!ftruncate($lock, 0) || fwrite($lock, (string)time()) === false || !fflush($lock)) {
            throw new \RuntimeException('Account email trigger lock could not be updated.');
        }
        error_log(sprintf(
            'Wellness account email trigger: accepted=%d failed=%d canceled=%d review=%d',
            $result['accepted'], $result['failed'], $result['canceled'], $result['needs_review']
        ));
        http_response_code(204);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (\Throwable $error) {
    // A failed/uncertain provider effect remains in the durable outbox for review.
    error_log('Wellness account email trigger failed: ' . $error::class);
    http_response_code(503);
}
