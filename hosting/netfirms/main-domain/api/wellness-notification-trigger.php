<?php
declare(strict_types=1);

// Development-only bridge for Netfirms' URL-based scheduler. Install under
// /public_html/tuff-tar.com/api; the application itself remains private.
ini_set('display_errors', '0');
header('Cache-Control: no-store');

$apiRoot = dirname(__DIR__, 3) . '/wellness-api';
if (!is_file($apiRoot . '/vendor/autoload.php') || !is_file($apiRoot . '/.env')) {
    error_log('Wellness notification bridge: private API files are unavailable.');
    http_response_code(503);
    exit;
}

try {
    require $apiRoot . '/vendor/autoload.php';
    \Wellness\Config::loadEnvFile($apiRoot . '/.env');
    $env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
    $enabled = filter_var($env('MAIL_ENABLED'), FILTER_VALIDATE_BOOL);
    $allowedRaw = $env('MAIL_CRON_ALLOWED_IPS');
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

    // Initial probe: leave MAIL_ENABLED=false and the allowlist unset. The
    // scheduler's source address appears in the private PHP error log.
    if ($allowedRaw === '') {
        if (!$enabled && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            error_log('Wellness notification bridge probe method=' . $method . ' source IP: ' . $remoteIp);
            // A singleton row is easier to inspect in phpMyAdmin than hosting
            // logs. The private lock caps unauthenticated probe writes to one
            // every five seconds; it is never used once mail is enabled.
            umask(0077);
            $probeLock = @fopen($apiRoot . '/.mail-probe.lock', 'c+');
            if ($probeLock === false) throw new \RuntimeException('Probe lock is unavailable.');
            try {
                if (flock($probeLock, LOCK_EX | LOCK_NB)) {
                    $lastProbe = (int)trim((string)stream_get_contents($probeLock));
                    if ($lastProbe === 0 || time() - $lastProbe >= 5) {
                        $pdo = (new \Wellness\Database(\Wellness\Config::fromEnvironment()))->connection();
                        $statement = $pdo->prepare(
                            'INSERT INTO notification_scheduler_probe '
                            . '(id,last_seen_at,last_source_ip,last_method,hit_count) '
                            . 'VALUES (1,UTC_TIMESTAMP(),:source_ip,:method,1) '
                            . 'ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP(),'
                            . 'last_source_ip=VALUES(last_source_ip),last_method=VALUES(last_method),'
                            . 'hit_count=hit_count+1'
                        );
                        $statement->execute(['source_ip' => $remoteIp, 'method' => substr($method, 0, 12)]);
                        rewind($probeLock);
                        if (!ftruncate($probeLock, 0) || fwrite($probeLock, (string)time()) === false || !fflush($probeLock)) {
                            throw new \RuntimeException('Probe lock could not be updated.');
                        }
                    }
                }
            } finally {
                flock($probeLock, LOCK_UN);
                fclose($probeLock);
            }
        }
        http_response_code(503);
        exit;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        exit;
    }

    $allowedIps = array_map('trim', explode(',', $allowedRaw));
    foreach ($allowedIps as $allowedIp) {
        if (!filter_var($allowedIp, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('Invalid cron IP allowlist.');
        }
    }
    if (!filter_var($remoteIp, FILTER_VALIDATE_IP) || !in_array($remoteIp, $allowedIps, true)) {
        http_response_code(404);
        exit;
    }
    if (!$enabled) {
        http_response_code(503);
        exit;
    }

    // The lock is in the private application directory. A failed lock or an
    // unwritable directory fails closed; the request never sends mail.
    umask(0077);
    $lock = @fopen($apiRoot . '/.mail-trigger.lock', 'c+');
    if ($lock === false) throw new \RuntimeException('Cron lock is unavailable.');
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
        $result = $worker->run(1);
        rewind($lock);
        if (!ftruncate($lock, 0) || fwrite($lock, (string)time()) === false || !fflush($lock)) {
            throw new \RuntimeException('Cron lock could not be updated.');
        }
        error_log(sprintf(
            'Wellness notification bridge: sent=%d retry=%d review=%d canceled=%d',
            $result['sent'], $result['retry'], $result['review'], $result['canceled']
        ));
        http_response_code(204);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (\Throwable $e) {
    error_log('Wellness notification bridge failed: ' . get_class($e));
    http_response_code(503);
}
