<?php
declare(strict_types=1);

$fixture = sys_get_temp_dir() . '/wellness-hosted-web-test-' . bin2hex(random_bytes(6));
$public = $fixture . '/public_html/wellness/api';
$private = $fixture . '/wellness-api';
mkdir($public, 0700, true);
mkdir($private . '/tests/hosted', 0700, true);
mkdir($private . '/vendor', 0700, true);
copy(__DIR__ . '/test-suite-web.php', $public . '/test-suite.php');
copy(__DIR__ . '/Suite.php', $private . '/tests/hosted/Suite.php');
file_put_contents($private . '/.env', "HOSTED_TEST_ENABLED=true\nHOSTED_TEST_DB_ACK=synthetic\nHOSTED_TEST_SECRET=" . str_repeat('a', 48) . "\nDB_NAME=synthetic\nHOSTED_TEST_API_BASE=https://example.test/api\n");
file_put_contents($private . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Wellness;
class Config {
    public static function loadEnvFile(string $path): void {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[$key] = $value;
        }
    }
}
PHP
);
file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
$_SERVER['HTTPS'] = 'on';
require __DIR__ . '/public_html/wellness/api/test-suite.php';
PHP
);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', $address, $fixture . '/router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($server)) throw new RuntimeException('Could not launch fixture web server.');
fclose($pipes[0]);

$request = static function (string $method, array $body = [], string $cookie = '') use ($address): array {
    $curl = curl_init('http://' . $address . '/api/test-suite.php');
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($body) : null,
        CURLOPT_HTTPHEADER => $cookie === '' ? [] : ['Cookie: ' . $cookie]]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    return [$status, substr($raw, 0, $size), substr($raw, $size)];
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $stream = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($stream !== false) { fclose($stream); $ready = true; break; }
        usleep(20000);
    }
    $check($ready, 'Fixture server did not start.');
    [$status, , $page] = $request('GET');
    $check($status === 200 && str_contains($page, 'Temporary test secret'), 'Login page is unavailable.');
    [$status] = $request('POST', ['action' => 'login', 'secret' => 'wrong']);
    $check($status === 404, 'Wrong secret was accepted.');
    [$status, $headers] = $request('POST', ['action' => 'login', 'secret' => str_repeat('a', 48)]);
    $check($status === 303 && preg_match('/Set-Cookie: (wellness_hosted_test_suite=[^;]+)/i', $headers, $matches) === 1, 'Login did not establish a session.');
    $cookie = $matches[1];
    [$status, , $page] = $request('GET', [], $cookie);
    $check($status === 200 && str_contains($page, 'Run complete suite'), 'Authenticated test page is unavailable.');
    $check(preg_match('/"csrf":"([a-f0-9]+)"/', $page, $matches) === 1, 'CSRF value is missing.');
    $csrf = $matches[1];
    [$status] = $request('POST', ['action' => 'run', 'id' => 'booking-race', 'csrf' => $csrf], $cookie);
    $check($status === 400, 'Race test ran without synthetic-record acknowledgement.');
    [$status] = $request('POST', ['action' => 'run', 'id' => 'unknown', 'csrf' => $csrf], $cookie);
    $check($status === 400, 'Unknown test case was accepted.');
    echo "Hosted web entry authentication and safety checks passed.\n";
} finally {
    proc_terminate($server);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($server);
    if (str_starts_with($fixture, sys_get_temp_dir() . '/wellness-hosted-web-test-')) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($fixture);
    }
}
