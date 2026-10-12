<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AppointmentEmail
{
    /** @return array{subject:string,body:string} */
    public static function compose(array $event, string $clientPortalUrl): array
    {
        $code = (string)($event['event_code'] ?? '');
        $words = match ($code) {
            'booking_confirmation' => ['Appointment confirmed', 'Rendez-vous confirmé', 'Your appointment is confirmed.', 'Votre rendez-vous est confirmé.'],
            'booking_change' => ['Appointment changed', 'Rendez-vous modifié', 'Your appointment has changed.', 'Votre rendez-vous a été modifié.'],
            'booking_cancellation' => ['Appointment canceled', 'Rendez-vous annulé', 'Your appointment has been canceled.', 'Votre rendez-vous a été annulé.'],
            'appointment_reminder' => ['Appointment reminder', 'Rappel de rendez-vous', 'Your appointment is coming up.', 'Votre rendez-vous approche.'],
            default => throw new InvalidArgumentException('Unsupported notification event.'),
        };
        $clinic = trim((string)($event['clinic_name'] ?? ''));
        if ($clinic === '') throw new InvalidArgumentException('Clinic name is missing.');
        $timezone = new DateTimeZone((string)($event['timezone'] ?? 'America/Toronto'));
        $start = new DateTimeImmutable((string)$event['starts_at'], new DateTimeZone('UTC'));
        $local = $start->setTimezone($timezone)->format('Y-m-d H:i T');
        $url = rtrim($clientPortalUrl, '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) throw new InvalidArgumentException('Client portal URL must be HTTPS.');
        $calendarNote = $code === 'appointment_reminder' ? '' : 'A calendar file is attached. Your calendar may ask you to add or accept it.' . "\n";
        $calendarNoteFr = $code === 'appointment_reminder' ? '' : 'Un fichier de calendrier est joint. Votre calendrier pourrait vous demander de l’ajouter ou de l’accepter.' . "\n";
        $payload = json_decode((string)($event['payload'] ?? ''), true);
        $reassigned = $code === 'booking_change' && is_array($payload) && ($payload['change_type'] ?? '') === 'practitioner_reassignment';
        $practitioner = $reassigned ? trim((string)($payload['practitioner_name'] ?? '')) : '';
        $practitioner = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $practitioner));
        $practitionerLine = $reassigned && $practitioner !== '' ? 'Your appointment is now with ' . $practitioner . ".\n" : '';
        $practitionerLineFr = $reassigned && $practitioner !== '' ? 'Votre rendez-vous est maintenant avec ' . $practitioner . ".\n" : '';
        $actionUrl = $event['appointment_action_url'] ?? null;
        if ($actionUrl !== null && (!is_string($actionUrl) || !preg_match('#^' . preg_quote($url . '/appointment#token=', '#') . '[a-f0-9]{64}$#D', $actionUrl))) {
            throw new InvalidArgumentException('Invalid appointment action URL.');
        }
        $actionLine = $actionUrl !== null && $code !== 'booking_cancellation' ? 'Review, reschedule or cancel (client sign-in required): ' . $actionUrl . "\n" : '';
        $actionLineFr = $actionUrl !== null && $code !== 'booking_cancellation' ? 'Voir, reporter ou annuler (connexion client requise) : ' . $actionUrl . "\n" : '';
        $body = $words[2] . "\n" . $practitionerLine . 'Appointment: ' . $local . "\n"
            . $calendarNote
            . $actionLine
            . 'Sign in to review your appointment: ' . $url . "\n\n"
            . $words[3] . "\n" . $practitionerLineFr . 'Rendez-vous : ' . $local . "\n"
            . $calendarNoteFr
            . $actionLineFr
            . 'Ouvrez une session pour voir votre rendez-vous : ' . $url . "\n\n"
            . $clinic;
        return ['subject' => $clinic . ' — ' . $words[0] . ' / ' . $words[1], 'body' => $body];
    }
}
