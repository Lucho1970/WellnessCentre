<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Wellness\Database;
use Wellness\Http\ApiException;

final class AvailabilityService
{
    public function __construct(private readonly Database $database) {}

    public function search(array $query): array
    {
        $mode=Delivery::mode($query);
        $serviceId=(int)($query['service_id']??0); $practitionerId=(int)($query['practitioner_id']??0); $locationId=(int)($query['location_id']??0);
        if(!$serviceId||!$practitionerId||!$locationId) throw new ApiException(422,'validation_error','service_id, practitioner_id, and location_id are required.');
        $from=$this->date((string)($query['date_from']??date('Y-m-d')),'date_from');
        $to=$this->date((string)($query['date_to']??$from->modify('+7 days')->format('Y-m-d')),'date_to');
        if($to<$from||$to>$from->modify('+31 days')) throw new ApiException(422,'invalid_date_range','The availability range must be between 1 and 31 days.');

        $sql="SELECT ps.offers_mobile,ps.offers_clinic,ps.travel_buffer_minutes,ps.mobile_fee_cents,COALESCE(ps.price_override_cents,d.price_cents,s.price_cents) base_price_cents,s.lead_time_minutes,s.booking_horizon_days,s.buffer_before_minutes,s.buffer_after_minutes,s.requires_room,d.id duration_option_id,d.duration_minutes,l.timezone
                FROM services s JOIN service_duration_options d ON d.service_id=s.id AND d.active=1 JOIN locations l ON l.id=:location JOIN service_locations sl ON sl.service_id=s.id AND sl.location_id=l.id AND sl.active=1
                JOIN practitioner_services ps ON ps.service_id=s.id AND ps.practitioner_id=:practitioner AND ps.active=1
                JOIN practitioners p ON p.id=ps.practitioner_id AND p.active=1
                JOIN users u ON u.id=p.user_id AND u.status='active' AND u.clinic_id=s.clinic_id
                JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.location_id=l.id AND pl.active=1
               WHERE s.id=:service AND s.active=1 AND l.is_bookable=1 AND l.clinic_id=s.clinic_id";
        $statement=$this->database->connection()->prepare($sql);$statement->execute(['location'=>$locationId,'practitioner'=>$practitionerId,'service'=>$serviceId]);$options=$statement->fetchAll();
        if(!$options) throw new ApiException(404,'service_not_available','That practitioner does not offer this service at the selected location.');
        $timezone=new DateTimeZone($options[0]['timezone']); $slots=[];
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        foreach(new DatePeriod($from,new DateInterval('P1D'),$to->modify('+1 day')) as $day){
            $weekday=(int)$day->format('N');
            $rules=$this->rules($practitionerId,$locationId,$weekday,$day->format('Y-m-d'),$timezone);
            foreach($rules as $rule){ foreach($options as $option){
                $terms=Delivery::terms($option,$mode);
                $windowStart=new DateTimeImmutable($day->format('Y-m-d').' '.$rule['start_time'],$timezone);
                $cursor=$windowStart; $end=new DateTimeImmutable($day->format('Y-m-d').' '.$rule['end_time'],$timezone);
                $cursor=$cursor->setTimestamp((int)(ceil($cursor->getTimestamp()/900)*900));
                while($cursor->setTimestamp($cursor->getTimestamp()+(int)$option['duration_minutes']*60)<=$end){
                    $slotEnd=$cursor->setTimestamp($cursor->getTimestamp()+(int)$option['duration_minutes']*60);
                    $utcStart=$cursor->setTimezone(new DateTimeZone('UTC'));$utcEnd=$slotEnd->setTimezone(new DateTimeZone('UTC'));
                    $bufferStart=$utcStart->modify('-'.((int)$option['buffer_before_minutes']+$terms['travel']).' minutes');$bufferEnd=$utcEnd->modify('+'.((int)$option['buffer_after_minutes']+$terms['travel']).' minutes');
                    if($utcStart>=$now->modify('+'.$option['lead_time_minutes'].' minutes')&&$utcStart<=$now->modify('+'.$option['booking_horizon_days'].' days')&&$bufferStart>=(new DateTimeImmutable($day->format('Y-m-d').' '.$rule['start_time'],$timezone))&&$bufferEnd<=$end&&!$this->blocked($practitionerId,$locationId,$bufferStart,$bufferEnd)){
                        $rooms=$terms['requires_room']?$this->rooms($serviceId,$practitionerId,$locationId,$bufferStart,$bufferEnd):[];
                        if(!$terms['requires_room']||$rooms)$slots[$option['duration_option_id'].':'.$utcStart->getTimestamp()]=['duration_option_id'=>(int)$option['duration_option_id'],'starts_at'=>$cursor->format(DATE_ATOM),'ends_at'=>$slotEnd->format(DATE_ATOM),'available_room_ids'=>$rooms];
                    }
                    $cursor=$cursor->setTimestamp($cursor->getTimestamp()+900);
                }
            }}
        }
        $slots=array_values($slots);usort($slots,fn($a,$b)=>strtotime($a['starts_at'])<=>strtotime($b['starts_at']));
        return ['slot_increment_minutes'=>15,'availability'=>$slots];
    }

    private function rules(int $practitionerId,int $locationId,int $weekday,string $date,DateTimeZone $timezone): array
    {
        $statement=$this->database->connection()->prepare('SELECT start_time,end_time FROM availability_rules WHERE practitioner_id=:p AND location_id=:l AND weekday=:w AND active=1 AND valid_from<=:date_from AND (valid_until IS NULL OR valid_until>=:date_until) AND MOD(FLOOR(DATEDIFF(:recurrence_date,DATE_SUB(valid_from,INTERVAL WEEKDAY(valid_from) DAY))/7),GREATEST(recurrence_interval_weeks,1))=0 ORDER BY start_time');
        $statement->execute(['p'=>$practitionerId,'l'=>$locationId,'w'=>$weekday,'date_from'=>$date,'date_until'=>$date,'recurrence_date'=>$date]);$rules=$statement->fetchAll();
        $day=new DateTimeImmutable($date,$timezone);$next=$day->modify('+1 day');$utc=new DateTimeZone('UTC');
        $statement=$this->database->connection()->prepare("SELECT starts_at,ends_at FROM availability_overrides WHERE practitioner_id=:p AND location_id=:l AND override_type='available' AND starts_at<:end AND ends_at>:start");
        $statement->execute(['p'=>$practitionerId,'l'=>$locationId,'start'=>$day->setTimezone($utc)->format('Y-m-d H:i:s'),'end'=>$next->setTimezone($utc)->format('Y-m-d H:i:s')]);
        foreach($statement->fetchAll() as $row){$start=(new DateTimeImmutable($row['starts_at'],$utc))->setTimezone($timezone);$end=(new DateTimeImmutable($row['ends_at'],$utc))->setTimezone($timezone);$rules[]=['start_time'=>$start<$day?'00:00:00':$start->format('H:i:s'),'end_time'=>$end>=$next?'24:00:00':$end->format('H:i:s')];}
        return ScheduleIntervals::merge($rules);
    }

    private function rooms(int $serviceId,int $practitionerId,int $locationId,DateTimeImmutable $start,DateTimeImmutable $end): array
    {
        $sql="SELECT r.id FROM rooms r WHERE r.location_id=:l AND r.is_bookable=1
            AND NOT EXISTS(SELECT 1 FROM room_practitioner_restrictions x WHERE x.room_id=r.id AND x.practitioner_id=:p AND x.allowed=0)
            AND NOT EXISTS(SELECT 1 FROM service_room_capability_requirements req WHERE req.service_id=:s AND NOT EXISTS(SELECT 1 FROM room_capability_assignments a WHERE a.room_id=r.id AND a.capability_id=req.capability_id))
            AND NOT EXISTS(SELECT 1 FROM appointments a WHERE a.room_id=r.id AND a.status NOT IN('canceled_by_client','canceled_by_clinic') AND a.buffer_starts_at<DATE_ADD(:end,INTERVAL r.turnover_minutes MINUTE) AND DATE_ADD(a.buffer_ends_at,INTERVAL r.turnover_minutes MINUTE)>:start) ORDER BY r.id";
        $statement=$this->database->connection()->prepare($sql);$statement->execute(['l'=>$locationId,'p'=>$practitionerId,'s'=>$serviceId,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s')]);return array_map('intval',$statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function blocked(int $practitionerId,int $locationId,DateTimeImmutable $start,DateTimeImmutable $end): bool
    {
        $pdo=$this->database->connection();$params=['p'=>$practitionerId,'l'=>$locationId,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s')];
        $queries=[
            "SELECT 1 FROM appointments WHERE practitioner_id=:p AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start LIMIT 1",
            "SELECT 1 FROM time_off WHERE practitioner_id=:p AND starts_at<:end AND ends_at>:start LIMIT 1",
            "SELECT 1 FROM availability_overrides WHERE practitioner_id=:p AND location_id=:l AND override_type='blocked' AND starts_at<:end AND ends_at>:start LIMIT 1",
            "SELECT 1 FROM imported_calendar_entries WHERE practitioner_id=:p AND blocks_booking=1 AND starts_at<:end AND ends_at>:start LIMIT 1",
        ];
        foreach($queries as $sql){$statement=$pdo->prepare($sql);$used=str_contains($sql,'location_id')?$params:array_diff_key($params,['l'=>true]);$statement->execute($used);if($statement->fetchColumn())return true;} return false;
    }

    private function date(string $value,string $field): DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new ApiException(422,'validation_error',"{$field} must use YYYY-MM-DD.",[$field=>'Invalid date']);return $date;
    }
}
