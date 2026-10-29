<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Application approval, account activation, publication and clinical access are separate gates. */
final class PractitionerApplicationService
{
    private const PROFILE=['given_name'=>70,'family_name'=>70,'email'=>190,'phone'=>40,'discipline'=>190,'credentials'=>2000,'service_area'=>190,'services'=>3000,'enquiry_reference'=>36];
    public function __construct(private readonly Database $database,private readonly Config $config) {}
    private function enabled(): void
    {
        if(!$this->config->practitionerApplicationsEnabled || !$this->config->clinicManagementEnabled)throw new ApiException(503,'applications_unavailable','Practitioner applications are not available.');
    }
    private function q(string $sql,array $values=[]): \PDOStatement
    { $q=$this->database->connection()->prepare($sql);$q->execute($values);return $q; }
    private function settings(int $clinic): array
    {
        return $this->q('SELECT s.*,t.version terms_version,t.content terms_text,t.content_hash,p.display_name plan_name,p.base_monthly_cents,p.scope plan_scope,p.currency FROM practitioner_application_settings s LEFT JOIN practitioner_application_terms t ON t.id=s.terms_id AND t.clinic_id=s.clinic_id LEFT JOIN subscription_plan_versions p ON p.id=s.plan_version_id WHERE s.clinic_id=?',[$clinic])->fetch()
            ?:['clinic_id'=>$clinic,'enabled'=>false,'activation_enabled'=>false,'terms_id'=>null,'plan_version_id'=>null,'timezone'=>'America/Toronto','revision'=>0,'terms_text'=>'','terms_version'=>null,'content_hash'=>null,'plan_name'=>null,'base_monthly_cents'=>null];
    }
    private function offer(array $s,bool $private=false): array
    {
        $open=(bool)$s['enabled'] && $s['terms_id']!==null && $s['plan_version_id']!==null;
        return ['available'=>true,'enabled'=>$open,'activation_available'=>$this->config->practitionerApplicationActivationEnabled && $this->config->externalStaffAuthEnabled && (bool)$s['activation_enabled'],
            'terms'=>($open||$private)&&$s['terms_id']!==null?['id'=>(int)$s['terms_id'],'version'=>(int)$s['terms_version'],'content'=>$s['terms_text'],'content_hash'=>$s['content_hash']]:null,
            'plan'=>($open||$private)&&$s['plan_version_id']!==null?['id'=>(int)$s['plan_version_id'],'name'=>$s['plan_name'],'base_monthly_cents'=>$s['base_monthly_cents'],'currency'=>'CAD']:null];
    }
    public function publicInfo(int $clinic): array
    {
        if(!$this->config->practitionerApplicationsEnabled || !$this->config->clinicManagementEnabled)return ['available'=>false,'enabled'=>false,'activation_available'=>false,'terms'=>null,'plan'=>null];
        return $this->read($clinic,null,fn()=>$this->offer($this->settings($clinic)));
    }
    private function authorize(ApplicationAdminContext $actor): void
    {
        if(!$this->config->applicationAdminEnabled)throw new ApiException(503,'application_admin_unavailable','Application administration is not enabled.');
        if(!$this->q("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE",[$actor->id,$actor->identity->issuer,$actor->identity->subject])->fetchColumn())throw new ApiException(403,'application_admin_required','Application administrator access is required.');
    }
    private function identity(VerifiedIdentity $identity,bool $create=false): ?int
    {
        if($identity->adapter!=='entra-external-staff')throw new ApiException(403,'staff_identity_required','Use the dedicated practitioner sign-in.');
        $row=$this->q('SELECT id,status FROM product_identities WHERE adapter=? AND issuer=? AND subject=? FOR UPDATE',[$identity->adapter,$identity->issuer,$identity->subject])->fetch();
        if($row && $row['status']!=='active')throw new ApiException(403,'identity_inactive','This identity is inactive.');
        if(!$row && $create){$this->q('INSERT INTO product_identities(adapter,issuer,subject) VALUES(?,?,?)',[$identity->adapter,$identity->issuer,$identity->subject]);return (int)$this->database->connection()->lastInsertId();}
        return $row?(int)$row['id']:null;
    }
    private function read(int $clinic,ApplicationAdminContext|VerifiedIdentity|null $actor,callable $work): array
    {
        $this->enabled();$pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Application service owns its transaction.');
        try {
            $pdo->beginTransaction();if($actor instanceof ApplicationAdminContext)$this->authorize($actor);
            if(!$this->q("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE",[$clinic])->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic not found.');
            if($actor instanceof VerifiedIdentity)$this->identity($actor);
            $result=$work();$pdo->commit();return $result;
        } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();if($e instanceof \PDOException && (int)($e->errorInfo[1]??0)===1062)self::conflict('application_conflict');throw $e;}
    }
    private function write(int $clinic,ApplicationAdminContext|VerifiedIdentity $actor,string $action,array $body,callable $work): array
    {
        $key=self::text($body,'request_key',36);if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$key))self::invalid('request_key');
        $principal=$actor instanceof ApplicationAdminContext?'admin:'.$actor->id:'identity:'.hash('sha256',json_encode([$actor->adapter,$actor->issuer,$actor->subject],JSON_THROW_ON_ERROR));
        $fingerprint=hash('sha256',$action.json_encode($body,JSON_THROW_ON_ERROR));
        return $this->read($clinic,$actor,function()use($clinic,$actor,$principal,$key,$fingerprint,$action,$work):array{
            $old=$this->q('SELECT fingerprint,response FROM practitioner_application_requests WHERE clinic_id=? AND principal=? AND reference=?',[$clinic,$principal,$key])->fetch();
            if($old){if(!hash_equals($old['fingerprint'],$fingerprint))self::conflict('application_request_changed');
                if($action==='activate' && $actor instanceof VerifiedIdentity){$app=$this->own($clinic,$actor);if(!$this->accountAvailable($app))throw new ApiException(403,'membership_required','Contact the clinic about your account access.');}
                return json_decode($old['response'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$work();$this->q('INSERT INTO practitioner_application_requests(clinic_id,principal,reference,fingerprint,response) VALUES(?,?,?,?,?)',[$clinic,$principal,$key,$fingerprint,json_encode($result,JSON_THROW_ON_ERROR)]);return $result;
        });
    }
    private function event(int $clinic,?int $app,ApplicationAdminContext|int $actor,string $action,string $reason,string $cid,array $details=[]): void
    {
        $this->q('INSERT INTO practitioner_application_events(clinic_id,application_id,application_administrator_id,applicant_identity_id,action,reason,details,correlation_id) VALUES(?,?,?,?,?,?,?,?)',
            [$clinic,$app,$actor instanceof ApplicationAdminContext?$actor->id:null,is_int($actor)?$actor:null,$action,$reason,json_encode($details,JSON_THROW_ON_ERROR),substr($cid,0,128)]);
        if($app!==null && $this->config->automatedCommunicationsEnabled)(new CommunicationNotices($this->database->connection()))->application($clinic,$app,(int)$this->database->connection()->lastInsertId());
    }
    public function admin(ApplicationAdminContext $actor,int $clinic,string $method,string $tail,array $body,string $cid): array
    {
        if($method==='GET' && $tail==='')return $this->read($clinic,$actor,function()use($clinic):array{
            $s=$this->settings($clinic);$s['enabled']=(bool)$s['enabled'];$s['activation_enabled']=(bool)$s['activation_enabled'];
            $items=$this->q('SELECT a.*,m.id existing_membership_id,m.status existing_membership_status FROM practitioner_applications a LEFT JOIN staff_memberships m ON m.identity_id=a.identity_id AND m.clinic_id=a.clinic_id WHERE a.clinic_id=? ORDER BY a.id DESC LIMIT 100',[$clinic])->fetchAll();
            foreach($items as &$item)unset($item['identity_id']);
            return ['settings'=>$s,'activation_gate'=>$this->config->practitionerApplicationActivationEnabled && $this->config->externalStaffAuthEnabled,'items'=>$items,
                'plans'=>$this->q("SELECT id,display_name,code,version,base_monthly_cents FROM subscription_plan_versions WHERE scope='practitioner' AND currency='CAD' ORDER BY id DESC LIMIT 200")->fetchAll(),
                'enquiries'=>$this->q('SELECT id,reference,full_name FROM practitioner_enquiries WHERE clinic_id=? ORDER BY id DESC LIMIT 100',[$clinic])->fetchAll(),
                'locations'=>$this->q('SELECT id,name FROM locations WHERE clinic_id=? AND is_bookable=1 ORDER BY name',[$clinic])->fetchAll()];
        });
        if($method==='GET' && preg_match('#^/([1-9][0-9]*)$#D',$tail,$m))return $this->read($clinic,$actor,function()use($clinic,$m):array{
            $app=$this->q('SELECT * FROM practitioner_applications WHERE id=? AND clinic_id=?',[(int)$m[1],$clinic])->fetch();if(!$app)throw new ApiException(404,'application_not_found','Application not found.');unset($app['identity_id']);
            $submissions=$this->q('SELECT version,terms_id,details,submitted_at FROM practitioner_application_submissions WHERE application_id=? ORDER BY version DESC',[$app['id']])->fetchAll();
            foreach($submissions as &$submission)$submission['details']=json_decode($submission['details'],true,512,JSON_THROW_ON_ERROR);
            return ['application'=>$app,'submissions'=>$submissions,'events'=>$this->q('SELECT action,reason,created_at FROM practitioner_application_events WHERE application_id=? AND clinic_id=? ORDER BY id DESC LIMIT 50',[$app['id'],$clinic])->fetchAll()];
        });
        if($method==='POST' && $tail==='/settings')return $this->configure($actor,$clinic,$body,$cid);
        if($method==='POST' && preg_match('#^/([1-9][0-9]*)/review$#D',$tail,$m))return $this->review($actor,$clinic,(int)$m[1],$body,$cid);
        throw new ApiException(404,'not_found','Route not found.');
    }
    private function configure(ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        self::fields($body,['revision','enabled','activation_enabled','readiness_acknowledged','terms_text','plan_version_id','timezone','reason']);
        return $this->write($clinic,$actor,'settings',$body,function()use($actor,$clinic,$body,$cid):array{
            $s=$this->settings($clinic);if(self::integer($body,'revision',0)!==(int)$s['revision'])self::conflict('application_version_changed');
            foreach(['enabled','activation_enabled'] as $key)if(!is_bool($body[$key]??null))self::invalid($key);
            $terms=self::text($body,'terms_text',12000,false,true);$plan=$body['plan_version_id']??null;
            if($plan!==null){$plan=self::integer($body,'plan_version_id',1);if(!$this->q("SELECT id FROM subscription_plan_versions WHERE id=? AND scope='practitioner' AND currency='CAD'",[$plan])->fetchColumn())self::invalid('plan_version_id');}
            $timezone=self::text($body,'timezone',64);if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))self::invalid('timezone');
            if(($body['enabled'] || $body['activation_enabled']) && ($plan===null || mb_strlen($terms)<80))self::invalid('terms_text');
            if($body['activation_enabled'] && (!$this->config->practitionerApplicationActivationEnabled || !$this->config->externalStaffAuthEnabled || ($body['readiness_acknowledged']??false)!==true))throw new ApiException(409,'application_activation_not_ready','Account activation has not been cleared for this clinic.');
            $reason=self::reason($body);$termsId=$s['terms_id'];
            if($terms!=='' && $terms!==$s['terms_text']){
                $version=(int)$this->q('SELECT COALESCE(MAX(version),0)+1 FROM practitioner_application_terms WHERE clinic_id=?',[$clinic])->fetchColumn();
                $this->q('INSERT INTO practitioner_application_terms(clinic_id,version,content,content_hash,published_by) VALUES(?,?,?,?,?)',[$clinic,$version,$terms,hash('sha256',$terms),$actor->id]);$termsId=(int)$this->database->connection()->lastInsertId();
            } elseif($terms==='')$termsId=null;
            $this->q('INSERT INTO practitioner_application_settings(clinic_id,enabled,activation_enabled,terms_id,plan_version_id,timezone,revision) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),activation_enabled=VALUES(activation_enabled),terms_id=VALUES(terms_id),plan_version_id=VALUES(plan_version_id),timezone=VALUES(timezone),revision=VALUES(revision)',[$clinic,(int)$body['enabled'],(int)$body['activation_enabled'],$termsId,$plan,$timezone,(int)$s['revision']+1]);
            $this->event($clinic,null,$actor,'settings.configure',$reason,$cid,['terms_id'=>$termsId,'plan_version_id'=>$plan,'enabled'=>$body['enabled'],'activation_enabled'=>$body['activation_enabled']]);
            return ['revision'=>(int)$s['revision']+1];
        });
    }
    private function own(int $clinic,VerifiedIdentity $identity): array
    {
        $id=$this->identity($identity);$app=$id?$this->q('SELECT * FROM practitioner_applications WHERE clinic_id=? AND identity_id=? FOR UPDATE',[$clinic,$id])->fetch():false;
        if(!$app)throw new ApiException(404,'application_not_found','Application not found.');return $app;
    }
    public function me(int $clinic,VerifiedIdentity $identity): array
    {
        return $this->read($clinic,$identity,function()use($clinic,$identity):array{
            $id=$this->identity($identity);$app=$id?$this->q('SELECT * FROM practitioner_applications WHERE clinic_id=? AND identity_id=?',[$clinic,$id])->fetch():false;
            $s=$this->settings($clinic);$result=['offer'=>$this->offer($s,$app!==false),'application'=>null];
            if($app){$available=$this->accountAvailable($app);unset($app['identity_id'],$app['allow_existing_membership'],$app['source_enquiry_id']);$app['account_available']=$available;
                $app['subscription']=$app['subscription_id']?$this->q('SELECT status,starts_at,trial_ends_at FROM billing_subscriptions WHERE id=?',[$app['subscription_id']])->fetch():null;
                $app['checklist']=$available?$this->checklist((int)$app['membership_id'],$clinic):[];$result['application']=$app;}
            return $result;
        });
    }
    public function submit(int $clinic,VerifiedIdentity $identity,array $body,string $cid): array
    {
        self::fields($body,array_merge(array_keys(self::PROFILE),['version','terms_id','accept_terms','information_confirmed']));
        $profile=[];foreach(self::PROFILE as $key=>$max)$profile[$key]=self::text($body,$key,$max,!in_array($key,['phone','enquiry_reference'],true),in_array($key,['credentials','services'],true));
        $profile['email']=strtolower($profile['email']);if(!filter_var($profile['email'],FILTER_VALIDATE_EMAIL))self::invalid('email');
        if(($body['accept_terms']??false)!==true || ($body['information_confirmed']??false)!==true)self::invalid('accept_terms');
        if($profile['enquiry_reference']!==''&&!preg_match('/^[a-f0-9-]{36}$/D',$profile['enquiry_reference']))self::invalid('enquiry_reference');
        return $this->write($clinic,$identity,'submit',$body,function()use($clinic,$identity,$body,$profile,$cid):array{
            $s=$this->settings($clinic);if(!(bool)$s['enabled'] || !$s['terms_id'] || !$s['plan_version_id'])throw new ApiException(409,'applications_closed','Practitioner applications are currently closed.');
            if(self::integer($body,'terms_id',1)!==(int)$s['terms_id'])self::conflict('application_terms_changed');
            $id=$this->identity($identity,true);$app=$this->q('SELECT * FROM practitioner_applications WHERE clinic_id=? AND identity_id=? FOR UPDATE',[$clinic,$id])->fetch();
            $version=self::integer($body,'version',0);if(($app?(int)$app['version']:0)!==$version)self::conflict('application_version_changed');
            if($app && $app['status']!=='changes_requested')self::conflict('application_state_changed');
            $bucket=gmdate('YmdH');foreach(['clinic'=>100,'identity:'.$id=>5] as $key=>$max){$hash=hash('sha256',$key);$this->q('INSERT IGNORE INTO practitioner_application_limits(clinic_id,bucket,key_hash) VALUES(?,?,?)',[$clinic,$bucket,$hash]);
                $attempts=(int)$this->q('SELECT attempts FROM practitioner_application_limits WHERE clinic_id=? AND bucket=? AND key_hash=?',[$clinic,$bucket,$hash])->fetchColumn();if($attempts>=$max)throw new ApiException(429,'rate_limited','Too many applications. Please try again later.');
                $this->q('UPDATE practitioner_application_limits SET attempts=attempts+1 WHERE clinic_id=? AND bucket=? AND key_hash=?',[$clinic,$bucket,$hash]);}
            $fields=array_keys(self::PROFILE);$values=array_values($profile);
            if($app){$assign=implode(',',array_map(fn($key)=>$key.'=?',$fields));$this->q("UPDATE practitioner_applications SET $assign,status='submitted',version=version+1,submission_version=submission_version+1,accepted_terms_id=?,review_message='' WHERE id=?",array_merge($values,[$s['terms_id'],$app['id']]));$appId=(int)$app['id'];$submission=(int)$app['submission_version']+1;}
            else {$this->q('INSERT INTO practitioner_applications(clinic_id,identity_id,'.implode(',',$fields).',verification_code,accepted_terms_id) VALUES('.implode(',',array_fill(0,count($fields)+4,'?')).')',array_merge([$clinic,$id],$values,[strtoupper(bin2hex(random_bytes(6))),$s['terms_id']]));$appId=(int)$this->database->connection()->lastInsertId();$submission=1;}
            $this->q('INSERT INTO practitioner_application_submissions(application_id,version,terms_id,details) VALUES(?,?,?,?)',[$appId,$submission,$s['terms_id'],json_encode($profile,JSON_THROW_ON_ERROR)]);
            $this->event($clinic,$appId,$id,'application.submit','Applicant confirmed information and joining terms.',$cid,['submission_version'=>$submission,'terms_id'=>(int)$s['terms_id']]);
            return ['id'=>$appId,'status'=>'submitted','version'=>$version+1];
        });
    }
    public function withdraw(int $clinic,VerifiedIdentity $identity,array $body,string $cid): array
    {
        self::fields($body,['version']);return $this->write($clinic,$identity,'withdraw',$body,function()use($clinic,$identity,$body,$cid):array{
            $app=$this->own($clinic,$identity);if(self::integer($body,'version',1)!==(int)$app['version'])self::conflict('application_version_changed');
            if(!in_array($app['status'],['submitted','changes_requested','approved'],true))self::conflict('application_state_changed');
            $this->q("UPDATE practitioner_applications SET status='withdrawn',version=version+1 WHERE id=?",[$app['id']]);$this->event($clinic,(int)$app['id'],(int)$app['identity_id'],'application.withdraw','Applicant withdrew the application.',$cid);
            return ['id'=>(int)$app['id'],'status'=>'withdrawn','version'=>(int)$app['version']+1];
        });
    }
    private function review(ApplicationAdminContext $actor,int $clinic,int $id,array $body,string $cid): array
    {
        self::fields($body,['version','decision','review_message','reason','verification_code','applicant_verified','credentials_reviewed','location_id','allow_existing_membership','source_enquiry_id']);
        return $this->write($clinic,$actor,'review:'.$id,$body,function()use($actor,$clinic,$id,$body,$cid):array{
            $app=$this->q('SELECT * FROM practitioner_applications WHERE id=? AND clinic_id=? FOR UPDATE',[$id,$clinic])->fetch();if(!$app)throw new ApiException(404,'application_not_found','Application not found.');
            if(self::integer($body,'version',1)!==(int)$app['version'])self::conflict('application_version_changed');
            if(in_array($app['status'],['withdrawn','activated'],true))self::conflict('application_state_changed');
            $decision=$body['decision']??'';if(!in_array($decision,['approved','changes_requested','rejected'],true))self::invalid('decision');
            $reason=self::reason($body);$message=self::text($body,'review_message',2000,true,true);$s=$this->settings($clinic);$location=null;$plan=null;$timezone=null;$existing=false;$source=$app['source_enquiry_id'];
            if($decision==='approved'){
                if(($body['applicant_verified']??false)!==true || ($body['credentials_reviewed']??false)!==true)self::invalid('applicant_verified');
                $code=self::text($body,'verification_code',12);if(!hash_equals($app['verification_code'],strtoupper($code)))self::invalid('verification_code');
                $location=self::integer($body,'location_id',1);if(!$this->q('SELECT id FROM locations WHERE id=? AND clinic_id=? AND is_bookable=1 FOR UPDATE',[$location,$clinic])->fetchColumn())self::invalid('location_id');
                $plan=$s['plan_version_id'];if(!$plan || !$this->q("SELECT id FROM subscription_plan_versions WHERE id=? AND scope='practitioner' AND currency='CAD'",[$plan])->fetchColumn())self::invalid('plan_version_id');
                if(!is_bool($body['allow_existing_membership']??null))self::invalid('allow_existing_membership');$existing=$body['allow_existing_membership'];$timezone=$s['timezone'];
                if(array_key_exists('source_enquiry_id',$body)){$source=null;if($body['source_enquiry_id']!==null){$source=self::integer($body,'source_enquiry_id',1);if(!$this->q('SELECT id FROM practitioner_enquiries WHERE id=? AND clinic_id=?',[$source,$clinic])->fetchColumn())self::invalid('source_enquiry_id');}}
            }
            $this->q('UPDATE practitioner_applications SET status=?,review_message=?,approved_location_id=?,approved_plan_version_id=?,approved_timezone=?,allow_existing_membership=?,source_enquiry_id=?,version=version+1 WHERE id=?',[$decision,$message,$location,$plan,$timezone,(int)$existing,$source,$id]);
            $this->event($clinic,$id,$actor,'application.'.$decision,$reason,$cid,['submission_version'=>(int)$app['submission_version'],'location_id'=>$location,'plan_version_id'=>$plan,'existing_membership_approved'=>$existing,'identity_and_credentials_reviewed'=>$decision==='approved','source_enquiry_id'=>$source]);
            return ['id'=>$id,'status'=>$decision,'version'=>(int)$app['version']+1];
        });
    }
    public function activate(int $clinic,VerifiedIdentity $identity,array $body,string $cid): array
    {
        self::fields($body,['version','terms_id','accept_terms']);
        if(!$this->config->practitionerApplicationActivationEnabled || !$this->config->externalStaffAuthEnabled)throw new ApiException(503,'application_activation_not_ready','Account activation has not been cleared for this clinic.');
        return $this->write($clinic,$identity,'activate',$body,function()use($clinic,$identity,$body,$cid):array{
            $app=$this->own($clinic,$identity);$s=$this->settings($clinic);
            if(!(bool)$s['activation_enabled'])throw new ApiException(409,'application_activation_not_ready','Account activation has not been cleared for this clinic.');
            if(self::integer($body,'version',1)!==(int)$app['version'] || $app['status']!=='approved')self::conflict('application_state_changed');
            if(($body['accept_terms']??false)!==true || self::integer($body,'terms_id',1)!==(int)$s['terms_id'])self::conflict('application_terms_changed');
            if((int)$s['plan_version_id']!==(int)$app['approved_plan_version_id'])self::conflict('application_offer_changed');
            if(!$this->q('SELECT id FROM locations WHERE id=? AND clinic_id=? AND is_bookable=1 FOR UPDATE',[$app['approved_location_id'],$clinic])->fetchColumn())self::conflict('application_location_unavailable');
            $member=$this->q('SELECT m.*,u.status user_status,u.user_type,u.clinic_id user_clinic,p.id practitioner_id,p.active FROM staff_memberships m JOIN users u ON u.id=m.local_user_id LEFT JOIN practitioners p ON p.user_id=u.id WHERE m.identity_id=? AND m.clinic_id=? FOR UPDATE',[$app['identity_id'],$clinic])->fetch();
            if($member){
                if(!$app['allow_existing_membership'] || $member['status']!=='active' || $member['user_status']!=='active' || $member['user_type']!=='staff' || (int)$member['user_clinic']!==$clinic || !(bool)$member['active'])self::conflict('application_account_review');
                if(!$this->q("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code='practitioner'",[$member['local_user_id']])->fetchColumn())self::conflict('application_account_review');
                $user=(int)$member['local_user_id'];$practitioner=(int)$member['practitioner_id'];$membership=(int)$member['id'];
            } else {
                if($this->q('SELECT id FROM users WHERE clinic_id=? AND email=?',[$clinic,$app['email']])->fetchColumn())self::conflict('application_email_review');
                $name=$app['given_name'].' '.$app['family_name'];
                $this->q("INSERT INTO users(clinic_id,email,given_name,family_name,display_name,user_type,status) VALUES(?,?,?,?,?,'staff','active')",[$clinic,$app['email'],$app['given_name'],$app['family_name'],$name]);$user=(int)$this->database->connection()->lastInsertId();
                $this->q('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(?,1)',[$user]);
                $this->q("INSERT INTO practitioners(user_id,discipline,booking_mode) VALUES(?,'Practitioner','practitioner_managed')",[$user]);$practitioner=(int)$this->database->connection()->lastInsertId();
                $this->q('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(?,?,1)',[$practitioner,$app['approved_location_id']]);
                $q=$this->q("INSERT INTO user_roles(user_id,role_id,location_id) SELECT ?,id,? FROM roles WHERE code='practitioner'",[$user,$app['approved_location_id']]);if($q->rowCount()!==1)self::conflict('application_role_unavailable');
                $this->q("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')",[$app['identity_id'],$clinic,$user]);$membership=(int)$this->database->connection()->lastInsertId();
            }
            $this->q('INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$app['identity_id'],$app['given_name'].' '.$app['family_name'],$app['email']]);$global=(int)$this->database->connection()->lastInsertId();
            $assignment=$this->q('SELECT practitioner_id,status FROM global_practitioner_clinics WHERE global_practitioner_id=? AND clinic_id=? FOR UPDATE',[$global,$clinic])->fetch();
            if($assignment && ((int)$assignment['practitioner_id']!==$practitioner || $assignment['status']!=='active'))self::conflict('application_account_review');
            if(!$assignment)$this->q('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,?,?)',[$global,$clinic,$practitioner]);
            $this->q('INSERT INTO billing_accounts(clinic_id,membership_id) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$clinic,$membership]);$account=(int)$this->database->connection()->lastInsertId();
            if($this->q('SELECT currency FROM billing_accounts WHERE id=?',[$account])->fetchColumn()!=='CAD')self::conflict('application_billing_review');
            $prior=$this->q('SELECT * FROM billing_subscriptions WHERE billing_account_id=? ORDER BY id DESC FOR UPDATE',[$account])->fetchAll();$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            if($prior){$live=array_values(array_filter($prior,fn($sub)=>in_array($sub['status'],['trialing','active'],true)&&SubscriptionPolicy::allowsNewActivity($sub,$now)));if(count($live)!==1)self::conflict('application_billing_review');$subscription=(int)$live[0]['id'];}
            else {$end=SubscriptionPolicy::trialEndsAt($now,$app['approved_timezone'])->format('Y-m-d H:i:s');$this->q("INSERT INTO billing_subscriptions(billing_account_id,plan_version_id,status,starts_at,trial_ends_at,trial_timezone) VALUES(?,?,'trialing',?,?,?)",[$account,$app['approved_plan_version_id'],$now->format('Y-m-d H:i:s'),$end,$app['approved_timezone']]);$subscription=(int)$this->database->connection()->lastInsertId();}
            $this->q("UPDATE practitioner_applications SET status='activated',membership_id=?,subscription_id=?,accepted_terms_id=?,version=version+1 WHERE id=?",[$membership,$subscription,$s['terms_id'],$app['id']]);
            $this->event($clinic,(int)$app['id'],(int)$app['identity_id'],'application.activate','Applicant accepted the current joining terms and activated the approved account.',$cid,['membership_id'=>$membership,'subscription_id'=>$subscription,'terms_id'=>(int)$s['terms_id'],'subscription_reused'=>$prior!==[]]);
            return ['id'=>(int)$app['id'],'status'=>'activated','version'=>(int)$app['version']+1];
        });
    }
    private function accountAvailable(array $app): bool
    {
        if($app['status']!=='activated' || !$app['membership_id'])return false;
        return (bool)$this->q("SELECT 1 FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id JOIN practitioners p ON p.user_id=u.id JOIN product_identities i ON i.id=m.identity_id WHERE m.id=? AND m.clinic_id=? AND m.identity_id=? AND m.status='active' AND u.status='active' AND u.user_type='staff' AND p.active=1 AND i.status='active' AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code='practitioner')",[$app['membership_id'],$app['clinic_id'],$app['identity_id']])->fetchColumn();
    }
    private function checklist(int $member,int $clinic): array
    {
        $p=$this->q('SELECT p.id FROM practitioners p JOIN staff_memberships m ON m.local_user_id=p.user_id WHERE m.id=? AND m.clinic_id=?',[$member,$clinic])->fetchColumn();
        return ['services'=>(bool)$this->q('SELECT 1 FROM practitioner_services WHERE practitioner_id=? LIMIT 1',[$p])->fetchColumn(),
            'availability'=>(bool)$this->q('SELECT 1 FROM availability_rules WHERE practitioner_id=? AND active=1 LIMIT 1',[$p])->fetchColumn(),
            'credentials'=>(bool)$this->q("SELECT 1 FROM practitioner_qualifications WHERE practitioner_id=? AND status='verified' AND (expires_on IS NULL OR expires_on>=UTC_DATE()) LIMIT 1",[$p])->fetchColumn(),
            'published'=>(bool)$this->q('SELECT 1 FROM public_team_profiles t JOIN staff_memberships m ON m.local_user_id=t.user_id AND m.clinic_id=t.clinic_id WHERE m.id=? AND t.published=1 LIMIT 1',[$member])->fetchColumn()];
    }
    private static function fields(array $body,array $allowed): void {if(array_diff(array_keys($body),array_merge($allowed,['request_key'])))self::invalid('request');}
    private static function integer(array $body,string $key,int $min): int {$value=$body[$key]??null;if(!is_int($value)||$value<$min)self::invalid($key);return $value;}
    private static function text(array $body,string $key,int $max,bool $required=true,bool $multiline=false): string
    {
        $value=$body[$key]??'';if(!is_string($value)||mb_strlen($value)>$max||($required&&trim($value)==='')||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$value))self::invalid($key);return trim($value);
    }
    private static function reason(array $body): string {$reason=self::text($body,'reason',2000);if(mb_strlen($reason)<5)self::invalid('reason');return $reason;}
    private static function invalid(string $field): never {throw new ApiException(422,'validation_error','Check the practitioner application fields.',[$field=>'Enter a valid value.']);}
    private static function conflict(string $code): never {throw new ApiException(409,$code,'The application or account needs review. Reload the current application before trying again.');}
}
