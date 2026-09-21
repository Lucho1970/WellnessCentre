<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class DashboardService
{
    private const ADMIN_WIDGETS = ['appointments_today','awaiting_confirmation','onsite_today'];
    private const PRACTITIONER_WIDGETS = ['my_appointments_today','my_next_appointment','my_onsite_today'];
    private const SIZES = ['small','medium','wide'];

    public function __construct(private readonly Database $database) {}

    public function summary(AuthContext $actor,string $workspace): array
    {
        $widgets=$this->eligible($actor,$workspace);
        $timezone=$this->timezone($actor,$workspace);
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($widgets===[])return ['workspace'=>$workspace,'timezone'=>$timezone,'as_of'=>$now->format(DATE_ATOM),'widgets'=>[],'metrics'=>['appointments_today'=>0,'awaiting_confirmation'=>0,'onsite_today'=>0,'next_appointment'=>null]];
        $local=$now->setTimezone(new DateTimeZone($timezone));
        $from=$local->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));
        $to=$local->modify('+1 day')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));
        $where="a.clinic_id=:clinic AND a.starts_at>=:from_time AND a.starts_at<:to_time AND a.status NOT IN ('canceled_by_client','canceled_by_clinic')";
        $params=['clinic'=>$actor->clinicId,'from_time'=>$from->format('Y-m-d H:i:s'),'to_time'=>$to->format('Y-m-d H:i:s')];
        if($workspace==='practitioner'){
            $where.=' AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user AND active=1 LIMIT 1)';
            $params['user']=$actor->userId;
        }
        $statement=$this->database->connection()->prepare("SELECT COUNT(*) appointments_today,SUM(a.status='requested') awaiting_confirmation,SUM(a.delivery_mode='mobile') onsite_today FROM appointments a WHERE {$where}");
        $statement->execute($params);$counts=$statement->fetch()?:[];
        $next=null;
        if(in_array('my_next_appointment',$widgets,true)){
            $nextStatement=$this->database->connection()->prepare("SELECT a.id,a.starts_at,a.delivery_mode,s.name service_name,u.display_name client_name,l.timezone FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id JOIN locations l ON l.id=a.location_id WHERE a.clinic_id=:clinic AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user AND active=1 LIMIT 1) AND a.ends_at>=UTC_TIMESTAMP() AND a.status NOT IN ('canceled_by_client','canceled_by_clinic') ORDER BY a.starts_at,a.id LIMIT 1");
            $nextStatement->execute(['clinic'=>$actor->clinicId,'user'=>$actor->userId]);$next=$nextStatement->fetch()?:null;
        }
        return ['workspace'=>$workspace,'timezone'=>$timezone,'as_of'=>$now->format(DATE_ATOM),'widgets'=>array_fill_keys($widgets,true),'metrics'=>[
            'appointments_today'=>(int)($counts['appointments_today']??0),
            'awaiting_confirmation'=>(int)($counts['awaiting_confirmation']??0),
            'onsite_today'=>(int)($counts['onsite_today']??0),
            'next_appointment'=>$next,
        ]];
    }

    public function preferences(AuthContext $actor,string $workspace): array
    {
        $eligible=$this->eligible($actor,$workspace);
        $statement=$this->database->connection()->prepare('SELECT layout_json FROM dashboard_preferences WHERE user_id=:user AND workspace=:workspace');
        $statement->execute(['user'=>$actor->userId,'workspace'=>$workspace]);$stored=$statement->fetchColumn();
        $items=$stored!==false?json_decode((string)$stored,true):null;
        return ['version'=>1,'workspace'=>$workspace,'widgets'=>$this->normalize(is_array($items)?$items:[],$eligible)];
    }

    public function savePreferences(AuthContext $actor,string $workspace,array $body): array
    {
        $eligible=$this->eligible($actor,$workspace);
        if(($body['version']??null)!==1||!is_array($body['widgets']??null))throw new ApiException(422,'validation_error','A supported dashboard preference version and widgets array are required.');
        $widgets=$this->normalize($body['widgets'],$eligible,true);
        $statement=$this->database->connection()->prepare("INSERT INTO dashboard_preferences(user_id,workspace,preference_version,layout_json) VALUES(:user,:workspace,1,:layout) ON DUPLICATE KEY UPDATE preference_version=1,layout_json=VALUES(layout_json)");
        $statement->execute(['user'=>$actor->userId,'workspace'=>$workspace,'layout'=>json_encode($widgets,JSON_THROW_ON_ERROR)]);
        return ['version'=>1,'workspace'=>$workspace,'widgets'=>$widgets];
    }

    public function resetPreferences(AuthContext $actor,string $workspace): array
    {
        $eligible=$this->eligible($actor,$workspace);
        $statement=$this->database->connection()->prepare('DELETE FROM dashboard_preferences WHERE user_id=:user AND workspace=:workspace');
        $statement->execute(['user'=>$actor->userId,'workspace'=>$workspace]);
        return ['version'=>1,'workspace'=>$workspace,'widgets'=>$this->normalize([],$eligible)];
    }

    private function eligible(AuthContext $actor,string $workspace): array
    {
        if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Dashboard access requires a staff account.');
        if($workspace==='practitioner'){
            if(!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','The practitioner dashboard is not available to this account.');
            return self::PRACTITIONER_WIDGETS;
        }
        if($workspace!=='admin'||!$actor->hasAnyRole('super_admin','clinic_admin','reception','accountant'))throw new ApiException(403,'forbidden','The operations dashboard is not available to this account.');
        return $actor->hasAnyRole('super_admin','clinic_admin','reception')?self::ADMIN_WIDGETS:[];
    }

    private function timezone(AuthContext $actor,string $workspace): string
    {
        $sql=$workspace==='practitioner'
            ?'SELECT l.timezone FROM practitioners p JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 JOIN locations l ON l.id=pl.location_id AND l.is_bookable=1 WHERE p.user_id=:user AND l.clinic_id=:clinic ORDER BY l.id LIMIT 1'
            :'SELECT timezone FROM locations WHERE clinic_id=:clinic AND is_bookable=1 ORDER BY id LIMIT 1';
        $statement=$this->database->connection()->prepare($sql);$params=['clinic'=>$actor->clinicId];if($workspace==='practitioner')$params['user']=$actor->userId;$statement->execute($params);
        $timezone=(string)($statement->fetchColumn()?:'America/Toronto');
        try{new DateTimeZone($timezone);}catch(\Throwable){$timezone='America/Toronto';}return $timezone;
    }

    private function normalize(array $items,array $eligible,bool $strict=false): array
    {
        $result=[];$seen=[];
        foreach($items as $item){
            if(!is_array($item)||!is_string($item['id']??null)||!in_array($item['id'],$eligible,true)||isset($seen[$item['id']])){if($strict)throw new ApiException(422,'validation_error','Dashboard preferences contain an invalid or duplicate widget.');continue;}
            $size=(string)($item['size']??'small');if(!in_array($size,self::SIZES,true)){if($strict)throw new ApiException(422,'validation_error','Dashboard preferences contain an invalid widget size.');$size='small';}
            $seen[$item['id']]=true;$result[]=['id'=>$item['id'],'enabled'=>(bool)($item['enabled']??true),'order'=>count($result),'size'=>$size];
        }
        foreach($eligible as $id)if(!isset($seen[$id]))$result[]=['id'=>$id,'enabled'=>true,'order'=>count($result),'size'=>$id==='my_next_appointment'?'medium':'small'];
        return $result;
    }
}
