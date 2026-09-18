<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Wellness\Service\AddressCoverageService;

$checks = 0;
function coverageCheck(bool $condition): void { global $checks; if (!$condition) throw new RuntimeException('Address coverage assertion failed'); $checks++; }
function coverageRejected(callable $call, string $code): void { try { $call(); } catch (ApiException $error) { coverageCheck($error->errorCode === $code); return; } throw new RuntimeException('Invalid coverage proof accepted'); }

$config = new Config('test', false, 'test', [], '', 3306, '', '', '', '', '', '', 3600,
    googleMapsApiKey: 'test-google-key', addressValidationSigningKey: str_repeat('k', 32));
$service = new AddressCoverageService(new Database($config), $config);
$actor = new AuthContext(7, 1, 'object', 'staff@example.test', 'Staff', 'staff', ['reception']);
$destination = ['address_line1' => '123 Test Street', 'address_line2' => '', 'city' => 'Test City', 'province' => 'Ontario', 'postal_code' => 'A1A 1A1', 'country' => 'Canada', 'instructions' => 'Side entrance'];
$body = ['location_id' => 2, 'service_id' => 3, 'practitioner_id' => 4, 'address_validation_token' => ''];

$hash = new ReflectionMethod(AddressCoverageService::class, 'destinationHash');
$sign = new ReflectionMethod(AddressCoverageService::class, 'sign');
$proof = ['clinic_id' => 1, 'user_id' => 7, 'location_id' => 2, 'service_id' => 3, 'practitioner_id' => 4, 'destination_hash' => $hash->invoke(null, $destination), 'distance_meters' => 8400, 'radius_meters' => 25000, 'iat' => time(), 'exp' => time() + 900];
$body['address_validation_token'] = $sign->invoke($service, $proof);

coverageCheck($service->verifyBooking($actor, $body, $destination)['distance_meters'] === 8400);
$changed = $destination; $changed['postal_code'] = 'B2B 2B2';
coverageRejected(fn() => $service->verifyBooking($actor, $body, $changed), 'coverage_validation_mismatch');
$wrongService = $body; $wrongService['service_id'] = 99;
coverageRejected(fn() => $service->verifyBooking($actor, $wrongService, $destination), 'coverage_validation_mismatch');
$tampered = $body; $tampered['address_validation_token'][5] = $tampered['address_validation_token'][5] === 'a' ? 'b' : 'a';
coverageRejected(fn() => $service->verifyBooking($actor, $tampered, $destination), 'invalid_coverage_validation');
$proof['exp'] = time() - 1; $expired = $body; $expired['address_validation_token'] = $sign->invoke($service, $proof);
coverageRejected(fn() => $service->verifyBooking($actor, $expired, $destination), 'coverage_validation_expired');

echo "{$checks} address coverage proof checks passed.\n";
