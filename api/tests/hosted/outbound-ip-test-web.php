<?php
declare(strict_types=1);

// Temporary manual upload to the portal's /api/outbound-ip-test.php; remove after use.
ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $body): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Outbound IP test</title><style>body{font:16px system-ui;max-width:45rem;margin:3rem auto;padding:1rem;line-height:1.5}input,button{font:inherit;padding:.5rem}pre{white-space:pre-wrap}</style><h1>Outbound IP test</h1>' . $body . '</html>';
};
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); exit; }
$https = ($_SERVER['HTTPS'] ?? '') === 'on' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
if (!$https) { http_response_code(403); exit; }
$root = dirname(__DIR__, 3) . '/wellness-api';
if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/.env')) { http_response_code(404); exit; }
require $root . '/vendor/autoload.php';
\Wellness\Config::loadEnvFile($root . '/.env');
$env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
if (!filter_var($env('OUTBOUND_IP_TEST_ENABLED'), FILTER_VALIDATE_BOOL)) { http_response_code(404); exit; }
$secret = $env('OUTBOUND_IP_TEST_SECRET');
if (strlen($secret) < 32 || !function_exists('curl_init')) { http_response_code(503); $page('<p>Configure a secret of at least 32 characters and enable PHP cURL.</p>'); exit; }
$form = '<form method="post"><label>Temporary test secret <input name="secret" type="password" autocomplete="off" required></label><button type="submit">Run one IP test</button></form>';
if ($method === 'GET') {
    $page('<p>Each run asks two HTTPS IP-check services which source IP they observe, forcing IPv4. No Google keys, user addresses or account details are sent. Results go to the PHP error log and this page.</p>' . $form); exit;
}
$provided = is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '';
if (!hash_equals($secret, $provided)) { http_response_code(404); exit; }
$run = bin2hex(random_bytes(8));
$results = [];
foreach (['ipify' => 'https://api.ipify.org', 'aws-checkip' => 'https://checkip.amazonaws.com'] as $provider => $url) {
    $handle = curl_init($url);
    if ($handle === false) { $results[] = "$provider: cURL initialization failed"; continue; }
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($handle);
    curl_close($handle);
    $ip = is_string($body) && strlen($body) < 100 ? trim($body) : '';
    $valid = $status === 200 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    $result = $valid ? "provider=$provider ip=$ip" : "provider=$provider failed status=$status curl_errno=$errno";
    error_log("Outbound IP diagnostic run=$run $result");
    $results[] = $result;
}
$page('<p>UTC: ' . gmdate('Y-m-d H:i:s') . ' · Run: ' . $run . '</p><pre>' . $escape(implode("\n", $results)) . '</pre><p>These are observations for the two test destinations, not a guarantee of the IP used for Google or of future IP stability. Run again later to sample additional workers.</p>' . $form);
