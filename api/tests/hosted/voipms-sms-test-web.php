<?php
declare(strict_types=1);

/**
 * TEMPORARY one-attempt hosted SMS diagnostic.
 * Copy manually to /public_html/wellness/api/sms-test.php, then REMOVE it.
 * It is deliberately excluded from normal deployment archives.
 */

ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
header('Content-Type: text/html; charset=utf-8');

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $title, string $body) use ($escape): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $escape($title) . '</title><style>body{font:16px system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem;line-height:1.5}input,button{font:inherit;padding:.5rem}input{width:100%;box-sizing:border-box}button{margin-top:1rem}</style>';
    echo '<main><h1>' . $escape($title) . '</h1>' . $body . '</main></html>';
};

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    exit;
}
$forwardedProtocol = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$https = (($_SERVER['HTTPS'] ?? '') === 'on') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443' || $forwardedProtocol === 'https';
if (!$https) {
    http_response_code(403);
    exit;
}

$privateRoot = dirname(__DIR__, 3) . '/wellness-api';
if (!is_file($privateRoot . '/vendor/autoload.php') || !is_file($privateRoot . '/.env')) {
    http_response_code(404);
    exit;
}
require $privateRoot . '/vendor/autoload.php';
\Wellness\Config::loadEnvFile($privateRoot . '/.env');
$env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));

if (!filter_var($env('SMS_TEST_ENABLED'), FILTER_VALIDATE_BOOL)) {
    http_response_code(404);
    exit;
}
$secret = $env('SMS_TEST_SECRET');
$to = $env('SMS_TEST_TO');
if (strlen($secret) < 32 || !\Wellness\Service\CanadianSmsNumber::isAllowed($to) || $env('VOIPMS_FROM_DID') !== '2892975234') {
    http_response_code(503);
    $page('SMS test is not configured', '<p>Set the temporary test secret and your own Canadian receiving mobile number in the private API environment file.</p>');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $lastFour = $escape(substr($to, -4));
    $page('One-time VoIP.ms SMS test', '<p>This will send exactly one non-clinical test SMS from 289-297-5234 to the privately configured number ending in ' . $lastFour . '. It does not enable appointment texts or establish A2P approval.</p>'
        . '<form method="post"><label for="secret">Temporary test secret</label><input id="secret" name="secret" type="password" autocomplete="off" required>'
        . '<p><label><input type="checkbox" name="ack" value="yes" required style="width:auto"> I control the receiving number and want to send one test SMS.</label></p>'
        . '<button type="submit">Send one test SMS</button></form>');
    exit;
}

$provided = is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '';
if (!hash_equals($secret, $provided)) {
    http_response_code(404);
    exit;
}
if (($_POST['ack'] ?? '') !== 'yes') {
    http_response_code(422);
    $page('Confirmation required', '<p>Confirm that you control the receiving number before sending.</p>');
    exit;
}

try {
    $client = new \Wellness\Service\VoipMsSmsClient($env('VOIPMS_API_USERNAME'), $env('VOIPMS_API_PASSWORD'), $env('VOIPMS_FROM_DID'));
} catch (\Throwable $e) {
    http_response_code(503);
    $page('SMS API is not configured', '<p>Check the VoIP.ms API settings in the private API environment file. No SMS was attempted.</p>');
    exit;
}

umask(0077);
$lock = @fopen($privateRoot . '/.sms-test-once', 'c+');
if ($lock === false || !flock($lock, LOCK_EX)) {
    http_response_code(503);
    $page('SMS test unavailable', '<p>The private one-time test lock could not be opened. No SMS was attempted.</p>');
    exit;
}
try {
    rewind($lock);
    if (trim((string)stream_get_contents($lock)) !== '') {
        http_response_code(409);
        $page('Test already attempted', '<p>This test is limited to one API attempt to prevent duplicate texts. Check your phone and the VoIP.ms message history before deciding whether another attempt is necessary.</p>');
        exit;
    }
    // Consume the attempt *before* calling the provider: a timeout could hide a
    // successful send, so refreshing must never make a second API call.
    rewind($lock);
    $marker = gmdate('c') . "\n";
    if (fwrite($lock, $marker) !== strlen($marker) || !fflush($lock)) {
        http_response_code(503);
        $page('SMS test unavailable', '<p>The one-time marker could not be saved. No SMS was attempted.</p>');
        exit;
    }
    $providerId = $client->send($to, 'Wellness Centre SMS API connectivity test. No appointment information is included.');
    $body = '<p>VoIP.ms accepted the test request. Check the receiving phone to confirm delivery.</p>';
    if ($providerId !== null) $body .= '<p>Provider message ID: ' . $escape($providerId) . '</p>';
    $page('SMS test accepted', $body . '<p>Remove this public test file when finished.</p>');
} catch (\Wellness\Service\SmsSendException $e) {
    http_response_code(502);
    $page('SMS test not confirmed', '<p>' . $escape($e->getMessage()) . '</p><p>The outcome may be uncertain. Check your phone and VoIP.ms history before attempting another test.</p>');
} catch (\Throwable $e) {
    error_log('Wellness one-time SMS test failed: ' . get_class($e));
    http_response_code(502);
    $page('SMS test not confirmed', '<p>An unexpected error occurred. No automatic retry will occur.</p>');
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
