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
        $clinic = $this->database->connection()->query("SELECT name,legal_name,email,phone FROM clinics WHERE status='active' ORDER BY id LIMIT 1")->fetch();
        if (!$clinic) throw new ApiException(503, 'clinic_not_configured', 'The clinic has not been configured.');
        return $clinic;
    }

    public function locations(): array
    {
        return $this->database->connection()->query("SELECT id,name,timezone,address_line1,address_line2,city,province,postal_code,phone FROM locations WHERE is_bookable=1 ORDER BY name")->fetchAll();
    }

    public function services(?int $practitionerId = null): array
    {
        $sql = "SELECT s.id,s.name,s.description,s.preparation_instructions,s.price_cents,s.duration_mode,s.lead_time_minutes,s.booking_horizon_days,s.cancellation_window_minutes,s.requires_room,s.recurrence_allowed,c.name category,
                       JSON_ARRAYAGG(JSON_OBJECT('id',d.id,'minutes',d.duration_minutes,'price_cents',COALESCE(d.price_cents,s.price_cents))) durations
                  FROM services s LEFT JOIN service_categories c ON c.id=s.category_id JOIN service_duration_options d ON d.service_id=s.id AND d.active=1";
        $params = [];
        if ($practitionerId) { $sql .= ' JOIN practitioner_services ps ON ps.service_id=s.id AND ps.active=1 WHERE s.active=1 AND ps.practitioner_id=:practitioner'; $params['practitioner']=$practitionerId; }
        else $sql .= ' WHERE s.active=1';
        $sql .= ' GROUP BY s.id,c.name ORDER BY c.name,s.name';
        $statement=$this->database->connection()->prepare($sql); $statement->execute($params);
        $rows=$statement->fetchAll(); foreach($rows as &$row) $row['durations']=json_decode($row['durations'],true); return $rows;
    }

    public function practitioners(?int $serviceId = null): array
    {
        $sql = "SELECT p.id,u.display_name,p.discipline,p.biography,p.credentials FROM practitioners p JOIN users u ON u.id=p.user_id";
        $params=[];
        if($serviceId){$sql.=' JOIN practitioner_services ps ON ps.practitioner_id=p.id WHERE p.active=1 AND ps.active=1 AND ps.service_id=:service';$params['service']=$serviceId;}else{$sql.=' WHERE p.active=1';}
        $sql.=' ORDER BY u.display_name'; $statement=$this->database->connection()->prepare($sql);$statement->execute($params);return $statement->fetchAll();
    }

    public function team(): array
    {
        $sql = "SELECT t.slug,t.section,u.display_name,t.public_title,t.public_title_fr,t.summary,t.summary_fr,t.display_order,p.discipline,p.credentials,
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
              ORDER BY CASE t.section WHEN 'practitioner' THEN 0 ELSE 1 END,t.display_order,u.display_name";
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
