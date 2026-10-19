<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Negative consent and reference-integrity checks after positive schedule ownership checks. */
final class ScheduleChangeAccess
{
    public function __construct(private readonly Database $database) {}

    private function query(AuthContext $actor,array $record,string $start,?string $end,int $after=0): array
    {
        $params=['clinic'=>$actor->clinicId,'practitioner'=>$record['practitioner_id'],'location'=>$record['location_id'],'start'=>$start,'after'=>$after];
        $visible=ConsentAccess::condition($actor,$params,'a.client_id');
        $upper='';if($end!==null){$upper=' AND a.buffer_starts_at<:end';$params['end']=$end;}
        $sql=" FROM appointments a WHERE a.clinic_id=:clinic AND a.practitioner_id=:practitioner AND a.location_id=:location
            AND a.id>:after AND a.status NOT IN('canceled_by_client','canceled_by_clinic') AND a.buffer_ends_at>:start $upper
            AND NOT EXISTS(SELECT 1 FROM users client
                JOIN services service ON service.id=a.service_id AND service.clinic_id=a.clinic_id
                JOIN locations location ON location.id=a.location_id AND location.clinic_id=a.clinic_id
                WHERE client.id=a.client_id AND client.clinic_id=a.clinic_id AND client.user_type='client'
                  AND NOT EXISTS(SELECT 1 FROM client_merge_records merged WHERE merged.duplicate_client_id=client.id) $visible)";
        return [$sql,$params];
    }

    private function denied(): never
    {
        throw new ApiException(403,'schedule_access_restricted','This schedule change requires an authorized privacy review. No changes were saved.');
    }

    public function assertOverride(AuthContext $actor,array $record): void
    {
        [$sql,$params]=$this->query($actor,$record,$record['starts_at'],$record['ends_at']);
        $q=$this->database->connection()->prepare('SELECT 1'.$sql.' LIMIT 1');$q->execute($params);
        if($q->fetchColumn())$this->denied();
    }

    public function assertRule(AuthContext $actor,array $record): void
    {
        $zone=new DateTimeZone($record['timezone']);$utc=new DateTimeZone('UTC');
        $start=(new DateTimeImmutable($record['valid_from'],$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end=$record['valid_until']!==null?(new DateTimeImmutable($record['valid_until'],$zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'):null;
        if($end!==null&&strlen(explode(' ',$end)[0])>10)$end=null;
        $after=0;
        do{
            [$sql,$params]=$this->query($actor,$record,$start,$end,$after);
            // Only denied/unresolvable appointments' minimal time metadata is loaded. No cap skips later rows.
            $q=$this->database->connection()->prepare('SELECT a.id,a.buffer_starts_at,a.buffer_ends_at'.$sql.' ORDER BY a.id LIMIT 256');$q->execute($params);$rows=$q->fetchAll();
            foreach($rows as $row){$after=(int)$row['id'];if(AvailabilityRuleWindow::overlaps($record,$row['buffer_starts_at'],$row['buffer_ends_at']))$this->denied();}
        }while(count($rows)===256);
    }
}
