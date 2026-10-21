<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Http\ApiException;

final class OnsiteFee
{
    public static function fingerprint(array $rule): string
    {
        return hash('sha256', json_encode([
            isset($rule['mobile_free_radius_km']) ? (int)$rule['mobile_free_radius_km'] : null,
            (int)($rule['mobile_radius_km'] ?? 0), (int)$rule['mobile_fee_cents'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function quote(array $rule, ?int $meters): int
    {
        if (!isset($rule['mobile_free_radius_km'])) return (int)$rule['mobile_fee_cents'];
        if ($meters === null || $meters < 0) throw new ApiException(422, 'coverage_validation_required', 'Validate the address again to calculate the travel fee.');
        if ($meters > (int)$rule['mobile_radius_km'] * 1000) throw new ApiException(422, 'outside_mobile_coverage', 'This address is outside the On-Site service area.');
        return $meters <= (int)$rule['mobile_free_radius_km'] * 1000 ? 0 : (int)$rule['mobile_fee_cents'];
    }
}
