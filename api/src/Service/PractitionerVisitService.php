<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Practitioner-only day-of cards and optional, append-only visit milestones. */
final class PractitionerVisitService
{
    private const ORDER = ['en_route'=>1,'arrived'=>2,'session_started'=>3,'session_finished'=>4,'left_residence'=>5];

    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): void
    {
        BookingService::authorizePractitionerCalendar($actor);
    }

    public function today(AuthContext $actor,string $correlationId): array
    {
        self::authorize($actor);
        $pdo=$this->database->connection();
        $zoneQuery=$pdo->prepare('SELECT l.timezone FROM practitioners p JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 JOIN locations l ON l.id=pl.location_id AND l.clinic_id=:clinic WHERE p.user_id=:user AND p.active=1 ORDER BY l.id LIMIT 1');
        $zoneQuery->execute(['clinic'=>$actor->clinicId,'user'=>$actor->userId]);
        $zone=(string)($zoneQuery->fetchColumn()?:'America/Toronto');
        try{$timezone=new DateTimeZone($zone);}catch(Throwable){$timezone=new DateTimeZone('America/Toronto');$zone='America/Toronto';}
        $local=new DateTimeImmutable('now',$timezone);
        $start=$local->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end=$local->modify('+1 day')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $query=$pdo->prepare("SELECT a.id,a.client_id,a.service_id,a.starts_at,a.ends_at,a.status,a.version,a.delivery_mode,
                   s.name service_name,u.display_name client_name,u.email client_email,cp.phone client_phone,l.name location_name,l.timezone
            FROM appointments a
            JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1
            JOIN services s ON s.id=a.service_id
            JOIN users u ON u.id=a.client_id AND u.clinic_id=a.clinic_id
            LEFT JOIN client_profiles cp ON cp.user_id=u.id
            JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
            WHERE a.clinic_id=:clinic AND a.starts_at>=:start AND a.starts_at<:end
              AND a.status IN ('requested','confirmed','rescheduled','no_show','completed','invoiced','paid')
            ORDER BY a.starts_at,a.id LIMIT 51");
        $query->execute(['clinic'=>$actor->clinicId,'user'=>$actor->userId,'start'=>$start,'end'=>$end]);
        $appointments=$query->fetchAll();
        if(count($appointments)>50)throw new ApiException(422,'too_many_visits','Too many appointments in the day-of view.');
        if($appointments){
            $this->audit->write($actor->clinicId,$actor,$correlationId,'practitioner.today_contact.view','appointment',null,'success',['appointment_ids'=>array_map(static fn($row)=>(int)$row['id'],$appointments)]);
            $ids=implode(',',array_map(static fn($row)=>(int)$row['id'],$appointments));
            $events=$pdo->prepare("SELECT id,appointment_id,event_code,event_action,occurred_at FROM appointment_visit_events WHERE clinic_id=:clinic AND appointment_id IN ({$ids}) ORDER BY id");
            $events->execute(['clinic'=>$actor->clinicId]);
            $grouped=[];foreach($events->fetchAll() as $event)$grouped[(int)$event['appointment_id']][]=$event;
            foreach($appointments as &$appointment){$appointment['events']=self::activeEvents($grouped[(int)$appointment['id']]??[]);}
            unset($appointment);
        }
        return ['date'=>$local->format('Y-m-d'),'timezone'=>$zone,'appointments'=>$appointments];
    }

    public function history(AuthContext $actor,int $appointmentId,string $correlationId): array
    {
        self::authorize($actor);
        $pdo=$this->database->connection();
        $this->ownedAppointment($pdo,$actor,$appointmentId,false);
        $query=$pdo->prepare('SELECT event_code,event_action,occurred_at FROM appointment_visit_events WHERE clinic_id=:clinic AND appointment_id=:appointment ORDER BY id DESC LIMIT 101');
        $query->execute(['clinic'=>$actor->clinicId,'appointment'=>$appointmentId]);
        $events=$query->fetchAll();
        $truncated=count($events)>100;
        if($truncated)$events=array_slice($events,0,100);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.visit_history.view','appointment',$appointmentId);
        return ['appointment_id'=>$appointmentId,'events'=>$events,'truncated'=>$truncated];
    }

    public function summary(AuthContext $actor): array
    {
        self::authorize($actor);
        $pdo=$this->database->connection();
        $zoneQuery=$pdo->prepare('SELECT l.timezone FROM practitioners p JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 JOIN locations l ON l.id=pl.location_id AND l.clinic_id=:clinic WHERE p.user_id=:user AND p.active=1 ORDER BY l.id LIMIT 1');
        $zoneQuery->execute(['clinic'=>$actor->clinicId,'user'=>$actor->userId]);
        $zone=(string)($zoneQuery->fetchColumn()?:'America/Toronto');
        try{$timezone=new DateTimeZone($zone);}catch(Throwable){$timezone=new DateTimeZone('America/Toronto');$zone='America/Toronto';}
        $local=new DateTimeImmutable('now',$timezone);
        $first=$local->modify('-6 days')->setTime(0,0);
        $last=$local->modify('+1 day')->setTime(0,0);
        $utc=new DateTimeZone('UTC');
        $query=$pdo->prepare("SELECT a.id,a.status,a.delivery_mode,a.ends_at FROM appointments a
            JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1
            WHERE a.clinic_id=:clinic AND a.starts_at>=:start AND a.starts_at<:end
              AND a.status IN ('confirmed','rescheduled','completed','no_show','invoiced','paid')
            ORDER BY a.id LIMIT 501");
        $query->execute(['clinic'=>$actor->clinicId,'user'=>$actor->userId,'start'=>$first->setTimezone($utc)->format('Y-m-d H:i:s'),'end'=>$last->setTimezone($utc)->format('Y-m-d H:i:s')]);
        $appointments=$query->fetchAll();
        if(count($appointments)>500)throw new ApiException(422,'too_many_visits','Too many visits in the seven-day summary.');
        $grouped=[];
        if($appointments){
            $ids=implode(',',array_map(static fn($row)=>(int)$row['id'],$appointments));
            $events=$pdo->prepare("SELECT appointment_id,event_code,event_action,occurred_at FROM appointment_visit_events WHERE clinic_id=:clinic AND appointment_id IN ({$ids}) ORDER BY id LIMIT 10001");
            $events->execute(['clinic'=>$actor->clinicId]);
            $rows=$events->fetchAll();
            if(count($rows)>10000)throw new ApiException(422,'too_many_visit_events','Too many visit steps in the seven-day summary.');
            foreach($rows as $event)$grouped[(int)$event['appointment_id']][]=$event;
        }
        return ['start_date'=>$first->format('Y-m-d'),'end_date'=>$local->format('Y-m-d'),'timezone'=>$zone,'counts'=>self::summaryCounts($appointments,$grouped,(new DateTimeImmutable('now',$utc))->format('Y-m-d H:i:s'))];
    }

    public static function summaryCounts(array $appointments,array $eventsByAppointment,string $nowUtc): array
    {
        $counts=['scheduled_visits'=>count($appointments),'completed'=>0,'no_show'=>0,'awaiting_outcome'=>0,'visits_with_steps'=>0,'onsite_arrivals'=>0,'onsite_departures'=>0];
        foreach($appointments as $appointment){
            $status=(string)$appointment['status'];
            if(in_array($status,['completed','invoiced','paid'],true))$counts['completed']++;
            elseif($status==='no_show')$counts['no_show']++;
            elseif(in_array($status,['confirmed','rescheduled'],true)&&$appointment['ends_at']<$nowUtc)$counts['awaiting_outcome']++;
            $active=self::activeEvents($eventsByAppointment[(int)$appointment['id']]??[]);
            if($active)$counts['visits_with_steps']++;
            if($appointment['delivery_mode']==='mobile'){
                if(isset($active['arrived']))$counts['onsite_arrivals']++;
                if(isset($active['left_residence']))$counts['onsite_departures']++;
            }
        }
        return $counts;
    }

    public function milestone(AuthContext $actor,int $appointmentId,array $body,string $correlationId): array
    {
        self::authorize($actor);
        $code=$body['code']??null;$action=$body['action']??'record';
        if(!is_string($code)||!isset(self::ORDER[$code])||!in_array($action,['record','undo'],true))throw new ApiException(422,'validation_error','Choose a supported visit action.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $appointment=$this->ownedAppointment($pdo,$actor,$appointmentId);
            self::assertMilestoneAllowed($appointment,$code,$action);
            $events=$this->events($pdo,$actor->clinicId,$appointmentId);
            $active=self::activeEvents($events);
            $latest=self::latestCode($active);
            if($action==='record'){
                if(isset($active[$code])){$pdo->commit();return ['events'=>$active];}
                if($latest!==null&&self::ORDER[$code]<self::ORDER[$latest])throw new ApiException(409,'visit_action_out_of_order','A later visit step is already recorded. Undo it before recording this one.');
            }elseif(!isset($active[$code])||$latest!==$code){
                throw new ApiException(409,'visit_action_not_latest','Only the most recent active visit step can be undone.');
            }
            $insert=$pdo->prepare('INSERT INTO appointment_visit_events(clinic_id,appointment_id,practitioner_user_id,event_code,event_action,occurred_at) VALUES(:clinic,:appointment,:user,:code,:action,UTC_TIMESTAMP())');
            $insert->execute(['clinic'=>$actor->clinicId,'appointment'=>$appointmentId,'user'=>$actor->userId,'code'=>$code,'action'=>$action]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.visit_'.$action,'appointment',$appointmentId,'success',['code'=>$code]);
            $active=self::activeEvents($this->events($pdo,$actor->clinicId,$appointmentId));
            $pdo->commit();
            return ['events'=>$active];
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public function outcome(AuthContext $actor,int $appointmentId,array $body,string $correlationId): array
    {
        self::authorize($actor);
        $outcome=$body['outcome']??null;$version=$body['version']??null;
        if(!in_array($outcome,['completed','no_show','reopen'],true)||!is_int($version)||$version<1)throw new ApiException(422,'validation_error','Choose a supported outcome and provide the current version.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $appointment=$this->ownedAppointment($pdo,$actor,$appointmentId);
            if($appointment['status']===$outcome&&(int)$appointment['version']===$version+1){$pdo->commit();return ['status'=>$outcome,'version'=>(int)$appointment['version']];}
            if((int)$appointment['version']!==$version)throw new ApiException(409,'appointment_changed','This appointment changed. Refresh it before making another change.');
            if($outcome==='reopen'){
                if(!in_array($appointment['status'],['completed','no_show'],true))throw new ApiException(409,'appointment_not_editable','Only a recently closed visit can be reopened.');
                if(new DateTimeImmutable($appointment['ends_at'],new DateTimeZone('UTC'))->modify('+2 days')<new DateTimeImmutable('now',new DateTimeZone('UTC')))throw new ApiException(409,'outcome_correction_expired','The visit correction window has ended.');
                $previous=$pdo->prepare('SELECT from_status FROM appointment_status_history WHERE appointment_id=:appointment AND to_status=:status ORDER BY id DESC LIMIT 1');
                $previous->execute(['appointment'=>$appointmentId,'status'=>$appointment['status']]);
                $restored=(string)($previous->fetchColumn()?:'');
                if(!in_array($restored,['confirmed','rescheduled'],true))throw new ApiException(409,'outcome_correction_unavailable','The previous booking status cannot be restored.');
                $update=$pdo->prepare('UPDATE appointments SET status=:status,version=version+1 WHERE id=:id AND version=:version');
                $update->execute(['status'=>$restored,'id'=>$appointmentId,'version'=>$version]);
                $history=$pdo->prepare('INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id,reason) VALUES(:appointment,:before,:after,:user,:reason)');
                $history->execute(['appointment'=>$appointmentId,'before'=>$appointment['status'],'after'=>$restored,'user'=>$actor->userId,'reason'=>'Practitioner corrected day-of outcome']);
                $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.outcome_reopen','appointment',$appointmentId);
                $pdo->commit();
                return ['status'=>$restored,'version'=>$version+1];
            }
            if(!in_array($appointment['status'],['confirmed','rescheduled'],true))throw new ApiException(409,'appointment_not_editable','This appointment cannot be closed from the day-of view.');
            if(new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC'))>new DateTimeImmutable('now',new DateTimeZone('UTC')))throw new ApiException(409,'appointment_not_started','Wait until the appointment starts before recording the outcome.');
            if($outcome==='no_show'){
                $active=self::activeEvents($this->events($pdo,$actor->clinicId,$appointmentId));
                if(isset($active['session_started'])||isset($active['session_finished']))throw new ApiException(409,'session_already_started','A started session cannot be marked as a no-show.');
            }
            $update=$pdo->prepare('UPDATE appointments SET status=:status,version=version+1 WHERE id=:id AND version=:version');
            $update->execute(['status'=>$outcome,'id'=>$appointmentId,'version'=>$version]);
            $history=$pdo->prepare('INSERT INTO appointment_status_history(appointment_id,from_status,to_status,actor_user_id) VALUES(:appointment,:before,:after,:user)');
            $history->execute(['appointment'=>$appointmentId,'before'=>$appointment['status'],'after'=>$outcome,'user'=>$actor->userId]);
            AppointmentReminderQueue::cancelForTerminal($pdo,$actor->clinicId,$appointmentId);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'appointment.'.$outcome,'appointment',$appointmentId);
            $pdo->commit();
            return ['status'=>$outcome,'version'=>$version+1];
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function activeEvents(array $events): array
    {
        $active=[];
        foreach($events as $event){
            $code=(string)($event['event_code']??'');
            if(!isset(self::ORDER[$code]))continue;
            if(($event['event_action']??'')==='record')$active[$code]=['code'=>$code,'occurred_at'=>$event['occurred_at']];
            elseif(($event['event_action']??'')==='undo')unset($active[$code]);
        }
        return $active;
    }

    public static function assertMilestoneAllowed(array $appointment,string $code,string $action): void
    {
        if(in_array($code,['en_route','arrived','left_residence'],true)&&$appointment['delivery_mode']!=='mobile')throw new ApiException(422,'onsite_action_only','This visit step is only for On-Site appointments.');
        if(!in_array($appointment['status'],['confirmed','rescheduled','completed','invoiced','paid'],true)||($appointment['status']!=='confirmed'&&$appointment['status']!=='rescheduled'&&$code!=='left_residence'))throw new ApiException(409,'appointment_not_editable','Visit steps are unavailable for this appointment.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $start=new DateTimeImmutable($appointment['starts_at'],new DateTimeZone('UTC'));
        $end=new DateTimeImmutable($appointment['ends_at'],new DateTimeZone('UTC'));
        if($now<$start->modify('-4 hours')||$now>$end->modify('+2 days'))throw new ApiException(409,'visit_action_outside_window','Visit steps are available near the appointment time only.');
    }

    private static function latestCode(array $active): ?string
    {
        $latest=null;
        foreach(array_keys($active) as $code)if($latest===null||self::ORDER[$code]>self::ORDER[$latest])$latest=$code;
        return $latest;
    }

    private function ownedAppointment(PDO $pdo,AuthContext $actor,int $appointmentId,bool $forUpdate=true): array
    {
        $query=$pdo->prepare('SELECT a.id,a.status,a.version,a.delivery_mode,a.starts_at,a.ends_at FROM appointments a JOIN practitioners p ON p.id=a.practitioner_id AND p.user_id=:user AND p.active=1 WHERE a.id=:id AND a.clinic_id=:clinic'.($forUpdate?' FOR UPDATE':''));
        $query->execute(['id'=>$appointmentId,'clinic'=>$actor->clinicId,'user'=>$actor->userId]);
        $appointment=$query->fetch();
        if(!$appointment)throw new ApiException(404,'appointment_not_found','Appointment not found.');
        return $appointment;
    }

    private function events(PDO $pdo,int $clinicId,int $appointmentId): array
    {
        $query=$pdo->prepare('SELECT event_code,event_action,occurred_at FROM appointment_visit_events WHERE clinic_id=:clinic AND appointment_id=:appointment ORDER BY id');
        $query->execute(['clinic'=>$clinicId,'appointment'=>$appointmentId]);
        return $query->fetchAll();
    }
}
