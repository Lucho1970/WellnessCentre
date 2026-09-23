<?php
declare(strict_types=1);

$source = dirname(__DIR__) . '/deploy/netfirms/public/cron/send-notifications.php';
$fixture = sys_get_temp_dir() . '/wellness-azure-trigger-test-' . bin2hex(random_bytes(8));
$webDir = $fixture . '/public_html/wellness/api/cron';
$privateDir = $fixture . '/wellness-api';
mkdir($webDir, 0700, true);
mkdir($privateDir . '/vendor', 0700, true);
copy($source, $webDir . '/send-notifications.php');
file_put_contents($privateDir . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Wellness;
class Config {
    public static function loadEnvFile(string $path): void {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[$key] = $value;
        }
    }
    public static function fromEnvironment(): self { return new self(); }
}
class Database {
    public function __construct($config) {}
    public function connection(): object { return new FakeDb(); }
}
class FakeDb {}
namespace Wellness\Service;
class GraphMailClient {
    public function __construct($tenant, $client, $secret, $sender) {}
}
class NotificationWorker {
    public function __construct($db, $mailer, $portal) {}
    public function run(int $limit): array {
        if ($limit !== 3) throw new \RuntimeException('Unsafe batch size');
        file_put_contents(getenv('TEST_COUNT_FILE'), "run\n", FILE_APPEND);
        return ['sent' => 1, 'retry' => 0, 'review' => 0, 'canceled' => 0];
    }
}
PHP
);

$countFile = $fixture . '/count.txt';
putenv('TEST_COUNT_FILE=' . $countFile);
$envPath = $privateDir . '/.env';
$trigger = $webDir . '/send-notifications.php';
$secret = str_repeat('a', 48);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (string $method, string $key) use ($trigger): int {
    $code = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true)
        . '; $_SERVER["HTTP_X_WELLNESS_CRON_KEY"]=' . var_export($key, true)
        . '; register_shutdown_function(static function (): void { echo http_response_code(); }); require '
        . var_export($trigger, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch PHP test process.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Test process failed: ' . $stderr);
    return (int)$stdout;
};
$writeEnv = static function (string $enabled) use ($envPath, $secret): void {
    file_put_contents($envPath, "MAIL_ENABLED={$enabled}\nMAIL_TRIGGER_SECRET={$secret}\nCLIENT_PORTAL_URL=https://example.test/client\n");
};

try {
    $writeEnv('false');
    $assert($invoke('GET', $secret) === 405, 'GET should not be accepted.');
    $assert($invoke('POST', 'incorrect') === 404, 'Wrong key should not be accepted.');
    $assert($invoke('POST', $secret) === 503, 'Disabled mail should not run.');
    $assert(!file_exists($countFile), 'Disabled mail consumed a message.');

    $writeEnv('true');
    $assert($invoke('POST', $secret) === 204, 'Valid call did not run.');
    $assert(count(file($countFile)) === 1, 'Worker did not run exactly once.');
    $assert($invoke('POST', $secret) === 204, 'Cooldown call failed.');
    $assert(count(file($countFile)) === 1, 'Cooldown allowed another worker run.');
    echo "Azure mail trigger checks passed.\n";
} finally {
    if (str_starts_with($fixture, sys_get_temp_dir() . '/wellness-azure-trigger-test-')) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($fixture);
    }
}
