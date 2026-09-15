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
        $serviceId=(int)($query['service_id']??0); $practitionerId=(int)($query['practitioner_id']??0); $locationId=(int)($query['location_id']??0);
        if(!$serviceId||!$practitionerId||!$locationId) throw new ApiException(422,'validation_error','service_id, practitioner_id, and location_id are required.');
        $from=$this->date((string)($query['date_from']??date('Y-m-d')),'date_from');
        $to=$this->date((string)($query['date_to']??$from->modify('+7 days')->format('Y-m-d')),'date_to');
        if($to<$from||$to>$from->modify('+31 days')) throw new ApiException(422,'invalid_date_range','The availability range must be between 1 and 31 days.');

        $sql="SELECT s.lead_time_minutes,s.booking_horizon_days,s.buffer_before_minutes,s.buffer_after_minutes,d.id duration_option_id,d.duration_minutes,l.timezone
                FROM services s JOIN service_duration_options d ON d.service_id=s.id AND d.active=1 JOIN locations l ON l.id=:location JOIN service_locations sl ON sl.service_id=s.id AND sl.location_id=l.id AND sl.active=1
                JOIN practitioner_services ps ON ps.service_id=s.id AND ps.practitioner_id=:practitioner AND ps.active=1
               WHERE s.id=:service AND s.active=1";
        $statement=$this->database->connection()->prepare($sql);$statement->execute(['location'=>$locationId,'practitioner'=>$practitionerId,'service'=>$serviceId]);$options=$statement->fetchAll();
        if(!$options) throw new ApiException(404,'service_not_available','That practitioner does not offer this service at the selected location.');
        $timezone=new DateTimeZone($options[0]['timezone']); $slots=[];
        foreach(new DatePeriod($from,new DateInterval('P1D'),$to->modify('+1 day')) as $day){
            $weekday=(int)$day->format('N');
            $rules=$this->rules($practitionerId,$locationId,$weekday,$day->format('Y-m-d'));
            foreach($rules as $rule){ foreach($options as $option){
                $cursor=new DateTimeImmutable($day->format('Y-m-d').' '.$rule['start_time'],$timezone); $end=new DateTimeImmutable($day->format('Y-m-d').' '.$rule['end_time'],$timezone);
                while($cursor->modify('+'.$option['duration_minutes'].' minutes')<=$end){
                    $slotEnd=$cursor->modify('+'.$option['duration_minutes'].' minutes');
                    $utcStart=$cursor->setTimezone(new DateTimeZone('UTC'));$utcEnd=$slotEnd->setTimezone(new DateTimeZone('UTC'));
                    $bufferStart=$utcStart->modify('-'.$option['buffer_before_minutes'].' minutes');$bufferEnd=$utcEnd->modify('+'.$option['buffer_after_minutes'].' minutes');
                    if($utcStart>=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$option['lead_time_minutes'].' minutes')&&!$this->blocked($practitionerId,$locationId,$bufferStart,$bufferEnd)){
                        $slots[]=['duration_option_id'=>(int)$option['duration_option_id'],'starts_at'=>$cursor->format(DATE_ATOM),'ends_at'=>$slotEnd->format(DATE_ATOM)];
                    }
                    $cursor=$cursor->modify('+15 minutes');
                }
            }}
        }
        return ['slot_increment_minutes'=>15,'availability'=>$slots];
    }

    private function rules(int $practitionerId,int $locationId,int $weekday,string $date): array
    {
        $statement=$this->database->connection()->prepare('SELECT start_time,end_time FROM availability_rules WHERE practitioner_id=:p AND location_id=:l AND weekday=:w AND active=1 AND valid_from<=:date_from AND (valid_until IS NULL OR valid_until>=:date_until) ORDER BY start_time');
        $statement->execute(['p'=>$practitionerId,'l'=>$locationId,'w'=>$weekday,'date_from'=>$date,'date_until'=>$date]);return $statement->fetchAll();
    }

    private function blocked(int $practitionerId,int $locationId,DateTimeImmutable $start,DateTimeImmutable $end): bool
    {
        $pdo=$this->database->connection();$params=['p'=>$practitionerId,'l'=>$locationId,'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s')];
        $queries=[
            "SELECT 1 FROM appointments WHERE practitioner_id=:p AND location_id=:l AND status NOT IN('canceled_by_client','canceled_by_clinic') AND buffer_starts_at<:end AND buffer_ends_at>:start LIMIT 1",
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
