<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\SmsSendException;
use Wellness\Service\CanadianSmsNumber;
use Wellness\Service\StaffAppointmentSms;
use Wellness\Service\VoipMsSmsClient;

$event = ['event_code' => 'staff_booking_confirmation', 'clinic_name' => 'Willow Wellness Centre', 'client_name' => 'Private Client', 'destination_snapshot' => 'Private Address'];
foreach (['+14166166855', '+12892975234', '+19025551234', '+18675551234'] as $number) {
    if (!CanadianSmsNumber::isAllowed($number)) throw new RuntimeException('Canadian number was rejected: ' . $number);
}
foreach (['+12025551234', '+12125551234', '+12735551234', '+19995551234', '4166166855', '+14161116855'] as $number) {
    if (CanadianSmsNumber::isAllowed($number)) throw new RuntimeException('Non-Canadian or invalid number was accepted: ' . $number);
}
foreach (['staff_booking_confirmation', 'staff_booking_change', 'staff_booking_cancellation'] as $code) {
    $message = StaffAppointmentSms::compose(array_replace($event, ['event_code' => $code]), 'https://portal.copihue.ca/client');
    if (strlen($message) > 160 || !str_contains($message, 'https://portal.copihue.ca/practitioner/schedule')) {
        throw new RuntimeException('Staff SMS length or portal link is wrong.');
    }
    if (str_contains($message, 'Private Client') || str_contains($message, 'Private Address')) {
        throw new RuntimeException('Staff SMS exposed client or visit details.');
    }
}
try {
    StaffAppointmentSms::compose($event, 'http://example.test/client');
    throw new RuntimeException('Insecure portal URL accepted.');
} catch (InvalidArgumentException) {}
try {
    new VoipMsSmsClient('not-an-email', 'secret', '2892975234');
    throw new RuntimeException('Invalid API username accepted.');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Invalid API username accepted.') throw $e;
}
$client = new VoipMsSmsClient('private@example.test', 'secret', '2892975234');
try {
    $client->send('+14166166855', str_repeat('x', 161));
    throw new RuntimeException('Oversize SMS reached transport.');
} catch (SmsSendException) {}
$calls = 0;
$guarded = new VoipMsSmsClient('private@example.test', 'secret', '2892975234', static function (string $body) use (&$calls): array {
    $calls++;
    return [200, '{"status":"success"}'];
});
try {
    $guarded->send('+12025551234', 'Test message');
    throw new RuntimeException('US SMS reached transport.');
} catch (SmsSendException) {}
if ($calls !== 0) throw new RuntimeException('US SMS invoked provider transport.');
$client = new VoipMsSmsClient('private@example.test', 'secret', '2892975234', static function (string $body): array {
    parse_str($body, $fields);
    foreach (['api_username' => 'private@example.test', 'api_password' => 'secret', 'method' => 'sendSMS', 'did' => '2892975234', 'dst' => '+14166166855', 'content_type' => 'json'] as $key => $expected) {
        if (($fields[$key] ?? null) !== $expected) throw new RuntimeException('SMS request field is wrong: ' . $key);
    }
    if (!str_contains($fields['message'] ?? '', 'practitioner/schedule')) throw new RuntimeException('SMS request message is wrong.');
    return [200, '{"status":"success","sms":"12345"}'];
});
if ($client->send('+14166166855', StaffAppointmentSms::compose($event, 'https://portal.copihue.ca/client')) !== '12345') {
    throw new RuntimeException('SMS provider ID was not recorded.');
}
$rejected = new VoipMsSmsClient('private@example.test', 'secret', '2892975234', static fn(string $body): array => [200, '{"status":"permission_denied"}']);
try {
    $rejected->send('+14166166855', 'Test message');
    throw new RuntimeException('Rejected SMS was accepted.');
} catch (SmsSendException) {}
echo "Staff SMS checks passed (no network request made).\n";
