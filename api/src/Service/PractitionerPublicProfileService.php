<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class PractitionerPublicProfileService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public function get(AuthContext $actor): array
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('practitioner')) {
            throw new ApiException(403, 'forbidden', 'Practitioner access is required.');
        }
        $query = $this->database->connection()->prepare("SELECT t.user_id,t.public_name,t.booking_name,t.summary,t.summary_fr,
                t.public_website_url,t.public_contact_email,t.public_contact_phone,t.public_contact_sms,t.published,t.slug
            FROM public_team_profiles t JOIN users u ON u.id=t.user_id AND u.clinic_id=t.clinic_id AND u.status='active'
            JOIN practitioners p ON p.user_id=u.id AND p.active=1
            WHERE t.user_id=:user AND t.clinic_id=:clinic AND t.section='practitioner'");
        $query->execute(['user' => $actor->userId, 'clinic' => $actor->clinicId]);
        $profile = $query->fetch();
        if (!$profile) throw new ApiException(404, 'profile_not_found', 'Ask an administrator to create your public practitioner profile.');
        $profile['published'] = (bool)$profile['published'];
        $profile['public_contact_sms'] = (bool)$profile['public_contact_sms'];
        return $profile;
    }

    public function update(AuthContext $actor, array $body, string $correlationId): array
    {
        $this->get($actor);
        $name = trim((string)($body['public_name'] ?? ''));
        $bookingName = trim((string)($body['booking_name'] ?? ''));
        $summary = trim((string)($body['summary'] ?? ''));
        $summaryFr = trim((string)($body['summary_fr'] ?? ''));
        if ($name === '' || strlen($name) > 150 || $bookingName === '' || strlen($bookingName) > 100) {
            throw new ApiException(422, 'validation_error', 'Enter a valid preferred public name and booking name.');
        }
        if (strlen($summary) > 1000 || strlen($summaryFr) > 1000) {
            throw new ApiException(422, 'validation_error', 'Public biographies may contain up to 1000 characters.');
        }
        $contact = PublicCardContact::fromInput($body);
        $statement = $this->database->connection()->prepare("UPDATE public_team_profiles SET public_name=:name,booking_name=:booking,
            summary=:summary,summary_fr=:summary_fr,public_website_url=:website,public_contact_email=:email,public_contact_phone=:phone,
            public_contact_sms=:sms,updated_by=:actor WHERE user_id=:user AND clinic_id=:clinic AND section='practitioner'");
        $statement->execute([
            'name' => $name, 'booking' => $bookingName,
            'summary' => $summary === '' ? null : $summary, 'summary_fr' => $summaryFr === '' ? null : $summaryFr,
            'website' => $contact['website'], 'email' => $contact['email'], 'phone' => $contact['phone'], 'sms' => $contact['sms'],
            'actor' => $actor->userId, 'user' => $actor->userId, 'clinic' => $actor->clinicId,
        ]);
        $this->audit->write($actor->clinicId, $actor, $correlationId, 'public_profile.self_update', 'user', $actor->userId, 'success', [
            'public_website' => $contact['website'] !== null, 'public_email' => $contact['email'] !== null, 'public_phone' => $contact['phone'] !== null, 'public_sms' => $contact['sms'] === 1,
        ]);
        return $this->get($actor);
    }
}
