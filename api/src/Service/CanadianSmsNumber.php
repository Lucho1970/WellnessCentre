<?php
declare(strict_types=1);

namespace Wellness\Service;

final class CanadianSmsNumber
{
    // Canadian Numbering Administrator geographic NPAs, checked 2026-09-24.
    // https://cnac.ca/co_codes/co_code_status.htm
    // 273 is listed for future relief planning, so it is intentionally excluded.
    // Unknown/new NPAs fail closed until this list is reviewed and updated.
    private const AREA_CODES = [
        '204', '226', '236', '249', '250', '257', '263', '289', '306', '343',
        '354', '365', '367', '368', '382', '403', '416', '418', '428', '431',
        '437', '438', '450', '468', '474', '506', '514', '519', '548', '579',
        '581', '584', '587', '604', '613', '639', '647', '672', '683', '705',
        '709', '742', '753', '778', '780', '782', '807', '819', '825', '867',
        '873', '879', '902', '905', '942',
    ];

    public static function isAllowed(string $number): bool
    {
        return preg_match('/^\+1[2-9]\d{2}[2-9]\d{6}$/', $number) === 1
            && in_array(substr($number, 2, 3), self::AREA_CODES, true);
    }
}
