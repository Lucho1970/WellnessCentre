<?php
declare(strict_types=1);

/** Synthetic concurrent bookings in an empty scratch DB or isolated dev clinic. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Wellness\Config;
use Wellness\Database;
use Wellness\Service\AvailabilityService;

$read = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || trim($value) === '') throw new RuntimeException("Set {$name} for the disposable booking test database.");
    return $value;
};
$name = $read('BOOKING_TEST_DB_NAME');
$mode = getenv('BOOKING_TEST_MODE') ?: 'empty';
if (!in_array($mode, ['empty', 'isolated-clinic'], true) || $read('BOOKING_TEST_CONFIRM') !== $name) throw new RuntimeException('Confirm the exact database name and choose a supported test mode.');
if ($mode === 'empty' && !preg_match('/^[a-zA-Z0-9_]*booking_test[a-zA-Z0-9_]*$/', $name)) throw new RuntimeException('An empty scratch database name must contain booking_test.');
if ($mode === 'isolated-clinic' && $read('BOOKING_TEST_EXISTING_ACK') !== 'synthetic-clinic-only') throw new RuntimeException('Confirm isolated-clinic mode explicitly before writing synthetic records.');
if (!function_exists('proc_open')) throw new RuntimeException('This PHP CLI must support proc_open for independent concurrent connections.');
$host = $read('BOOKING_TEST_DB_HOST');
$port = (int)(getenv('BOOKING_TEST_DB_PORT') ?: 3306);
$user = $read('BOOKING_TEST_DB_USER');
$password = getenv('BOOKING_TEST_DB_PASSWORD');
if ($password === false) throw new RuntimeException('Set BOOKING_TEST_DB_PASSWORD, even if empty.');
$connection = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
]);
$tableCount = (int)$connection->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($mode === 'empty') {
    if ($tableCount !== 0) throw new RuntimeException('The scratch database is not empty. No changes were made.');
    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
    if ($schema === false) throw new RuntimeException('Cannot read the fresh-install schema.');
    $schema = preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m', '', $schema);
    $connection->exec($schema);
} else {
    if ($tableCount === 0) throw new RuntimeException('The current development database has no schema; use empty mode.');
    foreach (['clinics', 'locations', 'users', 'practitioners', 'services', 'appointments', 'notification_events'] as $table) {
        $statement = $connection->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:name');
        $statement->execute(['name' => $table]);
        if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException("Missing required application table: {$table}.");
    }
}

$insert = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int)$pdo->lastInsertId();
};
$marker = bin2hex(random_bytes(6));
$clinicId = $insert($connection, 'INSERT INTO clinics(name) VALUES(:name)', ['name' => 'Synthetic booking race ' . $marker]);
$closeSyntheticClinic = static function () use ($connection, $clinicId): bool {
    try {
        if ($connection->inTransaction()) $connection->rollBack();
        $connection->prepare("UPDATE notification_events SET status='canceled' WHERE clinic_id=:clinic AND status IN('queued','failed','needs_review')")->execute(['clinic' => $clinicId]);
        $connection->prepare("UPDATE clinics SET status='inactive' WHERE id=:clinic")->execute(['clinic' => $clinicId]);
        return true;
    } catch (Throwable $error) {
        fwrite(STDERR, "Could not deactivate synthetic clinic ID {$clinicId}; inspect it before continuing.\n");
        return false;
    }
};
register_shutdown_function($closeSyntheticClinic);
$locationIds = [];
foreach (['A', 'B'] as $label) $locationIds[] = $insert($connection, "INSERT INTO locations(clinic_id,name,timezone) VALUES(:clinic,:name,'America/Toronto')", ['clinic' => $clinicId, 'name' => "Synthetic location {$label}"]);
$userIds = [];
foreach (['staff', 'practitioner-a', 'practitioner-b', 'client-a', 'client-b'] as $label) {
    $email = $label . '-' . $marker . '@booking-test';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) throw new RuntimeException('A synthetic recipient unexpectedly passed email validation.');
    $userIds[$label] = $insert($connection, "INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(:clinic,:email,:name,:type,'active')", ['clinic' => $clinicId, 'email' => $email, 'name' => 'Synthetic ' . $label, 'type' => str_starts_with($label, 'client') ? 'client' : 'staff']);
}
$practitionerIds = [];
foreach (['practitioner-a', 'practitioner-b'] as $label) $practitionerIds[] = $insert($connection, "INSERT INTO practitioners(user_id,discipline,active) VALUES(:user,'Test therapy',1)", ['user' => $userIds[$label]]);
foreach ([[$practitionerIds[0], $locationIds[0]], [$practitionerIds[0], $locationIds[1]], [$practitionerIds[1], $locationIds[0]]] as [$practitioner, $location]) $connection->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(?,?,1)')->execute([$practitioner, $location]);
$roomIds = [];
foreach ($locationIds as $index => $location) $roomIds[] = $insert($connection, 'INSERT INTO rooms(location_id,name,is_bookable) VALUES(:location,:name,1)', ['location' => $location, 'name' => 'Synthetic room ' . ($index + 1)]);
$serviceId = $insert($connection, "INSERT INTO services(clinic_id,slug,name,price_cents,requires_room,lead_time_minutes,booking_horizon_days,active) VALUES(:clinic,'synthetic-therapy','Synthetic therapy',10000,1,0,365,1)", ['clinic' => $clinicId]);
$durationId = $insert($connection, 'INSERT INTO service_duration_options(service_id,duration_minutes,price_cents,active) VALUES(:service,60,10000,1)', ['service' => $serviceId]);
foreach ($practitionerIds as $practitioner) $connection->prepare('INSERT INTO practitioner_services(practitioner_id,service_id,active,offers_clinic) VALUES(?,?,1,1)')->execute([$practitioner, $serviceId]);
foreach ($locationIds as $location) $connection->prepare('INSERT INTO service_locations(service_id,location_id,active) VALUES(?,?,1)')->execute([$serviceId, $location]);

$zone = new DateTimeZone('America/Toronto');
$day = (new DateTimeImmutable('now', $zone))->modify('+14 days')->format('Y-m-d');
$weekday = (int)(new DateTimeImmutable($day, $zone))->format('N');
$rule = $connection->prepare("INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from,active) VALUES(:practitioner,:location,:weekday,'09:00:00','20:00:00',:valid_from,1)");
foreach ([[$practitionerIds[0], $locationIds[0]], [$practitionerIds[0], $locationIds[1]], [$practitionerIds[1], $locationIds[0]]] as [$practitioner, $location]) $rule->execute(['practitioner' => $practitioner, 'location' => $location, 'weekday' => $weekday, 'valid_from' => $day]);

$config = new Config('test', false, 'booking-race-test', [], $host, $port, $name, $user, $password, '', '', '', 3600);
$database = new Database($config);
$slotAt = static function (int $location, int $practitioner, string $hour) use ($database, $day, $serviceId): string {
    $slots = (new AvailabilityService($database))->search(['service_id' => $serviceId, 'practitioner_id' => $practitioner, 'location_id' => $location, 'delivery_mode' => 'clinic', 'date_from' => $day, 'date_to' => $day])['availability'];
    foreach ($slots as $slot) if (substr($slot['starts_at'], 11, 5) === $hour) return $slot['starts_at'];
    throw new RuntimeException("Synthetic {$hour} slot is unavailable.");
};
$body = static fn(int $client, int $location, int $practitioner, int $room, string $start, string $key): array => [
    'client_id' => $client, 'location_id' => $location, 'practitioner_id' => $practitioner,
    'service_id' => $serviceId, 'duration_option_id' => $durationId, 'delivery_mode' => 'clinic',
    'room_id' => $room, 'starts_at' => $start, 'idempotency_key' => $key,
];
$credentials = compact('host', 'port', 'user', 'password') + ['database' => $name, 'clinic_id' => $clinicId, 'actor_id' => $userIds['staff']];
$race = static function (array $jobs) use ($connection, $credentials, $clinicId): array {
    $workers = [];
    $connection->beginTransaction();
    try {
        $lock = $connection->prepare('SELECT id FROM clinics WHERE id=:clinic FOR UPDATE');
        $lock->execute(['clinic' => $clinicId]);
        if ((int)$lock->fetchColumn() !== $clinicId) throw new RuntimeException('Synthetic clinic lock failed.');
        foreach ($jobs as $job) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/booking-race-worker.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start a booking race worker.');
            fwrite($pipes[0], json_encode($credentials + ['body' => $job], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        usleep(300000);
    } finally {
        if ($connection->inTransaction()) $connection->commit();
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $output === '') throw new RuntimeException('Booking race worker failed: ' . substr($error, 0, 500));
        $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$start = $slotAt($locationIds[0], $practitionerIds[0], '14:00');
$first = $race([$body($userIds['client-a'], $locationIds[0], $practitionerIds[0], $roomIds[0], $start, 'same-practitioner-a'), $body($userIds['client-b'], $locationIds[0], $practitionerIds[0], $roomIds[0], $start, 'same-practitioner-b')]);
$assert(count(array_filter($first, static fn(array $row): bool => $row['ok'])) === 1, 'Same-practitioner race did not have one winner.');
$assert(count(array_filter($first, static fn(array $row): bool => !$row['ok'] && ($row['status'] ?? null) === 409)) === 1, 'Same-practitioner loser was not rejected with 409.');

$start = $slotAt($locationIds[0], $practitionerIds[0], '15:00');
$second = $race([$body($userIds['client-a'], $locationIds[0], $practitionerIds[0], $roomIds[0], $start, 'same-room-a'), $body($userIds['client-b'], $locationIds[0], $practitionerIds[1], $roomIds[0], $start, 'same-room-b')]);
$assert(count(array_filter($second, static fn(array $row): bool => $row['ok'])) === 1, 'Same-room race did not have one winner.');
$assert(count(array_filter($second, static fn(array $row): bool => !$row['ok'] && ($row['status'] ?? null) === 409)) === 1, 'Same-room loser was not rejected with 409.');

$start = $slotAt($locationIds[0], $practitionerIds[0], '16:00');
$third = $race([$body($userIds['client-a'], $locationIds[0], $practitionerIds[0], $roomIds[0], $start, 'identical-retry'), $body($userIds['client-a'], $locationIds[0], $practitionerIds[0], $roomIds[0], $start, 'identical-retry')]);
$assert($third[0]['ok'] && $third[1]['ok'] && $third[0]['id'] === $third[1]['id'], 'Identical concurrent retries did not return the same appointment.');

$startA = $slotAt($locationIds[0], $practitionerIds[0], '17:00');
$startB = $slotAt($locationIds[1], $practitionerIds[0], '17:00');
$fourth = $race([$body($userIds['client-a'], $locationIds[0], $practitionerIds[0], $roomIds[0], $startA, 'cross-location-a'), $body($userIds['client-b'], $locationIds[1], $practitionerIds[0], $roomIds[1], $startB, 'cross-location-b')]);
$assert(count(array_filter($fourth, static fn(array $row): bool => $row['ok'])) === 1, 'Cross-location practitioner race did not have one winner.');
$assert(count(array_filter($fourth, static fn(array $row): bool => !$row['ok'] && ($row['status'] ?? null) === 409)) === 1, 'Cross-location loser was not rejected with 409.');

$statement = $connection->prepare("SELECT a.id,a.idempotency_key,(SELECT COUNT(*) FROM appointment_status_history h WHERE h.appointment_id=a.id) history_count,(SELECT COUNT(*) FROM notification_events n WHERE n.appointment_id=a.id) notification_count FROM appointments a WHERE a.clinic_id=:clinic ORDER BY a.id");
$statement->execute(['clinic' => $clinicId]);
$rows = $statement->fetchAll();
$assert(count($rows) === 4, 'Expected exactly four committed appointments.');
foreach ($rows as $row) $assert((int)$row['history_count'] === 1 && (int)$row['notification_count'] === 1, 'A booking had duplicate history or notification rows.');
if (!$closeSyntheticClinic()) throw new RuntimeException('The test passed but synthetic clinic shutdown failed.');
echo "Booking race acceptance passed: four scenarios, four appointments, one history and notification each; synthetic clinic ID {$clinicId} in {$name} is now inactive.\n";
