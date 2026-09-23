<?php
declare(strict_types=1);

/** Temporary Netfirms entry point. Copy to /public_html/wellness/api/booking-race-web.php only for the test. */
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");

$secure = ($_SERVER['HTTPS'] ?? '') === 'on' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!$secure) {
    http_response_code(404);
    exit;
}

$apiRoot = dirname(__DIR__, 3) . '/wellness-api';
if (!is_file($apiRoot . '/vendor/autoload.php') || !is_file($apiRoot . '/.env')
    || !is_file($apiRoot . '/tests/integration/booking-race.php')
    || !is_file($apiRoot . '/tests/integration/booking-race-worker.php')) {
    http_response_code(404);
    exit;
}
require $apiRoot . '/vendor/autoload.php';
\Wellness\Config::loadEnvFile($apiRoot . '/.env');
$env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
$secret = $env('BOOKING_RACE_WEB_SECRET');
if ($env('BOOKING_RACE_WEB_ENABLED') !== 'true' || strlen($secret) < 32
    || $env('BOOKING_RACE_WEB_DATABASE_ACK') === ''
    || !hash_equals($env('DB_NAME'), $env('BOOKING_RACE_WEB_DATABASE_ACK'))) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking race test</title>';
    echo '<main style="max-width:36rem;margin:3rem auto;font:1rem system-ui;padding:1rem">';
    echo '<h1>Development booking race test</h1><p>This creates synthetic clinic and appointment records in the current development database. Back it up first and run during a quiet testing window.</p>';
    echo '<form method="post"><label>One-time test secret <input type="password" name="secret" required autocomplete="off"></label> ';
    echo '<button type="submit">Run test</button></form></main>';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$provided = (string)($_POST['secret'] ?? '');
if (!hash_equals($secret, $provided)) {
    http_response_code(404);
    exit;
}
if (!function_exists('proc_open')) {
    http_response_code(503);
    exit('This host disables proc_open; the hosted race test cannot run here.');
}

umask(0077);
$lock = @fopen($apiRoot . '/.booking-race-test.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    exit('Another booking race test is running.');
}
try {
    $config = \Wellness\Config::fromEnvironment();
    foreach ([
        'BOOKING_TEST_DB_HOST' => $config->dbHost,
        'BOOKING_TEST_DB_PORT' => (string)$config->dbPort,
        'BOOKING_TEST_DB_NAME' => $config->dbName,
        'BOOKING_TEST_DB_USER' => $config->dbUser,
        'BOOKING_TEST_DB_PASSWORD' => $config->dbPassword,
        'BOOKING_TEST_CONFIRM' => $config->dbName,
        'BOOKING_TEST_MODE' => 'isolated-clinic',
        'BOOKING_TEST_EXISTING_ACK' => 'synthetic-clinic-only',
    ] as $key => $value) {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
    if (function_exists('set_time_limit')) @set_time_limit(120);
    ob_start();
    require $apiRoot . '/tests/integration/booking-race.php';
    $result = (string)ob_get_clean();
    echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking race result</title>';
    echo '<main style="max-width:45rem;margin:3rem auto;font:1rem system-ui;padding:1rem"><h1>Test passed</h1><pre>';
    echo htmlspecialchars($result, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '</pre><p>Disable the test setting and remove this public PHP file now.</p></main>';
} catch (\Throwable $error) {
    if (ob_get_level() > 0) ob_end_clean();
    $reference = bin2hex(random_bytes(8));
    error_log('Booking race web test failed [' . $reference . ']: ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code(503);
    echo 'Booking race test failed. Check the PHP error log. Reference: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
