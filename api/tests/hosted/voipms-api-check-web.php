<?php
declare(strict_types=1);

/**
 * TEMPORARY read-only VoIP.ms API diagnostic. Copy to the public wellness/api
 * directory manually and remove it immediately after checking the result.
 * This does not call sendSMS or alter the one-time SMS test marker.
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
$secret = $env('SMS_TEST_SECRET');
if (!filter_var($env('SMS_TEST_ENABLED'), FILTER_VALIDATE_BOOL) || strlen($secret) < 32) {
    http_response_code(404);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $page('Read-only VoIP.ms API check', '<p>This checks the Netfirms outbound IP and VoIP.ms API authentication. It does not send a text or change the one-time SMS test marker.</p>'
        . '<form method="post"><label for="secret">Temporary test secret</label><input id="secret" name="secret" type="password" autocomplete="off" required>'
        . '<button type="submit">Check API access</button></form>');
    exit;
}
$provided = is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '';
if (!hash_equals($secret, $provided)) {
    http_response_code(404);
    exit;
}
$username = $env('VOIPMS_API_USERNAME');
$password = $env('VOIPMS_API_PASSWORD');
if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '') {
    $page('API check result', '<p>API username or password is missing from the private environment file. No SMS was sent.</p>');
    exit;
}
if (!function_exists('curl_init')) {
    $page('API check result', '<p>The PHP cURL extension is unavailable on this server. No SMS was sent.</p>');
    exit;
}

/** @return array{string,?array} */
$check = static function (string $method) use ($username, $password): array {
    $handle = curl_init('https://voip.ms/api/v1/rest.php');
    if ($handle === false) return ['Could not start HTTPS request', null];
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'api_username' => $username,
            'api_password' => $password,
            'method' => $method,
            'content_type' => 'json',
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($handle);
    $curlNumber = curl_errno($handle);
    $http = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($response === false) return ['HTTPS connection failed (cURL ' . $curlNumber . ')', null];
    if ($http !== 200) return ['HTTP ' . $http, null];
    $data = json_decode((string)$response, true);
    if (!is_array($data)) return ['Invalid JSON response', null];
    $status = $data['status'] ?? null;
    $safeStatus = is_string($status) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $status) ? $status : 'unrecognized response';
    return [$safeStatus, $data];
};

[$ipStatus, $ipResponse] = $check('getIP');
[$authStatus] = $check('getDIDsInfo');
$ip = $ipResponse['ip'] ?? null;
$safeIp = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'not returned';
error_log('Wellness VoIP.ms read-only API check: getIP=' . $ipStatus . ' getDIDsInfo=' . $authStatus);
$page('API check result', '<p>Netfirms outbound IP: ' . $escape($safeIp) . '</p>'
    . '<p>IP lookup: ' . $escape($ipStatus) . '</p>'
    . '<p>Authenticated API access: ' . $escape($authStatus) . '</p>'
    . '<p>No SMS was sent. Remove this temporary public file after noting the result.</p>');
