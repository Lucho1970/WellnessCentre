<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Api;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\Response;

// Local test router: uses synthetic configuration, never the real .env or DB.
if (PHP_SAPI === 'cli-server') {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/response-test') {
        Response::json(['data' => ['ok' => true]], 200, 'response-test-id');
    }
    $config = new Config(
        environment: 'test', debug: false, appKey: 'test-only-not-a-secret',
        allowedOrigins: ['https://portal.example.test'],
        dbHost: '127.0.0.1', dbPort: 9, dbName: 'unused', dbUser: 'unused', dbPassword: '',
        entraTenantId: 'test-tenant', entraApiClientId: 'test-api',
        entraRequiredScope: 'access_as_user', entraJwksCacheSeconds: 300,
    );
    (new Api($config, new Database($config)))->handle();
}

// CLI catches accidental output that some HTTP SAPIs silently discard for 204.
if (($argv[1] ?? '') === '--probe-204') {
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['status' => http_response_code(), 'body' => $body], JSON_THROW_ON_ERROR);
    });
    Response::json(['must_not_be_written' => true], 204, 'response-test-id');
}

if (($argv[1] ?? '') === '--probe-after-json') {
    Response::afterJson(static function (): void { file_put_contents((string)getenv('WELLNESS_AFTER_JSON_TEST_FILE'), 'called'); });
    Response::json(['data' => ['ok' => true]], 201, 'response-test-id');
}

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

$probe = proc_open([PHP_BINARY, __FILE__, '--probe-204'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($probe)) throw new RuntimeException('Cannot run response probe.');
fclose($pipes[0]);
$result = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($probe) === 0 && $error === '', 'Response probe failed: ' . $error);
check($result['status'] === 204 && $result['body'] === '', '204 emitted content before the HTTP server could suppress it.');

$afterJsonFile = tempnam(sys_get_temp_dir(), 'wellness-after-json-');
if ($afterJsonFile === false) throw new RuntimeException('Cannot create response test file.');
try {
    putenv('WELLNESS_AFTER_JSON_TEST_FILE=' . $afterJsonFile);
    $probe = proc_open([PHP_BINARY, __FILE__, '--probe-after-json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($probe)) throw new RuntimeException('Cannot run post-response probe.');
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($probe) === 0 && $error === '', 'Post-response probe failed: ' . $error);
    check(json_decode($body, true) === ['data' => ['ok' => true]] && file_get_contents($afterJsonFile) === 'called', 'Post-response callback did not run after JSON output.');
} finally {
    unlink($afterJsonFile);
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
if (!is_resource($server)) throw new RuntimeException('Cannot start local test server.');
fclose($serverPipes[0]);
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection !== false) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Local test server did not start.');
    $request = static function (string $method, string $path, string $origin) use ($address): array {
        $handle = curl_init('http://' . $address . $path);
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => [
                'Origin: ' . $origin, 'Access-Control-Request-Method: GET',
                'Access-Control-Request-Headers: authorization,content-type',
            ]]);
        $raw = curl_exec($handle);
        if ($raw === false) throw new RuntimeException(curl_error($handle));
        $length = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return [$status, strtolower(substr($raw, 0, $length)), substr($raw, $length)];
    };
    [$status, $headers, $body] = $request('OPTIONS', '/api/v1/auth/me', 'https://portal.example.test');
    check($status === 204 && $body === '', 'Preflight must return 204 and zero body bytes.');
    check(str_contains($headers, 'access-control-allow-origin: https://portal.example.test'), 'Allowed origin lost.');
    check(str_contains($headers, 'access-control-allow-headers: authorization, content-type'), 'Authorization/content-type preflight permission lost.');
    check(str_contains($headers, 'access-control-allow-methods: get, post, put, patch, delete, options'), 'Allowed methods changed.');
    check(str_contains($headers, 'x-correlation-id:'), 'Correlation ID lost.');
    check(!str_contains($headers, 'content-type: application/json'), '204 should not advertise a JSON body.');
    [$status, $headers, $body] = $request('OPTIONS', '/api/v1/auth/me', 'https://untrusted.example.test');
    check($status === 204 && !str_contains($headers, 'access-control-allow-origin:'), 'Untrusted origin was allowed.');
    [$status, $headers, $body] = $request('GET', '/api/v1/auth/me', 'https://portal.example.test');
    check($status === 401 && isset(json_decode($body, true)['error']), 'Protected API no longer requires authentication.');
    [$status, $headers, $body] = $request('GET', '/response-test', 'https://portal.example.test');
    check($status === 200 && json_decode($body, true) === ['data' => ['ok' => true]], 'Normal JSON response changed.');
    check(str_contains($headers, 'content-type: application/json'), 'Normal JSON content type lost.');
} finally {
    proc_terminate($server);
    fclose($serverPipes[1]); fclose($serverPipes[2]); proc_close($server);
}
echo "{$checks} preflight/response checks passed.\n";
