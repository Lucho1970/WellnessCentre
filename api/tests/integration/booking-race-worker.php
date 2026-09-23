<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AddressCoverageService;
use Wellness\Service\AuditLogger;
use Wellness\Service\BookingService;

try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $config = new Config('test', false, 'booking-race-test', [], $input['host'], (int)$input['port'], $input['database'], $input['user'], $input['password'], '', '', '', 3600);
    $database = new Database($config);
    $database->connection()->exec('SET innodb_lock_wait_timeout=10');
    $actor = new AuthContext((int)$input['actor_id'], (int)$input['clinic_id'], '', 'staff@booking-test', 'Synthetic Staff', 'staff', ['super_admin']);
    $booking = new BookingService($database, new AuditLogger($database), new AddressCoverageService($database, $config));
    $result = $booking->create($actor, $input['body'], '00000000-0000-4000-8000-000000000001');
    echo json_encode(['ok' => true, 'id' => (int)$result['id']], JSON_THROW_ON_ERROR);
} catch (ApiException $error) {
    echo json_encode(['ok' => false, 'status' => $error->status, 'code' => $error->errorCode], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'unexpected' => get_class($error)], JSON_THROW_ON_ERROR);
}
