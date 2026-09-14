<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class AdminService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public function updateClinic(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$this->required($body,['name']);
        $name=trim((string)$body['name']);$legalName=$this->optional($body,'legal_name');$email=$this->optional($body,'email');$phone=$this->optional($body,'phone');
        if(strlen($name)>160)throw new ApiException(422,'validation_error','The operating name is too long.',['name'=>'Maximum 160 characters']);
        if($legalName!==null&&strlen($legalName)>190)throw new ApiException(422,'validation_error','The legal name is too long.',['legal_name'=>'Maximum 190 characters']);
        if($email!==null&&(strlen($email)>190||filter_var($email,FILTER_VALIDATE_EMAIL)===false))throw new ApiException(422,'validation_error','Enter a valid email address.',['email'=>'Invalid email']);
        if($phone!==null&&strlen($phone)>40)throw new ApiException(422,'validation_error','The phone number is too long.',['phone'=>'Maximum 40 characters']);
        $pdo=$this->database->connection();
        try{$pdo->beginTransaction();$statement=$pdo->prepare('UPDATE clinics SET name=:name,legal_name=:legal_name,email=:email,phone=:phone WHERE id=:clinic');$statement->execute(['name'=>$name,'legal_name'=>$legalName,'email'=>$email,'phone'=>$phone,'clinic'=>$actor->clinicId]);if($statement->rowCount()===0){$check=$pdo->prepare('SELECT 1 FROM clinics WHERE id=:clinic');$check->execute(['clinic'=>$actor->clinicId]);if(!$check->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic not found.');}$this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.settings.update','clinic',$actor->clinicId,['fields'=>['name','legal_name','email','phone']]);$pdo->commit();return $this->clinic($actor->clinicId);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function createLocation(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['name','timezone']);
        $sql='INSERT INTO locations(clinic_id,name,timezone,address_line1,address_line2,city,province,postal_code,phone) VALUES(:clinic,:name,:timezone,:line1,:line2,:city,:province,:postal,:phone)';
        $statement=$this->database->connection()->prepare($sql);$statement->execute(['clinic'=>$actor->clinicId,'name'=>$body['name'],'timezone'=>$body['timezone'],'line1'=>$body['address_line1']??null,'line2'=>$body['address_line2']??null,'city'=>$body['city']??null,'province'=>$body['province']??null,'postal'=>$body['postal_code']??null,'phone'=>$body['phone']??null]);
        return $this->created($actor,$correlationId,'location',(int)$this->database->connection()->lastInsertId());
    }

    public function createRoom(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['location_id','name']);$this->ownedLocation($actor,(int)$body['location_id']);
        $statement=$this->database->connection()->prepare('INSERT INTO rooms(location_id,name,room_type,equipment_notes,turnover_minutes) VALUES(:location,:name,:type,:notes,:turnover)');$statement->execute(['location'=>(int)$body['location_id'],'name'=>$body['name'],'type'=>$body['room_type']??null,'notes'=>$body['equipment_notes']??null,'turnover'=>(int)($body['turnover_minutes']??0)]);
        return $this->created($actor,$correlationId,'room',(int)$this->database->connection()->lastInsertId());
    }

    public function createStaff(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['tenant_id','object_id','email','display_name','role']);
        $allowed=['super_admin','clinic_admin','reception','practitioner','accountant'];if(!in_array($body['role'],$allowed,true))throw new ApiException(422,'validation_error','Invalid staff role.',['role'=>'Invalid role']);
        $pdo=$this->database->connection();
        try{$pdo->beginTransaction();$statement=$pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(:clinic,:email,:name,'staff','active')");$statement->execute(['clinic'=>$actor->clinicId,'email'=>strtolower(trim($body['email'])),'name'=>$body['display_name']]);$id=(int)$pdo->lastInsertId();
            $statement=$pdo->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject,email_at_link_time) VALUES(:user,'microsoft',:tenant,:subject,:email)");$statement->execute(['user'=>$id,'tenant'=>$body['tenant_id'],'subject'=>$body['object_id'],'email'=>strtolower(trim($body['email']))]);
            $statement=$pdo->prepare('INSERT INTO user_roles(user_id,role_id,location_id,assigned_by) SELECT :user,id,:location,:actor FROM roles WHERE code=:role');$statement->execute(['user'=>$id,'location'=>isset($body['location_id'])?(int)$body['location_id']:null,'actor'=>$actor->userId,'role'=>$body['role']]);
            $statement=$pdo->prepare('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(:user,1)');$statement->execute(['user'=>$id]);$this->audit->write($actor->clinicId,$actor,$correlationId,'staff.create','user',$id,['role'=>$body['role']]);$pdo->commit();return ['id'=>$id,'status'=>'active'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function createPractitioner(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['user_id','discipline','location_id']);$this->ownedLocation($actor,(int)$body['location_id']);$pdo=$this->database->connection();
        try{$pdo->beginTransaction();$statement=$pdo->prepare('INSERT INTO practitioners(user_id,discipline,biography,credentials,booking_mode) SELECT id,:discipline,:bio,:credentials,:mode FROM users WHERE id=:user AND clinic_id=:clinic AND user_type=\'staff\'');$statement->execute(['discipline'=>$body['discipline'],'bio'=>$body['biography']??null,'credentials'=>$body['credentials']??null,'mode'=>$body['booking_mode']??'clinic_managed','user'=>(int)$body['user_id'],'clinic'=>$actor->clinicId]);if($statement->rowCount()!==1)throw new ApiException(422,'invalid_user','Practitioner user was not found in this clinic.');$id=(int)$pdo->lastInsertId();$statement=$pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(:p,:l)');$statement->execute(['p'=>$id,'l'=>(int)$body['location_id']]);$this->audit->write($actor->clinicId,$actor,$correlationId,'practitioner.create','practitioner',$id);$pdo->commit();return ['id'=>$id,'status'=>'active'];}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function createService(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['name','price_cents','durations']);if(!is_array($body['durations'])||$body['durations']===[])throw new ApiException(422,'validation_error','At least one duration is required.',['durations'=>'Required']);foreach($body['durations'] as $duration)if((int)$duration<=0||(int)$duration%15!==0)throw new ApiException(422,'validation_error','Durations must be positive 15-minute increments.',['durations'=>'Invalid duration']);$pdo=$this->database->connection();
        try{$pdo->beginTransaction();$statement=$pdo->prepare("INSERT INTO services(clinic_id,category_id,name,description,preparation_instructions,price_cents,duration_mode,lead_time_minutes,booking_horizon_days,cancellation_window_minutes,cancellation_fee_type,cancellation_fee_value,buffer_before_minutes,buffer_after_minutes,requires_room,recurrence_allowed) VALUES(:clinic,:category,:name,:description,:instructions,:price,:mode,:lead,:horizon,:cancel_window,:fee_type,:fee_value,:before,:after,:room,:recurrence)");$statement->execute(['clinic'=>$actor->clinicId,'category'=>$body['category_id']??null,'name'=>$body['name'],'description'=>$body['description']??null,'instructions'=>$body['preparation_instructions']??null,'price'=>(int)$body['price_cents'],'mode'=>count($body['durations'])>1?'selectable':'fixed','lead'=>(int)($body['lead_time_minutes']??0),'horizon'=>(int)($body['booking_horizon_days']??365),'cancel_window'=>(int)($body['cancellation_window_minutes']??1440),'fee_type'=>$body['cancellation_fee_type']??'none','fee_value'=>(int)($body['cancellation_fee_value']??0),'before'=>(int)($body['buffer_before_minutes']??0),'after'=>(int)($body['buffer_after_minutes']??0),'room'=>(bool)($body['requires_room']??true),'recurrence'=>(bool)($body['recurrence_allowed']??false)]);$id=(int)$pdo->lastInsertId();$durationInsert=$pdo->prepare('INSERT INTO service_duration_options(service_id,duration_minutes) VALUES(:service,:minutes)');foreach($body['durations'] as $duration)$durationInsert->execute(['service'=>$id,'minutes'=>(int)$duration]);foreach($body['practitioner_ids']??[] as $practitioner){$link=$pdo->prepare('INSERT INTO practitioner_services(practitioner_id,service_id) SELECT id,:service FROM practitioners WHERE id=:practitioner AND user_id IN(SELECT id FROM users WHERE clinic_id=:clinic)');$link->execute(['service'=>$id,'practitioner'=>(int)$practitioner,'clinic'=>$actor->clinicId]);}$this->audit->write($actor->clinicId,$actor,$correlationId,'service.create','service',$id);$pdo->commit();return ['id'=>$id,'status'=>'active'];}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function createAvailability(AuthContext $actor,array $body,string $correlationId): array
    {
        if(!$actor->hasAnyRole('super_admin','clinic_admin','practitioner'))throw new ApiException(403,'forbidden','Your role cannot manage availability.');$this->required($body,['practitioner_id','location_id','weekday','start_time','end_time','valid_from']);$this->ownedLocation($actor,(int)$body['location_id']);
        if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin')){$statement=$this->database->connection()->prepare('SELECT 1 FROM practitioners WHERE id=:practitioner AND user_id=:user');$statement->execute(['practitioner'=>(int)$body['practitioner_id'],'user'=>$actor->userId]);if(!$statement->fetchColumn())throw new ApiException(403,'forbidden','Practitioners can only manage their own availability.');}
        $statement=$this->database->connection()->prepare('INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from,valid_until,recurrence_interval_weeks) VALUES(:p,:l,:weekday,:start,:end,:valid_from,:valid_until,:interval)');$statement->execute(['p'=>(int)$body['practitioner_id'],'l'=>(int)$body['location_id'],'weekday'=>(int)$body['weekday'],'start'=>$body['start_time'],'end'=>$body['end_time'],'valid_from'=>$body['valid_from'],'valid_until'=>$body['valid_until']??null,'interval'=>(int)($body['recurrence_interval_weeks']??1)]);return $this->created($actor,$correlationId,'availability_rule',(int)$this->database->connection()->lastInsertId());
    }

    private function admin(AuthContext $actor): void{if(!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Administrator access is required.');}
    private function superAdmin(AuthContext $actor): void{if(!$actor->hasAnyRole('super_admin'))throw new ApiException(403,'forbidden','Super administrator access is required.');}
    private function required(array $body,array $fields): void{foreach($fields as $field)if(!array_key_exists($field,$body)||$body[$field]===''||$body[$field]===null)throw new ApiException(422,'validation_error',"{$field} is required.",[$field=>'Required']);}
    private function optional(array $body,string $field): ?string{$value=trim((string)($body[$field]??''));return $value===''?null:$value;}
    private function clinic(int $clinicId): array{$statement=$this->database->connection()->prepare('SELECT name,legal_name,email,phone FROM clinics WHERE id=:clinic');$statement->execute(['clinic'=>$clinicId]);$clinic=$statement->fetch();if(!$clinic)throw new ApiException(404,'clinic_not_found','Clinic not found.');return $clinic;}
    private function ownedLocation(AuthContext $actor,int $locationId): void{$statement=$this->database->connection()->prepare('SELECT 1 FROM locations WHERE id=:id AND clinic_id=:clinic');$statement->execute(['id'=>$locationId,'clinic'=>$actor->clinicId]);if(!$statement->fetchColumn())throw new ApiException(404,'location_not_found','Location not found.');}
    private function created(AuthContext $actor,string $correlationId,string $type,int $id): array{$this->audit->write($actor->clinicId,$actor,$correlationId,$type.'.create',$type,$id);return ['id'=>$id];}
}
