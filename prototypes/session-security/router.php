<?php
declare(strict_types=1);

// Hard local-only gate. No environment-file loading, database or provider transport.
header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
if (PHP_SAPI !== 'cli-server' || getenv('SESSION_PROTOTYPE_ENABLED') !== 'local-synthetic-only' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404); echo '{"error":{"code":"prototype_disabled"}}'; return;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__session-prototype/health') { echo '{"data":{"prototype_only":true}}'; return; }
ini_set('display_errors', '0');
$root = dirname(__DIR__, 2);
require $root . '/api/vendor/autoload.php';
require __DIR__ . '/SessionFixture.php';

try {
    $runtime = getenv('SESSION_PROTOTYPE_RUNTIME');
    $allowed = realpath($root . '/.tmp/session-security-prototype');
    $resolved = is_string($runtime) ? realpath($runtime) : false;
    if (!$allowed || !$resolved || $resolved !== $allowed) throw new \WellnessPrototype\FixtureError(503, 'fixture_configuration');
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) throw new \WellnessPrototype\FixtureError(413, 'request_too_large');
    try { $decoded = $raw === '' ? new \stdClass() : json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new \WellnessPrototype\FixtureError(400, 'invalid_json'); }
    if (!$decoded instanceof \stdClass) throw new \WellnessPrototype\FixtureError(400, 'invalid_json');
    $body = (array)$decoded;
    $fixture = new \WellnessPrototype\SessionFixture($resolved);
    if ($path === '/__session-prototype/control' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $data = $fixture->control($_SERVER['HTTP_X_PROTOTYPE_CONTROL'] ?? '', $body);
    else {
        if (!is_string($path) || !preg_match('#^/__session-prototype/([a-z]+)/([a-z]+)$#D', $path, $parts)) throw new \WellnessPrototype\FixtureError(404, 'fixture_route_unknown');
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $bearer = preg_match('/^Bearer (.+)$/D', $header, $match) ? $match[1] : null;
        $data = $fixture->dispatch($_SERVER['HTTP_HOST'] ?? '', $parts[1], $parts[2], $_SERVER['REQUEST_METHOD'] ?? '', $body, $bearer);
    }
    echo json_encode(['data' => $data], JSON_THROW_ON_ERROR);
} catch (\WellnessPrototype\FixtureError $error) {
    http_response_code($error->status); echo json_encode(['error' => ['code' => $error->errorCode]], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    error_log('Local session prototype failure: ' . $error::class);
    http_response_code(500); echo '{"error":{"code":"prototype_failure"}}';
}
