<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

try {
    if (count($argv) === 1) $envFile = dirname(__DIR__).'/.env';
    elseif (count($argv) === 3 && $argv[1] === '--env') $envFile = $argv[2];
    else throw new RuntimeException('Usage: php api/bin/pilot-preflight.php [--env private-env-file]');
    if (!is_file($envFile)) throw new RuntimeException('The requested private environment file is unavailable.');
    \Wellness\Config::loadEnvFile($envFile);
    $summary = \Wellness\PilotPolicy::fromEnvironment()->summary();
    echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
    if (!$summary['enabled'] || $summary['identities'] < 1) {
        fwrite(STDERR, "Pilot access is not configured for testers. No provider or database connection was attempted.\n");
        exit(2);
    }
    echo "Pilot configuration is valid; empty delivery lists block delivery.\n";
    echo "This checks configuration only. Verify deployed identities, roles, synthetic records, sender/reply/calendar addresses, workers and rollback before inviting testers.\n";
} catch (Throwable) {
    fwrite(STDERR, "Pilot preflight failed. Check the command, private file and PILOT_* configuration; no provider or database connection was attempted.\n");
    exit(1);
}
