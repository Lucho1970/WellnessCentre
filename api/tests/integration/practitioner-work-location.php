<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AddressCoverageService;
use Wellness\Service\AuditLogger;
use Wellness\Service\PractitionerWorkLocationService;

if (getenv('RECURRING_TEST_ALLOW_CREATE') !== 'true') throw new RuntimeException('Use the isolated local SQL runner.');
$port = (int)(getenv('RECURRING_TEST_PORT') ?: 13317);
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", getenv('RECURRING_TEST_USER') ?: 'root', getenv('RECURRING_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'wellness_work_location_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4; USE `$name`");
$schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
$schema = substr($schema, 0, strpos($schema, '-- Private practitioner addresses;'));
$pdo->exec(preg_replace('/^(CREATE DATABASE|USE ).*;\r?$/m', '', $schema));
$pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/038_practitioner_private_locations.sql'));
$pdo->exec("INSERT INTO clinics(id,name) VALUES(1,'Test A'),(2,'Test B');
 INSERT INTO locations(id,clinic_id,name,address_line1,city,province,postal_code) VALUES(1,1,'Public clinic','1 Public Street','Toronto','Ontario','M1M 1M1');
 INSERT INTO users(id,clinic_id,email,display_name,user_type,status) VALUES(1,1,'p@example.test','P','staff','active'),(2,1,'client@example.test','C','client','active'),(3,2,'other@example.test','Other','staff','active');
 INSERT INTO practitioners(id,user_id,discipline,booking_mode) VALUES(1,1,'Massage','practitioner_managed'),(2,3,'Massage','practitioner_managed');
 INSERT INTO services(id,clinic_id,slug,name,price_cents) VALUES(1,1,'massage','Massage',10000);
 INSERT INTO practitioner_services(practitioner_id,service_id,offers_mobile,mobile_radius_km) VALUES(1,1,1,25);
 INSERT INTO service_locations(service_id,location_id) VALUES(1,1);");
$config = new Config('test', false, 'test', [], '127.0.0.1', $port, $name, '', '', '', '', '', 300, googleMapsApiKey: 'synthetic', addressValidationSigningKey: str_repeat('k', 32));
$db = new Database($config); (new ReflectionProperty(Database::class, 'connection'))->setValue($db, $pdo);
$service = new PractitionerWorkLocationService($db, new AuditLogger($db));
$staff = new AuthContext(1, 1, '', '', '', 'staff', ['practitioner']);
$client = new AuthContext(2, 1, '', '', '', 'client', []);
$wrongClinic = new AuthContext(1, 2, '', '', '', 'staff', ['practitioner']);
$checks = 0;
$check = function (bool $ok) use (&$checks) { if (!$ok) throw new RuntimeException('Private location assertion failed at ' . ($checks + 1)); $checks++; };
$deny = function (callable $action, string $code) use ($check) { try { $action(); } catch (ApiException $error) { $check($error->errorCode === $code); return; } throw new RuntimeException('Expected rejection: ' . $code); };
$address = ['address_line1' => '12 Private Street', 'address_line2' => '', 'city' => 'Toronto', 'province' => 'Ontario', 'postal_code' => 'M2M 2M2', 'country' => 'Canada'];
$check($service->get($staff)['version'] === 0);
$deny(fn() => $service->get($client), 'forbidden');
$deny(fn() => $service->save($client, [], 'test'), 'forbidden');
$deny(fn() => $service->get($wrongClinic), 'forbidden');
$deny(fn() => $service->get(new AuthContext(1, 1, '', '', '', 'staff', ['super_admin'])), 'forbidden');
$deny(fn() => $service->save($staff, ['version' => 0, 'work_same_as_home' => true], 'test'), 'validation_error');
$saved = $service->save($staff, ['version' => 0, 'work_same_as_home' => true, 'home_address' => $address, 'work_address' => ['address_line1' => 'IGNORED']], 'test');
$check($saved['home_address'] === $address && $saved['work_address'] === null && $saved['work_same_as_home'] && $saved['version'] === 1);
$check(!str_contains(json_encode($pdo->query('SELECT metadata FROM audit_logs')->fetchAll()), 'Private Street'));
$deny(fn() => $service->save($staff, ['version' => 0, 'work_same_as_home' => true, 'home_address' => $address], 'test'), 'work_location_changed');
$check($service->get(new AuthContext(3, 2, '', '', '', 'staff', ['practitioner']))['home_address'] === null);

$requests = []; $meters = 8000;
$transport = function (string $url, array $headers, array $payload) use (&$requests, &$meters) {
    $requests[] = $payload;
    if (str_contains($url, 'computeRoutes')) return ['routes' => [['distanceMeters' => $meters]]];
    return ['result' => ['verdict' => ['addressComplete' => true], 'address' => ['postalAddress' => $payload['address']], 'geocode' => ['location' => ['latitude' => 43.7, 'longitude' => -79.4]]]];
};
$coverage = new AddressCoverageService($db, $config, $transport);
$destination = array_replace($address, ['address_line1' => '80 Client Street', 'instructions' => '']);
$body = ['location_id' => 1, 'service_id' => 1, 'practitioner_id' => 1, 'destination' => $destination];
$result = $coverage->validate($client, $body);
$check($requests[0]['address']['addressLines'] === ['12 Private Street']);
$check($result['covered'] && !isset($result['distance_km']) && !isset($result['origin']));
$proof = json_decode(base64_decode(strtr(explode('.', $result['token'])[0], '-_', '+/')), true);
$check(!isset($proof['distance_meters']) && !str_contains(json_encode($proof), 'Private Street'));
$hash = new ReflectionMethod(AddressCoverageService::class, 'addressHash');
$check($proof['origin_hash'] !== $hash->invoke(null, $address));
$coverage->verifyBooking($client, $body + ['address_validation_token' => $result['token']], $destination); $check(true);
$staffResult = $coverage->validate($staff, $body);
$check($staffResult['distance_km'] === 8.0);
$coverage->approve($staff = new AuthContext(1, 1, '', '', '', 'staff', ['practitioner'], ['approve_onsite_service_area']), $body + ['client_id' => 2, 'address_validation_token' => $staffResult['token']], 'test');
$check($coverage->approvalStatus($client, $body)['approved']);

$homeMoved = array_replace($address, ['address_line1' => '14 Private Street']);
$service->save($staff, ['version' => 1, 'work_same_as_home' => true, 'home_address' => $homeMoved], 'test');
$deny(fn() => $coverage->verifyBooking($client, $body + ['address_validation_token' => $result['token']], $destination), 'coverage_validation_mismatch');
$check(!$coverage->approvalStatus($client, $body)['approved']);
$requests = []; $coverage->validate($client, $body); $check($requests[0]['address']['addressLines'] === ['14 Private Street']);
$work = array_replace($address, ['address_line1' => '99 Office Street']);
$service->save($staff, ['version' => 2, 'work_same_as_home' => false, 'home_address' => $homeMoved, 'work_address' => $work], 'test');
$requests = []; $coverage->validate($client, $body); $check($requests[0]['address']['addressLines'] === ['99 Office Street']);
$service->save($staff, ['version' => 3, 'work_same_as_home' => true, 'home_address' => $address], 'test');
$check(!$coverage->approvalStatus($client, $body)['approved']);
$meters = 26000;
try { $coverage->validate($client, $body); throw new RuntimeException('Outside area accepted'); }
catch (ApiException $error) { $check($error->errorCode === 'outside_mobile_coverage' && !str_contains($error->getMessage(), '26') && !str_contains($error->getMessage(), 'Private Street')); }
$meters = 8000; $pdo->exec('DELETE FROM practitioner_private_locations WHERE practitioner_id=1');
$requests = []; $coverage->validate($client, $body); $check($requests[0]['address']['addressLines'] === ['1 Public Street']);
echo "$checks real SQL private work location checks passed. Synthetic database: $name\n";
