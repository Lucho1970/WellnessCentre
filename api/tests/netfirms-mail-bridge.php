<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2) . '/hosting/netfirms/main-domain/api/wellness-notification-trigger.php';
if (!is_file($source)) $source = dirname(__DIR__) . '/hosting/netfirms/main-domain/api/wellness-notification-trigger.php';
$fixture = sys_get_temp_dir() . '/wellness-mail-bridge-test-' . bin2hex(random_bytes(8));
$webDir = $fixture . '/public_html/tuff-tar.com/api';
$privateDir = $fixture . '/wellness-api';
mkdir($webDir, 0700, true);
mkdir($privateDir . '/vendor', 0700, true);
copy($source, $webDir . '/wellness-notification-trigger.php');
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
class FakeDb {
    public function prepare(string $sql): object { return new FakeStatement(); }
}
class FakeStatement {
    public function execute(array $params): void {
        file_put_contents(getenv('TEST_PROBE_FILE'), json_encode($params) . "\n", FILE_APPEND);
    }
}
namespace Wellness\Service;
class GraphMailClient {
    public function __construct($tenant, $client, $secret, $sender) {}
}
class NotificationWorker {
    public function __construct($db, $mailer, $portal) {}
    public function run(int $limit): array {
        if ($limit !== 1) throw new \RuntimeException('Unsafe batch size');
        file_put_contents(getenv('TEST_COUNT_FILE'), "run\n", FILE_APPEND);
        return ['sent' => 1, 'retry' => 0, 'review' => 0, 'canceled' => 0];
    }
}
PHP
);

$countFile = $fixture . '/count.txt';
putenv('TEST_COUNT_FILE=' . $countFile);
$probeFile = $fixture . '/probe.txt';
putenv('TEST_PROBE_FILE=' . $probeFile);
$envPath = $privateDir . '/.env';
$trigger = $webDir . '/wellness-notification-trigger.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (string $remoteIp) use ($trigger): int {
    $code = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]=' . var_export($remoteIp, true)
        . '; register_shutdown_function(static function (): void { echo http_response_code(); }); require '
        . var_export($trigger, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch PHP test process.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('Test process failed: ' . $stderr);
    return (int)$stdout;
};
$writeEnv = static function (string $enabled, string $allowlist) use ($envPath): void {
    file_put_contents($envPath, "MAIL_ENABLED={$enabled}\nMAIL_CRON_ALLOWED_IPS={$allowlist}\nCLIENT_PORTAL_URL=https://example.test/client\n");
};

try {
    $writeEnv('false', '');
    $assert($invoke('192.0.2.10') === 503, 'Probe must not run the worker.');
    $assert(!file_exists($countFile), 'Probe consumed mail.');
    $assert(file_exists($probeFile), 'Probe did not record a database marker.');
    $probe = json_decode((string)file_get_contents($probeFile), true);
    $assert($probe['source_ip'] === '192.0.2.10' && $probe['method'] === 'GET', 'Probe recorded incorrect request details.');

    $writeEnv('true', '192.0.2.10');
    $assert($invoke('198.51.100.9') === 404, 'Unlisted IP was accepted.');
    $assert(!file_exists($countFile), 'Unlisted IP consumed mail.');
    $assert($invoke('192.0.2.10') === 204, 'Listed IP did not run.');
    $assert(count(file($countFile)) === 1, 'Worker did not run exactly once.');
    $assert($invoke('192.0.2.10') === 204, 'Cooldown request failed.');
    $assert(count(file($countFile)) === 1, 'Cooldown allowed a second run.');

    $writeEnv('false', '192.0.2.10');
    $assert($invoke('192.0.2.10') === 503, 'Disabled mail was accepted.');
    $assert(count(file($countFile)) === 1, 'Disabled mail consumed a message.');
    echo "Netfirms mail bridge checks passed.\n";
} finally {
    // The test owns this uniquely named directory and never touches the repo.
    if (str_starts_with($fixture, sys_get_temp_dir() . '/wellness-mail-bridge-test-')) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($fixture);
    }
}
