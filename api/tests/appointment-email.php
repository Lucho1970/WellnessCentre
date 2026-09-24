<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\AppointmentEmail;
use Wellness\Service\NotificationWorker;

$event = [
    'event_code' => 'booking_confirmation',
    'clinic_name' => 'Wellness Centre',
    'starts_at' => '2026-09-22 14:00:00',
    'timezone' => 'America/Toronto',
];
$message = AppointmentEmail::compose($event, 'https://portal.copihue.ca/client');
if (!str_contains($message['subject'], 'Appointment confirmed') || !str_contains($message['subject'], 'Rendez-vous confirmé')) throw new RuntimeException('Bilingual subject missing.');
if (!str_contains($message['body'], '2026-09-22 10:00 EDT') || !str_contains($message['body'], 'https://portal.copihue.ca/client')) throw new RuntimeException('Local time or portal link missing.');
if (str_contains($message['body'], 'Massage') || str_contains($message['body'], 'address')) throw new RuntimeException('Unexpected sensitive details.');
foreach (['booking_change', 'booking_cancellation'] as $code) {
    $changed = AppointmentEmail::compose(array_replace($event, ['event_code' => $code]), 'https://portal.copihue.ca/client');
    if ($changed['subject'] === $message['subject']) throw new RuntimeException('Event-specific subject missing.');
}
if ([NotificationWorker::backoff(1), NotificationWorker::backoff(2), NotificationWorker::backoff(3), NotificationWorker::backoff(4)] !== [60, 300, 900, 3600]) throw new RuntimeException('Retry schedule changed.');
echo "Appointment email tests passed.\n";
