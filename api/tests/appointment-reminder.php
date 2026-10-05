<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\AppointmentReminderQueue;

$now = new DateTimeImmutable('2026-09-28 12:00:00', new DateTimeZone('UTC'));
if (AppointmentReminderQueue::scheduledAt('2026-09-30 15:30:00', 1440, $now) !== '2026-09-29 15:30:00') throw new RuntimeException('Reminder time must be UTC and 24 hours before the booking.');
if (AppointmentReminderQueue::scheduledAt('2026-09-29 12:00:00', 1440, $now) !== null) throw new RuntimeException('Do not queue a reminder whose lead time has elapsed.');
if (AppointmentReminderQueue::scheduledAt('2026-09-30 15:30:00', 0, $now) !== null) throw new RuntimeException('Zero-minute reminder is invalid.');
echo "Appointment reminder tests passed.\n";
