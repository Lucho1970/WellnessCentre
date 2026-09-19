<?php
declare(strict_types=1);

namespace Wellness\Service;

use Closure;
use JsonException;
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

final class AddressCoverageService
{
    private Closure $transport;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? $this->request(...);
    }

    public function validate(AuthContext $actor, array $body): array
    {
        $this->assertStaffCanBook($actor);
        foreach (['location_id', 'service_id', 'practitioner_id'] as $field) {
            if (empty($body[$field])) throw new ApiException(422, 'validation_error', "{$field} is required.", [$field => 'Required']);
        }
        $this->assertConfigured();
        $destination = Delivery::destination(['delivery_mode' => 'mobile', 'destination' => $body['destination'] ?? null]);
        $rule = $this->rule($actor, $body);
        $radius = (float)($rule['mobile_radius_km'] ?? 0);
        if ($radius <= 0) throw new ApiException(422, 'coverage_radius_not_configured', 'On-Site coverage is not configured for this practitioner and service.');

        $origin = [
            'address_line1' => trim((string)($rule['address_line1'] ?? '')),
            'address_line2' => trim((string)($rule['address_line2'] ?? '')),
            'city' => trim((string)($rule['city'] ?? '')),
            'province' => trim((string)($rule['province'] ?? '')),
            'postal_code' => trim((string)($rule['postal_code'] ?? '')),
            'country' => 'Canada',
            'instructions' => '',
        ];
        foreach (['address_line1', 'city', 'province', 'postal_code'] as $field) {
            if ($origin[$field] === '') throw new ApiException(422, 'base_address_incomplete', 'The base location needs a complete address before On-Site coverage can be calculated.');
        }

        $validatedOrigin = $this->validateAddress($origin);
        $validatedDestination = $this->validateAddress($destination);
        $distanceMeters = $this->drivingDistance($validatedOrigin['location'], $validatedDestination['location']);
        $radiusMeters = (int)round($radius * 1000);
        if ($distanceMeters > $radiusMeters) {
            throw new ApiException(422, 'outside_mobile_coverage', sprintf('This address is %.1f km away by road and is outside the %.1f km service area.', $distanceMeters / 1000, $radius));
        }

        $proof = [
            'clinic_id' => $actor->clinicId,
            'user_id' => $actor->userId,
            'location_id' => (int)$body['location_id'],
            'service_id' => (int)$body['service_id'],
            'practitioner_id' => (int)$body['practitioner_id'],
            'destination_hash' => self::destinationHash($destination),
            'distance_meters' => $distanceMeters,
            'radius_meters' => $radiusMeters,
            'iat' => time(),
            'exp' => time() + $this->config->addressValidationTokenTtlSeconds,
        ];

        return [
            'destination' => $destination,
            'distance_km' => round($distanceMeters / 1000, 1),
            'radius_km' => round($radius, 1),
            'token' => $this->sign($proof),
            'expires_at' => gmdate(DATE_ATOM, $proof['exp']),
        ];
    }

    public function verifyBooking(AuthContext $actor, array $body, array $destination): array
    {
        $this->assertConfigured();
        $token = $body['address_validation_token'] ?? null;
        if (!is_string($token) || $token === '') throw new ApiException(422, 'coverage_validation_required', 'Validate the visit address before booking.');
        $parts = explode('.', $token);
        if (count($parts) !== 2) throw new ApiException(422, 'invalid_coverage_validation', 'The address validation is invalid. Validate the address again.');
        [$encoded, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', $encoded, $this->config->addressValidationSigningKey, true));
        if (!hash_equals($expected, $signature)) throw new ApiException(422, 'invalid_coverage_validation', 'The address validation is invalid. Validate the address again.');
        try {
            $proof = json_decode($this->base64UrlDecode($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(422, 'invalid_coverage_validation', 'The address validation is invalid. Validate the address again.');
        }
        $expectedValues = [
            'clinic_id' => $actor->clinicId,
            'user_id' => $actor->userId,
            'location_id' => (int)($body['location_id'] ?? 0),
            'service_id' => (int)($body['service_id'] ?? 0),
            'practitioner_id' => (int)($body['practitioner_id'] ?? 0),
            'destination_hash' => self::destinationHash($destination),
        ];
        foreach ($expectedValues as $key => $value) {
            if (($proof[$key] ?? null) !== $value) throw new ApiException(422, 'coverage_validation_mismatch', 'The booking details changed. Validate the address again.');
        }
        if (!is_int($proof['exp'] ?? null) || $proof['exp'] < time()) throw new ApiException(422, 'coverage_validation_expired', 'The address validation expired. Validate the address again.');
        return $proof;
    }

    private function rule(AuthContext $actor, array $body): array
    {
        $sql = "SELECT ps.mobile_radius_km,l.address_line1,l.address_line2,l.city,l.province,l.postal_code,p.user_id,p.booking_mode
                  FROM practitioner_services ps
                  JOIN services s ON s.id=ps.service_id AND s.clinic_id=:clinic AND s.active=1
                  JOIN service_locations sl ON sl.service_id=s.id AND sl.location_id=:location AND sl.active=1
                  JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id AND l.is_bookable=1
                  JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1
                 WHERE ps.practitioner_id=:practitioner AND ps.service_id=:service AND ps.active=1 AND ps.offers_mobile=1";
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute(['clinic' => $actor->clinicId, 'location' => (int)$body['location_id'], 'practitioner' => (int)$body['practitioner_id'], 'service' => (int)$body['service_id']]);
        $rule = $statement->fetch();
        if (!$rule) throw new ApiException(422, 'delivery_unavailable', 'This practitioner does not offer On-Site visits for the selected service and location.');
        $practitionerOnly = $actor->hasAnyRole('practitioner') && !$actor->hasAnyRole('super_admin', 'clinic_admin', 'reception') && !$actor->hasPermission('schedule_for_other_practitioners');
        if ($practitionerOnly && ((int)$rule['user_id'] !== $actor->userId || $rule['booking_mode'] !== 'practitioner_managed')) {
            throw new ApiException(403, 'forbidden', 'Practitioners can only validate addresses for their own practitioner-managed appointments.');
        }
        return $rule;
    }

    private function validateAddress(array $address): array
    {
        $regionCode = $this->regionCode($address['country']);
        $response = ($this->transport)(
            'https://addressvalidation.googleapis.com/v1:validateAddress',
            ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $this->config->googleMapsApiKey],
            ['address' => ['regionCode' => $regionCode, 'addressLines' => array_values(array_filter([$address['address_line1'], $address['address_line2']])), 'locality' => $address['city'], 'administrativeArea' => $address['province'], 'postalCode' => $address['postal_code']]],
        );
        $result = $response['result'] ?? null;
        $verdict = $result['verdict'] ?? [];
        if (!is_array($result) || ($verdict['addressComplete'] ?? false) !== true || ($verdict['hasUnconfirmedComponents'] ?? false) || ($verdict['hasUnresolvedTokens'] ?? false)) {
            throw new ApiException(422, 'address_not_confirmed', 'Google could not confirm this complete address. Check the street, city, province, and postal code.');
        }
        $postal = $result['address']['postalAddress'] ?? null;
        $location = $result['geocode']['location'] ?? null;
        if (!is_array($postal) || !is_numeric($location['latitude'] ?? null) || !is_numeric($location['longitude'] ?? null)) {
            throw new ApiException(503, 'address_validation_unavailable', 'Address validation is temporarily unavailable. Please try again.');
        }
        $lines = array_values(array_filter(array_map('trim', $postal['addressLines'] ?? [])));
        return ['location' => ['latitude' => (float)$location['latitude'], 'longitude' => (float)$location['longitude']], 'address' => [
            'address_line1' => $lines[0] ?? $address['address_line1'],
            'address_line2' => count($lines) > 1 ? implode(', ', array_slice($lines, 1)) : '',
            'city' => trim((string)($postal['locality'] ?? $address['city'])),
            'province' => trim((string)($postal['administrativeArea'] ?? $address['province'])),
            'postal_code' => trim((string)($postal['postalCode'] ?? $address['postal_code'])),
            'country' => $regionCode === 'CA' ? 'Canada' : trim((string)$address['country']),
        ]];
    }

    private function drivingDistance(array $origin, array $destination): int
    {
        $response = ($this->transport)(
            'https://routes.googleapis.com/directions/v2:computeRoutes',
            ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $this->config->googleMapsApiKey, 'X-Goog-FieldMask: routes.distanceMeters'],
            ['origin' => ['location' => ['latLng' => $origin]], 'destination' => ['location' => ['latLng' => $destination]], 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE'],
        );
        $distance = $response['routes'][0]['distanceMeters'] ?? null;
        if (!is_numeric($distance)) throw new ApiException(422, 'route_not_found', 'No driving route could be calculated for this address.');
        return (int)round((float)$distance);
    }

    private function request(string $url, array $headers, array $payload): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new ApiException(503, 'address_validation_unavailable', 'Address validation is temporarily unavailable. Please try again.');
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log('Google Maps request failed status=' . $status . ($error !== '' ? ' curl=' . $error : ''));
            throw new ApiException(503, 'address_validation_unavailable', 'Address validation is temporarily unavailable. Please try again.');
        }
        try { $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiException(503, 'address_validation_unavailable', 'Address validation is temporarily unavailable. Please try again.'); }
        if (!is_array($decoded)) throw new ApiException(503, 'address_validation_unavailable', 'Address validation is temporarily unavailable. Please try again.');
        return $decoded;
    }

    private function assertStaffCanBook(AuthContext $actor): void
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin', 'reception', 'practitioner')) throw new ApiException(403, 'forbidden', 'Your role cannot validate On-Site visit addresses.');
    }

    private function assertConfigured(): void
    {
        if ($this->config->googleMapsApiKey === '' || strlen($this->config->addressValidationSigningKey) < 32) throw new ApiException(503, 'address_validation_not_configured', 'Address validation is not configured.');
    }

    private function sign(array $proof): string
    {
        $encoded = $this->base64Url(json_encode($proof, JSON_THROW_ON_ERROR));
        return $encoded . '.' . $this->base64Url(hash_hmac('sha256', $encoded, $this->config->addressValidationSigningKey, true));
    }

    private static function destinationHash(array $destination): string
    {
        return hash('sha256', json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function regionCode(string $country): string
    {
        $normalized = strtoupper(trim($country));
        return match ($normalized) { 'CANADA', 'CA' => 'CA', 'UNITED STATES', 'UNITED STATES OF AMERICA', 'USA', 'US' => 'US', default => strlen($normalized) === 2 ? $normalized : throw new ApiException(422, 'unsupported_country', 'Enter a two-letter country code or a supported country name.') };
    }

    private function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new ApiException(422, 'invalid_coverage_validation', 'The address validation is invalid. Validate the address again.');
        return $decoded;
    }
}
