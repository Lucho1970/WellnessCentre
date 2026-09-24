<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\AppointmentCalendar;
use Wellness\Service\GraphMailClient;

$appointment = [
    'id' => 42,
    'clinic_id' => 7,
    'version' => 1,
    'starts_at' => '2026-09-24 14:00:00',
    'ends_at' => '2026-09-24 15:00:00',
    'client_name' => 'Private Client',
    'destination_snapshot' => 'Private Address',
];
$url = 'https://wellness.copihue.ca/client';
$initial = AppointmentCalendar::compose($appointment, $url, 'REQUEST', 'wellness@example.com', 'client@example.com');
$changed = AppointmentCalendar::compose(array_replace($appointment, ['version' => 2, 'starts_at' => '2026-09-25 14:00:00', 'ends_at' => '2026-09-25 15:00:00']), $url);
$canceled = AppointmentCalendar::compose(array_replace($appointment, ['version' => 3]), $url, 'CANCEL');

preg_match('/^UID:(.+)\r?$/m', $initial, $firstUid);
preg_match('/^UID:(.+)\r?$/m', $changed, $changedUid);
preg_match('/^UID:(.+)\r?$/m', $canceled, $canceledUid);
if (empty($firstUid[1]) || $firstUid[1] !== $changedUid[1] || $firstUid[1] !== $canceledUid[1]) throw new RuntimeException('Calendar UID must survive changes and cancellation.');
foreach ([[$initial, 'SEQUENCE:0'], [$changed, 'SEQUENCE:1'], [$canceled, 'SEQUENCE:2']] as [$calendar, $sequence]) {
    if (!str_contains($calendar, $sequence)) throw new RuntimeException('Calendar sequence did not advance.');
    if (preg_match('/(?<!\r)\n/', $calendar)) throw new RuntimeException('Calendar must use CRLF.');
    if (str_contains($calendar, 'Private Client') || str_contains($calendar, 'Private Address')) throw new RuntimeException('Calendar leaked private details.');
}
if (!str_contains($initial, 'DTSTART:20260924T140000Z') || !str_contains($initial, 'DTEND:20260924T150000Z')) throw new RuntimeException('Calendar UTC times are wrong.');
if (!str_contains($initial, 'METHOD:REQUEST') || !str_contains($canceled, 'METHOD:CANCEL') || !str_contains($canceled, 'STATUS:CANCELLED')) throw new RuntimeException('Calendar method is wrong.');
foreach ([$initial, $changed] as $calendar) {
    if (substr_count($calendar, "BEGIN:VALARM\r\n") !== 2 || substr_count($calendar, "END:VALARM\r\n") !== 2) throw new RuntimeException('Calendar request must include two reminders.');
    foreach (['-P1D', '-PT1H'] as $trigger) {
        if (!str_contains($calendar, "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Appointment reminder / Rappel de rendez-vous\r\nTRIGGER:{$trigger}\r\nEND:VALARM\r\n")) {
            throw new RuntimeException('Calendar reminder is missing or malformed.');
        }
    }
}
if (str_contains($canceled, 'BEGIN:VALARM') || str_contains($canceled, 'TRIGGER:')) throw new RuntimeException('Cancelled events must not request reminders.');
$unfolded = str_replace("\r\n ", '', $initial);
if (!str_contains($unfolded, 'ORGANIZER:mailto:wellness@example.com') || !str_contains($unfolded, 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:client@example.com')) throw new RuntimeException('Calendar parties are missing.');

$message = GraphMailClient::mailPayload('client@example.com', 'Appointment', 'Body', $initial);
if (base64_decode($message['message']['attachments'][0]['contentBytes'] ?? '', true) !== $initial) throw new RuntimeException('Calendar mail attachment is wrong.');
if (isset(GraphMailClient::mailPayload('client@example.com', 'Appointment', 'Body')['message']['attachments'])) throw new RuntimeException('Optional attachment should be absent.');

foreach ([['starts_at' => 'invalid'], ['ends_at' => '2026-09-24 13:00:00'], ['clinic_id' => 0]] as $invalid) {
    try { AppointmentCalendar::compose(array_replace($appointment, $invalid), $url); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Invalid appointment was accepted.');
}
echo "Appointment calendar tests passed.\n";
