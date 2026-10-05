<?php
declare(strict_types=1);

namespace Wellness\Service;

use Closure;
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

/** On-demand travel estimates for the signed-in practitioner's next near-term On-Site visit. */
final class PractitionerTravelService
{
    private Closure $transport;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly AuditLogger $audit,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? $this->request(...);
    }

    public function next(AuthContext $actor): ?array
    {
        BookingService::authorizePractitionerCalendar($actor);
        $statement = $this->database->connection()->prepare(
            "SELECT a.id,a.starts_at,a.destination_snapshot,a.travel_buffer_minutes,
                    s.name service_name,u.display_name client_name,l.name location_name,l.timezone,
                    l.address_line1,l.address_line2,l.city,l.province,l.postal_code
             FROM appointments a
             JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1
             JOIN services s ON s.id=a.service_id
             JOIN users u ON u.id=a.client_id
             JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
             WHERE a.clinic_id=:clinic AND a.delivery_mode='mobile'
               AND a.status IN ('confirmed','rescheduled')
               AND a.starts_at>=UTC_TIMESTAMP()
               AND a.starts_at<DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
             ORDER BY a.starts_at,a.id LIMIT 1"
        );
        $statement->execute(['user'=>$actor->userId,'clinic'=>$actor->clinicId]);
        $row=$statement->fetch();
        if(!$row)return null;
        return [
            'id'=>(int)$row['id'], 'starts_at'=>$row['starts_at'],
            'service_name'=>$row['service_name'], 'client_name'=>$row['client_name'],
            'location_name'=>$row['location_name'], 'timezone'=>$row['timezone'],
            'clinic_origin_available'=>self::address($row)!=='',
        ];
    }

    public function estimate(AuthContext $actor,int $appointmentId,array $body,string $correlationId): array
    {
        BookingService::authorizePractitionerCalendar($actor);
        if($this->config->googleMapsApiKey==='')throw new ApiException(503,'routes_not_configured','Travel estimates are not configured.');
        $source=$body['source']??null;
        if(!in_array($source,['current','clinic'],true))throw new ApiException(422,'validation_error','Choose current or clinic as the starting point.');
        $coordinates=$source==='current'?self::coordinates($body):null;
        $statement=$this->database->connection()->prepare(
            "SELECT a.id,a.starts_at,a.destination_snapshot,l.address_line1,l.address_line2,l.city,l.province,l.postal_code
             FROM appointments a
             JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1
             JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
             WHERE a.id=:id AND a.clinic_id=:clinic AND a.delivery_mode='mobile'
               AND a.status IN ('confirmed','rescheduled')
               AND a.starts_at>=UTC_TIMESTAMP()
               AND a.starts_at<DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR)"
        );
        $statement->execute(['id'=>$appointmentId,'user'=>$actor->userId,'clinic'=>$actor->clinicId]);
        $row=$statement->fetch();
        if(!$row)throw new ApiException(404,'appointment_not_found','No upcoming On-Site appointment was found.');
        $snapshot=json_decode((string)($row['destination_snapshot']??''),true);
        $destination=is_array($snapshot)?self::address($snapshot):'';
        if($destination==='')throw new ApiException(422,'destination_unavailable','The visit address is incomplete.');
        $clinic=self::address($row);
        if($source==='clinic'&&$clinic==='')throw new ApiException(422,'clinic_origin_unavailable','The clinic address is incomplete.');
        $origin=$source==='current'?['location'=>['latLng'=>$coordinates]]:['address'=>$clinic];
        $result=($this->transport)(
            'https://routes.googleapis.com/directions/v2:computeRoutes',
            ['Content-Type: application/json','X-Goog-Api-Key: '.$this->config->googleMapsApiKey,'X-Goog-FieldMask: routes.duration,routes.distanceMeters'],
            ['origin'=>$origin,'destination'=>['address'=>$destination],'travelMode'=>'DRIVE','routingPreference'=>'TRAFFIC_AWARE'],
        );
        [$minutes,$kilometers]=self::parseRoute($result);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.travel_estimate.view','appointment',$appointmentId,'success',['source'=>$source]);
        return [
            'appointment_id'=>$appointmentId, 'starts_at'=>$row['starts_at'],
            'source'=>$source, 'duration_minutes'=>$minutes, 'distance_km'=>$kilometers,
            'checked_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'), 'destination_address'=>$destination,
            'traffic_aware'=>true,
        ];
    }

    public static function coordinates(array $body): array
    {
        $latitude=$body['latitude']??null;$longitude=$body['longitude']??null;
        if(!is_float($latitude)&&!is_int($latitude)||!is_float($longitude)&&!is_int($longitude)
            ||!is_finite((float)$latitude)||!is_finite((float)$longitude)
            ||$latitude < -90||$latitude > 90||$longitude < -180||$longitude > 180) {
            throw new ApiException(422,'invalid_coordinates','Valid current-location coordinates are required.');
        }
        return ['latitude'=>(float)$latitude,'longitude'=>(float)$longitude];
    }

    public static function parseRoute(array $result): array
    {
        $duration=$result['routes'][0]['duration']??null;
        $distance=$result['routes'][0]['distanceMeters']??null;
        if(!is_string($duration)||!preg_match('/^(\d+(?:\.\d+)?)s$/',$duration,$match)||!is_numeric($distance)||$distance<0) {
            throw new ApiException(422,'route_not_found','No driving route could be calculated for this visit.');
        }
        return [(int)ceil((float)$match[1]/60),round((float)$distance/1000,1)];
    }

    private static function address(array $value): string
    {
        foreach(['address_line1','city','province','postal_code'] as $field)if(trim((string)($value[$field]??''))==='')return '';
        $parts=array_filter(array_map(static fn($field)=>trim((string)($value[$field]??'')),['address_line1','address_line2','city','province','postal_code']));
        $parts[]=trim((string)($value['country']??'Canada'));
        return implode(', ',$parts);
    }

    private function request(string $url,array $headers,array $payload): array
    {
        $handle=curl_init($url);
        if($handle===false)throw new ApiException(503,'routes_unavailable','Travel estimates are temporarily unavailable.');
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR)]);
        $response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if(!is_string($response)||$status<200||$status>=300)throw new ApiException(503,'routes_unavailable','Travel estimates are temporarily unavailable.');
        $decoded=json_decode($response,true);
        if(!is_array($decoded))throw new ApiException(503,'routes_unavailable','Travel estimates are temporarily unavailable.');
        return $decoded;
    }
}
