<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Suite.php';

use Wellness\Config;
use Wellness\Tests\Hosted\Suite;

$cases = Suite::cases();
if (count($cases) !== 31 || count(array_filter($cases, static fn(array $case): bool => $case['type'] === 'php')) !== 22) {
    throw new RuntimeException('Hosted suite manifest changed unexpectedly.');
}
$config = new Config('test', false, 'test-key', [], '127.0.0.1', 3306, 'unused', 'unused', '', '', '', '', 3600);
$failed = [];
$passed = 0;
foreach ($cases as $id => $case) {
    if ($case['type'] !== 'php' || $id === 'database-read') continue;
    $result = Suite::run($id, $config, 'https://example.test/api', PHP_BINARY);
    if ($result['status'] === 'passed') $passed++;
    else $failed[$id] = $result['detail'];
}
if ($failed) throw new RuntimeException('Hosted PHP checks failed: ' . json_encode($failed, JSON_THROW_ON_ERROR));
$fallback = Suite::run('booking-request', $config, 'https://example.test/api', '/nonexistent/php-cli');
if ($fallback['status'] !== 'passed') throw new RuntimeException('Web-request fallback did not run a safe PHP fixture.');
$counted = Suite::run('mobile-delivery', $config, 'https://example.test/api', '/nonexistent/php-cli');
if ($counted['status'] !== 'passed' || !preg_match('/^[1-9][0-9]* mobile delivery checks passed\./', $counted['detail'])) {
    throw new RuntimeException('Web-request fallback did not report fixture assertion counts.');
}
$skipped = Suite::run('booking-race', $config, 'https://example.test/api', '/nonexistent/php-cli');
if ($skipped['status'] !== 'skipped') throw new RuntimeException('Booking race must be skipped when PHP CLI is unavailable.');
echo "{$passed} hosted PHP fixture checks passed.\n";
