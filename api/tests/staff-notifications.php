<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\StaffAppointmentEmail;

$event = [
    'event_code' => 'staff_booking_confirmation',
    'clinic_name' => 'Wellness Centre',
    'starts_at' => '2026-09-22 14:00:00',
    'timezone' => 'America/Toronto',
    'client_name' => 'Private Client',
    'destination_snapshot' => 'Private Address',
];
foreach (['staff_booking_confirmation', 'staff_booking_change', 'staff_booking_cancellation', 'staff_booking_reassigned_away'] as $code) {
    $mail = StaffAppointmentEmail::compose(array_replace($event, ['event_code' => $code]), 'https://willowwellness.copihue.ca/client');
    if (!str_contains($mail['body'], '2026-09-22 10:00 EDT') || !str_contains($mail['body'], 'https://willowwellness.copihue.ca/practitioner/schedule')) throw new RuntimeException('Staff time or portal link is wrong.');
    if (str_contains($mail['body'], 'Private Client') || str_contains($mail['body'], 'Private Address') || str_contains($mail['subject'], 'Private Client')) throw new RuntimeException('Staff notice exposed client or visit details.');
}
$away = StaffAppointmentEmail::compose(array_replace($event, ['event_code' => 'staff_booking_reassigned_away']), 'https://willowwellness.copihue.ca/client');
if (!str_contains($away['body'], 'moved off your schedule') || str_contains($away['body'], 'has been canceled')) throw new RuntimeException('Reassignment notice was confused with cancellation.');
try { StaffAppointmentEmail::compose(array_replace($event, ['event_code' => 'unknown']), 'https://willowwellness.copihue.ca/client'); }
catch (InvalidArgumentException) { echo "Staff notification tests passed.\n"; exit; }
throw new RuntimeException('Unknown staff event accepted.');
