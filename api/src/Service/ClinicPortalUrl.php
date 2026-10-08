<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use RuntimeException;

final class ClinicPortalUrl
{
    public static function resolve(PDO $pdo,int $clinicId,string $fallback,?bool $enabled=null): string
    {
        // Disabled deployments retain the existing URL contract and schema.
        if (!($enabled??filter_var($_ENV['CLINIC_MANAGEMENT_ENABLED']??getenv('CLINIC_MANAGEMENT_ENABLED')?:'false',FILTER_VALIDATE_BOOL))) return $fallback;
        $query=$pdo->prepare('SELECT h.host FROM clinic_hosts h JOIN clinics c ON c.id=h.clinic_id AND c.status=\'active\' WHERE h.clinic_id=?');
        $query->execute([$clinicId]);$host=$query->fetchColumn();
        if (!$host) throw new RuntimeException('The clinic portal must be configured before sending links.');
        return 'https://'.$host.'/client';
    }
}
