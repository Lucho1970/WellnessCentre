<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Database;

final class CatalogService
{
    public function __construct(private readonly Database $database) {}

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
}
