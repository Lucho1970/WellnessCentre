<?php
declare(strict_types=1);

namespace Wellness\Tests\Hosted;

use RuntimeException;
use Wellness\Config;

final class Suite
{
    /** @return array<string, array{label:string,group:string,type:string,target:string}> */
    public static function cases(): array
    {
        $unit = [
            'address-coverage-proof', 'appointment-access', 'appointment-calendar',
            'appointment-email', 'azure-mail-trigger', 'booking-request',
            'cancellation-policy', 'catalog-duration-order', 'clients',
            'customer-auth', 'customer-onboarding', 'dst-availability',
            'mobile-availability', 'mobile-delivery', 'netfirms-mail-bridge',
            'notification-status', 'practitioner-calendar', 'preflight-response',
            'response-id-normalization', 'schedule-intervals', 'service-duration-pricing',
        ];
        $cases = [];
        foreach ($unit as $name) {
            $cases[$name] = ['label' => str_replace('-', ' ', ucfirst($name)), 'group' => 'PHP checks', 'type' => 'php', 'target' => $name . '.php'];
        }
        $cases['database-read'] = ['label' => 'Database schema and connection', 'group' => 'Hosted checks', 'type' => 'php', 'target' => 'hosted/database-read.php'];
        foreach ([
            'health' => '/v1/health',
            'database-health' => '/v1/health/database',
            'site-config' => '/v1/site-config',
            'locations' => '/v1/locations',
            'services' => '/v1/services',
            'public-services' => '/v1/public/services',
            'public-practitioners' => '/v1/public/practitioners',
            'team' => '/v1/team',
        ] as $name => $path) {
            $cases['api-' . $name] = ['label' => 'GET ' . $path, 'group' => 'Hosted API', 'type' => 'http', 'target' => $path];
        }
        $cases['booking-race'] = ['label' => 'Concurrent booking, room, retry and cross-location', 'group' => 'MySQL integration', 'type' => 'race', 'target' => 'integration/booking-race.php'];
        return $cases;
    }

    /** @return array{status:string,detail:string,seconds:float} */
    public static function run(string $id, Config $config, string $apiBase, string $phpCli, string $workerSecret = ''): array
    {
        $cases = self::cases();
        if (!isset($cases[$id])) throw new RuntimeException('Unknown test case.');
        $case = $cases[$id];
        $started = microtime(true);
        $cliAvailable = function_exists('proc_open') && is_file($phpCli) && !preg_match('/(?:cgi|fpm)/i', basename($phpCli));
        $httpRaceAvailable = $case['type'] === 'race' && !$cliAvailable
            && function_exists('curl_multi_init') && strlen($workerSecret) >= 32;
        if ($case['type'] !== 'http' && !$cliAvailable
            && (($case['type'] === 'race' && !$httpRaceAvailable) || in_array($id, ['azure-mail-trigger', 'netfirms-mail-bridge', 'preflight-response'], true))) {
            return ['status' => 'skipped', 'detail' => 'This case requires PHP CLI with proc_open, or the signed HTTPS worker for booking.', 'seconds' => 0.0];
        }
        try {
            $detail = $case['type'] === 'http'
                ? self::http($apiBase . $case['target'], $id === 'api-health')
                : ($cliAvailable
                    ? self::script($case['target'], $case['type'] === 'race', $config, $phpCli)
                    : ($httpRaceAvailable
                        ? self::httpRace($case['target'], $config, $apiBase . '/test-suite.php', $workerSecret)
                        : self::inProcess($case['target'])));
            return ['status' => 'passed', 'detail' => $detail, 'seconds' => round(microtime(true) - $started, 2)];
        } catch (\Throwable $error) {
            error_log('Hosted test ' . $id . ' failed: ' . get_class($error) . ': ' . $error->getMessage());
            $detail = $error instanceof RuntimeException ? $error->getMessage() : get_class($error);
            // Never return exception details that could include a DSN, SQL, token or client data.
            if (preg_match('/password|secret|token|SQLSTATE|PDO|DSN|recipient|client data/i', $detail)) $detail = 'Check the private PHP error log.';
            return ['status' => 'failed', 'detail' => substr($detail, 0, 300), 'seconds' => round(microtime(true) - $started, 2)];
        }
    }

    private static function http(string $url, bool $health): string
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Cannot initialize HTTP request.');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) throw new RuntimeException('HTTPS request failed: ' . substr($error, 0, 120));
        if ($status !== 200) throw new RuntimeException('Expected HTTP 200; received HTTP ' . $status . '.');
        $json = json_decode($body, true);
        if (!is_array($json) || !array_key_exists('data', $json)) throw new RuntimeException('Response was not an API JSON data envelope.');
        if ($health && ($json['data']['status'] ?? null) !== 'ok') throw new RuntimeException('Health status was not ok.');
        return 'HTTP 200; valid JSON data envelope.';
    }

    private static function script(string $relative, bool $race, Config $config, string $phpCli): string
    {
        if (!function_exists('proc_open') || !is_file($phpCli)) throw new RuntimeException('PHP CLI or proc_open is unavailable on this host.');
        $testsRoot = dirname(__DIR__);
        $file = $testsRoot . '/' . $relative;
        if (!is_file($file)) throw new RuntimeException('Test file is missing from the private installation.');
        $environment = getenv();
        if (!is_array($environment)) $environment = [];
        if ($race) {
            $environment = array_replace($environment, [
                'BOOKING_TEST_DB_HOST' => $config->dbHost,
                'BOOKING_TEST_DB_PORT' => (string)$config->dbPort,
                'BOOKING_TEST_DB_NAME' => $config->dbName,
                'BOOKING_TEST_DB_USER' => $config->dbUser,
                'BOOKING_TEST_DB_PASSWORD' => $config->dbPassword,
                'BOOKING_TEST_CONFIRM' => $config->dbName,
                'BOOKING_TEST_MODE' => 'isolated-clinic',
                'BOOKING_TEST_EXISTING_ACK' => 'synthetic-clinic-only',
                'BOOKING_TEST_PHP_CLI' => $phpCli,
            ]);
        }
        $process = proc_open([$phpCli, $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $testsRoot, $environment);
        if (!is_resource($process)) throw new RuntimeException('Could not start PHP CLI process.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + ($race ? 90 : 25);
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) >= $deadline) {
                    proc_terminate($process);
                    throw new RuntimeException('Test exceeded its time limit; inspect any synthetic clinic left active.');
                }
                usleep(100000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $exit = $status['exitcode'];
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if ($exit !== 0) {
            error_log('Hosted PHP test ' . $relative . ' stderr: ' . substr($stderr, 0, 1500));
            throw new RuntimeException('PHP test exited ' . $exit . '; check the private PHP error log.');
        }
        return trim(substr($stdout, 0, 500)) ?: 'Passed without output.';
    }

    private static function inProcess(string $relative): string
    {
        $file = dirname(__DIR__) . '/' . $relative;
        if (!is_file($file)) throw new RuntimeException('Test file is missing from the private installation.');
        ob_start();
        try {
            (static function (string $path): void { require $path; })($file);
            $output = (string)ob_get_clean();
            return trim(substr($output, 0, 500)) ?: 'Passed without output.';
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    private static function httpRace(string $relative, Config $config, string $workerUrl, string $secret): string
    {
        $settings = [
            'BOOKING_TEST_DB_HOST' => $config->dbHost,
            'BOOKING_TEST_DB_PORT' => (string)$config->dbPort,
            'BOOKING_TEST_DB_NAME' => $config->dbName,
            'BOOKING_TEST_DB_USER' => $config->dbUser,
            'BOOKING_TEST_DB_PASSWORD' => $config->dbPassword,
            'BOOKING_TEST_CONFIRM' => $config->dbName,
            'BOOKING_TEST_MODE' => 'isolated-clinic',
            'BOOKING_TEST_EXISTING_ACK' => 'synthetic-clinic-only',
            'BOOKING_TEST_HTTP_WORKER_URL' => $workerUrl,
            'BOOKING_TEST_HTTP_WORKER_SECRET' => $secret,
        ];
        $previous = [];
        foreach ($settings as $key => $value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null];
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
        try {
            return self::inProcess($relative);
        } finally {
            foreach ($previous as $key => [$oldEnvironment, $oldArray]) {
                $oldEnvironment === false ? putenv($key) : putenv("{$key}={$oldEnvironment}");
                if ($oldArray === null) unset($_ENV[$key]); else $_ENV[$key] = $oldArray;
            }
        }
    }
}
