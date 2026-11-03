<?php
declare(strict_types=1);

// Exact public entry copy, isolated fake services and synthetic configuration.
$root = sys_get_temp_dir() . '/wellness-account-trigger-' . bin2hex(random_bytes(8));
$public = $root . '/public_html/example.test/api/cron';
$private = $root . '/wellness-api';
mkdir($public, 0700, true);
mkdir($private . '/vendor', 0700, true);
copy(dirname(__DIR__) . '/deploy/netfirms/public/cron/send-account-emails.php', $public . '/send-account-emails.php');
file_put_contents($private . '/.env', "ACCOUNT_EMAIL_TRIGGER_SECRET=" . str_repeat('a', 40) . "\nCOMMIT_SAFE_EMAIL_ENABLED=false\nCOMMIT_SAFE_EMAIL_KEY=" . str_repeat('b', 64) . "\nMAIL_ENABLED=true\n");
file_put_contents($private . '/vendor/autoload.php', <<<'PHP'
<?php
namespace Wellness {
    final class Config {
        public static function loadEnvFile(string $path): void {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                [$key, $value] = explode('=', $line, 2);
                $_ENV[$key] = $value;
            }
        }
        public static function fromEnvironment(): self { return new self(); }
    }
    final class Database {
        public function __construct(Config $config) {}
        public function connection(): object { return new \stdClass(); }
    }
}
namespace Wellness\Service {
    final class GraphMailClient {
        public function __construct(string $tenant, string $client, string $secret, string $sender) {}
    }
    final class CommitSafeEmail {
        public function __construct(object $connection) {}
        public static function requireConfigured(): void {
            if (($_ENV['COMMIT_SAFE_EMAIL_ENABLED'] ?? '') !== 'true' || ($_ENV['MAIL_ENABLED'] ?? '') !== 'true')
                throw new \RuntimeException('Synthetic delivery disabled.');
        }
        public function run(GraphMailClient $mail, int $limit): array {
            if ($limit !== 1) throw new \RuntimeException('Unbounded trigger pass.');
            file_put_contents(dirname(__DIR__) . '/worker-called', '1', FILE_APPEND);
            return ['accepted' => 0, 'failed' => 0, 'canceled' => 0, 'needs_review' => 0];
        }
    }
}
PHP
);

$template = json_decode(file_get_contents(dirname(__DIR__, 2) . '/hosting/azure/account-email-trigger.json'), true, 32, JSON_THROW_ON_ERROR);
$resource = $template['resources'][0] ?? [];
$definition = $resource['properties']['definition'] ?? [];
$action = $definition['actions']['Call_Account_Email_Trigger'] ?? [];
if (($resource['properties']['state'] ?? '') !== 'Disabled'
    || ($template['parameters']['accountEmailTriggerSecret']['type'] ?? '') !== 'securestring'
    || isset($template['parameters']['accountEmailTriggerSecret']['defaultValue'])
    || ($definition['triggers']['Every_minute']['recurrence'] ?? []) !== ['frequency' => 'Minute', 'interval' => 1]
    || ($action['inputs']['method'] ?? '') !== 'POST'
    || ($action['inputs']['uri'] ?? '') !== 'https://livinlively.ca/api/cron/send-account-emails.php'
    || ($action['inputs']['retryPolicy']['type'] ?? '') !== 'none'
    || ($action['runtimeConfiguration']['secureData']['properties'] ?? []) !== ['inputs', 'outputs'])
    throw new RuntimeException('Account email scheduler template safety contract changed.');

$port = random_int(20000, 49000);
$log = $root . '/server.log';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public_html/example.test'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, null, ['bypass_shell' => true]);
if (!is_resource($server)) throw new RuntimeException('Synthetic HTTP server did not start.');
fclose($pipes[0]);
try {
    $url = 'http://127.0.0.1:' . $port . '/api/cron/send-account-emails.php';
    $request = static function (string $method, ?string $key = null) use ($url): array {
        $headers = "Content-Type: application/json\r\n" . ($key === null ? '' : 'X-Wellness-Account-Email-Key: ' . $key . "\r\n");
        $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $method === 'POST' ? '{}' : '',
            'ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0]]);
        $body = file_get_contents($url, false, $context);
        return [$http_response_header[0] ?? '', $body];
    };
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        [$status] = $request('GET');
        if (str_contains($status, ' 405 ')) { $ready = true; break; }
    }
    if (!$ready) throw new RuntimeException('Synthetic HTTP server was not ready.');
    $assert = static function (string $method, ?string $key, int $expected) use ($request): void {
        [$status, $body] = $request($method, $key);
        if (!str_contains($status, ' ' . $expected . ' ') || $body !== '')
            throw new RuntimeException('Unexpected synthetic trigger response: ' . $status);
    };
    $secret = str_repeat('a', 40);
    $assert('POST', null, 404);
    $assert('POST', str_repeat('z', 40), 404);
    $assert('POST', $secret, 503);
    if (is_file($private . '/worker-called')) throw new RuntimeException('Disabled or unauthenticated trigger ran worker.');
    $settings = file_get_contents($private . '/.env');
    file_put_contents($private . '/.env', str_replace('COMMIT_SAFE_EMAIL_ENABLED=false', 'COMMIT_SAFE_EMAIL_ENABLED=true', $settings));
    $assert('POST', $secret, 204);
    if (file_get_contents($private . '/worker-called') !== '1') throw new RuntimeException('Expected one bounded worker pass.');
    $assert('POST', $secret, 204);
    if (file_get_contents($private . '/worker-called') !== '1') throw new RuntimeException('Cooldown did not prevent a second pass.');
    echo "Account email HTTPS trigger: 8 checks passed.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    if (str_starts_with($root, sys_get_temp_dir() . '/wellness-account-trigger-')) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}
