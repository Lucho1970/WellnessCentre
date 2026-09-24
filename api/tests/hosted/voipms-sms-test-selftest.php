<?php
declare(strict_types=1);

$fixture = sys_get_temp_dir() . '/wellness-sms-web-test-' . bin2hex(random_bytes(6));
$public = $fixture . '/public_html/wellness/api';
$private = $fixture . '/wellness-api';
mkdir($public, 0700, true);
mkdir($private . '/vendor', 0700, true);
copy(__DIR__ . '/voipms-sms-test-web.php', $public . '/sms-test.php');
file_put_contents($private . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Wellness;
final class Config {
    public static function loadEnvFile(string $path): void {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $_ENV[$key] = $value;
        }
    }
}
namespace Wellness\Service;
final class SmsSendException extends \RuntimeException {}
final class CanadianSmsNumber {
    public static function isAllowed(string $number): bool { return $number === '+14166166855'; }
}
final class VoipMsSmsClient {
    public function __construct(string $user, string $password, string $did) {
        if ($user !== 'private@example.test' || $password !== 'private-pass' || $did !== '2892975234') throw new \RuntimeException('Invalid configuration');
    }
    public function send(string $recipient, string $message): string {
        if ($recipient !== '+14166166855' || !str_contains($message, 'connectivity test')) throw new \RuntimeException('Invalid test SMS');
        file_put_contents(getenv('SMS_TEST_COUNT_FILE'), "send\n", FILE_APPEND);
        return 'mock-123';
    }
}
PHP
);
$secret = str_repeat('a', 48);
$envPath = $private . '/.env';
$countFile = $private . '/count.txt';
file_put_contents($envPath, "SMS_TEST_ENABLED=true\nSMS_TEST_SECRET={$secret}\nSMS_TEST_TO=+14166166855\nVOIPMS_API_USERNAME=private@example.test\nVOIPMS_API_PASSWORD=private-pass\nVOIPMS_FROM_DID=2892975234\n");
putenv('SMS_TEST_COUNT_FILE=' . $countFile);
file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
$_SERVER['HTTPS'] = 'on';
require __DIR__ . '/public_html/wellness/api/sms-test.php';
PHP
);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', $address, $fixture . '/router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($server)) throw new RuntimeException('Could not launch test server.');
fclose($pipes[0]);
$request = static function (string $method, array $fields = []) use ($address): array {
    $handle = curl_init('http://' . $address . '/sms-test.php');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($fields) : null,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($handle);
    if ($body === false) throw new RuntimeException(curl_error($handle));
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [$status, (string)$body];
};
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $stream = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($stream !== false) { fclose($stream); $ready = true; break; }
        usleep(20000);
    }
    $check($ready, 'Test server did not start.');
    [$status, $body] = $request('GET');
    $check($status === 200 && str_contains($body, 'ending in 6855') && !str_contains($body, $secret), 'Safe test form was not shown.');
    [$status] = $request('POST', ['secret' => 'wrong', 'ack' => 'yes']);
    $check($status === 404 && !file_exists($countFile), 'Wrong secret made a send attempt.');
    [$status] = $request('POST', ['secret' => $secret]);
    $check($status === 422 && !file_exists($countFile), 'Missing acknowledgement made a send attempt.');
    [$status, $body] = $request('POST', ['secret' => $secret, 'ack' => 'yes']);
    $check($status === 200 && str_contains($body, 'mock-123'), 'Test SMS was not accepted.');
    [$status] = $request('POST', ['secret' => $secret, 'ack' => 'yes']);
    $check($status === 409 && count(file($countFile)) === 1, 'Repeat request sent a second SMS.');
    echo "Hosted VoIP.ms test page checks passed.\n";
} finally {
    proc_terminate($server);
    foreach (array_slice($pipes, 1) as $pipe) fclose($pipe);
    proc_close($server);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($fixture);
}
