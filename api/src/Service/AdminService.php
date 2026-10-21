<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class AdminService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit,private readonly bool $globalPractitionersEnabled=false) {}

    /** Clinic administration never grants access to patient records. */
    private function clinicAdministration(AuthContext $actor,callable $work,array $roles=['super_admin'],bool $clinicWide=false): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole(...$roles))throw new ApiException(403,'forbidden','Administrator access is required.');
        $pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Clinic administration requires its own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Administrator access is required.');
            $marks=implode(',',array_fill(0,count($roles),'?'));$roleScope=$clinicWide?' AND ur.location_id IS NULL':'';
            $q=$pdo->prepare("SELECT u.id FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
                AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ($marks)$roleScope) FOR UPDATE");
            $q->execute([$actor->userId,$actor->clinicId,...$roles]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Administrator access is required.');
            $result=$work();$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    public function practitioners(AuthContext $actor): array
    {return $this->clinicAdministration($actor,fn()=>$this->practitionerDirectory($actor));}

    public function onboardPractitioner(AuthContext $actor,array $body,string $cid): array
    {return $this->clinicAdministration($actor,fn()=>$this->onboardPractitionerRecord($actor,$body,$cid));}

    public function updatePractitioner(AuthContext $actor,int $id,array $body,string $cid): array
    {return $this->clinicAdministration($actor,fn()=>$this->updatePractitionerRecord($actor,$id,$body,$cid));}

    public function createPractitioner(AuthContext $actor,array $body,string $cid): array
    {return $this->clinicAdministration($actor,fn()=>$this->createPractitionerRecord($actor,$body,$cid),['super_admin','clinic_admin']);}

    private function practitionerDirectory(AuthContext $actor): array
    {
        $this->superAdmin($actor);
        $statement=$this->database->connection()->prepare("SELECT p.id AS practitioner_id,u.id AS user_id,u.given_name,u.family_name,u.display_name,t.booking_name AS preferred_name,u.email,u.status,p.discipline,p.credentials,p.booking_mode,p.active,MIN(l.id) AS location_id,GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR ', ') AS locations FROM practitioners p JOIN users u ON u.id=p.user_id AND u.user_type='staff' LEFT JOIN public_team_profiles t ON t.user_id=u.id AND t.clinic_id=u.clinic_id LEFT JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 LEFT JOIN locations l ON l.id=pl.location_id AND l.clinic_id=u.clinic_id WHERE u.clinic_id=:clinic GROUP BY p.id,u.id,u.given_name,u.family_name,u.display_name,t.booking_name,u.email,u.status,p.discipline,p.credentials,p.booking_mode,p.active ORDER BY COALESCE(t.booking_name,u.display_name)");
        $statement->execute(['clinic'=>$actor->clinicId]);$items=$statement->fetchAll();
        if($this->globalPractitionersEnabled){$q=$this->database->connection()->prepare('SELECT practitioner_id FROM global_practitioner_clinics WHERE clinic_id=?');$q->execute([$actor->clinicId]);$registered=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));foreach($items as &$item)$item['global_registered']=in_array((int)$item['practitioner_id'],$registered,true);}
        return $items;
    }

    private function onboardPractitionerRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$this->required($body,['tenant_id','object_id','email','given_name','family_name','display_name','location_id','discipline']);$this->ownedLocation($actor,(int)$body['location_id']);
        $tenantId=strtolower(trim((string)$body['tenant_id']));$objectId=strtolower(trim((string)$body['object_id']));$email=strtolower(trim((string)$body['email']));$givenName=trim((string)$body['given_name']);$familyName=trim((string)$body['family_name']);$displayName=trim((string)$body['display_name']);$discipline=trim((string)$body['discipline']);$credentials=$this->optional($body,'credentials');$bookingMode=(string)($body['booking_mode']??'practitioner_managed');
        $uuid='/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if(!preg_match($uuid,$tenantId))throw new ApiException(422,'validation_error','Enter a valid Entra tenant ID.',['tenant_id'=>'Invalid UUID']);
        if(!preg_match($uuid,$objectId))throw new ApiException(422,'validation_error','Enter a valid Entra Object ID.',['object_id'=>'Invalid UUID']);
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false||strlen($email)>190)throw new ApiException(422,'validation_error','Enter a valid email address.',['email'=>'Invalid email']);
        if($givenName===''||strlen($givenName)>100)throw new ApiException(422,'validation_error','Enter a valid first name.',['given_name'=>'Required; maximum 100 characters']);
        if($familyName===''||strlen($familyName)>100)throw new ApiException(422,'validation_error','Enter a valid last name.',['family_name'=>'Required; maximum 100 characters']);
        if($displayName===''||strlen($displayName)>150)throw new ApiException(422,'validation_error','Enter a valid display name.',['display_name'=>'Required; maximum 150 characters']);
        if(strlen($discipline)>100)throw new ApiException(422,'validation_error','The discipline is too long.',['discipline'=>'Maximum 100 characters']);
        if($credentials!==null&&strlen($credentials)>500)throw new ApiException(422,'validation_error','The credentials are too long.',['credentials'=>'Maximum 500 characters']);
        if(!in_array($bookingMode,['practitioner_managed','clinic_managed'],true))throw new ApiException(422,'validation_error','Invalid booking mode.',['booking_mode'=>'Invalid booking mode']);
        $pdo=$this->database->connection();
        $existing=$pdo->prepare("SELECT 1 FROM identity_links WHERE provider='microsoft' AND tenant_id=:tenant AND provider_subject=:subject");$existing->execute(['tenant'=>$tenantId,'subject'=>$objectId]);if($existing->fetchColumn())throw new ApiException(409,'identity_already_linked','This Entra identity is already linked to a local user.');
        $existing=$pdo->prepare('SELECT 1 FROM users WHERE clinic_id=:clinic AND email=:email');$existing->execute(['clinic'=>$actor->clinicId,'email'=>$email]);if($existing->fetchColumn())throw new ApiException(409,'email_already_exists','A user with this email already exists in the clinic.');

        $statement=$pdo->prepare("INSERT INTO users(clinic_id,email,given_name,family_name,display_name,user_type,status) VALUES(:clinic,:email,:given,:family,:name,'staff','active')");$statement->execute(['clinic'=>$actor->clinicId,'email'=>$email,'given'=>$givenName,'family'=>$familyName,'name'=>$displayName]);$userId=(int)$pdo->lastInsertId();
        $statement=$pdo->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject,email_at_link_time) VALUES(:user,'microsoft',:tenant,:subject,:email)");$statement->execute(['user'=>$userId,'tenant'=>$tenantId,'subject'=>$objectId,'email'=>$email]);
        $statement=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,location_id,assigned_by) SELECT :user,id,:location,:actor FROM roles WHERE code='practitioner'");$statement->execute(['user'=>$userId,'location'=>(int)$body['location_id'],'actor'=>$actor->userId]);if($statement->rowCount()!==1)throw new ApiException(500,'role_not_configured','The practitioner role is not configured.');
        $statement=$pdo->prepare('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(:user,1)');$statement->execute(['user'=>$userId]);
        $statement=$pdo->prepare('INSERT INTO practitioners(user_id,discipline,credentials,booking_mode) VALUES(:user,:discipline,:credentials,:mode)');$statement->execute(['user'=>$userId,'discipline'=>$discipline,'credentials'=>$credentials,'mode'=>$bookingMode]);$practitionerId=(int)$pdo->lastInsertId();
        $statement=$pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(:practitioner,:location,1)');$statement->execute(['practitioner'=>$practitionerId,'location'=>(int)$body['location_id']]);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'practitioner.onboard','practitioner',$practitionerId,'success',['user_id'=>$userId,'location_id'=>(int)$body['location_id']]);return ['id'=>$practitionerId,'user_id'=>$userId,'status'=>'active'];
    }

    private function updatePractitionerRecord(AuthContext $actor,int $practitionerId,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$this->required($body,['given_name','family_name','display_name','email','location_id','discipline','booking_mode','status']);$this->ownedLocation($actor,(int)$body['location_id']);
        $givenName=trim((string)$body['given_name']);$familyName=trim((string)$body['family_name']);$displayName=trim((string)$body['display_name']);$email=strtolower(trim((string)$body['email']));$discipline=trim((string)$body['discipline']);$credentials=$this->optional($body,'credentials');$bookingMode=(string)$body['booking_mode'];$status=(string)$body['status'];if(array_key_exists('active',$body)&&!is_bool($body['active']))throw new ApiException(422,'validation_error','active must be a boolean.');$active=$body['active']??true;
        if($givenName===''||strlen($givenName)>100)throw new ApiException(422,'validation_error','Enter a valid first name.',['given_name'=>'Required; maximum 100 characters']);
        if($familyName===''||strlen($familyName)>100)throw new ApiException(422,'validation_error','Enter a valid last name.',['family_name'=>'Required; maximum 100 characters']);
        if($displayName===''||strlen($displayName)>150)throw new ApiException(422,'validation_error','Enter a valid display name.',['display_name'=>'Required; maximum 150 characters']);
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false||strlen($email)>190)throw new ApiException(422,'validation_error','Enter a valid email address.',['email'=>'Invalid email']);
        if($discipline===''||strlen($discipline)>100)throw new ApiException(422,'validation_error','Enter a valid discipline.',['discipline'=>'Required; maximum 100 characters']);
        if($credentials!==null&&strlen($credentials)>500)throw new ApiException(422,'validation_error','The credentials are too long.',['credentials'=>'Maximum 500 characters']);
        if(!in_array($bookingMode,['practitioner_managed','clinic_managed'],true))throw new ApiException(422,'validation_error','Invalid booking mode.',['booking_mode'=>'Invalid booking mode']);
        if(!in_array($status,['active','inactive'],true))throw new ApiException(422,'validation_error','Invalid account status.',['status'=>'Invalid status']);
        $pdo=$this->database->connection();

        $find=$pdo->prepare('SELECT p.user_id FROM practitioners p JOIN users u ON u.id=p.user_id AND u.user_type=\'staff\' WHERE p.id=:practitioner AND u.clinic_id=:clinic FOR UPDATE');$find->execute(['practitioner'=>$practitionerId,'clinic'=>$actor->clinicId]);$userId=$find->fetchColumn();if(!$userId)throw new ApiException(404,'practitioner_not_found','Practitioner not found.');
        if($this->globalPractitionersEnabled){
            $global=$pdo->prepare('SELECT a.status,p.discipline,p.credentials FROM global_practitioner_clinics a JOIN practitioners p ON p.id=a.practitioner_id WHERE a.practitioner_id=?');$global->execute([$practitionerId]);$registered=$global->fetch();
            if($registered){
                if($registered['status']!=='active')throw new ApiException(409,'assignment_removed','This clinic assignment was removed centrally.');
                if($discipline!==$registered['discipline']||$credentials!==$registered['credentials'])throw new ApiException(403,'credential_owner_required','Only the practitioner can choose the credentials and discipline shared with this clinic.');
            }
        }
        $duplicate=$pdo->prepare('SELECT 1 FROM users WHERE clinic_id=:clinic AND email=:email AND id<>:user');$duplicate->execute(['clinic'=>$actor->clinicId,'email'=>$email,'user'=>(int)$userId]);if($duplicate->fetchColumn())throw new ApiException(409,'email_already_exists','A user with this email already exists in the clinic.');
        $statement=$pdo->prepare('UPDATE users SET given_name=:given,family_name=:family,display_name=:name,email=:email,status=:status WHERE id=:user');$statement->execute(['given'=>$givenName,'family'=>$familyName,'name'=>$displayName,'email'=>$email,'status'=>$status,'user'=>(int)$userId]);
        $statement=$pdo->prepare('UPDATE practitioners SET discipline=:discipline,credentials=:credentials,booking_mode=:mode,active=:active WHERE id=:practitioner');$statement->execute(['discipline'=>$discipline,'credentials'=>$credentials,'mode'=>$bookingMode,'active'=>$active?1:0,'practitioner'=>$practitionerId]);
        $statement=$pdo->prepare('UPDATE practitioner_locations SET active=0 WHERE practitioner_id=:practitioner');$statement->execute(['practitioner'=>$practitionerId]);
        $statement=$pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(:practitioner,:location,1) ON DUPLICATE KEY UPDATE active=1');$statement->execute(['practitioner'=>$practitionerId,'location'=>(int)$body['location_id']]);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'practitioner.update','practitioner',$practitionerId,'success',['user_id'=>(int)$userId,'location_id'=>(int)$body['location_id']]);return ['id'=>$practitionerId,'status'=>$status,'active'=>$active];
    }

    public function updateClinic(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->updateClinicRecord($actor,$body,$correlationId),clinicWide:true);}

    private function updateClinicRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $keys=['name'=>160,'legal_name'=>190,'email'=>190,'phone'=>40];
        if(array_intersect_key($body,$keys)===[])throw new ApiException(422,'validation_error','Supply at least one clinic profile field.');
        $values=array_replace($this->clinic($actor->clinicId),array_intersect_key($body,$keys));
        foreach($keys as $key=>$limit){
            $value=$values[$key];
            if($key!=='name'&&$value===null)continue;
            if(!is_string($value)||mb_strlen(trim($value))>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Enter valid clinic contact details.',[$key=>"Text only; maximum $limit characters"]);
            $values[$key]=trim($value);if($key!=='name'&&$values[$key]==='')$values[$key]=null;
        }
        if($values['name']==='')throw new ApiException(422,'validation_error','Enter an operating name.',['name'=>'Required']);
        if($values['email']!==null&&filter_var($values['email'],FILTER_VALIDATE_EMAIL)===false)throw new ApiException(422,'validation_error','Enter a valid email address.',['email'=>'Invalid email']);
        $this->database->connection()->prepare('UPDATE clinics SET name=:name,legal_name=:legal_name,email=:email,phone=:phone WHERE id=:clinic')->execute($values+['clinic'=>$actor->clinicId]);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.settings.update','clinic',$actor->clinicId,'success',['fields'=>array_keys(array_intersect_key($body,$keys))]);
        return $this->clinic($actor->clinicId);
    }

    public function branding(AuthContext $actor): array
    {return $this->clinicAdministration($actor,fn()=>$this->brandingRecord($actor),clinicWide:true);}

    private function brandingRecord(AuthContext $actor): array
    {
        $this->superAdmin($actor);
        $statement=$this->database->connection()->prepare('SELECT asset_type,mime_type,byte_size,width_px,height_px,content_hash,updated_at FROM clinic_brand_assets WHERE clinic_id=:clinic ORDER BY asset_type');
        $statement->execute(['clinic'=>$actor->clinicId]);
        $result=['logo'=>null,'favicon'=>null];
        foreach($statement->fetchAll() as $asset)$result[(string)$asset['asset_type']]=$asset;
        return $result;
    }

    public function saveBrandAsset(AuthContext $actor,string $type,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->saveBrandAssetRecord($actor,$type,$body,$correlationId),clinicWide:true);}

    private function saveBrandAssetRecord(AuthContext $actor,string $type,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);
        if(!in_array($type,['logo','favicon'],true))throw new ApiException(404,'brand_asset_not_found','Brand asset not found.');
        $mime=$body['mime_type']??null;$encoded=$body['image_base64']??null;
        if(!in_array($mime,['image/png','image/webp'],true)||!is_string($encoded)||$encoded===''||strlen($encoded)>1_100_000)throw new ApiException(422,'validation_error','Choose a valid PNG or WebP image.');
        $data=base64_decode($encoded,true);
        if($data===false||strlen($data)>750_000)throw new ApiException(422,'validation_error','The image is invalid or larger than 750 KB.');
        $info=@getimagesizefromstring($data);$actualMime=$info['mime']??'';$width=(int)($info[0]??0);$height=(int)($info[1]??0);
        if($info===false||$actualMime!==$mime)throw new ApiException(422,'validation_error','The image content does not match its format.');
        if($type==='logo'&&($width<64||$height<32||$width>1200||$height>600))throw new ApiException(422,'validation_error','The logo must be between 64Ã—32 and 1200Ã—600 pixels.');
        if($type==='favicon'&&($width!==$height||$width<32||$width>512))throw new ApiException(422,'validation_error','The favicon must be square and between 32 and 512 pixels.');
        $statement=$this->database->connection()->prepare('INSERT INTO clinic_brand_assets(clinic_id,asset_type,mime_type,image_data,byte_size,width_px,height_px,content_hash,updated_by) VALUES(:clinic,:type,:mime,:data,:size,:width,:height,:hash,:actor) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type),image_data=VALUES(image_data),byte_size=VALUES(byte_size),width_px=VALUES(width_px),height_px=VALUES(height_px),content_hash=VALUES(content_hash),updated_by=VALUES(updated_by)');
        $statement->bindValue(':clinic',$actor->clinicId,\PDO::PARAM_INT);$statement->bindValue(':type',$type);$statement->bindValue(':mime',$mime);$statement->bindValue(':data',$data,\PDO::PARAM_LOB);$statement->bindValue(':size',strlen($data),\PDO::PARAM_INT);$statement->bindValue(':width',$width,\PDO::PARAM_INT);$statement->bindValue(':height',$height,\PDO::PARAM_INT);$statement->bindValue(':hash',hash('sha256',$data));$statement->bindValue(':actor',$actor->userId,\PDO::PARAM_INT);$statement->execute();
        $this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.branding.update','clinic',$actor->clinicId,'success',['asset_type'=>$type]);
        return ['asset_type'=>$type,'updated'=>true,'content_hash'=>hash('sha256',$data)];
    }

    public function deleteBrandAsset(AuthContext $actor,string $type,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->deleteBrandAssetRecord($actor,$type,$correlationId),clinicWide:true);}

    private function deleteBrandAssetRecord(AuthContext $actor,string $type,string $correlationId): array
    {
        $this->superAdmin($actor);if(!in_array($type,['logo','favicon'],true))throw new ApiException(404,'brand_asset_not_found','Brand asset not found.');
        $statement=$this->database->connection()->prepare('DELETE FROM clinic_brand_assets WHERE clinic_id=:clinic AND asset_type=:type');$statement->execute(['clinic'=>$actor->clinicId,'type'=>$type]);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.branding.delete','clinic',$actor->clinicId,'success',['asset_type'=>$type]);
        return ['asset_type'=>$type,'deleted'=>$statement->rowCount()>0];
    }
    public function catalogueSettings(AuthContext $actor): array
    {return $this->clinicAdministration($actor,fn()=>$this->catalogueSettingsRecord($actor),clinicWide:true);}

    private function catalogueSettingsRecord(AuthContext $actor): array{$this->superAdmin($actor);$pdo=$this->database->connection();$c=$pdo->prepare('SELECT id,name,name_fr,description,description_fr FROM service_categories WHERE clinic_id=:clinic ORDER BY name');$c->execute(['clinic'=>$actor->clinicId]);$t=$pdo->prepare('SELECT id,code,name,rate_basis_points,active FROM taxes WHERE clinic_id=:clinic ORDER BY name');$t->execute(['clinic'=>$actor->clinicId]);$s=$pdo->prepare('SELECT default_lead_time_minutes,default_booking_horizon_days,default_cancellation_window_minutes,slot_increment_minutes,currency FROM clinic_booking_settings WHERE clinic_id=:clinic');$s->execute(['clinic'=>$actor->clinicId]);return ['categories'=>$c->fetchAll(),'taxes'=>$t->fetchAll(),'settings'=>$s->fetch()?:null];}
    private function categoryCopy(array $body): array
    {
        foreach(['name','name_fr','description','description_fr'] as $key)if(isset($body[$key])&&(!is_string($body[$key])||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$body[$key])))throw new ApiException(422,'validation_error','Invalid category text.');
        $name=trim((string)($body['name']??''));$nameFr=trim((string)($body['name_fr']??''));$description=trim((string)($body['description']??''));$descriptionFr=trim((string)($body['description_fr']??''));
        if($name===''||strlen($name)>120||strlen($nameFr)>120||strlen($description)>500||strlen($descriptionFr)>500)throw new ApiException(422,'validation_error','Choose valid category names and descriptions.');
        return ['name'=>$name,'name_fr'=>$nameFr?:null,'description'=>$description?:null,'description_fr'=>$descriptionFr?:null];
    }
    public function createServiceCategory(AuthContext $actor,array $body,string $cid): array
    {return $this->clinicAdministration($actor,fn()=>$this->createServiceCategoryRecord($actor,$body,$cid),clinicWide:true);}

    private function createServiceCategoryRecord(AuthContext $actor,array $body,string $cid): array
    {
        $this->superAdmin($actor);$copy=$this->categoryCopy($body);$s=$this->database->connection()->prepare('INSERT INTO service_categories(clinic_id,name,name_fr,description,description_fr) VALUES(:clinic,:name,:name_fr,:description,:description_fr)');$s->execute(['clinic'=>$actor->clinicId]+$copy);return $this->created($actor,$cid,'service_category',(int)$this->database->connection()->lastInsertId());
    }
    public function updateServiceCategory(AuthContext $actor,int $id,array $body,string $cid): array
    {return $this->clinicAdministration($actor,fn()=>$this->updateServiceCategoryRecord($actor,$id,$body,$cid),clinicWide:true);}

    private function updateServiceCategoryRecord(AuthContext $actor,int $id,array $body,string $cid): array
    {
        $this->superAdmin($actor);$copy=$this->categoryCopy($body);$s=$this->database->connection()->prepare('UPDATE service_categories SET name=:name,name_fr=:name_fr,description=:description,description_fr=:description_fr WHERE id=:id AND clinic_id=:clinic');$s->execute($copy+['id'=>$id,'clinic'=>$actor->clinicId]);$check=$this->database->connection()->prepare('SELECT 1 FROM service_categories WHERE id=:id AND clinic_id=:clinic');$check->execute(['id'=>$id,'clinic'=>$actor->clinicId]);if(!$check->fetchColumn())throw new ApiException(404,'category_not_found','Category not found.');$this->audit->write($actor->clinicId,$actor,$cid,'service_category.update','service_category',$id);return ['id'=>$id];
    }
    public function createTax(AuthContext $actor,array $body,string $cid): array
    {return $this->clinicAdministration($actor,function()use($actor,$body,$cid){
        $code=$body['code']??null;$name=$body['name']??null;$rate=$body['rate_basis_points']??null;
        if(!is_string($code)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D',trim($code))!==1)throw new ApiException(422,'validation_error','Enter a valid tax code.',['code'=>'Use 1 to 40 letters, numbers, underscores or hyphens']);
        if(!is_string($name)||trim($name)===''||mb_strlen(trim($name))>100||preg_match('/[\x00-\x1f\x7f]/',$name))throw new ApiException(422,'validation_error','Enter a valid tax name.',['name'=>'Required; maximum 100 characters']);
        if(!is_int($rate)||$rate<0||$rate>10000)throw new ApiException(422,'validation_error','Tax rate must be between 0 and 100%.',['rate_basis_points'=>'Integer from 0 to 10000 required']);
        $pdo=$this->database->connection();$code=strtoupper(trim($code));
        $q=$pdo->prepare('SELECT id FROM taxes WHERE clinic_id=? AND code=? FOR UPDATE');$q->execute([$actor->clinicId,$code]);
        if($q->fetchColumn())throw new ApiException(409,'tax_already_exists','A tax with that code already exists in this clinic.');
        try{$pdo->prepare('INSERT INTO taxes(clinic_id,code,name,rate_basis_points) VALUES(?,?,?,?)')->execute([$actor->clinicId,$code,trim($name),$rate]);}
        catch(\PDOException $e){if(($e->errorInfo[1]??null)===1062)throw new ApiException(409,'tax_already_exists','A tax with that code already exists in this clinic.');throw $e;}
        return $this->created($actor,$cid,'tax',(int)$pdo->lastInsertId());
    },clinicWide:true);}

    /** Stored defaults only: never propagates values into services, bookings or billing history. */
    public function updateBookingSettings(AuthContext $actor,array $body,string $cid): array
    {return $this->clinicAdministration($actor,function()use($actor,$body,$cid){
        $limits=['default_lead_time_minutes'=>525600,'default_booking_horizon_days'=>3650,'default_cancellation_window_minutes'=>525600];
        $changes=array_intersect_key($body,$limits);
        if($changes===[])throw new ApiException(422,'validation_error','Supply at least one booking default.');
        foreach($changes as $key=>$value)if(!is_int($value)||$value<0||$value>$limits[$key])throw new ApiException(422,'validation_error','Enter a valid booking default.',[$key=>'Integer from 0 to '.$limits[$key].' required']);
        $pdo=$this->database->connection();$q=$pdo->prepare('SELECT default_lead_time_minutes,default_booking_horizon_days,default_cancellation_window_minutes FROM clinic_booking_settings WHERE clinic_id=? FOR UPDATE');$q->execute([$actor->clinicId]);$old=$q->fetch();
        $values=array_replace($old?:['default_lead_time_minutes'=>60,'default_booking_horizon_days'=>90,'default_cancellation_window_minutes'=>1440],$changes);
        $pdo->prepare("INSERT INTO clinic_booking_settings(clinic_id,default_lead_time_minutes,default_booking_horizon_days,default_cancellation_window_minutes,slot_increment_minutes,currency) VALUES(?,?,?,?,15,'CAD') ON DUPLICATE KEY UPDATE default_lead_time_minutes=VALUES(default_lead_time_minutes),default_booking_horizon_days=VALUES(default_booking_horizon_days),default_cancellation_window_minutes=VALUES(default_cancellation_window_minutes)")->execute([$actor->clinicId,$values['default_lead_time_minutes'],$values['default_booking_horizon_days'],$values['default_cancellation_window_minutes']]);
        $this->audit->write($actor->clinicId,$actor,$cid,'booking.settings.update','clinic',$actor->clinicId,'success',['fields'=>array_keys($changes)]);return ['updated'=>true];
    },clinicWide:true);}

    public function createLocation(AuthContext $actor,array $body,string $correlationId): array
    {
        return $this->clinicAdministration($actor,function()use($actor,$body,$correlationId):array{
            $this->required($body,['name','timezone']);$this->validateLocation($body);
            $sql='INSERT INTO locations(clinic_id,name,timezone,address_line1,address_line2,city,province,postal_code,phone,is_bookable) VALUES(:clinic,:name,:timezone,:line1,:line2,:city,:province,:postal,:phone,:bookable)';
            $statement=$this->database->connection()->prepare($sql);$statement->execute(['clinic'=>$actor->clinicId,'name'=>trim($body['name']),'timezone'=>$body['timezone'],'line1'=>$this->optional($body,'address_line1'),'line2'=>$this->optional($body,'address_line2'),'city'=>$this->optional($body,'city'),'province'=>$this->optional($body,'province'),'postal'=>$this->optional($body,'postal_code'),'phone'=>$this->optional($body,'phone'),'bookable'=>($body['is_bookable']??true)?1:0]);
            return $this->created($actor,$correlationId,'location',(int)$this->database->connection()->lastInsertId());
        },clinicWide:true);
    }

    public function locations(AuthContext $actor): array
    {
        return $this->clinicAdministration($actor,function()use($actor):array{
            $statement=$this->database->connection()->prepare('SELECT id,name,timezone,address_line1,address_line2,city,province,postal_code,phone,is_bookable FROM locations WHERE clinic_id=:clinic ORDER BY name');$statement->execute(['clinic'=>$actor->clinicId]);return $statement->fetchAll();
        },clinicWide:true);
    }

    public function updateLocation(AuthContext $actor,int $locationId,array $body,string $correlationId): array
    {
        return $this->clinicAdministration($actor,function()use($actor,$locationId,$body,$correlationId):array{
            $this->required($body,['name','timezone']);$this->validateLocation($body);$pdo=$this->database->connection();
            $current=$pdo->prepare('SELECT * FROM locations WHERE id=? AND clinic_id=? FOR UPDATE');$current->execute([$locationId,$actor->clinicId]);$old=$current->fetch();
            if(!$old)throw new ApiException(404,'location_not_found','Location not found.');
            // Optional fields omitted by a PATCH retain their stored values.
            $body=array_replace($old,$body);
            $addressChanged=false;
            foreach(['address_line1','address_line2','city','province','postal_code'] as $field){if($this->optional($old,$field)!==$this->optional($body,$field))$addressChanged=true;}
            // No preview limit or inner join may hide a restricted or malformed affected row.
            $approvals=$addressChanged?$this->locationPatientEffects($actor,$locationId,'approvals'):[];
            if($addressChanged||$old['timezone']!==$body['timezone'])$this->locationPatientEffects($actor,$locationId,'appointments');
            $statement=$pdo->prepare('UPDATE locations SET name=:name,timezone=:timezone,address_line1=:line1,address_line2=:line2,city=:city,province=:province,postal_code=:postal,phone=:phone,is_bookable=:bookable WHERE id=:id AND clinic_id=:clinic');
            $statement->execute(['name'=>trim($body['name']),'timezone'=>$body['timezone'],'line1'=>$this->optional($body,'address_line1'),'line2'=>$this->optional($body,'address_line2'),'city'=>$this->optional($body,'city'),'province'=>$this->optional($body,'province'),'postal'=>$this->optional($body,'postal_code'),'phone'=>$this->optional($body,'phone'),'bookable'=>($body['is_bookable']??true)?1:0,'id'=>$locationId,'clinic'=>$actor->clinicId]);
            if($addressChanged){
                $pdo->prepare('DELETE FROM onsite_area_approvals WHERE clinic_id=? AND location_id=?')->execute([$actor->clinicId,$locationId]);
                foreach($approvals as $row)$this->audit->write($actor->clinicId,$actor,$correlationId,'onsite_area.revoke','client',(int)$row['client_id'],'success',['location_id'=>$locationId,'practitioner_id'=>(int)$row['practitioner_id'],'service_id'=>(int)$row['service_id'],'reason'=>'location_address_changed']);
            }
            $this->audit->write($actor->clinicId,$actor,$correlationId,'location.update','location',$locationId);return ['id'=>$locationId];
        },clinicWide:true);
    }

    /** Complete, locked effect set. Historical appointments also use the location's address and timezone. */
    private function locationPatientEffects(AuthContext $actor,int $location,string $kind): array
    {
        $table=match($kind){'approvals'=>'onsite_area_approvals','appointments'=>'appointments',default=>throw new \LogicException('Invalid location effect kind.')};
        $q=$this->database->connection()->prepare("SELECT x.id,x.clinic_id,x.client_id,x.practitioner_id,x.service_id,patient.id patient_user_id,staff.id staff_user_id,s.id owned_service_id
            FROM $table x
            LEFT JOIN users patient ON patient.id=x.client_id AND patient.clinic_id=x.clinic_id AND patient.user_type='client'
            LEFT JOIN practitioners p ON p.id=x.practitioner_id
            LEFT JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=x.clinic_id AND staff.user_type='staff'
            LEFT JOIN services s ON s.id=x.service_id AND s.clinic_id=x.clinic_id
            WHERE x.location_id=? ORDER BY x.id FOR UPDATE");$q->execute([$location]);$rows=$q->fetchAll();
        $consent=new ConsentAccess($this->database->connection());
        foreach($rows as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||!$row['patient_user_id']||!$row['staff_user_id']||!$row['owned_service_id'])throw new ApiException(409,'location_privacy_review','The location change requires privacy review.');
            if($consent->restricted($actor->clinicId,(int)$row['client_id'],$actor->userId,true)||$consent->restricted($actor->clinicId,(int)$row['client_id'],(int)$row['staff_user_id'],true))throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
        }
        return $rows;
    }

    public function createRoom(AuthContext $actor,array $body,string $correlationId): array
    {
        return $this->clinicAdministration($actor,function()use($actor,$body,$correlationId):array{
            $this->required($body,['location_id','name']);$this->validateRoom($body);$this->roomLocation($actor,(int)$body['location_id']);
            $statement=$this->database->connection()->prepare('INSERT INTO rooms(location_id,name,room_type,equipment_notes,turnover_minutes,is_bookable) VALUES(:location,:name,:type,:notes,:turnover,:bookable)');
            $statement->execute(['location'=>(int)$body['location_id'],'name'=>trim($body['name']),'type'=>$this->optional($body,'room_type'),'notes'=>$this->optional($body,'equipment_notes'),'turnover'=>$body['turnover_minutes']??0,'bookable'=>($body['is_bookable']??true)?1:0]);
            return $this->created($actor,$correlationId,'room',(int)$this->database->connection()->lastInsertId());
        },clinicWide:true);
    }

    public function rooms(AuthContext $actor): array
    {
        return $this->clinicAdministration($actor,function()use($actor):array{
            $statement=$this->database->connection()->prepare('SELECT r.id,r.location_id,r.name,r.room_type,r.equipment_notes,r.turnover_minutes,r.is_bookable,l.name AS location_name FROM rooms r JOIN locations l ON l.id=r.location_id WHERE l.clinic_id=:clinic ORDER BY l.name,r.name');$statement->execute(['clinic'=>$actor->clinicId]);return $statement->fetchAll();
        },clinicWide:true);
    }

    public function updateRoom(AuthContext $actor,int $roomId,array $body,string $correlationId): array
    {
        return $this->clinicAdministration($actor,function()use($actor,$roomId,$body,$correlationId):array{
            $this->required($body,['location_id','name']);$this->validateRoom($body);$pdo=$this->database->connection();
            $q=$pdo->prepare('SELECT r.* FROM rooms r JOIN locations l ON l.id=r.location_id WHERE r.id=? AND l.clinic_id=? FOR UPDATE');$q->execute([$roomId,$actor->clinicId]);$old=$q->fetch();
            if(!$old)throw new ApiException(404,'room_not_found','Room not found.');
            $this->roomLocation($actor,(int)$body['location_id']);$body=array_replace($old,$body);
            $relocated=(int)$old['location_id']!==(int)$body['location_id'];$turnoverChanged=(int)$old['turnover_minutes']!==(int)$body['turnover_minutes'];
            if($relocated)$this->roomRelocation($actor,$roomId);
            if($turnoverChanged)$this->roomAppointmentEffects($actor,$roomId,(int)$old['location_id']);
            $q=$pdo->prepare('UPDATE rooms SET location_id=?,name=?,room_type=?,equipment_notes=?,turnover_minutes=?,is_bookable=? WHERE id=?');
            $q->execute([(int)$body['location_id'],trim($body['name']),$this->optional($body,'room_type'),$this->optional($body,'equipment_notes'),(int)$body['turnover_minutes'],$body['is_bookable']?1:0,$roomId]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'room.update','room',$roomId,'success',['location_id'=>(int)$body['location_id'],'relocated'=>$relocated,'turnover_changed'=>$turnoverChanged]);return ['id'=>$roomId];
        },clinicWide:true);
    }

    private function roomLocation(AuthContext $actor,int $location): void
    {
        $q=$this->database->connection()->prepare('SELECT id FROM locations WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$location,$actor->clinicId]);
        if(!$q->fetchColumn())throw new ApiException(404,'location_not_found','Location not found.');
    }

    /** Relocation cannot rewrite the venue of an existing booking or its historical room. */
    private function roomRelocation(AuthContext $actor,int $room): void
    {
        $pdo=$this->database->connection();$q=$pdo->prepare('SELECT id FROM appointments WHERE room_id=? LIMIT 1 FOR UPDATE');$q->execute([$room]);
        if($q->fetchColumn())throw new ApiException(409,'room_relocation_review','The room change requires review.');
        $q=$pdo->prepare('SELECT a.room_id FROM room_capability_assignments a LEFT JOIN room_capabilities c ON c.id=a.capability_id AND c.clinic_id=? WHERE a.room_id=? AND c.id IS NULL LIMIT 1 FOR UPDATE');$q->execute([$actor->clinicId,$room]);
        if($q->fetchColumn())throw new ApiException(409,'room_relocation_review','The room change requires review.');
        $q=$pdo->prepare("SELECT a.room_id FROM room_practitioner_restrictions a LEFT JOIN practitioners p ON p.id=a.practitioner_id LEFT JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.user_type='staff' WHERE a.room_id=? AND u.id IS NULL LIMIT 1 FOR UPDATE");$q->execute([$actor->clinicId,$room]);
        if($q->fetchColumn())throw new ApiException(409,'room_relocation_review','The room change requires review.');
    }

    /** Mirrors room conflict eligibility: canceled bookings have no turnover effect. No preview cap. */
    private function roomAppointmentEffects(AuthContext $actor,int $room,int $location): void
    {
        $q=$this->database->connection()->prepare("SELECT a.id,a.clinic_id,a.location_id,a.client_id,patient.id patient_user_id,staff.id staff_user_id,s.id owned_service_id
            FROM appointments a
            LEFT JOIN users patient ON patient.id=a.client_id AND patient.clinic_id=a.clinic_id AND patient.user_type='client'
            LEFT JOIN practitioners p ON p.id=a.practitioner_id
            LEFT JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=a.clinic_id AND staff.user_type='staff'
            LEFT JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
            WHERE a.room_id=? AND a.status NOT IN('canceled_by_client','canceled_by_clinic') ORDER BY a.id FOR UPDATE");$q->execute([$room]);
        $consent=new ConsentAccess($this->database->connection());
        foreach($q->fetchAll() as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||(int)$row['location_id']!==$location||!$row['patient_user_id']||!$row['staff_user_id']||!$row['owned_service_id'])throw new ApiException(409,'room_privacy_review','The room change requires review.');
            if($consent->restricted($actor->clinicId,(int)$row['client_id'],$actor->userId,true)||$consent->restricted($actor->clinicId,(int)$row['client_id'],(int)$row['staff_user_id'],true))throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
        }
    }

    public function roomCapabilities(AuthContext $actor): array
    {return $this->clinicAdministration($actor,function()use($actor){
        $pdo=$this->database->connection();
        $c=$pdo->prepare('SELECT id,code,name FROM room_capabilities WHERE clinic_id=? ORDER BY name');$c->execute([$actor->clinicId]);
        $r=$pdo->prepare('SELECT a.room_id,a.capability_id FROM room_capability_assignments a JOIN rooms r ON r.id=a.room_id JOIN locations l ON l.id=r.location_id JOIN room_capabilities c ON c.id=a.capability_id AND c.clinic_id=l.clinic_id WHERE l.clinic_id=?');$r->execute([$actor->clinicId]);
        $s=$pdo->prepare('SELECT a.service_id,a.capability_id FROM service_room_capability_requirements a JOIN services s ON s.id=a.service_id JOIN room_capabilities c ON c.id=a.capability_id AND c.clinic_id=s.clinic_id WHERE s.clinic_id=?');$s->execute([$actor->clinicId]);
        return ['capabilities'=>$c->fetchAll(),'rooms'=>$r->fetchAll(),'services'=>$s->fetchAll()];
    },clinicWide:true);}

    public function createRoomCapability(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,function()use($actor,$body,$correlationId){
        $name=$body['name']??null;
        if(!is_string($name)||trim($name)===''||mb_strlen(trim($name))>120||preg_match('/[\x00-\x1f\x7f]/',$name))throw new ApiException(422,'validation_error','Enter a valid capability name.');
        $name=trim($name);$code=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','_',$name),'_'));
        if($code===''||strlen($code)>80)throw new ApiException(422,'validation_error','Enter a valid capability name.');
        $pdo=$this->database->connection();$q=$pdo->prepare('SELECT id FROM room_capabilities WHERE clinic_id=? AND code=? FOR UPDATE');$q->execute([$actor->clinicId,$code]);
        if($q->fetchColumn())throw new ApiException(409,'validation_error','This capability already exists.');
        $pdo->prepare('INSERT INTO room_capabilities(clinic_id,code,name) VALUES(?,?,?)')->execute([$actor->clinicId,$code,$name]);
        return $this->created($actor,$correlationId,'room_capability',(int)$pdo->lastInsertId());
    },clinicWide:true);}

    /** Explicit replacement lists: omitted, coerced and foreign IDs must never silently clear policy. */
    private function assignmentIds(array $body,string $key): array
    {
        if(!isset($body[$key])||!is_array($body[$key])||!array_is_list($body[$key]))throw new ApiException(422,'validation_error','An explicit list of identifiers is required.');
        $ids=[];foreach($body[$key] as $id){if(!is_int($id)||$id<=0)throw new ApiException(422,'validation_error','Invalid assignment identifier.');$ids[]=$id;}
        $ids=array_values(array_unique($ids));sort($ids);return $ids;
    }

    private function assignmentRoom(AuthContext $actor,int $room): int
    {
        $q=$this->database->connection()->prepare('SELECT r.location_id FROM rooms r JOIN locations l ON l.id=r.location_id WHERE r.id=? AND l.clinic_id=? FOR UPDATE');$q->execute([$room,$actor->clinicId]);
        $location=$q->fetchColumn();if(!$location)throw new ApiException(404,'room_not_found','Room not found.');return (int)$location;
    }

    public function updateRoomCapabilityAssignments(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,function()use($actor,$body,$correlationId){
        $pdo=$this->database->connection();$targets=[];
        foreach(['room'=>'room_capability_assignments','service'=>'service_room_capability_requirements'] as $kind=>$table){
            if(!array_key_exists($kind.'_id',$body)){if(array_key_exists($kind.'_capability_ids',$body))throw new ApiException(422,'validation_error','An assignment target is required.');continue;}
            $id=$body[$kind.'_id'];if(!is_int($id)||$id<=0)throw new ApiException(422,'validation_error','Invalid assignment target.');
            $ids=$this->assignmentIds($body,$kind.'_capability_ids');
            if($kind==='room')$this->assignmentRoom($actor,$id);
            else{$q=$pdo->prepare('SELECT id FROM services WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$id,$actor->clinicId]);if(!$q->fetchColumn())throw new ApiException(404,'service_not_found','Service not found.');}
            foreach($ids as $cap){$q=$pdo->prepare('SELECT id FROM room_capabilities WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$cap,$actor->clinicId]);if(!$q->fetchColumn())throw new ApiException(404,'capability_not_found','Capability not found.');}
            $q=$pdo->prepare("SELECT a.capability_id,c.clinic_id FROM $table a LEFT JOIN room_capabilities c ON c.id=a.capability_id WHERE a.{$kind}_id=? ORDER BY a.capability_id FOR UPDATE");$q->execute([$id]);$old=$q->fetchAll();
            foreach($old as $row)if((int)$row['clinic_id']!==$actor->clinicId)throw new ApiException(409,'room_privacy_review','The assignment requires review.');
            if(array_map('intval',array_column($old,'capability_id'))!==$ids)$targets[]=[$kind,$table,$id,$ids];
        }
        if(!array_key_exists('room_id',$body)&&!array_key_exists('service_id',$body))throw new ApiException(422,'validation_error','An assignment target is required.');
        // Authorize the complete union before either half of a combined command writes.
        foreach($targets as [$kind,$table,$id,$ids])$this->assignmentAppointmentEffects($actor,$kind,$id);
        foreach($targets as [$kind,$table,$id,$ids]){
            $pdo->prepare("DELETE FROM $table WHERE {$kind}_id=?")->execute([$id]);
            $q=$pdo->prepare("INSERT INTO $table({$kind}_id,capability_id) VALUES(?,?)");foreach($ids as $cap)$q->execute([$id,$cap]);
        }
        $this->audit->write($actor->clinicId,$actor,$correlationId,'room.capabilities.update','clinic',$actor->clinicId);return ['updated'=>true];
    },clinicWide:true);}

    public function roomPractitionerRestrictions(AuthContext $actor): array
    {return $this->clinicAdministration($actor,function()use($actor){
        $q=$this->database->connection()->prepare("SELECT x.room_id,x.practitioner_id,x.allowed FROM room_practitioner_restrictions x JOIN rooms r ON r.id=x.room_id JOIN locations l ON l.id=r.location_id JOIN practitioners p ON p.id=x.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=l.clinic_id AND u.user_type='staff' WHERE l.clinic_id=?");$q->execute([$actor->clinicId]);return $q->fetchAll();
    },clinicWide:true);}

    public function updateRoomPractitioners(AuthContext $actor,int $roomId,array $body,string $cid): array
    {return $this->clinicAdministration($actor,function()use($actor,$roomId,$body,$cid){
        $pdo=$this->database->connection();$this->assignmentRoom($actor,$roomId);$ids=$this->assignmentIds($body,'practitioner_ids');
        foreach($ids as $id){$q=$pdo->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active' AND p.active=1 FOR UPDATE");$q->execute([$id,$actor->clinicId]);if(!$q->fetchColumn())throw new ApiException(404,'practitioner_not_found','Practitioner not found.');}
        $q=$pdo->prepare("SELECT x.practitioner_id,x.allowed,u.clinic_id,u.user_type FROM room_practitioner_restrictions x LEFT JOIN practitioners p ON p.id=x.practitioner_id LEFT JOIN users u ON u.id=p.user_id WHERE x.room_id=? ORDER BY x.practitioner_id FOR UPDATE");$q->execute([$roomId]);$old=$q->fetchAll();
        foreach($old as $row)if((int)$row['clinic_id']!==$actor->clinicId||$row['user_type']!=='staff')throw new ApiException(409,'room_privacy_review','The assignment requires review.');
        $same=array_map('intval',array_column($old,'practitioner_id'))===$ids&&!in_array(0,array_map('intval',array_column($old,'allowed')),true);
        if(!$same){
            $this->assignmentAppointmentEffects($actor,'room',$roomId);
            $pdo->prepare('DELETE FROM room_practitioner_restrictions WHERE room_id=?')->execute([$roomId]);
            $q=$pdo->prepare('INSERT INTO room_practitioner_restrictions(room_id,practitioner_id,allowed) VALUES(?,?,1)');foreach($ids as $id)$q->execute([$roomId,$id]);
        }
        $this->audit->write($actor->clinicId,$actor,$cid,'room.practitioners.update','room',$roomId);return ['updated'=>true];
    },clinicWide:true);}

    /** Eligibility configuration affects the complete noncanceled booking scope, including historical rows. */
    private function assignmentAppointmentEffects(AuthContext $actor,string $kind,int $id): void
    {
        $pdo=$this->database->connection();$q=$pdo->prepare("SELECT a.clinic_id,a.location_id,a.client_id,patient.id patient_id,staff.id staff_id,s.id service_id,l.id owned_location,r.location_id room_location,a.room_id
            FROM appointments a
            LEFT JOIN users patient ON patient.id=a.client_id AND patient.clinic_id=a.clinic_id AND patient.user_type='client'
            LEFT JOIN practitioners p ON p.id=a.practitioner_id
            LEFT JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=a.clinic_id AND staff.user_type='staff'
            LEFT JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
            LEFT JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
            LEFT JOIN rooms r ON r.id=a.room_id
            WHERE a.{$kind}_id=? AND a.status NOT IN('canceled_by_client','canceled_by_clinic') ORDER BY a.id FOR UPDATE");$q->execute([$id]);
        $consent=new ConsentAccess($pdo);
        foreach($q->fetchAll() as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||!$row['patient_id']||!$row['staff_id']||!$row['service_id']||!$row['owned_location']||($row['room_id']!==null&&(int)$row['room_location']!==(int)$row['location_id']))throw new ApiException(409,'room_privacy_review','The assignment requires review.');
            if($consent->restricted($actor->clinicId,(int)$row['client_id'],$actor->userId,true)||$consent->restricted($actor->clinicId,(int)$row['client_id'],(int)$row['staff_id'],true))throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
        }
    }


    public function createStaff(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$this->required($body,['tenant_id','object_id','email','display_name','role']);
        $allowed=['super_admin','clinic_admin','reception','practitioner','accountant'];if(!in_array($body['role'],$allowed,true))throw new ApiException(422,'validation_error','Invalid staff role.',['role'=>'Invalid role']);
        $pdo=$this->database->connection();
        try{$pdo->beginTransaction();$statement=$pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(:clinic,:email,:name,'staff','active')");$statement->execute(['clinic'=>$actor->clinicId,'email'=>strtolower(trim($body['email'])),'name'=>$body['display_name']]);$id=(int)$pdo->lastInsertId();
            $statement=$pdo->prepare("INSERT INTO identity_links(user_id,provider,tenant_id,provider_subject,email_at_link_time) VALUES(:user,'microsoft',:tenant,:subject,:email)");$statement->execute(['user'=>$id,'tenant'=>$body['tenant_id'],'subject'=>$body['object_id'],'email'=>strtolower(trim($body['email']))]);
            $statement=$pdo->prepare('INSERT INTO user_roles(user_id,role_id,location_id,assigned_by) SELECT :user,id,:location,:actor FROM roles WHERE code=:role');$statement->execute(['user'=>$id,'location'=>isset($body['location_id'])?(int)$body['location_id']:null,'actor'=>$actor->userId,'role'=>$body['role']]);
            $statement=$pdo->prepare('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(:user,1)');$statement->execute(['user'=>$id]);$this->audit->write($actor->clinicId,$actor,$correlationId,'staff.create','user',$id,'success',['role'=>$body['role']]);$pdo->commit();return ['id'=>$id,'status'=>'active'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function staff(AuthContext $actor): array
    {
        $this->superAdmin($actor);
        $s=$this->database->connection()->prepare("SELECT u.id,u.display_name,u.email,u.status,GROUP_CONCAT(DISTINCT r.code ORDER BY r.code) roles,GROUP_CONCAT(DISTINCT permission.code ORDER BY permission.code) permissions FROM users u JOIN staff_accounts a ON a.user_id=u.id LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id LEFT JOIN user_permissions user_permission ON user_permission.user_id=u.id LEFT JOIN permissions permission ON permission.id=user_permission.permission_id WHERE u.clinic_id=:clinic AND u.user_type='staff' GROUP BY u.id,u.display_name,u.email,u.status ORDER BY u.display_name");
        $s->execute(['clinic'=>$actor->clinicId]);$rows=$s->fetchAll();
        foreach($rows as &$row){$row['roles']=$row['roles']?explode(',',$row['roles']):[];$row['permissions']=$row['permissions']?explode(',',$row['permissions']):[];}
        return $rows;
    }

    public function updateStaff(AuthContext $actor,int $userId,array $body,string $cid): array
    {
        $this->superAdmin($actor);$this->required($body,['display_name','email','status','roles']);
        if($userId===$actor->userId)throw new ApiException(422,'self_role_change','Use another Super Admin to change your own access.');
        $roles=array_values(array_unique($body['roles']));$allowed=['super_admin','clinic_admin','reception','practitioner','accountant'];foreach($roles as $role)if(!in_array($role,$allowed,true))throw new ApiException(422,'validation_error','Invalid role.');
        $permissions=array_values(array_unique($body['permissions']??[]));$allowedPermissions=['schedule_for_other_practitioners','add_clients','approve_onsite_service_area','manage_client_consent','review_privacy_audit'];foreach($permissions as $permission)if(!in_array($permission,$allowedPermissions,true))throw new ApiException(422,'validation_error','Invalid permission.');
        if(in_array('review_privacy_audit',$permissions,true)&&!array_intersect($roles,['super_admin','clinic_admin']))throw new ApiException(422,'validation_error','Privacy review requires a clinic administrator role.');
        if(array_diff($permissions,['manage_client_consent','review_privacy_audit'])!==[]&&!in_array('practitioner',$roles,true))throw new ApiException(422,'validation_error','Additional permissions can only be assigned to a practitioner.');
        if(!in_array($body['status'],['active','inactive','locked'],true))throw new ApiException(422,'validation_error','Invalid staff status.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();$s=$pdo->prepare("UPDATE users SET display_name=:name,email=:email,status=:status WHERE id=:id AND clinic_id=:clinic AND user_type='staff'");$s->execute(['name'=>trim((string)$body['display_name']),'email'=>strtolower(trim((string)$body['email'])),'status'=>$body['status'],'id'=>$userId,'clinic'=>$actor->clinicId]);
            $check=$pdo->prepare('SELECT 1 FROM users WHERE id=:id AND clinic_id=:clinic');$check->execute(['id'=>$userId,'clinic'=>$actor->clinicId]);if(!$check->fetchColumn())throw new ApiException(404,'staff_not_found','Staff member not found.');
            $pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')->execute(['id'=>$userId]);$insert=$pdo->prepare('INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT :user,id,:actor FROM roles WHERE code=:role');foreach($roles as $role)$insert->execute(['user'=>$userId,'actor'=>$actor->userId,'role'=>$role]);
            $pdo->prepare('DELETE FROM user_permissions WHERE user_id=:id')->execute(['id'=>$userId]);$insertPermission=$pdo->prepare('INSERT INTO user_permissions(user_id,permission_id,assigned_by) SELECT :user,id,:actor FROM permissions WHERE code=:permission');foreach($permissions as $permission){$insertPermission->execute(['user'=>$userId,'actor'=>$actor->userId,'permission'=>$permission]);if($insertPermission->rowCount()!==1)throw new ApiException(503,'permission_unavailable','The requested permission is not installed. Apply the matching database migration first.');}
            $this->audit->write($actor->clinicId,$actor,$cid,'staff.update','user',$userId,'success',['roles'=>$roles,'permissions'=>$permissions,'status'=>$body['status']]);$pdo->commit();return ['id'=>$userId];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function teamProfiles(AuthContext $actor): array
    {
        $this->superAdmin($actor);
        $statement = $this->database->connection()->prepare(
            "SELECT u.id AS user_id,u.given_name,u.family_name,u.display_name,u.status,p.id AS practitioner_id,
                    t.slug,t.section,t.public_name,t.booking_name,t.public_title,t.public_title_fr,t.summary,t.summary_fr,t.public_website_url,t.public_contact_email,t.public_contact_phone,t.public_contact_sms,t.display_order,t.published,t.show_booking_action,
                    CASE WHEN i.user_id IS NULL THEN 0 ELSE 1 END has_image
               FROM users u
               JOIN staff_accounts a ON a.user_id=u.id
          LEFT JOIN practitioners p ON p.user_id=u.id
          LEFT JOIN public_team_profiles t ON t.user_id=u.id
          LEFT JOIN user_profile_images i ON i.user_id=u.id
              WHERE u.clinic_id=:clinic
              ORDER BY CASE WHEN p.id IS NULL THEN 1 ELSE 0 END,u.display_name"
        );
        $statement->execute(['clinic' => $actor->clinicId]);
        return $statement->fetchAll();
    }

    public function updateTeamProfile(AuthContext $actor, int $userId, array $body, string $correlationId): array
    {
        $this->superAdmin($actor);
        $this->required($body, ['slug','section','public_name','public_title']);
        $slug = strtolower(trim((string)$body['slug']));
        $section = (string)$body['section'];
        $publicName = trim((string)$body['public_name']);
        $bookingName = $this->optional($body, 'booking_name');
        $title = trim((string)$body['public_title']);
        $titleFr = $this->optional($body, 'public_title_fr');
        $summary = $this->optional($body, 'summary');
        $summaryFr = $this->optional($body, 'summary_fr');
        $contact = $section === 'practitioner' ? PublicCardContact::fromInput($body) : ['email' => null, 'phone' => null, 'sms' => 0, 'website' => null];
        $order = (int)($body['display_order'] ?? 100);
        $published = (bool)($body['published'] ?? false);
        $showBooking = (bool)($body['show_booking_action'] ?? false);
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $slug) !== 1) throw new ApiException(422,'validation_error','Use a lowercase URL name containing letters, numbers, and hyphens.',['slug'=>'Invalid URL name']);
        if (!in_array($section,['practitioner','administration'],true)) throw new ApiException(422,'validation_error','Choose a valid team section.',['section'=>'Invalid section']);
        if ($publicName==='' || strlen($publicName)>150) throw new ApiException(422,'validation_error','Enter a valid public name.',['public_name'=>'Required; maximum 150 characters']);
        if ($section==='practitioner' && ($bookingName===null || strlen($bookingName)>100)) throw new ApiException(422,'validation_error','Enter a valid booking name.',['booking_name'=>'Required for practitioners; maximum 100 characters']);
        if ($title==='' || strlen($title)>150 || ($titleFr!==null && strlen($titleFr)>150)) throw new ApiException(422,'validation_error','Team titles may contain up to 150 characters.',['public_title'=>'Required; maximum 150 characters']);
        if (($summary!==null && strlen($summary)>1000) || ($summaryFr!==null && strlen($summaryFr)>1000)) throw new ApiException(422,'validation_error','Team summaries may contain up to 1000 characters.',['summary'=>'Maximum 1000 characters']);
        if ($order<0 || $order>65535) throw new ApiException(422,'validation_error','Display order must be between 0 and 65535.',['display_order'=>'Invalid display order']);
        $pdo = $this->database->connection();
        $check = $pdo->prepare("SELECT p.id practitioner_id FROM users u JOIN staff_accounts a ON a.user_id=u.id LEFT JOIN practitioners p ON p.user_id=u.id AND p.active=1 WHERE u.id=:user AND u.clinic_id=:clinic AND u.user_type='staff'");
        $check->execute(['user'=>$userId,'clinic'=>$actor->clinicId]);
        $staff = $check->fetch();
        if (!$staff) throw new ApiException(404,'staff_not_found','Staff member not found.');
        if ($section==='practitioner' && !$staff['practitioner_id']) throw new ApiException(422,'validation_error','Only an active practitioner can appear in the practitioner section.',['section'=>'Active practitioner required']);
        if ($showBooking && ($section!=='practitioner' || !$staff['practitioner_id'])) throw new ApiException(422,'validation_error','Booking can only be shown for an active practitioner.',['show_booking_action'=>'Active practitioner required']);
        $statement = $pdo->prepare("INSERT INTO public_team_profiles(user_id,clinic_id,slug,section,public_name,booking_name,public_title,public_title_fr,summary,summary_fr,public_website_url,public_contact_email,public_contact_phone,public_contact_sms,display_order,published,show_booking_action,updated_by)
            VALUES(:user,:clinic,:slug,:section,:public_name,:booking_name,:title,:title_fr,:summary,:summary_fr,:website,:contact_email,:contact_phone,:contact_sms,:display_order,:published,:booking,:actor)
            ON DUPLICATE KEY UPDATE slug=VALUES(slug),section=VALUES(section),public_name=VALUES(public_name),booking_name=VALUES(booking_name),public_title=VALUES(public_title),public_title_fr=VALUES(public_title_fr),summary=VALUES(summary),summary_fr=VALUES(summary_fr),public_website_url=VALUES(public_website_url),public_contact_email=VALUES(public_contact_email),public_contact_phone=VALUES(public_contact_phone),public_contact_sms=VALUES(public_contact_sms),display_order=VALUES(display_order),published=VALUES(published),show_booking_action=VALUES(show_booking_action),updated_by=VALUES(updated_by)");
        try {
            $statement->execute(['user'=>$userId,'clinic'=>$actor->clinicId,'slug'=>$slug,'section'=>$section,'public_name'=>$publicName,'booking_name'=>$bookingName,'title'=>$title,'title_fr'=>$titleFr,'summary'=>$summary,'summary_fr'=>$summaryFr,'website'=>$contact['website'],'contact_email'=>$contact['email'],'contact_phone'=>$contact['phone'],'contact_sms'=>$contact['sms'],'display_order'=>$order,'published'=>$published?1:0,'booking'=>$showBooking?1:0,'actor'=>$actor->userId]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode()==='23000') throw new ApiException(409,'slug_already_exists','That public profile URL is already in use.',['slug'=>'Already in use']);
            throw $e;
        }
        $this->audit->write($actor->clinicId,$actor,$correlationId,'team_profile.update','user',$userId,'success',['published'=>$published,'section'=>$section]);
        return ['user_id'=>$userId,'published'=>$published];
    }

    private function createPractitionerRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->admin($actor);$this->required($body,['user_id','discipline','location_id']);$this->ownedLocation($actor,(int)$body['location_id']);$pdo=$this->database->connection();
        $statement=$pdo->prepare('INSERT INTO practitioners(user_id,discipline,biography,credentials,booking_mode) SELECT id,:discipline,:bio,:credentials,:mode FROM users WHERE id=:user AND clinic_id=:clinic AND user_type=\'staff\' AND status=\'active\'');$statement->execute(['discipline'=>$body['discipline'],'bio'=>$body['biography']??null,'credentials'=>$body['credentials']??null,'mode'=>$body['booking_mode']??'clinic_managed','user'=>(int)$body['user_id'],'clinic'=>$actor->clinicId]);if($statement->rowCount()!==1)throw new ApiException(422,'invalid_user','Practitioner user was not found in this clinic.');$id=(int)$pdo->lastInsertId();$statement=$pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id) VALUES(:p,:l)');$statement->execute(['p'=>$id,'l'=>(int)$body['location_id']]);$this->audit->write($actor->clinicId,$actor,$correlationId,'practitioner.create','practitioner',$id);return ['id'=>$id,'status'=>'active'];
    }

    public function previewServiceImport(AuthContext $actor,array $body,string $cid): array
    {
        return $this->clinicAdministration($actor,fn()=>(new ServiceImportService($this->database,$this->audit))->preview($actor,$body,$cid,
            function(array $payload):void{$this->serviceInput($payload);$this->validateService($payload,self::durationOptions($payload));$this->servicePublishing($payload);},
            function(int $id)use($actor):void{$this->ownedServiceRecord($actor,$id);$this->servicePatientEffects($actor,$id,'appointments',true);$this->serviceSeriesEffects($actor,$id,true);}
        ),clinicWide:true);
    }

    public function serviceImport(AuthContext $actor,string $id): array
    {return $this->clinicAdministration($actor,fn()=>(new ServiceImportService($this->database,$this->audit))->get($actor,$id),clinicWide:true);}

    public function applyServiceImport(AuthContext $actor,array $body,string $cid): array
    {
        return $this->clinicAdministration($actor,fn()=>(new ServiceImportService($this->database,$this->audit))->apply($actor,$body,$cid,
            fn(array $payload)=>$this->createServiceCategoryRecord($actor,$payload,$cid),
            fn(array $payload)=>$this->createServiceRecord($actor,$payload,$cid),
            fn(int $id,array $payload)=>$this->updateServiceRecord($actor,$id,$payload,$cid,true)
        ),clinicWide:true);
    }

    public function createService(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->createServiceRecord($actor,$body,$correlationId),clinicWide:true);}

    private function createServiceRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);$this->serviceInput($body);$options=self::durationOptions($body);$this->validateService($body,$options);$categoryId=$this->ownedServiceCategory($actor,$body['category_id']??null);$public=$this->servicePublishing($body);$pdo=$this->database->connection();$initialPractitioners=$this->serviceInitialPractitioners($actor,$body);
        try{$statement=$pdo->prepare("INSERT INTO services(clinic_id,category_id,slug,name,name_fr,public_summary,public_summary_fr,description,description_fr,preparation_instructions,preparation_instructions_fr,price_cents,duration_mode,lead_time_minutes,booking_horizon_days,cancellation_window_minutes,cancellation_fee_type,cancellation_fee_value,buffer_before_minutes,buffer_after_minutes,requires_room,recurrence_allowed,published,display_order,active) VALUES(:clinic,:category,:slug,:name,:name_fr,:summary,:summary_fr,:description,:description_fr,:instructions,:instructions_fr,:price,:mode,:lead,:horizon,:cancel_window,:fee_type,:fee_value,:before,:after,:room,:recurrence,:published,:display_order,:active)");$statement->execute(['clinic'=>$actor->clinicId,'category'=>$categoryId,'slug'=>$public['slug'],'name'=>trim((string)$body['name']),'name_fr'=>$public['name_fr'],'summary'=>$public['public_summary'],'summary_fr'=>$public['public_summary_fr'],'description'=>$this->optional($body,'description'),'description_fr'=>$public['description_fr'],'instructions'=>$this->optional($body,'preparation_instructions'),'instructions_fr'=>$public['preparation_instructions_fr'],'price'=>min(array_column($options,'price_cents')),'mode'=>count($options)>1?'selectable':'fixed','lead'=>(int)($body['lead_time_minutes']??0),'horizon'=>(int)($body['booking_horizon_days']??365),'cancel_window'=>(int)($body['cancellation_window_minutes']??1440),'fee_type'=>$body['cancellation_fee_type']??'none','fee_value'=>(int)($body['cancellation_fee_value']??0),'before'=>(int)($body['buffer_before_minutes']??0),'after'=>(int)($body['buffer_after_minutes']??0),'room'=>(bool)($body['requires_room']??true)?1:0,'recurrence'=>(bool)($body['recurrence_allowed']??false)?1:0,'published'=>$public['published'],'display_order'=>$public['display_order'],'active'=>($body['active']??true)?1:0]);$id=(int)$pdo->lastInsertId();$durationInsert=$pdo->prepare('INSERT INTO service_duration_options(service_id,duration_minutes,price_cents) VALUES(:service,:minutes,:price)');foreach($options as $option)$durationInsert->execute(['service'=>$id,'minutes'=>$option['minutes'],'price'=>$option['price_cents']]);foreach($initialPractitioners as $practitioner){$link=$pdo->prepare('INSERT INTO practitioner_services(practitioner_id,service_id) SELECT id,:service FROM practitioners WHERE id=:practitioner AND user_id IN(SELECT id FROM users WHERE clinic_id=:clinic)');$link->execute(['service'=>$id,'practitioner'=>(int)$practitioner,'clinic'=>$actor->clinicId]);}$this->audit->write($actor->clinicId,$actor,$correlationId,'service.create','service',$id,'success',['category_id'=>$categoryId,'published'=>(bool)$public['published'],'slug'=>$public['slug']]);return ['id'=>$id,'status'=>($body['active']??true)?'active':'inactive'];}catch(Throwable $e){if((string)$e->getCode()==='23000')throw new ApiException(409,'service_already_exists','A service with that name or public URL already exists.');throw $e;}
    }

    public function services(AuthContext $actor): array
    {return $this->clinicAdministration($actor,fn()=>$this->servicesRecord($actor),clinicWide:true);}

    private function servicesRecord(AuthContext $actor): array{$this->superAdmin($actor);$s=$this->database->connection()->prepare('SELECT s.id,c.id category_id,c.name category_name,s.slug,s.name,s.name_fr,s.public_summary,s.public_summary_fr,s.description,s.description_fr,s.preparation_instructions,s.preparation_instructions_fr,s.published,s.display_order,s.price_cents,s.lead_time_minutes,s.booking_horizon_days,s.cancellation_window_minutes,s.cancellation_fee_type,s.cancellation_fee_value,s.buffer_before_minutes,s.buffer_after_minutes,s.requires_room,s.recurrence_allowed,s.active,d.duration_minutes,COALESCE(d.price_cents,s.price_cents) duration_price_cents FROM services s LEFT JOIN service_categories c ON c.id=s.category_id AND c.clinic_id=s.clinic_id JOIN service_duration_options d ON d.service_id=s.id AND d.active=1 WHERE s.clinic_id=:clinic ORDER BY c.name IS NULL,c.name,s.display_order,s.name,d.duration_minutes');$s->execute(['clinic'=>$actor->clinicId]);$result=[];foreach($s->fetchAll() as $row){$id=(int)$row['id'];$option=['minutes'=>(int)$row['duration_minutes'],'price_cents'=>(int)$row['duration_price_cents']];if(!isset($result[$id])){$row['category_id']=$row['category_id']===null?null:(int)$row['category_id'];$row['published']=(int)$row['published'];$row['display_order']=(int)$row['display_order'];$row['duration_options']=[];$row['durations']=[];unset($row['duration_minutes'],$row['duration_price_cents']);$result[$id]=$row;}$result[$id]['duration_options'][]=$option;$result[$id]['durations'][]=$option['minutes'];}return array_values($result);}
    public function updateService(AuthContext $actor,int $id,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->updateServiceRecord($actor,$id,$body,$correlationId),clinicWide:true);}

    private function updateServiceRecord(AuthContext $actor,int $id,array $body,string $correlationId,bool $contentOnly=false): array{$this->superAdmin($actor);$body=$this->serviceUpdateBody($actor,$id,$body);$this->serviceInput($body);$this->servicePatientEffects($actor,$id,'appointments',true);$this->serviceSeriesEffects($actor,$id,true);$options=self::durationOptions($body);$this->validateService($body,$options);$categoryId=$this->ownedServiceCategory($actor,$body['category_id']??null);$public=$this->servicePublishing($body);$pdo=$this->database->connection();try{
        // Imports must preserve even legacy base prices/modes and inactive duration rows verbatim.
        if($contentOnly){
            $s=$pdo->prepare('UPDATE services SET category_id=?,name=?,name_fr=?,public_summary=?,public_summary_fr=?,description=?,description_fr=? WHERE id=? AND clinic_id=?');
            $s->execute([$categoryId,trim($body['name']),$public['name_fr'],$public['public_summary'],$public['public_summary_fr'],$this->optional($body,'description'),$public['description_fr'],$id,$actor->clinicId]);
            $this->audit->write($actor->clinicId,$actor,$correlationId,'service.update','service',$id,'success',['category_id'=>$categoryId,'published'=>(bool)$public['published'],'slug'=>$public['slug']]);
            return ['id'=>$id];
        }
        $s=$pdo->prepare('UPDATE services SET category_id=:category,slug=:slug,name=:name,name_fr=:name_fr,public_summary=:summary,public_summary_fr=:summary_fr,description=:description,description_fr=:description_fr,preparation_instructions=:instructions,preparation_instructions_fr=:instructions_fr,published=:published,display_order=:display_order,price_cents=:price,duration_mode=:mode,lead_time_minutes=:lead,booking_horizon_days=:horizon,cancellation_window_minutes=:cancel_window,cancellation_fee_type=:fee_type,cancellation_fee_value=:fee_value,buffer_before_minutes=:before,buffer_after_minutes=:after,requires_room=:room,recurrence_allowed=:recurrence,active=:active WHERE id=:id AND clinic_id=:clinic');$s->execute(['category'=>$categoryId,'slug'=>$public['slug'],'name'=>trim((string)$body['name']),'name_fr'=>$public['name_fr'],'summary'=>$public['public_summary'],'summary_fr'=>$public['public_summary_fr'],'description'=>$this->optional($body,'description'),'description_fr'=>$public['description_fr'],'instructions'=>$this->optional($body,'preparation_instructions'),'instructions_fr'=>$public['preparation_instructions_fr'],'published'=>$public['published'],'display_order'=>$public['display_order'],'price'=>min(array_column($options,'price_cents')),'mode'=>count($options)>1?'selectable':'fixed','lead'=>(int)($body['lead_time_minutes']??0),'horizon'=>(int)($body['booking_horizon_days']??365),'cancel_window'=>(int)($body['cancellation_window_minutes']??1440),'fee_type'=>$body['cancellation_fee_type']??'none','fee_value'=>(int)($body['cancellation_fee_value']??0),'before'=>(int)($body['buffer_before_minutes']??0),'after'=>(int)($body['buffer_after_minutes']??0),'room'=>(bool)($body['requires_room']??true)?1:0,'recurrence'=>(bool)($body['recurrence_allowed']??false)?1:0,'active'=>(bool)($body['active']??true)?1:0,'id'=>$id,'clinic'=>$actor->clinicId]);$check=$pdo->prepare('SELECT 1 FROM services WHERE id=:id AND clinic_id=:clinic');$check->execute(['id'=>$id,'clinic'=>$actor->clinicId]);if(!$check->fetchColumn())throw new ApiException(404,'service_not_found','Service not found.');$pdo->prepare('UPDATE service_duration_options SET active=0 WHERE service_id=:id')->execute(['id'=>$id]);$d=$pdo->prepare('INSERT INTO service_duration_options(service_id,duration_minutes,price_cents) VALUES(:id,:minutes,:price) ON DUPLICATE KEY UPDATE price_cents=VALUES(price_cents),active=1');foreach($options as $option)$d->execute(['id'=>$id,'minutes'=>$option['minutes'],'price'=>$option['price_cents']]);$this->audit->write($actor->clinicId,$actor,$correlationId,'service.update','service',$id,'success',['category_id'=>$categoryId,'published'=>(bool)$public['published'],'slug'=>$public['slug']]);return ['id'=>$id];}catch(Throwable $e){if((string)$e->getCode()==='23000')throw new ApiException(409,'service_already_exists','A service with that name or public URL already exists.');throw $e;}}

    public function serviceAssignments(AuthContext $actor): array
    {return $this->clinicAdministration($actor,function()use($actor){
        $pdo=$this->database->connection();
        $q=$pdo->prepare("SELECT ps.practitioner_id,ps.service_id,ps.active,ps.offers_mobile,ps.offers_clinic,ps.mobile_radius_km,ps.mobile_free_radius_km,ps.travel_buffer_minutes,ps.mobile_fee_cents FROM practitioner_services ps JOIN services s ON s.id=ps.service_id JOIN practitioners p ON p.id=ps.practitioner_id JOIN users u ON u.id=p.user_id AND u.clinic_id=s.clinic_id AND u.user_type='staff' WHERE s.clinic_id=?");$q->execute([$actor->clinicId]);
        $l=$pdo->prepare('SELECT sl.service_id,sl.location_id,sl.active FROM service_locations sl JOIN services s ON s.id=sl.service_id JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id WHERE s.clinic_id=?');$l->execute([$actor->clinicId]);
        return ['practitioners'=>$q->fetchAll(),'locations'=>$l->fetchAll()];
    },clinicWide:true);}

    private function ownedServiceRecord(AuthContext $actor,int $id): array
    {
        $q=$this->database->connection()->prepare('SELECT * FROM services WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$id,$actor->clinicId]);$old=$q->fetch();
        if(!$old)throw new ApiException(404,'service_not_found','Service not found.');return $old;
    }

    private function serviceUpdateBody(AuthContext $actor,int $id,array $body): array
    {
        $old=$this->ownedServiceRecord($actor,$id);
        foreach(['published','requires_room','recurrence_allowed','active'] as $key)$old[$key]=(bool)$old[$key];
        foreach(['display_order','lead_time_minutes','booking_horizon_days','cancellation_window_minutes','cancellation_fee_value','buffer_before_minutes','buffer_after_minutes'] as $key)$old[$key]=(int)$old[$key];
        $old['category_id']=$old['category_id']===null?null:(int)$old['category_id'];
        if(!array_key_exists('duration_options',$body)&&!array_key_exists('durations',$body)){
            $q=$this->database->connection()->prepare('SELECT duration_minutes minutes,COALESCE(price_cents,?) price_cents FROM service_duration_options WHERE service_id=? AND active=1 ORDER BY duration_minutes FOR UPDATE');$q->execute([(int)$old['price_cents'],$id]);
            $old['duration_options']=array_map(fn($r)=>['minutes'=>(int)$r['minutes'],'price_cents'=>(int)$r['price_cents']],$q->fetchAll());
        }
        return array_replace($old,$body);
    }

    private function serviceInput(array $body): void
    {
        foreach(['name'=>150,'name_fr'=>150,'slug'=>120,'public_summary'=>500,'public_summary_fr'=>500,'description'=>65535,'description_fr'=>65535,'preparation_instructions'=>65535,'preparation_instructions_fr'=>65535] as $key=>$max){
            $v=$body[$key]??'';
            if(!is_string($v)||mb_strlen($v)>$max||(($max===65535)&&strlen($v)>65535)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new ApiException(422,'validation_error','Check the service fields.',[$key=>'Invalid text']);
        }
        foreach(['published','requires_room','recurrence_allowed','active'] as $key)if(array_key_exists($key,$body)&&!is_bool($body[$key]))throw new ApiException(422,'validation_error','Check the service fields.',[$key=>'Boolean required']);
        foreach(['lead_time_minutes'=>525600,'booking_horizon_days'=>3650,'cancellation_window_minutes'=>525600,'cancellation_fee_value'=>10000000,'buffer_before_minutes'=>1440,'buffer_after_minutes'=>1440,'display_order'=>65535] as $key=>$max){
            if(array_key_exists($key,$body)&&(!is_int($body[$key])||$body[$key]<0||$body[$key]>$max))throw new ApiException(422,'validation_error','Check the service fields.',[$key=>'Invalid integer']);
        }
        if(array_key_exists('category_id',$body)&&$body['category_id']!==null&&(!is_int($body['category_id'])||$body['category_id']<=0))throw new ApiException(422,'validation_error','Choose a valid service category.');
        if(isset($body['cancellation_fee_type'])&&!is_string($body['cancellation_fee_type']))throw new ApiException(422,'validation_error','Choose a valid cancellation fee type.');
        if(array_key_exists('duration_options',$body)){
            $raw=$body['duration_options'];if(!is_array($raw)||!array_is_list($raw))throw new ApiException(422,'validation_error','Invalid duration options.');
            foreach($raw as $option)if(!is_array($option)||!is_int($option['minutes']??null)||!is_int($option['price_cents']??null))throw new ApiException(422,'validation_error','Duration and price must be integers.');
        }elseif(array_key_exists('durations',$body)){
            if(!is_array($body['durations'])||!array_is_list($body['durations'])||!is_int($body['price_cents']??null))throw new ApiException(422,'validation_error','Invalid durations.');
            foreach($body['durations'] as $minutes)if(!is_int($minutes))throw new ApiException(422,'validation_error','Invalid duration.');
        }
        if(array_key_exists('practitioner_ids',$body))$this->assignmentIds($body,'practitioner_ids');
    }

    private function serviceInitialPractitioners(AuthContext $actor,array $body): array
    {
        $ids=array_key_exists('practitioner_ids',$body)?$this->assignmentIds($body,'practitioner_ids'):[];
        foreach($ids as $id)$this->servicePractitioner($actor,$id);return $ids;
    }

    private function servicePractitioner(AuthContext $actor,int $id): void
    {
        $q=$this->database->connection()->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.active=1 AND u.clinic_id=? AND u.user_type='staff' AND u.status='active' FOR UPDATE");$q->execute([$id,$actor->clinicId]);
        if(!$q->fetchColumn())throw new ApiException(404,'practitioner_not_found','Practitioner not found.');
    }

    public function updateServiceAssignments(AuthContext $actor,int $serviceId,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,function()use($actor,$serviceId,$body,$correlationId){
        $pdo=$this->database->connection();$this->ownedServiceRecord($actor,$serviceId);
        $locations=$this->assignmentIds($body,'location_ids');
        if(!isset($body['practitioners'])||!is_array($body['practitioners'])||!array_is_list($body['practitioners']))throw new ApiException(422,'validation_error','An explicit practitioner list is required.');
        foreach($locations as $id)$this->roomLocation($actor,$id);
        $people=[];
        foreach($body['practitioners'] as $item){
            if(!is_array($item)||!is_int($item['practitioner_id']??null)||$item['practitioner_id']<=0)throw new ApiException(422,'validation_error','Invalid practitioner assignment.');
            $id=$item['practitioner_id'];if(isset($people[$id]))throw new ApiException(422,'validation_error','Duplicate practitioner assignment.');$this->servicePractitioner($actor,$id);
            foreach(['offers_clinic'=>1,'offers_mobile'=>0] as $key=>$default){$v=$item[$key]??$default;if(!in_array($v,[true,false,0,1],true))throw new ApiException(422,'validation_error','Invalid visit mode.');$item[$key]=(int)(bool)$v;}
            if(!$item['offers_clinic']&&!$item['offers_mobile'])throw new ApiException(422,'validation_error','Enable at least one visit mode.');
            foreach(['travel_buffer_minutes'=>240,'mobile_fee_cents'=>10000000] as $key=>$max){$v=$item[$key]??0;if(!is_int($v)||$v<0||$v>$max||($key==='travel_buffer_minutes'&&$v%15!==0))throw new ApiException(422,'validation_error','Invalid travel setting.');$item[$key]=$v;}
            $radius=$item['mobile_radius_km']??null;$free=$item['mobile_free_radius_km']??null;
            if(($radius!==null&&(!is_int($radius)||$radius<1||$radius>500))||($item['offers_mobile']&&$radius===null)||($free!==null&&(!is_int($free)||$free<0||($radius===null?$free!==0:$free>$radius))))throw new ApiException(422,'validation_error','Invalid travel radius.');
            $people[$id]=['practitioner_id'=>$id,'offers_mobile'=>$item['offers_mobile'],'offers_clinic'=>$item['offers_clinic'],'mobile_radius_km'=>$radius,'mobile_free_radius_km'=>$free,'travel_buffer_minutes'=>$item['travel_buffer_minutes'],'mobile_fee_cents'=>$item['mobile_fee_cents']];
        }
        ksort($people);$people=array_values($people);
        $q=$pdo->prepare("SELECT ps.*,u.clinic_id,u.user_type FROM practitioner_services ps LEFT JOIN practitioners p ON p.id=ps.practitioner_id LEFT JOIN users u ON u.id=p.user_id WHERE ps.service_id=? ORDER BY ps.practitioner_id FOR UPDATE");$q->execute([$serviceId]);$oldPeople=[];
        foreach($q->fetchAll() as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||$row['user_type']!=='staff')throw new ApiException(409,'service_privacy_review','The service change requires review.');
            if(!(int)$row['active'])continue;$item=[];foreach(['practitioner_id','offers_mobile','offers_clinic','mobile_radius_km','mobile_free_radius_km','travel_buffer_minutes','mobile_fee_cents'] as $key)$item[$key]=$row[$key]===null?null:(int)$row[$key];$oldPeople[]=$item;
        }
        $q=$pdo->prepare('SELECT sl.location_id,sl.active,l.clinic_id FROM service_locations sl LEFT JOIN locations l ON l.id=sl.location_id WHERE sl.service_id=? ORDER BY sl.location_id FOR UPDATE');$q->execute([$serviceId]);$oldLocations=[];
        foreach($q->fetchAll() as $row){if((int)$row['clinic_id']!==$actor->clinicId)throw new ApiException(409,'service_privacy_review','The service change requires review.');if((int)$row['active'])$oldLocations[]=(int)$row['location_id'];}
        if($oldPeople!==$people||$oldLocations!==$locations){
            $this->servicePatientEffects($actor,$serviceId,'appointments',false);
            $this->serviceSeriesEffects($actor,$serviceId,false);
            $approvals=$this->servicePatientEffects($actor,$serviceId,'approvals');
            $pdo->prepare('UPDATE practitioner_services SET active=0 WHERE service_id=?')->execute([$serviceId]);
            $up=$pdo->prepare('INSERT INTO practitioner_services(practitioner_id,service_id,active,offers_mobile,offers_clinic,mobile_radius_km,mobile_free_radius_km,travel_buffer_minutes,mobile_fee_cents) VALUES(?,?,1,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE active=1,offers_mobile=VALUES(offers_mobile),offers_clinic=VALUES(offers_clinic),mobile_radius_km=VALUES(mobile_radius_km),mobile_free_radius_km=VALUES(mobile_free_radius_km),travel_buffer_minutes=VALUES(travel_buffer_minutes),mobile_fee_cents=VALUES(mobile_fee_cents)');
            foreach($people as $item)$up->execute([$item['practitioner_id'],$serviceId,$item['offers_mobile'],$item['offers_clinic'],$item['mobile_radius_km'],$item['mobile_free_radius_km'],$item['travel_buffer_minutes'],$item['mobile_fee_cents']]);
            $pdo->prepare('UPDATE service_locations SET active=0 WHERE service_id=?')->execute([$serviceId]);$up=$pdo->prepare('INSERT INTO service_locations(service_id,location_id,active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE active=1');foreach($locations as $id)$up->execute([$serviceId,$id]);
            $pdo->prepare('DELETE FROM onsite_area_approvals WHERE service_id=? AND clinic_id=?')->execute([$serviceId,$actor->clinicId]);
            foreach($approvals as $row)$this->audit->write($actor->clinicId,$actor,$correlationId,'onsite_area.revoke','client',(int)$row['client_id'],'success',['approval_id'=>(int)$row['id'],'service_id'=>$serviceId,'reason'=>'service_assignments_changed']);
        }
        $this->audit->write($actor->clinicId,$actor,$correlationId,'service.assignments.update','service',$serviceId,'success',['practitioners'=>count($people),'locations'=>count($locations)]);return ['id'=>$serviceId];
    },clinicWide:true);}

    private function serviceSeriesEffects(AuthContext $actor,int $service,bool $history): void
    {
        $filter=$history?'':" AND a.status='active'";
        $q=$this->database->connection()->prepare("SELECT a.clinic_id,a.client_id,patient.id patient_id,staff.id staff_id FROM recurring_series a
            LEFT JOIN users patient ON patient.id=a.client_id AND patient.clinic_id=a.clinic_id AND patient.user_type='client'
            LEFT JOIN practitioners p ON p.id=a.practitioner_id
            LEFT JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=a.clinic_id AND staff.user_type='staff'
            WHERE a.service_id=? $filter ORDER BY a.id FOR UPDATE");$q->execute([$service]);$consent=new ConsentAccess($this->database->connection());
        foreach($q->fetchAll() as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||!$row['patient_id']||!$row['staff_id'])throw new ApiException(409,'service_privacy_review','The service change requires review.');
            if($consent->restricted($actor->clinicId,(int)$row['client_id'],$actor->userId,true)||$consent->restricted($actor->clinicId,(int)$row['client_id'],(int)$row['staff_id'],true))throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
        }
    }

    private function servicePatientEffects(AuthContext $actor,int $service,string $kind,bool $history=false): array
    {
        $table=match($kind){'appointments'=>'appointments','approvals'=>'onsite_area_approvals',default=>throw new \LogicException('Invalid service effect.')};
        $extra=$kind==='appointments'&&!$history?" AND a.status NOT IN('canceled_by_client','canceled_by_clinic')":'';
        $roomColumns=$kind==='appointments'?',a.room_id,r.location_id room_location':'';
        $roomJoin=$kind==='appointments'?' LEFT JOIN rooms r ON r.id=a.room_id':'';
        $q=$this->database->connection()->prepare("SELECT a.id,a.clinic_id,a.client_id,patient.id patient_id,staff.id staff_id,l.id owned_location,a.location_id $roomColumns FROM $table a
            LEFT JOIN users patient ON patient.id=a.client_id AND patient.clinic_id=a.clinic_id AND patient.user_type='client'
            LEFT JOIN practitioners p ON p.id=a.practitioner_id
            LEFT JOIN users staff ON staff.id=p.user_id AND staff.clinic_id=a.clinic_id AND staff.user_type='staff'
            LEFT JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id $roomJoin
            WHERE a.service_id=? $extra ORDER BY a.id FOR UPDATE");$q->execute([$service]);$rows=$q->fetchAll();$consent=new ConsentAccess($this->database->connection());
        foreach($rows as $row){
            if((int)$row['clinic_id']!==$actor->clinicId||!$row['patient_id']||!$row['staff_id']||!$row['owned_location']||($kind==='appointments'&&$row['room_id']!==null&&(int)$row['room_location']!==(int)$row['location_id']))throw new ApiException(409,'service_privacy_review','The service change requires review.');
            if($consent->restricted($actor->clinicId,(int)$row['client_id'],$actor->userId,true)||$consent->restricted($actor->clinicId,(int)$row['client_id'],(int)$row['staff_id'],true))throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
        }
        return $rows;
    }


    public function practitionerAvailabilityContext(AuthContext $actor): array
    {
        if(!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner access is required.');
        $pdo=$this->database->connection();
        $practitioner=$pdo->prepare("SELECT p.id practitioner_id,u.display_name,t.booking_name preferred_name,p.discipline,p.booking_mode,p.active,MIN(pl.location_id) location_id FROM practitioners p JOIN users u ON u.id=p.user_id LEFT JOIN public_team_profiles t ON t.user_id=u.id AND t.clinic_id=u.clinic_id LEFT JOIN practitioner_locations pl ON pl.practitioner_id=p.id AND pl.active=1 WHERE p.user_id=:user AND u.clinic_id=:clinic GROUP BY p.id,u.display_name,t.booking_name,p.discipline,p.booking_mode,p.active");
        $practitioner->execute(['user'=>$actor->userId,'clinic'=>$actor->clinicId]);$person=$practitioner->fetch();
        if(!$person||(int)$person['active']!==1)throw new ApiException(404,'practitioner_not_found','An active practitioner profile was not found for this account.');
        $locations=$pdo->prepare('SELECT l.id,l.name,l.timezone FROM practitioner_locations pl JOIN locations l ON l.id=pl.location_id WHERE pl.practitioner_id=:practitioner AND pl.active=1 AND l.clinic_id=:clinic ORDER BY l.name');
        $locations->execute(['practitioner'=>(int)$person['practitioner_id'],'clinic'=>$actor->clinicId]);
        return ['practitioners'=>[$person],'locations'=>$locations->fetchAll(),'can_manage'=>$person['booking_mode']==='practitioner_managed'];
    }

    public function createAvailability(AuthContext $actor,array $body,string $correlationId): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$body,$correlationId):array{
            $this->validateAvailabilityRule($actor,$body);$this->authorizeRuleChange($actor,$body);
            $q=$this->database->connection()->prepare('INSERT INTO availability_rules(practitioner_id,location_id,weekday,start_time,end_time,valid_from,valid_until,recurrence_interval_weeks) VALUES(:p,:l,:weekday,:start,:end,:valid_from,:valid_until,:interval)');
            $q->execute($this->ruleParams($body));return $this->created($actor,$correlationId,'availability_rule',(int)$this->database->connection()->lastInsertId());
        });
    }
    private function ruleParams(array $body): array
    {
        return ['p'=>(int)$body['practitioner_id'],'l'=>(int)$body['location_id'],'weekday'=>(int)$body['weekday'],'start'=>$body['start_time'],'end'=>$body['end_time'],'valid_from'=>$body['valid_from'],'valid_until'=>$body['valid_until']??null,'interval'=>(int)($body['recurrence_interval_weeks']??1)];
    }
    private function authorizeRuleChange(AuthContext $actor,array $body): void
    {
        $q=$this->database->connection()->prepare('SELECT timezone FROM locations WHERE id=? AND clinic_id=?');$q->execute([(int)$body['location_id'],$actor->clinicId]);
        (new ScheduleChangeAccess($this->database))->assertRule($actor,array_replace($body,['timezone'=>$q->fetchColumn(),'valid_until'=>$body['valid_until']??null,'recurrence_interval_weeks'=>(int)($body['recurrence_interval_weeks']??1)]));
    }
    private function lockedScheduleChange(AuthContext $actor,string $table,int $id): array
    {
        $code=$table==='availability_rules'?'availability_rule_not_found':'schedule_exception_not_found';
        $this->scheduleRecordAccess($actor,$table,$id,$code);
        $q=$this->database->connection()->prepare("SELECT x.* FROM $table x JOIN locations l ON l.id=x.location_id AND l.clinic_id=:clinic WHERE x.id=:id FOR UPDATE");$q->execute(['clinic'=>$actor->clinicId,'id'=>$id]);
        $row=$q->fetch();if(!$row)throw new ApiException(404,$code,'Schedule item not found.');
        $this->scheduleLocationAccess($actor,(int)$row['practitioner_id'],(int)$row['location_id']);return $row;
    }
    public function availabilityRules(AuthContext $actor): array{$this->scheduleRole($actor);$own=$actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin');$where=$own?' AND p.user_id=:actor AND p.active=1':'';$params=['clinic'=>$actor->clinicId];if($own)$params['actor']=$actor->userId;$s=$this->database->connection()->prepare("SELECT a.id,a.practitioner_id,a.location_id,a.weekday,a.start_time,a.end_time,a.valid_from,a.valid_until,a.recurrence_interval_weeks,a.active,u.display_name practitioner_name,l.name location_name FROM availability_rules a JOIN practitioners p ON p.id=a.practitioner_id JOIN users u ON u.id=p.user_id JOIN locations l ON l.id=a.location_id WHERE u.clinic_id=:clinic{$where} ORDER BY u.display_name,a.weekday,a.start_time");$s->execute($params);return $s->fetchAll();}
    public function updateAvailabilityRule(AuthContext $actor,int $id,array $body,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$id,$body,$cid):array{
            $old=$this->lockedScheduleChange($actor,'availability_rules',$id);$this->validateAvailabilityRule($actor,$body);
            $this->authorizeRuleChange($actor,$old);$this->authorizeRuleChange($actor,$body);
            $q=$this->database->connection()->prepare('UPDATE availability_rules SET practitioner_id=:p,location_id=:l,weekday=:weekday,start_time=:start,end_time=:end,valid_from=:valid_from,valid_until=:valid_until,recurrence_interval_weeks=:interval WHERE id=:id');$q->execute($this->ruleParams($body)+['id'=>$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'availability_rule.update','availability_rule',$id);return ['id'=>$id];
        });
    }
    public function deleteAvailabilityRule(AuthContext $actor,int $id,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$id,$cid):array{
            $old=$this->lockedScheduleChange($actor,'availability_rules',$id);
            if(!(bool)$old['active'])throw new ApiException(404,'availability_rule_not_found','Availability rule not found.');
            $this->authorizeRuleChange($actor,$old);
            $this->database->connection()->prepare('UPDATE availability_rules SET active=0 WHERE id=?')->execute([$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'availability_rule.archive','availability_rule',$id);return ['archived'=>true];
        });
    }

    public function scheduleExceptions(AuthContext $actor): array{$this->scheduleRole($actor);$own=$actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin');$where=$own?' AND p.user_id=:actor AND p.active=1':'';$params=['clinic'=>$actor->clinicId];if($own)$params['actor']=$actor->userId;$pdo=$this->database->connection();$o=$pdo->prepare("SELECT o.id,o.practitioner_id,o.location_id,o.starts_at,o.ends_at,o.override_type type,o.reason,u.display_name practitioner_name,l.name location_name,'override' kind FROM availability_overrides o JOIN practitioners p ON p.id=o.practitioner_id JOIN users u ON u.id=p.user_id JOIN locations l ON l.id=o.location_id WHERE u.clinic_id=:clinic{$where}");$o->execute($params);$visible=ConsentAccess::condition($actor,$params,'a.client_id');$t=$pdo->prepare("SELECT t.id,t.practitioner_id,t.location_id,t.starts_at,t.ends_at,t.reason_type type,t.notes reason,u.display_name practitioner_name,l.name location_name,'time_off' kind,(SELECT COUNT(*) FROM appointments a WHERE a.clinic_id=u.clinic_id AND a.practitioner_id=t.practitioner_id AND a.status NOT IN('canceled_by_client','canceled_by_clinic') AND a.starts_at<t.ends_at AND a.ends_at>t.starts_at $visible) affected_appointment_count FROM time_off t JOIN practitioners p ON p.id=t.practitioner_id JOIN users u ON u.id=p.user_id LEFT JOIN locations l ON l.id=t.location_id WHERE u.clinic_id=:clinic{$where}");$t->execute($params);$rows=array_merge($o->fetchAll(),$t->fetchAll());usort($rows,fn($a,$b)=>strcmp($a['starts_at'],$b['starts_at']));return $rows;}
    private function overrideInput(AuthContext $actor,array $body): array
    {
        $this->required($body,['practitioner_id','location_id','starts_at','ends_at','override_type']);
        $this->scheduleAccess($actor,(int)$body['practitioner_id']);$this->scheduleLocationAccess($actor,(int)$body['practitioner_id'],(int)$body['location_id']);
        [$start,$end]=$this->utcInterval($body,(int)$body['location_id']);
        if(!in_array($body['override_type'],['available','blocked'],true))throw new ApiException(422,'validation_error','Invalid override type.');
        return array_replace($body,['starts_at'=>$start,'ends_at'=>$end]);
    }
    private function overrideParams(array $body): array
    {
        return ['p'=>(int)$body['practitioner_id'],'l'=>(int)$body['location_id'],'starts'=>$body['starts_at'],'ends'=>$body['ends_at'],'type'=>$body['override_type'],'reason'=>$this->optional($body,'reason')];
    }
    public function createAvailabilityOverride(AuthContext $actor,array $body,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$body,$cid):array{
            $body=$this->overrideInput($actor,$body);(new ScheduleChangeAccess($this->database))->assertOverride($actor,$body);
            $q=$this->database->connection()->prepare('INSERT INTO availability_overrides(practitioner_id,location_id,starts_at,ends_at,override_type,reason,created_by) VALUES(:p,:l,:starts,:ends,:type,:reason,:actor)');$q->execute($this->overrideParams($body)+['actor'=>$actor->userId]);
            return $this->created($actor,$cid,'availability_override',(int)$this->database->connection()->lastInsertId());
        });
    }
    public function updateAvailabilityOverride(AuthContext $actor,int $id,array $body,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$id,$body,$cid):array{
            $old=$this->lockedScheduleChange($actor,'availability_overrides',$id);$body=$this->overrideInput($actor,$body);$access=new ScheduleChangeAccess($this->database);
            $access->assertOverride($actor,$old);$access->assertOverride($actor,$body);
            $q=$this->database->connection()->prepare('UPDATE availability_overrides SET practitioner_id=:p,location_id=:l,starts_at=:starts,ends_at=:ends,override_type=:type,reason=:reason WHERE id=:id');$q->execute($this->overrideParams($body)+['id'=>$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'availability_override.update','availability_override',$id);return ['id'=>$id];
        });
    }
    public function previewTimeOffImpact(AuthContext $actor,array $body): array
    {
        $this->required($body,['practitioner_id','location_id','starts_at','ends_at']);
        $practitionerId=(int)$body['practitioner_id'];
        $this->scheduleAccess($actor,$practitionerId);
        $this->scheduleLocationAccess($actor,$practitionerId,(int)$body['location_id']);
        [$start,$end]=$this->utcInterval($body,(int)$body['location_id']);
        return ['appointments'=>$this->timeOffAppointments($actor->clinicId,$practitionerId,$start,$end,$actor)];
    }

    public function updatePortalTheme(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->updatePortalThemeRecord($actor,$body,$correlationId),clinicWide:true);}

    private function updatePortalThemeRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);
        $primary=$body['primary_color']??null;$secondary=$body['secondary_color']??null;$font=$body['font_family']??null;
        if(!is_string($primary)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$primary)||!is_string($secondary)||!preg_match('/^#[0-9a-fA-F]{6}$/D',$secondary)||!in_array($font,['Inter','Arial','Georgia'],true))throw new ApiException(422,'validation_error','Choose valid portal colours and font.');
        $pdo=$this->database->connection();
        $save=$pdo->prepare('INSERT INTO clinic_portal_themes(clinic_id,primary_color,secondary_color,font_family,updated_by) VALUES(:clinic,:primary,:secondary,:font,:actor) ON DUPLICATE KEY UPDATE primary_color=VALUES(primary_color),secondary_color=VALUES(secondary_color),font_family=VALUES(font_family),updated_by=VALUES(updated_by)');$save->execute(['clinic'=>$actor->clinicId,'primary'=>strtolower($primary),'secondary'=>strtolower($secondary),'font'=>$font,'actor'=>$actor->userId]);$this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.portal_theme.update','clinic',$actor->clinicId);return ['primary_color'=>strtolower($primary),'secondary_color'=>strtolower($secondary),'font_family'=>$font];
    }
    public function updatePortalWelcome(AuthContext $actor,array $body,string $correlationId): array
    {return $this->clinicAdministration($actor,fn()=>$this->updatePortalWelcomeRecord($actor,$body,$correlationId),clinicWide:true);}

    private function updatePortalWelcomeRecord(AuthContext $actor,array $body,string $correlationId): array
    {
        $this->superAdmin($actor);
        $copy=[];
        foreach(['welcome_title_en'=>160,'welcome_title_fr'=>160,'welcome_body_en'=>3000,'welcome_body_fr'=>3000] as $field=>$limit){
            $value=$body[$field]??null;
            if(!is_string($value)||trim($value)===''||mb_strlen($value)>$limit||preg_match(str_starts_with($field,'welcome_title')?'/[\x00-\x1f\x7f]/':'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Provide the welcome title and message in English and French.');
            $copy[$field]=trim($value);
        }
        $pdo=$this->database->connection();
        $statement=$pdo->prepare('INSERT INTO clinic_portal_themes(clinic_id,welcome_title_en,welcome_title_fr,welcome_body_en,welcome_body_fr,updated_by) VALUES(:clinic,:welcome_title_en,:welcome_title_fr,:welcome_body_en,:welcome_body_fr,:actor) ON DUPLICATE KEY UPDATE welcome_title_en=VALUES(welcome_title_en),welcome_title_fr=VALUES(welcome_title_fr),welcome_body_en=VALUES(welcome_body_en),welcome_body_fr=VALUES(welcome_body_fr),updated_by=VALUES(updated_by)');
        $statement->execute(['clinic'=>$actor->clinicId,'actor'=>$actor->userId]+$copy);
        $this->audit->write($actor->clinicId,$actor,$correlationId,'clinic.portal_welcome.update','clinic',$actor->clinicId);

        return $copy;
    }
    public function timeOffImpact(AuthContext $actor,int $id): array
    {
        $this->scheduleRole($actor);
        $sql='SELECT t.practitioner_id,t.starts_at,t.ends_at FROM time_off t JOIN practitioners p ON p.id=t.practitioner_id JOIN users u ON u.id=p.user_id WHERE t.id=:id AND u.clinic_id=:clinic';
        $params=['id'=>$id,'clinic'=>$actor->clinicId];
        if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin')){$sql.=' AND p.user_id=:actor AND p.active=1';$params['actor']=$actor->userId;}
        $statement=$this->database->connection()->prepare($sql);$statement->execute($params);$timeOff=$statement->fetch();
        if(!$timeOff)throw new ApiException(404,'schedule_exception_not_found','Schedule item not found.');
        return ['appointments'=>$this->timeOffAppointments($actor->clinicId,(int)$timeOff['practitioner_id'],$timeOff['starts_at'],$timeOff['ends_at'],$actor)];
    }
    private function timeOffAppointments(int $clinicId,int $practitionerId,string $start,string $end,?AuthContext $viewer=null): array
    {
        $params=['clinic'=>$clinicId,'practitioner'=>$practitionerId,'start'=>$start,'end'=>$end];
        // Read previews filter patients; mutation callers must inspect the full affected set.
        // Mutation callers authorize the full set before hydrating or limiting it.
        $visible=$viewer?ConsentAccess::condition($viewer,$params,'a.client_id'):'';
        $statement=$this->database->connection()->prepare("SELECT a.id,a.starts_at,a.ends_at,a.status,u.display_name client_name,s.name service_name,l.name location_name,l.timezone FROM appointments a JOIN users u ON u.id=a.client_id AND u.clinic_id=a.clinic_id JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id WHERE a.clinic_id=:clinic AND a.practitioner_id=:practitioner AND a.status NOT IN('canceled_by_client','canceled_by_clinic') AND a.starts_at<:end AND a.ends_at>:start $visible ORDER BY a.starts_at,a.id LIMIT 501");
        $statement->execute($params);$rows=$statement->fetchAll();
        if(count($rows)>500)throw new ApiException(422,'too_many_affected_appointments','This time-off period affects more than 500 appointments. Shorten the period and review it in parts.');
        return $rows;
    }
    private function assertTimeOffImpactMatches(array $body,array $affected): void
    {
        if(!array_key_exists('expected_affected_appointment_ids',$body))return;
        $expected=$body['expected_affected_appointment_ids'];
        if(!is_array($expected)||count($expected)>500||count($expected)!==count(array_unique($expected))||array_filter($expected,static fn($id)=>filter_var($id,FILTER_VALIDATE_INT)===false||(int)$id<=0))throw new ApiException(422,'validation_error','Review the affected appointments again.');
        $actual=array_map(static fn(array $row): int=>(int)$row['id'],$affected);$expected=array_map('intval',$expected);sort($actual);sort($expected);
        if($actual!==$expected)throw new ApiException(409,'time_off_impact_changed','Appointments changed during review. Review the affected appointments again before saving.');
    }
    /** Serialize with withdrawal and booking changes, including direct service callers. */
    private function scheduleMutation(AuthContext $actor,callable $work): array
    {
        $this->scheduleRole($actor);$pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Schedule mutations require their own transaction.');
        $consentLock=new ConsentLock($pdo);$consentLock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=:clinic AND status='active' FOR UPDATE");$q->execute(['clinic'=>$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $q=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND clinic_id=? AND user_type='staff' AND status='active'");$q->execute([$actor->userId,$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Schedule access is required.');
            $result=$work();$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$consentLock->release();}
    }

    /** No preview cap or outer joins that discard appointments: one unresolvable/restricted row denies. */
    private function authorizeTimeOffAppointments(AuthContext $actor,int $practitioner,string $start,string $end): void
    {
        $params=['clinic'=>$actor->clinicId,'practitioner'=>$practitioner,'start'=>$start,'end'=>$end];
        $visible=ConsentAccess::condition($actor,$params,'a.client_id');
        $q=$this->database->connection()->prepare("SELECT 1 FROM appointments a
            WHERE a.clinic_id=:clinic AND a.practitioner_id=:practitioner
              AND a.status NOT IN('canceled_by_client','canceled_by_clinic') AND a.starts_at<:end AND a.ends_at>:start
              AND NOT EXISTS(SELECT 1 FROM users client
                JOIN services service ON service.id=a.service_id AND service.clinic_id=a.clinic_id
                JOIN locations location ON location.id=a.location_id AND location.clinic_id=a.clinic_id
                WHERE client.id=a.client_id AND client.clinic_id=a.clinic_id AND client.user_type='client'
                  AND NOT EXISTS(SELECT 1 FROM client_merge_records merged WHERE merged.duplicate_client_id=client.id)
                  $visible) LIMIT 1");
        $q->execute($params);
        if($q->fetchColumn())throw new ApiException(403,'time_off_access_restricted','This schedule change requires an authorized privacy review. No changes were saved.');
    }

    private function lockedTimeOff(AuthContext $actor,int $id): array
    {
        $this->scheduleRecordAccess($actor,'time_off',$id,'schedule_exception_not_found');
        $q=$this->database->connection()->prepare('SELECT practitioner_id,starts_at,ends_at FROM time_off WHERE id=? FOR UPDATE');$q->execute([$id]);
        $row=$q->fetch();if(!$row)throw new ApiException(404,'schedule_exception_not_found','Schedule item not found.');return $row;
    }

    public function createTimeOff(AuthContext $actor,array $body,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$body,$cid):array{
            $this->required($body,['practitioner_id','location_id','starts_at','ends_at','reason_type']);$practitionerId=(int)$body['practitioner_id'];
            $this->scheduleAccess($actor,$practitionerId);$this->scheduleLocationAccess($actor,$practitionerId,(int)$body['location_id']);
            [$start,$end]=$this->utcInterval($body,(int)$body['location_id']);
            if(!in_array($body['reason_type'],['vacation','sick','personal','other'],true))throw new ApiException(422,'validation_error','Invalid reason type.');
            $this->authorizeTimeOffAppointments($actor,$practitionerId,$start,$end);
            $affected=$this->timeOffAppointments($actor->clinicId,$practitionerId,$start,$end);$this->assertTimeOffImpactMatches($body,$affected);
            $pdo=$this->database->connection();
            $s=$pdo->prepare('INSERT INTO time_off(practitioner_id,location_id,starts_at,ends_at,reason_type,notes,created_by) VALUES(:p,:l,:starts,:ends,:type,:notes,:actor)');$s->execute(['p'=>$practitionerId,'l'=>(int)$body['location_id'],'starts'=>$start,'ends'=>$end,'type'=>$body['reason_type'],'notes'=>$this->optional($body,'notes'),'actor'=>$actor->userId]);
            return $this->created($actor,$cid,'time_off',(int)$pdo->lastInsertId())+['affected_appointment_count'=>count($affected)];
        });
    }
    public function updateTimeOff(AuthContext $actor,int $id,array $body,string $cid): array
    {
        return $this->scheduleMutation($actor,function()use($actor,$id,$body,$cid):array{
            $old=$this->lockedTimeOff($actor,$id);
            $this->required($body,['practitioner_id','location_id','starts_at','ends_at','reason_type']);$practitionerId=(int)$body['practitioner_id'];
            $this->scheduleAccess($actor,$practitionerId);$this->scheduleLocationAccess($actor,$practitionerId,(int)$body['location_id']);
            [$start,$end]=$this->utcInterval($body,(int)$body['location_id']);
            if(!in_array($body['reason_type'],['vacation','sick','personal','other'],true))throw new ApiException(422,'validation_error','Invalid reason type.');
            $this->authorizeTimeOffAppointments($actor,(int)$old['practitioner_id'],$old['starts_at'],$old['ends_at']);
            $this->authorizeTimeOffAppointments($actor,$practitionerId,$start,$end);
            $affected=$this->timeOffAppointments($actor->clinicId,$practitionerId,$start,$end);$this->assertTimeOffImpactMatches($body,$affected);
            $s=$this->database->connection()->prepare('UPDATE time_off SET practitioner_id=:p,location_id=:l,starts_at=:starts,ends_at=:ends,reason_type=:type,notes=:notes WHERE id=:id');$s->execute(['p'=>$practitionerId,'l'=>(int)$body['location_id'],'starts'=>$start,'ends'=>$end,'type'=>$body['reason_type'],'notes'=>$this->optional($body,'notes'),'id'=>$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'time_off.update','time_off',$id);return ['id'=>$id,'affected_appointment_count'=>count($affected)];
        });
    }
    public function deleteScheduleException(AuthContext $actor,string $table,int $id,string $cid): array
    {
        if($table==='time_off')return $this->scheduleMutation($actor,function()use($actor,$id,$cid):array{
            $old=$this->lockedTimeOff($actor,$id);
            $this->authorizeTimeOffAppointments($actor,(int)$old['practitioner_id'],$old['starts_at'],$old['ends_at']);
            $this->database->connection()->prepare('DELETE FROM time_off WHERE id=?')->execute([$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'schedule_exception.delete','time_off',$id);return ['deleted'=>true];
        });
        if($table!=='availability_overrides')throw new ApiException(500,'invalid_table','Invalid schedule table.');
        return $this->scheduleMutation($actor,function()use($actor,$id,$cid):array{
            $old=$this->lockedScheduleChange($actor,'availability_overrides',$id);(new ScheduleChangeAccess($this->database))->assertOverride($actor,$old);
            $this->database->connection()->prepare('DELETE FROM availability_overrides WHERE id=?')->execute([$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'schedule_exception.delete','availability_overrides',$id);return ['deleted'=>true];
        });
    }

    private function admin(AuthContext $actor): void{if(!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Administrator access is required.');}
    private function superAdmin(AuthContext $actor): void{if(!$actor->hasAnyRole('super_admin'))throw new ApiException(403,'forbidden','Super administrator access is required.');}
    private function required(array $body,array $fields): void{foreach($fields as $field)if(!array_key_exists($field,$body)||$body[$field]===''||$body[$field]===null)throw new ApiException(422,'validation_error',"{$field} is required.",[$field=>'Required']);}
    private function optional(array $body,string $field): ?string{$value=trim((string)($body[$field]??''));return $value===''?null:$value;}
    private function clinic(int $clinicId): array{$statement=$this->database->connection()->prepare('SELECT name,legal_name,email,phone FROM clinics WHERE id=:clinic');$statement->execute(['clinic'=>$clinicId]);$clinic=$statement->fetch();if(!$clinic)throw new ApiException(404,'clinic_not_found','Clinic not found.');return $clinic;}
    private function ownedLocation(AuthContext $actor,int $locationId): void{$statement=$this->database->connection()->prepare('SELECT 1 FROM locations WHERE id=:id AND clinic_id=:clinic');$statement->execute(['id'=>$locationId,'clinic'=>$actor->clinicId]);if(!$statement->fetchColumn())throw new ApiException(404,'location_not_found','Location not found.');}
    private function ownedServiceCategory(AuthContext $actor,mixed $categoryId): ?int{if($categoryId===null||$categoryId==='')return null;if(filter_var($categoryId,FILTER_VALIDATE_INT)===false||(int)$categoryId<=0)throw new ApiException(422,'validation_error','Choose a valid service category.',['category_id'=>'Invalid category']);$statement=$this->database->connection()->prepare('SELECT 1 FROM service_categories WHERE id=:id AND clinic_id=:clinic');$statement->execute(['id'=>(int)$categoryId,'clinic'=>$actor->clinicId]);if(!$statement->fetchColumn())throw new ApiException(422,'validation_error','Choose a valid service category.',['category_id'=>'Category not found']);return (int)$categoryId;}
    private function validateLocation(array $body): void
    {
        foreach(['name'=>120,'timezone'=>64,'address_line1'=>190,'address_line2'=>190,'city'=>100,'province'=>100,'postal_code'=>20,'phone'=>40] as $field=>$limit){
            $value=$body[$field]??'';
            if(!is_string($value)||mb_strlen(trim($value))>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Check the location fields.',[$field=>"Maximum $limit characters; text only"]);
        }
        if(trim($body['name'])==='')throw new ApiException(422,'validation_error','name is required.',['name'=>'Required']);
        if(!in_array($body['timezone'],timezone_identifiers_list(),true))throw new ApiException(422,'validation_error','Enter a valid timezone.',['timezone'=>'Invalid timezone']);
        if(array_key_exists('is_bookable',$body)&&!is_bool($body['is_bookable']))throw new ApiException(422,'validation_error','Check the location fields.',['is_bookable'=>'Boolean required']);
    }
    private function validateRoom(array $body): void
    {
        $location=$body['location_id']??null;
        if(is_bool($location)||filter_var($location,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new ApiException(422,'validation_error','Select a valid location.',['location_id'=>'Required']);
        foreach(['name'=>100,'room_type'=>80] as $field=>$limit){$value=$body[$field]??'';if(!is_string($value)||mb_strlen(trim($value))>$limit||preg_match('/[\x00-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Enter valid room details.',[$field=>"Maximum $limit characters; text only"]);}
        if(trim($body['name'])==='')throw new ApiException(422,'validation_error','Enter a valid room name.',['name'=>'Required; maximum 100 characters']);
        $turnover=$body['turnover_minutes']??0;
        if(!is_int($turnover)||$turnover<0||$turnover>240||$turnover%5!==0)throw new ApiException(422,'validation_error','Turnover time must be a 5-minute increment between 0 and 240.',['turnover_minutes'=>'Invalid turnover time']);
        $notes=$body['equipment_notes']??'';
        if(!is_string($notes)||strlen($notes)>65535||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$notes))throw new ApiException(422,'validation_error','Enter valid room details.',['equipment_notes'=>'Text only; maximum 65535 bytes']);
        if(array_key_exists('is_bookable',$body)&&!is_bool($body['is_bookable']))throw new ApiException(422,'validation_error','Enter valid room details.',['is_bookable'=>'Boolean required']);
    }
    public static function durationOptions(array $body): array
    {
        $raw=$body['duration_options']??null;
        if($raw===null&&is_array($body['durations']??null))$raw=array_map(static fn($minutes)=>['minutes'=>$minutes,'price_cents'=>$body['price_cents']??null],$body['durations']);
        if(!is_array($raw)||$raw===[])throw new ApiException(422,'validation_error','Add at least one duration and price.');
        $options=[];
        foreach($raw as $option){
            if(!is_array($option)||filter_var($option['minutes']??null,FILTER_VALIDATE_INT)===false||filter_var($option['price_cents']??null,FILTER_VALIDATE_INT)===false)throw new ApiException(422,'validation_error','Each duration needs a valid time and price.');
            $minutes=(int)$option['minutes'];$price=(int)$option['price_cents'];
            if($minutes<=0||$minutes>480||$minutes%15!==0)throw new ApiException(422,'validation_error','Durations must be 15-minute increments up to 480 minutes.');
            if($price<0||$price>10000000)throw new ApiException(422,'validation_error','Each duration needs a valid price.');
            if(isset($options[$minutes]))throw new ApiException(422,'validation_error','Each duration can be listed only once.');
            $options[$minutes]=['minutes'=>$minutes,'price_cents'=>$price];
        }
        ksort($options);return array_values($options);
    }
    private function servicePublishing(array $body): array
    {
        $slug=strtolower(trim((string)($body['slug']??'')));
        if($slug===''){$slug=preg_replace('/[^a-z0-9]+/','-',strtolower(trim((string)($body['name']??''))))??'';$slug=trim($slug,'-');}
        if($slug===''||strlen($slug)>120||preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/',$slug)!==1)throw new ApiException(422,'validation_error','Use lowercase letters, numbers, and hyphens for the public URL.',['slug'=>'Invalid public URL']);
        $displayOrder=$body['display_order']??100;
        if(filter_var($displayOrder,FILTER_VALIDATE_INT)===false||(int)$displayOrder<0||(int)$displayOrder>65535)throw new ApiException(422,'validation_error','Display order must be between 0 and 65535.',['display_order'=>'Invalid display order']);
        foreach(['name_fr'=>150,'public_summary'=>500,'public_summary_fr'=>500] as $field=>$limit)if(strlen(trim((string)($body[$field]??'')))>$limit)throw new ApiException(422,'validation_error',"{$field} is too long.",[$field=>"Maximum {$limit} characters"]);
        return ['slug'=>$slug,'name_fr'=>$this->optional($body,'name_fr'),'public_summary'=>$this->optional($body,'public_summary'),'public_summary_fr'=>$this->optional($body,'public_summary_fr'),'description_fr'=>$this->optional($body,'description_fr'),'preparation_instructions_fr'=>$this->optional($body,'preparation_instructions_fr'),'published'=>(bool)($body['published']??false)?1:0,'display_order'=>(int)$displayOrder];
    }
    private function validateService(array $body,array $options): void{$this->required($body,['name']);$name=trim((string)$body['name']);if($name===''||strlen($name)>150)throw new ApiException(422,'validation_error','Enter a valid service name.');if($options===[])throw new ApiException(422,'validation_error','Add at least one duration and price.');foreach(['buffer_before_minutes','buffer_after_minutes'] as $f)if((int)($body[$f]??0)<0||(int)($body[$f]??0)%15!==0)throw new ApiException(422,'validation_error','Buffers must be non-negative 15-minute increments.');$window=(int)($body['cancellation_window_minutes']??1440);$type=(string)($body['cancellation_fee_type']??'none');$value=(int)($body['cancellation_fee_value']??0);if($window<0||$window>525600)throw new ApiException(422,'validation_error','Cancellation window must be between 0 and 525600 minutes.');if(!in_array($type,['none','fixed','percentage'],true))throw new ApiException(422,'validation_error','Choose a valid cancellation fee type.');if($value<0||($type==='percentage'&&$value>10000)||($type==='fixed'&&$value>10000000)||($type==='none'&&$value!==0))throw new ApiException(422,'validation_error','Enter a valid cancellation fee value.');}
    private function scheduleRole(AuthContext $actor): void{if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','practitioner'))throw new ApiException(403,'forbidden','Schedule access is required.');}
    private function scheduleAccess(AuthContext $actor,int $practitionerId): void{$this->scheduleRole($actor);$sql='SELECT 1 FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=:p AND u.clinic_id=:clinic';$params=['p'=>$practitionerId,'clinic'=>$actor->clinicId];if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin')){$sql.=" AND p.user_id=:actor AND p.active=1 AND p.booking_mode='practitioner_managed'";$params['actor']=$actor->userId;}$s=$this->database->connection()->prepare($sql);$s->execute($params);if(!$s->fetchColumn())throw new ApiException(403,'forbidden','You cannot manage that practitioner schedule.');}
    private function scheduleLocationAccess(AuthContext $actor,int $practitionerId,int $locationId): void{$this->ownedLocation($actor,$locationId);if(!$actor->hasAnyRole('practitioner')||$actor->hasAnyRole('super_admin','clinic_admin'))return;$s=$this->database->connection()->prepare('SELECT 1 FROM practitioner_locations pl JOIN practitioners p ON p.id=pl.practitioner_id WHERE pl.practitioner_id=:practitioner AND pl.location_id=:location AND pl.active=1 AND p.user_id=:actor');$s->execute(['practitioner'=>$practitionerId,'location'=>$locationId,'actor'=>$actor->userId]);if(!$s->fetchColumn())throw new ApiException(403,'forbidden','You cannot manage availability at that location.');}
    private function scheduleRecordAccess(AuthContext $actor,string $table,int $id,string $code): void{if(!in_array($table,['availability_rules','availability_overrides','time_off'],true))throw new ApiException(500,'invalid_table','Invalid schedule table.');$this->scheduleRole($actor);$sql="SELECT 1 FROM {$table} x JOIN practitioners p ON p.id=x.practitioner_id JOIN users u ON u.id=p.user_id WHERE x.id=:id AND u.clinic_id=:clinic";$params=['id'=>$id,'clinic'=>$actor->clinicId];if($actor->hasAnyRole('practitioner')&&!$actor->hasAnyRole('super_admin','clinic_admin')){$sql.=" AND p.user_id=:actor AND p.active=1 AND p.booking_mode='practitioner_managed'";$params['actor']=$actor->userId;}$s=$this->database->connection()->prepare($sql);$s->execute($params);if(!$s->fetchColumn())throw new ApiException(404,$code,'Schedule item not found.');}
    private function validateAvailabilityRule(AuthContext $actor,array $body): void{$this->required($body,['practitioner_id','location_id','weekday','start_time','end_time','valid_from']);$this->scheduleAccess($actor,(int)$body['practitioner_id']);$this->scheduleLocationAccess($actor,(int)$body['practitioner_id'],(int)$body['location_id']);$weekday=(int)$body['weekday'];$interval=(int)($body['recurrence_interval_weeks']??1);foreach(['valid_from','valid_until'] as $dateField){$value=$body[$dateField]??null;if($dateField==='valid_until'&&$value===null)continue;$parsed=is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;if(!$parsed||$parsed->format('Y-m-d')!==$value||(int)$parsed->format('Y')<1000)throw new ApiException(422,'validation_error','Enter valid effective dates.');}$time='/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/';$date='/^\\d{4}-\\d{2}-\\d{2}$/';if($weekday<1||$weekday>7)throw new ApiException(422,'validation_error','Choose a valid weekday.');if(!preg_match($time,(string)$body['start_time'])||!preg_match($time,(string)$body['end_time'])||(string)$body['start_time']>=(string)$body['end_time'])throw new ApiException(422,'validation_error','Start time must be before end time.');if(!preg_match($date,(string)$body['valid_from'])||(($body['valid_until']??null)!==null&&!preg_match($date,(string)$body['valid_until'])))throw new ApiException(422,'validation_error','Enter valid effective dates.');if(($body['valid_until']??null)!==null&&(string)$body['valid_until']<(string)$body['valid_from'])throw new ApiException(422,'validation_error','Valid until must be on or after valid from.');if($interval<1||$interval>52)throw new ApiException(422,'validation_error','Recurrence interval must be between 1 and 52 weeks.');}
    private function utcInterval(array $body,int $locationId): array{$s=$this->database->connection()->prepare('SELECT timezone FROM locations WHERE id=:id');$s->execute(['id'=>$locationId]);$timezone=$s->fetchColumn();try{$zone=new \DateTimeZone((string)$timezone);$start=new \DateTimeImmutable((string)$body['starts_at'],$zone);$end=new \DateTimeImmutable((string)$body['ends_at'],$zone);}catch(\Throwable){throw new ApiException(422,'validation_error','Enter valid local start and end times.');}if($start>=$end)throw new ApiException(422,'validation_error','Start must be before end.');$utc=new \DateTimeZone('UTC');return [$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s')];}
    private function created(AuthContext $actor,string $correlationId,string $type,int $id): array{$this->audit->write($actor->clinicId,$actor,$correlationId,$type.'.create',$type,$id);return ['id'=>$id];}
}
