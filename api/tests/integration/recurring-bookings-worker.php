<?php
declare(strict_types=1);
// CLI-only companion: no .env, credentials or application database selection.
if (PHP_SAPI !== 'cli' || getenv('RECURRING_TEST_ALLOW_CREATE') !== 'true') throw new RuntimeException('Local synthetic test only.');
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Service\{AddressCoverageService,AuditLogger,BookingService,RecurringBookingService};
$name = $argv[1] ?? '';
if (!preg_match('/^wellness_recurring_test_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Unexpected synthetic database name.');
$body = json_decode($argv[2] ?? '', true, 32, JSON_THROW_ON_ERROR);
$port = (int)(getenv('RECURRING_TEST_PORT') ?: 13317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4", getenv('RECURRING_TEST_USER') ?: 'root', getenv('RECURRING_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+00:00'");
$_ENV['MAIL_ENABLED'] = 'false';
$config = new Config('test', false, 'test', [], '127.0.0.1', $port, $name, '', '', 'workforce', 'api', 'scope', 300);
$database = new Database($config);
(new ReflectionProperty(Database::class, 'connection'))->setValue($database, $pdo);
$service = new RecurringBookingService($database, new BookingService($database, new AuditLogger($database), new AddressCoverageService($database, $config)));
$actor = new AuthContext(8, 1, '', '', '', 'client', []);
// Signal readiness after opening an independent connection; parent releases both together.
file_put_contents($argv[3], 'ready');
if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing race release.');
echo json_encode($service->create($actor, $body, 'synthetic-race', true), JSON_THROW_ON_ERROR)."\n";
