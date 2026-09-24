<?php
declare(strict_types=1);

namespace Wellness\Service;

use InvalidArgumentException;

final class StaffAppointmentSms
{
    public static function compose(array $event, string $clientPortalUrl): string
    {
        $text = match ((string)($event['event_code'] ?? '')) {
            'staff_booking_confirmation' => 'New booking / Nouveau rendez-vous.',
            'staff_booking_change' => 'Booking changed / Rendez-vous modifie.',
            'staff_booking_cancellation' => 'Booking canceled / Rendez-vous annule.',
            default => throw new InvalidArgumentException('Unsupported staff SMS event.'),
        };
        $parts = parse_url($clientPortalUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Staff SMS portal URL is invalid.');
        }
        $clinic = trim((string)($event['clinic_name'] ?? ''));
        $clinic = trim((string)preg_replace('/[^\x20-\x7E]/', '', $clinic));
        if ($clinic === '') throw new InvalidArgumentException('Staff SMS clinic name is invalid.');
        $clinic = substr($clinic, 0, 35);
        $portal = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/practitioner/schedule';
        $message = $clinic . ': ' . $text . ' View / Voir: ' . $portal;
        if (strlen($message) > 160) throw new InvalidArgumentException('Staff SMS exceeds 160 characters.');
        return $message;
    }
}
