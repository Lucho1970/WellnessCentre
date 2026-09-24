<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class StaffAppointmentEmail
{
    /** @return array{subject:string,body:string} */
    public static function compose(array $event, string $clientPortalUrl): array
    {
        $words = match ((string)($event['event_code'] ?? '')) {
            'staff_booking_confirmation' => ['New appointment', 'Nouveau rendez-vous', 'An appointment has been booked for you.', 'Un rendez-vous a été réservé pour vous.'],
            'staff_booking_change' => ['Appointment changed', 'Rendez-vous modifié', 'An appointment on your schedule has changed.', 'Un rendez-vous dans votre horaire a été modifié.'],
            'staff_booking_cancellation' => ['Appointment canceled', 'Rendez-vous annulé', 'An appointment on your schedule has been canceled.', 'Un rendez-vous dans votre horaire a été annulé.'],
            default => throw new InvalidArgumentException('Unsupported staff notification event.'),
        };
        $clinic = trim((string)($event['clinic_name'] ?? ''));
        $parts = parse_url($clientPortalUrl);
        if ($clinic === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) throw new InvalidArgumentException('Staff notification configuration is invalid.');
        $timezone = new DateTimeZone((string)($event['timezone'] ?? 'America/Toronto'));
        $start = new DateTimeImmutable((string)$event['starts_at'], new DateTimeZone('UTC'));
        $local = $start->setTimezone($timezone)->format('Y-m-d H:i T');
        $portal = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/practitioner/schedule';
        return [
            'subject' => $clinic . ' — ' . $words[0] . ' / ' . $words[1],
            'body' => $words[2] . "\n" . 'Time: ' . $local . "\n" . 'Sign in to review the details: ' . $portal . "\n\n" . $words[3] . "\n" . 'Heure : ' . $local . "\n" . 'Ouvrez une session pour voir les détails : ' . $portal . "\n\n" . $clinic,
        ];
    }
}
