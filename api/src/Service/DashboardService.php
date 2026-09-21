<?php
declare(strict_types=1);

namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class DashboardService
{
    private const SIZES=['small','medium','wide'];
    private const ICONS=['calendar-check','clock','map-pin'];
    private const PROJECTIONS=['appointment_count','next_appointment'];
    private const CAPABILITIES=['appointments.view.clinic','appointments.view.own'];
    private const STATUSES=['draft','requested','confirmed','rescheduled','no_show','completed','invoiced','paid'];

    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public function summary(AuthContext $actor,string $workspace): array
    {
        $definitions=$this->catalogue($actor,$workspace);$timezone=$this->timezone($actor,$workspace);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$values=[];
        foreach($definitions as $definition)$values[$definition['id']]=$this->project($actor,$workspace,$timezone,$definition);
        return ['workspace'=>$workspace,'timezone'=>$timezone,'as_of'=>$now->format(DATE_ATOM),'definitions'=>$definitions,'values'=>$values];
    }

    public function preferences(AuthContext $actor,string $workspace): array
    {
        $definitions=$this->catalogue($actor,$workspace);$eligible=array_column($definitions,'id');$defaults=[];foreach($definitions as $definition)$defaults[$definition['id']]=['enabled'=>$definition['defaultEnabled'],'size'=>$definition['defaultSize'],'sizes'=>$definition['sizes']];$statement=$this->database->connection()->prepare('SELECT layout_json FROM dashboard_preferences WHERE user_id=:user AND workspace=:workspace');$statement->execute(['user'=>$actor->userId,'workspace'=>$workspace]);$stored=$statement->fetchColumn();$items=$stored!==false?json_decode((string)$stored,true):null;
        return ['version'=>1,'workspace'=>$workspace,'widgets'=>$this->normalize(is_array($items)?$items:[],$eligible,$defaults)];
    }

    public function savePreferences(AuthContext $actor,string $workspace,array $body): array
    {
        $definitions=$this->catalogue($actor,$workspace);$eligible=array_column($definitions,'id');$defaults=[];foreach($definitions as $definition)$defaults[$definition['id']]=['enabled'=>$definition['defaultEnabled'],'size'=>$definition['defaultSize'],'sizes'=>$definition['sizes']];if(($body['version']??null)!==1||!is_array($body['widgets']??null))throw new ApiException(422,'validation_error','A supported dashboard preference version and widgets array are required.');$widgets=$this->normalize($body['widgets'],$eligible,$defaults,true);
        $statement=$this->database->connection()->prepare('INSERT INTO dashboard_preferences(user_id,workspace,preference_version,layout_json) VALUES(:user,:workspace,1,:layout) ON DUPLICATE KEY UPDATE preference_version=1,layout_json=VALUES(layout_json)');$statement->execute(['user'=>$actor->userId,'workspace'=>$workspace,'layout'=>json_encode($widgets,JSON_THROW_ON_ERROR)]);return ['version'=>1,'workspace'=>$workspace,'widgets'=>$widgets];
    }

    public function resetPreferences(AuthContext $actor,string $workspace): array
    {
        $definitions=$this->catalogue($actor,$workspace);$eligible=array_column($definitions,'id');$defaults=[];foreach($definitions as $definition)$defaults[$definition['id']]=['enabled'=>$definition['defaultEnabled'],'size'=>$definition['defaultSize'],'sizes'=>$definition['sizes']];$statement=$this->database->connection()->prepare('DELETE FROM dashboard_preferences WHERE user_id=:user AND workspace=:workspace');$statement->execute(['user'=>$actor->userId,'workspace'=>$workspace]);return ['version'=>1,'workspace'=>$workspace,'widgets'=>$this->normalize([],$eligible,$defaults)];
    }

    public function adminList(AuthContext $actor): array
    {
        $this->superAdmin($actor);$overrides=$this->storedDefinitions($actor->clinicId,true);$rows=[];
        foreach($this->builtIns() as $id=>$definition){$stored=$overrides[$id]??null;$rows[]=['id'=>$id,'source'=>'built_in','enabled'=>$stored?boolval($stored['enabled']):true,'active_version'=>$stored['active_version']??0,'definition'=>$stored['definition']??$definition,'has_override'=>boolval($stored&&$stored['active_version']!==null)];unset($overrides[$id]);}
        foreach($overrides as $id=>$stored)$rows[]=['id'=>$id,'source'=>'custom','enabled'=>boolval($stored['enabled']),'active_version'=>$stored['active_version'],'definition'=>$stored['definition'],'has_override'=>true];usort($rows,fn($a,$b)=>strcmp($a['id'],$b['id']));return $rows;
    }

    public function upload(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$definition=$body['definition']??null;if(!is_array($definition))throw new ApiException(422,'validation_error','A widget definition object is required.');if(strlen(json_encode($definition,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>65536)throw new ApiException(413,'request_too_large','Widget definitions must be 64 KB or smaller.');$definition=$this->validateDefinition($definition);$id=$definition['id'];$builtIn=array_key_exists($id,$this->builtIns());$pdo=$this->database->connection();$find=$pdo->prepare('SELECT id,active_version FROM dashboard_widgets WHERE clinic_id=:clinic AND widget_key=:widget');$find->execute(['clinic'=>$actor->clinicId,'widget'=>$id]);$existing=$find->fetch();
        if(($builtIn||$existing)&&!boolval($body['confirm_replace']??false))throw new ApiException(409,'widget_exists','A widget with this ID already exists. Confirm replacement to publish a new version.');
        if($builtIn&&($definition['dataProjection']!==$this->builtIns()[$id]['dataProjection']||$definition['requiredCapability']!==$this->builtIns()[$id]['requiredCapability']))throw new ApiException(422,'validation_error','A built-in widget override cannot change its data projection or required capability.');
        $pdo->beginTransaction();try{
            if(!$existing){$insert=$pdo->prepare('INSERT INTO dashboard_widgets(clinic_id,widget_key,enabled,created_by,updated_by) VALUES(:clinic,:widget,1,:actor,:actor)');$insert->execute(['clinic'=>$actor->clinicId,'widget'=>$id,'actor'=>$actor->userId]);$widgetId=(int)$pdo->lastInsertId();$version=1;}else{$widgetId=(int)$existing['id'];$max=$pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM dashboard_widget_versions WHERE widget_id=:widget');$max->execute(['widget'=>$widgetId]);$version=(int)$max->fetchColumn();}
            $json=json_encode($definition,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$versionInsert=$pdo->prepare('INSERT INTO dashboard_widget_versions(widget_id,version_number,definition_json,definition_hash,validation_result,created_by) VALUES(:widget,:version,:definition,:hash,:validation,:actor)');$versionInsert->execute(['widget'=>$widgetId,'version'=>$version,'definition'=>$json,'hash'=>hash('sha256',$json),'validation'=>json_encode(['valid'=>true,'schema_version'=>1],JSON_THROW_ON_ERROR),'actor'=>$actor->userId]);$activate=$pdo->prepare('UPDATE dashboard_widgets SET active_version=:version,enabled=1,updated_by=:actor WHERE id=:widget');$activate->execute(['version'=>$version,'actor'=>$actor->userId,'widget'=>$widgetId]);$this->audit->write($actor->clinicId,$actor,$correlationId,'dashboard.widget.publish','dashboard_widget',$widgetId,'success',['widget_key'=>$id,'version'=>$version]);$pdo->commit();return ['id'=>$id,'version'=>$version,'replaced'=>$builtIn||boolval($existing)];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function versions(AuthContext $actor,string $id): array
    {
        $this->superAdmin($actor);$statement=$this->database->connection()->prepare('SELECT v.version_number,v.definition_json,v.definition_hash,v.created_at,u.display_name created_by,w.active_version FROM dashboard_widgets w JOIN dashboard_widget_versions v ON v.widget_id=w.id JOIN users u ON u.id=v.created_by WHERE w.clinic_id=:clinic AND w.widget_key=:widget ORDER BY v.version_number DESC');$statement->execute(['clinic'=>$actor->clinicId,'widget'=>$id]);$rows=$statement->fetchAll();foreach($rows as &$row){$row['definition']=json_decode($row['definition_json'],true);unset($row['definition_json']);$row['active']=(int)$row['active_version']===(int)$row['version_number'];unset($row['active_version']);}return ['built_in'=>array_key_exists($id,$this->builtIns()),'versions'=>$rows];
    }

    public function restore(AuthContext $actor,string $id,int $version,string $correlationId): array
    {
        $this->superAdmin($actor);$pdo=$this->database->connection();$find=$pdo->prepare('SELECT id FROM dashboard_widgets WHERE clinic_id=:clinic AND widget_key=:widget');$find->execute(['clinic'=>$actor->clinicId,'widget'=>$id]);$widgetId=(int)($find->fetchColumn()?:0);if(!$widgetId)throw new ApiException(404,'widget_not_found','Widget not found.');
        if($version===0){if(!array_key_exists($id,$this->builtIns()))throw new ApiException(422,'validation_error','Only a built-in widget can restore the system definition.');$update=$pdo->prepare('UPDATE dashboard_widgets SET active_version=NULL,enabled=1,updated_by=:actor WHERE id=:widget');$args=['actor'=>$actor->userId,'widget'=>$widgetId];}
        else{$check=$pdo->prepare('SELECT 1 FROM dashboard_widget_versions WHERE widget_id=:widget AND version_number=:version');$check->execute(['widget'=>$widgetId,'version'=>$version]);if(!$check->fetchColumn())throw new ApiException(404,'widget_version_not_found','Widget version not found.');$update=$pdo->prepare('UPDATE dashboard_widgets SET active_version=:version,enabled=1,updated_by=:actor WHERE id=:widget');$args=['version'=>$version,'actor'=>$actor->userId,'widget'=>$widgetId];}
        $update->execute($args);$this->audit->write($actor->clinicId,$actor,$correlationId,'dashboard.widget.restore','dashboard_widget',$widgetId,'success',['widget_key'=>$id,'version'=>$version]);return ['id'=>$id,'active_version'=>$version];
    }

    public function setEnabled(AuthContext $actor,string $id,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);if(!is_bool($body['enabled']??null))throw new ApiException(422,'validation_error','enabled must be a boolean.');$pdo=$this->database->connection();$exists=$pdo->prepare('SELECT id FROM dashboard_widgets WHERE clinic_id=:clinic AND widget_key=:widget');$exists->execute(['clinic'=>$actor->clinicId,'widget'=>$id]);$widgetId=$exists->fetchColumn();if($widgetId){$statement=$pdo->prepare('UPDATE dashboard_widgets SET enabled=:enabled,updated_by=:actor WHERE id=:id');$statement->execute(['enabled'=>$body['enabled']?1:0,'actor'=>$actor->userId,'id'=>$widgetId]);}else{
            if(!array_key_exists($id,$this->builtIns()))throw new ApiException(404,'widget_not_found','Widget not found.');$insert=$pdo->prepare('INSERT INTO dashboard_widgets(clinic_id,widget_key,active_version,enabled,created_by,updated_by) VALUES(:clinic,:widget,NULL,:enabled,:actor,:actor)');$insert->execute(['clinic'=>$actor->clinicId,'widget'=>$id,'enabled'=>$body['enabled']?1:0,'actor'=>$actor->userId]);$widgetId=$pdo->lastInsertId();
        }$this->audit->write($actor->clinicId,$actor,$correlationId,'dashboard.widget.toggle','dashboard_widget',(int)$widgetId,'success',['widget_key'=>$id,'enabled'=>$body['enabled']]);return ['id'=>$id,'enabled'=>$body['enabled']];
    }

    private function catalogue(AuthContext $actor,string $workspace): array
    {
        $this->assertWorkspace($actor,$workspace);$definitions=$this->builtIns();foreach($this->storedDefinitions($actor->clinicId,true) as $id=>$stored){if(!$stored['enabled'])unset($definitions[$id]);elseif($stored['definition'])$definitions[$id]=$stored['definition'];}
        $eligible=array_values(array_filter($definitions,fn($definition)=>in_array($workspace,$definition['workspaces'],true)&&$this->can($actor,$definition['requiredCapability'])));usort($eligible,fn($a,$b)=>($a['defaultOrder']<=>$b['defaultOrder'])?:strcmp($a['id'],$b['id']));return $eligible;
    }

    private function project(AuthContext $actor,string $workspace,string $timezone,array $definition): mixed
    {
        $params=['clinic'=>$actor->clinicId];$where='a.clinic_id=:clinic';if($workspace==='practitioner'){$where.=' AND a.practitioner_id=(SELECT id FROM practitioners WHERE user_id=:user AND active=1 LIMIT 1)';$params['user']=$actor->userId;}$configuration=$definition['parameters']??[];
        if(($configuration['date']??null)==='today'){$local=new DateTimeImmutable('now',new DateTimeZone($timezone));$from=$local->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));$to=$local->modify('+1 day')->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));$where.=' AND a.starts_at>=:from_time AND a.starts_at<:to_time';$params['from_time']=$from->format('Y-m-d H:i:s');$params['to_time']=$to->format('Y-m-d H:i:s');}
        $statuses=$configuration['statuses']??self::STATUSES;if($statuses){$marks=[];foreach($statuses as $index=>$status){$key='status_'.$index;$marks[]=':'.$key;$params[$key]=$status;}$where.=' AND a.status IN ('.implode(',',$marks).')';}if(isset($configuration['delivery_mode'])){$where.=' AND a.delivery_mode=:delivery_mode';$params['delivery_mode']=$configuration['delivery_mode'];}
        if($definition['dataProjection']==='appointment_count'){$statement=$this->database->connection()->prepare("SELECT COUNT(*) FROM appointments a WHERE {$where}");$statement->execute($params);return (int)$statement->fetchColumn();}
        $where.=' AND a.ends_at>=UTC_TIMESTAMP()';$statement=$this->database->connection()->prepare("SELECT a.id,a.starts_at,a.delivery_mode,s.name service_name,u.display_name client_name,l.timezone FROM appointments a JOIN services s ON s.id=a.service_id JOIN users u ON u.id=a.client_id JOIN locations l ON l.id=a.location_id WHERE {$where} ORDER BY a.starts_at,a.id LIMIT 1");$statement->execute($params);return $statement->fetch()?:null;
    }

    private function storedDefinitions(int $clinicId,bool $includeDisabled=false): array
    {
        $sql='SELECT w.widget_key,w.enabled,w.active_version,v.definition_json FROM dashboard_widgets w LEFT JOIN dashboard_widget_versions v ON v.widget_id=w.id AND v.version_number=w.active_version WHERE w.clinic_id=:clinic'.($includeDisabled?'':' AND w.enabled=1');$statement=$this->database->connection()->prepare($sql);$statement->execute(['clinic'=>$clinicId]);$result=[];foreach($statement->fetchAll() as $row)$result[$row['widget_key']]=['enabled'=>boolval($row['enabled']),'active_version'=>$row['active_version']===null?null:(int)$row['active_version'],'definition'=>$row['definition_json']?json_decode($row['definition_json'],true):null];return $result;
    }

    private function validateDefinition(array $input): array
    {
        $id=(string)($input['id']??'');if(!preg_match('/^[a-z][a-z0-9_]{2,99}$/',$id))throw new ApiException(422,'validation_error','Widget ID must use lowercase letters, numbers, and underscores.');$renderer=(string)($input['renderer']??'');if(!in_array($renderer,['metric','next_appointment'],true))throw new ApiException(422,'validation_error','Unknown widget renderer.');$projection=(string)($input['dataProjection']??'');if(!in_array($projection,self::PROJECTIONS,true))throw new ApiException(422,'validation_error','Unknown widget data projection.');if(($renderer==='next_appointment')!==($projection==='next_appointment'))throw new ApiException(422,'validation_error','The renderer does not match the data projection.');$capability=(string)($input['requiredCapability']??'');if(!in_array($capability,self::CAPABILITIES,true))throw new ApiException(422,'validation_error','Unknown widget capability.');
        $requestedWorkspaces=$input['workspaces']??[];$workspaces=is_array($requestedWorkspaces)?array_values(array_unique(array_filter($requestedWorkspaces,fn($value)=>is_string($value)&&in_array($value,['admin','practitioner'],true)))):[];if(!$workspaces||count($workspaces)!==count($requestedWorkspaces))throw new ApiException(422,'validation_error','Choose one or more supported workspaces.');if($capability==='appointments.view.clinic'&&$workspaces!==['admin'])throw new ApiException(422,'validation_error','Clinic appointment widgets are limited to the Operations workspace.');if($capability==='appointments.view.own'&&$workspaces!==['practitioner'])throw new ApiException(422,'validation_error','Own-appointment widgets are limited to the Practitioner workspace.');
        foreach(['title','description'] as $field){if(!is_array($input[$field]??null))throw new ApiException(422,'validation_error',"{$field} must contain English and French text.");foreach(['en','fr'] as $language){$value=trim((string)($input[$field][$language]??''));$length=function_exists('mb_strlen')?mb_strlen($value):strlen($value);if($value===''||$length>300)throw new ApiException(422,'validation_error',"{$field}.{$language} is required and must be at most 300 characters.");$input[$field][$language]=$value;}}
        $icon=(string)($input['icon']??'');if(!in_array($icon,self::ICONS,true))throw new ApiException(422,'validation_error','Unknown widget icon.');$requestedSizes=$input['sizes']??[];$sizes=is_array($requestedSizes)?array_values(array_unique(array_filter($requestedSizes,fn($value)=>is_string($value)&&in_array($value,self::SIZES,true)))):[];if(!$sizes||count($sizes)!==count($requestedSizes))throw new ApiException(422,'validation_error','Choose one or more supported widget sizes.');$default=(string)($input['defaultSize']??'');if(!in_array($default,$sizes,true))throw new ApiException(422,'validation_error','Default size must be one of the supported sizes.');$destination=$input['destination']??null;if(!is_array($destination)||($destination['page']??null)!=='appointments')throw new ApiException(422,'validation_error','Widget destination must be the appointments page.');
        $parameters=$input['parameters']??[];if(!is_array($parameters)||array_diff(array_keys($parameters),['date','statuses','delivery_mode']))throw new ApiException(422,'validation_error','Widget parameters contain unsupported fields.');if(isset($parameters['date'])&&$parameters['date']!=='today')throw new ApiException(422,'validation_error','Only the today date filter is currently supported.');if(isset($parameters['delivery_mode'])&&!in_array($parameters['delivery_mode'],['clinic','mobile'],true))throw new ApiException(422,'validation_error','Unknown delivery mode.');if(isset($parameters['statuses'])){$statuses=$parameters['statuses'];if(!is_array($statuses)||!$statuses||count(array_filter($statuses,fn($value)=>is_string($value)&&in_array($value,self::STATUSES,true)))!==count($statuses))throw new ApiException(422,'validation_error','Widget statuses contain unsupported values.');}
        return ['id'=>$id,'schemaVersion'=>1,'workspaces'=>$workspaces,'renderer'=>$renderer,'dataProjection'=>$projection,'parameters'=>$parameters,'requiredCapability'=>$capability,'title'=>$input['title'],'description'=>$input['description'],'icon'=>$icon,'destination'=>['page'=>'appointments'],'sizes'=>$sizes,'defaultSize'=>$default,'defaultEnabled'=>boolval($input['defaultEnabled']??true),'defaultOrder'=>max(0,min(10000,(int)($input['defaultOrder']??100)))];
    }

    private function builtIns(): array
    {
        $base=['schemaVersion'=>1,'renderer'=>'metric','dataProjection'=>'appointment_count','icon'=>'calendar-check','destination'=>['page'=>'appointments'],'sizes'=>self::SIZES,'defaultSize'=>'small','defaultEnabled'=>true];$definitions=[
            ['id'=>'appointments_today','workspaces'=>['admin'],'requiredCapability'=>'appointments.view.clinic','title'=>['en'=>"Today's appointments",'fr'=>'Rendez-vous d’aujourd’hui'],'description'=>['en'=>'All active appointments scheduled today.','fr'=>'Tous les rendez-vous actifs prévus aujourd’hui.'],'parameters'=>['date'=>'today'],'defaultOrder'=>10],
            ['id'=>'awaiting_confirmation','workspaces'=>['admin'],'requiredCapability'=>'appointments.view.clinic','title'=>['en'=>'Awaiting confirmation','fr'=>'En attente de confirmation'],'description'=>['en'=>'Requested appointments that still need confirmation.','fr'=>'Rendez-vous demandés qui doivent encore être confirmés.'],'parameters'=>['date'=>'today','statuses'=>['requested']],'icon'=>'clock','defaultOrder'=>20],
            ['id'=>'onsite_today','workspaces'=>['admin'],'requiredCapability'=>'appointments.view.clinic','title'=>['en'=>"Today's On-Site visits",'fr'=>'Visites sur place aujourd’hui'],'description'=>['en'=>'Appointments taking place at a client location.','fr'=>'Rendez-vous ayant lieu chez un client.'],'parameters'=>['date'=>'today','delivery_mode'=>'mobile'],'icon'=>'map-pin','defaultOrder'=>30],
            ['id'=>'my_appointments_today','workspaces'=>['practitioner'],'requiredCapability'=>'appointments.view.own','title'=>['en'=>'My appointments today','fr'=>'Mes rendez-vous aujourd’hui'],'description'=>['en'=>'Your active appointments scheduled today.','fr'=>'Vos rendez-vous actifs prévus aujourd’hui.'],'parameters'=>['date'=>'today'],'defaultOrder'=>10],
            ['id'=>'my_next_appointment','workspaces'=>['practitioner'],'requiredCapability'=>'appointments.view.own','title'=>['en'=>'My next appointment','fr'=>'Mon prochain rendez-vous'],'description'=>['en'=>'Your next active appointment.','fr'=>'Votre prochain rendez-vous actif.'],'parameters'=>[],'renderer'=>'next_appointment','dataProjection'=>'next_appointment','icon'=>'clock','defaultSize'=>'medium','defaultOrder'=>20],
            ['id'=>'my_onsite_today','workspaces'=>['practitioner'],'requiredCapability'=>'appointments.view.own','title'=>['en'=>'My On-Site visits today','fr'=>'Mes visites sur place aujourd’hui'],'description'=>['en'=>'Your visits taking place at a client location.','fr'=>'Vos visites ayant lieu chez un client.'],'parameters'=>['date'=>'today','delivery_mode'=>'mobile'],'icon'=>'map-pin','defaultOrder'=>30],
        ];$result=[];foreach($definitions as $definition){$merged=array_replace($base,$definition);$result[$merged['id']]=$merged;}return $result;
    }

    private function assertWorkspace(AuthContext $actor,string $workspace): void{if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Dashboard access requires a staff account.');if($workspace==='practitioner'&&$actor->hasAnyRole('practitioner'))return;if($workspace==='admin'&&$actor->hasAnyRole('super_admin','clinic_admin','reception','accountant'))return;throw new ApiException(403,'forbidden','This dashboard workspace is not available to this account.');}
    private function can(AuthContext $actor,string $capability): bool{return $capability==='appointments.view.clinic'?$actor->hasAnyRole('super_admin','clinic_admin','reception'):$actor->hasAnyRole('practitioner');}
    private function superAdmin(AuthContext $actor): void{if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin'))throw new ApiException(403,'forbidden','Only a Super Admin can manage dashboard widgets.');}
    private function timezone(AuthContext $actor,string $workspace): string{$sql=$workspace==='practitioner'?'SELECT l.timezone FROM practitioners p JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 JOIN locations l ON l.id=pl.location_id AND l.is_bookable=1 WHERE p.user_id=:user AND l.clinic_id=:clinic ORDER BY l.id LIMIT 1':'SELECT timezone FROM locations WHERE clinic_id=:clinic AND is_bookable=1 ORDER BY id LIMIT 1';$statement=$this->database->connection()->prepare($sql);$params=['clinic'=>$actor->clinicId];if($workspace==='practitioner')$params['user']=$actor->userId;$statement->execute($params);$timezone=(string)($statement->fetchColumn()?:'America/Toronto');try{new DateTimeZone($timezone);}catch(Throwable){$timezone='America/Toronto';}return $timezone;}
    private function normalize(array $items,array $eligible,array $defaults,bool $strict=false): array{$result=[];$seen=[];foreach($items as $item){if(!is_array($item)||!is_string($item['id']??null)||!in_array($item['id'],$eligible,true)||isset($seen[$item['id']])){if($strict)throw new ApiException(422,'validation_error','Dashboard preferences contain an invalid or duplicate widget.');continue;}$size=(string)($item['size']??'small');if(!in_array($size,$defaults[$item['id']]['sizes']??self::SIZES,true)){if($strict)throw new ApiException(422,'validation_error','Dashboard preferences contain an invalid widget size.');$size=$defaults[$item['id']]['size']??'small';}$seen[$item['id']]=true;$result[]=['id'=>$item['id'],'enabled'=>(bool)($item['enabled']??true),'order'=>count($result),'size'=>$size];}foreach($eligible as $id)if(!isset($seen[$id]))$result[]=['id'=>$id,'enabled'=>$defaults[$id]['enabled']??true,'order'=>count($result),'size'=>$defaults[$id]['size']??'small'];return $result;}
}
