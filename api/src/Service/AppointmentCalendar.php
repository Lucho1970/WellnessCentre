<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A privacy-minimal iCalendar event; the appointment database remains authoritative. */
final class AppointmentCalendar
{
    public static function compose(array $appointment, string $portalUrl, string $method = 'REQUEST', ?string $organizer = null, ?string $attendee = null): string
    {
        if (!in_array($method, ['REQUEST', 'CANCEL'], true)) throw new InvalidArgumentException('Unsupported calendar method.');
        $clinicId = filter_var($appointment['clinic_id'] ?? null, FILTER_VALIDATE_INT);
        $appointmentId = filter_var($appointment['appointment_id'] ?? $appointment['id'] ?? null, FILTER_VALIDATE_INT);
        $version = filter_var($appointment['version'] ?? null, FILTER_VALIDATE_INT);
        if (!$clinicId || !$appointmentId || !$version || $clinicId < 1 || $appointmentId < 1 || $version < 1) throw new InvalidArgumentException('Calendar identity is incomplete.');
        $start = self::utc((string)($appointment['starts_at'] ?? ''));
        $end = self::utc((string)($appointment['ends_at'] ?? ''));
        if ($end <= $start) throw new InvalidArgumentException('Calendar end must follow the start.');
        $url = rtrim($portalUrl, '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) throw new InvalidArgumentException('Client portal URL must be HTTPS.');
        if ($organizer !== null && !filter_var($organizer, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Calendar organizer is invalid.');
        if ($attendee !== null && !filter_var($attendee, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Calendar attendee is invalid.');

        // The namespace never changes with a future public-domain migration.
        $uid = hash('sha256', "wellness-calendar-v1/{$clinicId}/{$appointmentId}") . '@calendar.copihue.ca';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Wellness Centre//Appointments//EN',
            'CALSCALE:GREGORIAN', 'METHOD:' . $method, 'BEGIN:VEVENT',
            'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'), 'DTEND:' . $end->format('Ymd\THis\Z'),
            'SEQUENCE:' . ($version - 1),
            'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:Appointment / Rendez-vous',
            'DESCRIPTION:' . self::escape('Sign in to review / Ouvrez une session pour voir : ' . $url),
            'URL:' . self::escape($url),
        ];
        if ($organizer !== null) $lines[] = 'ORGANIZER:mailto:' . $organizer;
        if ($attendee !== null) $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $attendee;
        if ($method === 'REQUEST') {
            // Separate display alarms are requested 24 hours and one hour before
            // the start. Keep the reminder text as privacy-minimal as the event.
            foreach (['-P1D', '-PT1H'] as $trigger) {
                array_push($lines,
                    'BEGIN:VALARM',
                    'ACTION:DISPLAY',
                    'DESCRIPTION:Appointment reminder / Rappel de rendez-vous',
                    'TRIGGER:' . $trigger,
                    'END:VALARM',
                );
            }
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    private static function utc(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('Calendar time is invalid.');
        return $date;
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
    }

    private static function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $out .= substr($line, 0, 75) . "\r\n ";
            $line = substr($line, 75);
        }
        return $out . $line;
    }
}
