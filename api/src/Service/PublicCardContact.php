<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Http\ApiException;

final class PublicCardContact
{
    /** @return array{email:?string,phone:?string,sms:int,website:?string} */
    public static function fromInput(array $body): array
    {
        if (isset($body['public_website_url']) && !is_string($body['public_website_url'])) {
            throw new ApiException(422, 'validation_error', 'Enter a valid website or social page URL.', ['public_website_url' => 'Invalid URL']);
        }
        $website = trim($body['public_website_url'] ?? '');
        $parts = parse_url($website);
        if ($website !== '' && (strlen($website) > 2048 || filter_var($website, FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']))) {
            throw new ApiException(422, 'validation_error', 'Enter a valid website or social page URL starting with https:// or http://.', ['public_website_url' => 'Invalid URL']);
        }
        $email = strtolower(trim((string)($body['public_contact_email'] ?? '')));
        $phone = trim((string)($body['public_contact_phone'] ?? ''));
        $sms = ($body['public_contact_sms'] ?? false) === true || ($body['public_contact_sms'] ?? false) === 1;
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ApiException(422, 'validation_error', 'Enter a valid public contact email.', ['public_contact_email' => 'Invalid email']);
        }
        if ($phone !== '' && preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            throw new ApiException(422, 'validation_error', 'Use an international public phone number, for example +12892975234.', ['public_contact_phone' => 'Use + and 8 to 15 digits']);
        }
        if ($sms && $phone === '') {
            throw new ApiException(422, 'validation_error', 'A public phone number is required to offer text messaging.', ['public_contact_sms' => 'Phone required']);
        }
        return ['email' => $email === '' ? null : $email, 'phone' => $phone === '' ? null : $phone, 'sms' => $sms ? 1 : 0, 'website' => $website === '' ? null : $website];
    }
}
