<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Database;
use Wellness\Http\ApiException;

final class CatalogService
{
    public function __construct(private readonly Database $database) {}

    public function siteConfig(): array
    {
        $clinic = $this->database->connection()->query("SELECT c.name,c.legal_name,c.email,c.phone,
                   (SELECT content_hash FROM clinic_brand_assets WHERE clinic_id=c.id AND asset_type='logo') logo_version,
                   (SELECT content_hash FROM clinic_brand_assets WHERE clinic_id=c.id AND asset_type='favicon') favicon_version
              FROM clinics c WHERE c.status='active' ORDER BY c.id LIMIT 1")->fetch();
        if (!$clinic) throw new ApiException(503, 'clinic_not_configured', 'The clinic has not been configured.');
        return $clinic;
    }

    public function brandAsset(string $type): array
    {
        if (!in_array($type, ['logo', 'favicon'], true)) throw new ApiException(404, 'brand_asset_not_found', 'Brand asset not found.');
        $statement = $this->database->connection()->prepare("SELECT a.mime_type,a.image_data,a.content_hash
              FROM clinic_brand_assets a JOIN clinics c ON c.id=a.clinic_id AND c.status='active'
             WHERE a.asset_type=:type ORDER BY c.id LIMIT 1");
        $statement->execute(['type' => $type]);
        $asset = $statement->fetch();
        if (!$asset) throw new ApiException(404, 'brand_asset_not_found', 'Brand asset not found.');
        return $asset;
    }

    public function locations(): array
    {
        return $this->database->connection()->query("SELECT id,name,timezone,address_line1,address_line2,city,province,postal_code,phone FROM locations WHERE is_bookable=1 ORDER BY name")->fetchAll();
    }

    public function services(?int $practitionerId = null): array
    {
        $sql = "SELECT s.id,s.slug,s.name,s.description,s.preparation_instructions,s.price_cents,c.name category,
                       JSON_ARRAYAGG(JSON_OBJECT('id',d.id,'minutes',d.duration_minutes,'price_cents',COALESCE(d.price_cents,s.price_cents))) durations
                  FROM services s LEFT JOIN service_categories c ON c.id=s.category_id JOIN service_duration_options d ON d.service_id=s.id AND d.active=1";
        $params = [];
        if ($practitionerId) { $sql .= ' JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1 WHERE s.active=1 AND s.published=1 AND ps.practitioner_id=:practitioner'; $params['practitioner']=$practitionerId; }
        else $sql .= ' WHERE s.active=1 AND s.published=1';
        $sql .= ' GROUP BY s.id,s.slug,s.name,s.description,s.preparation_instructions,s.price_cents,c.name ORDER BY c.name,s.display_order,s.name';
        $statement=$this->database->connection()->prepare($sql); $statement->execute($params);
        $rows=$statement->fetchAll(); foreach($rows as &$row) $row['durations']=self::sortedDurations($row['durations']); return $rows;
    }

    public function publicServices(): array
    {
        $sql="SELECT s.slug,s.name,s.name_fr,s.public_summary,s.public_summary_fr,s.description,s.description_fr,s.preparation_instructions,s.preparation_instructions_fr,c.name category,
                     EXISTS(SELECT 1 FROM practitioner_services ps WHERE ps.service_id=s.id AND ps.active=1 AND ps.offers_clinic=1) offers_clinic,
                     EXISTS(SELECT 1 FROM practitioner_services ps WHERE ps.service_id=s.id AND ps.active=1 AND ps.offers_mobile=1) offers_mobile,
                     JSON_ARRAYAGG(JSON_OBJECT('minutes',d.duration_minutes,'price_cents',COALESCE(d.price_cents,s.price_cents))) durations
                FROM services s
           LEFT JOIN service_categories c ON c.id=s.category_id AND c.clinic_id=s.clinic_id
                JOIN service_duration_options d ON d.service_id=s.id AND d.active=1
               WHERE s.active=1 AND s.published=1
                 AND s.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
            GROUP BY s.id,s.slug,s.name,s.name_fr,s.public_summary,s.public_summary_fr,s.description,s.description_fr,s.preparation_instructions,s.preparation_instructions_fr,c.name,s.display_order
            ORDER BY c.name IS NULL,c.name,s.display_order,s.name";
        $rows=$this->database->connection()->query($sql)->fetchAll();
        foreach($rows as &$row){$row['durations']=self::sortedDurations($row['durations']);$row['offers_clinic']=(bool)$row['offers_clinic'];$row['offers_mobile']=(bool)$row['offers_mobile'];}
        return $rows;
    }

    private static function sortedDurations(string $json): array
    {
        $durations=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        usort($durations,static fn(array $a,array $b): int => ((int)$a['minutes']<=>(int)$b['minutes']) ?: ((int)($a['id']??0)<=>(int)($b['id']??0)));
        return $durations;
    }

    public function publicService(string $slug): array
    {
        if(preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/',$slug)!==1)throw new ApiException(404,'service_not_found','Published service not found.');
        $services=array_values(array_filter($this->publicServices(),static fn(array $service):bool=>$service['slug']===$slug));
        if($services===[])throw new ApiException(404,'service_not_found','Published service not found.');
        $service=$services[0];
        $practitioners=$this->database->connection()->prepare("SELECT t.slug,t.public_name,t.booking_name,t.public_title,t.public_title_fr,t.summary,t.summary_fr,p.id booking_practitioner_id
              FROM services s JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1
              JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1 JOIN users u ON u.id=p.user_id AND u.status='active'
              JOIN public_team_profiles t ON t.user_id=u.id AND t.clinic_id=s.clinic_id AND t.published=1 AND t.section='practitioner'
             WHERE s.slug=:slug AND s.active=1 AND s.published=1
               AND s.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
          ORDER BY t.display_order,t.public_name");
        $practitioners->execute(['slug'=>$slug]);$service['practitioners']=$practitioners->fetchAll();
        $locations=$this->database->connection()->prepare("SELECT l.name,l.city,l.province FROM services s JOIN service_locations sl ON sl.service_id=s.id AND sl.active=1 JOIN locations l ON l.id=sl.location_id AND l.is_bookable=1 WHERE s.slug=:slug AND s.active=1 AND s.published=1 AND s.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1) ORDER BY l.name");
        $locations->execute(['slug'=>$slug]);$service['locations']=$locations->fetchAll();
        return $service;
    }

    public function publicPractitioners(): array
    {
        $sql = "SELECT t.slug,t.public_name,t.booking_name,t.public_title,t.public_title_fr,t.summary,t.summary_fr,
                       p.discipline,p.credentials,t.display_order,
                       CASE WHEN i.user_id IS NULL THEN 0 ELSE 1 END has_image,
                       i.content_hash image_version,
                       CASE WHEN t.show_booking_action=1 THEN p.id ELSE NULL END booking_practitioner_id
                  FROM public_team_profiles t
                  JOIN users u ON u.id=t.user_id AND u.status='active' AND u.user_type='staff'
                  JOIN practitioners p ON p.user_id=u.id AND p.active=1
             LEFT JOIN user_profile_images i ON i.user_id=u.id
                 WHERE t.published=1 AND t.section='practitioner'
                   AND t.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
              ORDER BY t.display_order,t.public_name";
        $practitioners = $this->database->connection()->query($sql)->fetchAll();
        if ($practitioners === []) return [];

        $serviceSql = "SELECT t.slug practitioner_slug,s.slug,s.name,s.name_fr,c.name category
                         FROM public_team_profiles t
                         JOIN practitioners p ON p.user_id=t.user_id AND p.active=1
                         JOIN practitioner_services ps ON ps.practitioner_id=p.id AND ps.active=1
                         JOIN services s ON s.id=ps.service_id AND s.active=1 AND s.published=1
                    LEFT JOIN service_categories c ON c.id=s.category_id AND c.clinic_id=s.clinic_id
                        WHERE t.published=1 AND t.section='practitioner'
                          AND t.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
                     ORDER BY c.name IS NULL,c.name,s.display_order,s.name";
        $servicesByPractitioner = [];
        foreach ($this->database->connection()->query($serviceSql)->fetchAll() as $service) {
            $practitionerSlug = (string)$service['practitioner_slug'];
            unset($service['practitioner_slug']);
            $servicesByPractitioner[$practitionerSlug][] = $service;
        }
        foreach ($practitioners as &$practitioner) {
            $practitioner['has_image'] = (bool)$practitioner['has_image'];
            $practitioner['services'] = $servicesByPractitioner[(string)$practitioner['slug']] ?? [];
        }
        return $practitioners;
    }

    public function publicPractitioner(string $slug): array
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $slug) !== 1) {
            throw new ApiException(404, 'practitioner_not_found', 'Published practitioner not found.');
        }
        $matches = array_values(array_filter(
            $this->publicPractitioners(),
            static fn(array $practitioner): bool => $practitioner['slug'] === $slug
        ));
        if ($matches === []) throw new ApiException(404, 'practitioner_not_found', 'Published practitioner not found.');
        $practitioner = $matches[0];
        $serviceSlugs = array_column($practitioner['services'], 'slug');
        $practitioner['services'] = array_values(array_filter(
            $this->publicServices(),
            static fn(array $service): bool => in_array($service['slug'], $serviceSlugs, true)
        ));
        return $practitioner;
    }

    public function practitioners(?int $serviceId = null): array
    {
        $sql = "SELECT p.id,COALESCE(t.booking_name,t.public_name,u.display_name) display_name,p.discipline,p.biography,p.credentials FROM practitioners p JOIN users u ON u.id=p.user_id LEFT JOIN public_team_profiles t ON t.user_id=u.id AND t.clinic_id=u.clinic_id";
        $params=[];
        if($serviceId){$sql.=' JOIN practitioner_services ps ON ps.practitioner_id=p.id WHERE p.active=1 AND ps.active=1 AND ps.service_id=:service';$params['service']=$serviceId;}else{$sql.=' WHERE p.active=1';}
        $sql.=' ORDER BY u.display_name'; $statement=$this->database->connection()->prepare($sql);$statement->execute($params);return $statement->fetchAll();
    }

    public function team(): array
    {
        $sql = "SELECT t.slug,t.section,t.public_name,t.booking_name,t.public_title,t.public_title_fr,t.summary,t.summary_fr,t.display_order,p.discipline,p.credentials,
                       CASE WHEN i.user_id IS NULL THEN 0 ELSE 1 END has_image,
                       i.content_hash image_version,
                       CASE WHEN t.section='practitioner' AND t.show_booking_action=1 AND p.active=1 THEN p.id ELSE NULL END practitioner_id
                  FROM public_team_profiles t
                  JOIN users u ON u.id=t.user_id AND u.status='active' AND u.user_type='staff'
             LEFT JOIN practitioners p ON p.user_id=u.id
             LEFT JOIN user_profile_images i ON i.user_id=u.id
                 WHERE t.published=1
                   AND t.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
                   AND (t.section<>'practitioner' OR p.active=1)
              ORDER BY CASE t.section WHEN 'practitioner' THEN 0 ELSE 1 END,t.display_order,t.public_name";
        return $this->database->connection()->query($sql)->fetchAll();
    }

    public function teamImage(string $slug): array
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $slug) !== 1) {
            throw new ApiException(404, 'not_found', 'Published team image not found.');
        }
        $statement = $this->database->connection()->prepare(
            "SELECT i.mime_type,i.image_data,i.content_hash
               FROM public_team_profiles t
               JOIN users u ON u.id=t.user_id AND u.status='active' AND u.user_type='staff'
               JOIN user_profile_images i ON i.user_id=u.id
          LEFT JOIN practitioners p ON p.user_id=u.id
              WHERE t.slug=:slug AND t.published=1
                AND t.clinic_id=(SELECT id FROM clinics WHERE status='active' ORDER BY id LIMIT 1)
                AND (t.section<>'practitioner' OR p.active=1)
              LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $image = $statement->fetch();
        if (!$image) throw new ApiException(404, 'not_found', 'Published team image not found.');
        return $image;
    }
}
