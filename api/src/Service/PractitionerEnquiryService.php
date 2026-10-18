<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Auth\{AuthContext,ApplicationAdminContext};
use Wellness\Database;
use Wellness\Http\{ApiException,Response};
use Throwable;

final class PractitionerEnquiryService
{
    public function __construct(private readonly Database $database) {}
    private function admin(AuthContext|ApplicationAdminContext $actor,int $clinic): void
    {
        if ($actor instanceof AuthContext && ($actor->clinicId!==$clinic||$actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin'))) throw new ApiException(403,'forbidden','Clinic administrator access is required.');
        if(PHP_SAPI!=='cli')header('Cache-Control: no-store');
    }
    private function settings(int $clinic): array
    {
        $q=$this->database->connection()->prepare('SELECT recipient_email,enabled,revision FROM practitioner_enquiry_settings WHERE clinic_id=?');$q->execute([$clinic]);
        $row=$q->fetch()?:['recipient_email'=>null,'enabled'=>false,'revision'=>0];
        $row['enabled']=(bool)$row['enabled'];$row['revision']=(int)$row['revision'];
        $row['mail_configured']=filter_var($_ENV['MAIL_ENABLED']??getenv('MAIL_ENABLED')?:'false',FILTER_VALIDATE_BOOL);
        return $row;
    }
    public function submit(int $clinic,array $body,string $ip): array
    {
        $allowed=['idempotency_key','full_name','email','phone','discipline','credentials','service_area','message','consent','website'];
        if(array_diff(array_keys($body),$allowed)||($body['consent']??null)!==true||($body['website']??'')!=='')throw new ApiException(422,'validation_error','Check the enquiry fields and agree to be contacted.');
        $key=$body['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D',$key))throw new ApiException(422,'validation_error','Invalid enquiry reference.');
        $fields=[];
        foreach(['full_name'=>160,'email'=>254,'phone'=>40,'discipline'=>190,'credentials'=>500,'service_area'=>190,'message'=>2000] as $field=>$max){
            $value=$body[$field]??'';
            if(!is_string($value)||strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Check the enquiry fields.');
            $fields[$field]=trim($value);
        }
        foreach(['full_name','email','discipline','service_area','message'] as $required)if($fields[$required]==='')throw new ApiException(422,'validation_error','Complete the required enquiry fields.');
        $fields['email']=strtolower($fields['email']);
        if(!filter_var($fields['email'],FILTER_VALIDATE_EMAIL))throw new ApiException(422,'validation_error','Enter a valid email address.');
        $hash=hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));$pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$clinic]);if(!$q->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic unavailable.');
            $q=$pdo->prepare('SELECT request_hash FROM practitioner_enquiries WHERE clinic_id=? AND reference=?');$q->execute([$clinic,$key]);
            if($previous=$q->fetchColumn()){
                if(!hash_equals($previous,$hash))throw new ApiException(409,'idempotency_conflict','This enquiry reference was already used.');
                $pdo->commit();return ['received'=>true,'reference'=>$key];
            }
            if(!$this->settings($clinic)['enabled'])throw new ApiException(503,'enquiries_closed','Practitioner enquiries are temporarily closed. Please contact the clinic.');
            $bucket=gmdate('YmdH');
            foreach(['clinic'=>60,'email:'.$fields['email']=>3,'ip:'.$ip=>20] as $quota=>$max){
                $digest=hash('sha256',$quota);
                $q=$pdo->prepare('SELECT attempts FROM practitioner_enquiry_limits WHERE clinic_id=? AND bucket=? AND key_hash=?');$q->execute([$clinic,$bucket,$digest]);
                if((int)$q->fetchColumn()>=$max)throw new ApiException(429,'rate_limited','Too many enquiries. Please try again later.');
                $pdo->prepare('INSERT INTO practitioner_enquiry_limits(clinic_id,bucket,key_hash) VALUES(?,?,?) ON DUPLICATE KEY UPDATE attempts=attempts+1')->execute([$clinic,$bucket,$digest]);
            }
            $pdo->prepare('DELETE FROM practitioner_enquiry_limits WHERE clinic_id=? AND bucket<?')->execute([$clinic,gmdate('YmdH',time()-172800)]);
            $q=$pdo->prepare('INSERT INTO practitioner_enquiries(clinic_id,reference,request_hash,full_name,email,phone,discipline,credentials,service_area,message) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$clinic,$key,$hash,...array_values($fields)]);$id=(int)$pdo->lastInsertId();
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        if(PHP_SAPI!=='cli')Response::afterJson(fn()=>$this->notify($clinic,$id));
        return ['received'=>true,'reference'=>$key];
    }
    public function list(AuthContext|ApplicationAdminContext $actor,int $clinic,array $query=[]): array
    {
        $this->admin($actor,$clinic);$page=filter_var($query['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if($page===false)throw new ApiException(422,'validation_error','Invalid page.');
        $offset=($page-1)*25;
        $q=$this->database->connection()->prepare("SELECT id,reference,full_name,email,phone,discipline,credentials,service_area,message,status,revision,notification_status,created_at FROM practitioner_enquiries WHERE clinic_id=? ORDER BY id DESC LIMIT 26 OFFSET $offset");$q->execute([$clinic]);$items=$q->fetchAll();$more=count($items)>25;
        return ['items'=>array_slice($items,0,25),'page'=>$page,'has_more'=>$more,'settings'=>$this->settings($clinic)];
    }
    public function saveSettings(ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        $recipient=$body['recipient_email']??'';$enabled=$body['enabled']??null;$revision=$body['revision']??null;
        if(!is_string($recipient)||strlen($recipient)>254||!is_bool($enabled)||!is_int($revision)||($recipient!==''&&!filter_var($recipient,FILTER_VALIDATE_EMAIL)))throw new ApiException(422,'validation_error','Enter a valid enquiry notification email.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();$q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$clinic]);if(!$q->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic unavailable.');
            if($this->settings($clinic)['revision']!==$revision)throw new ApiException(409,'settings_changed','Settings changed. Refresh and try again.');
            $pdo->prepare('INSERT INTO practitioner_enquiry_settings(clinic_id,recipient_email,enabled,revision) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE recipient_email=VALUES(recipient_email),enabled=VALUES(enabled),revision=revision+1')->execute([$clinic,$recipient===''?null:strtolower($recipient),$enabled?1:0]);
            (new AuditLogger($this->database))->write($clinic,null,$cid,'practitioner_enquiry.settings','clinic',$clinic,'success',['application_admin_id'=>$actor->id,'enabled'=>$enabled]);
            $pdo->commit();return $this->settings($clinic);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function update(AuthContext|ApplicationAdminContext $actor,int $clinic,int $id,array $body,string $cid): array
    {
        $this->admin($actor,$clinic);$status=$body['status']??null;$revision=$body['revision']??null;
        if(!in_array($status,['new','contacted','closed'],true)||!is_int($revision))throw new ApiException(422,'validation_error','Choose a valid enquiry status.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare('UPDATE practitioner_enquiries SET status=?,revision=revision+1 WHERE id=? AND clinic_id=? AND revision=?');$q->execute([$status,$id,$clinic,$revision]);
            if($q->rowCount()!==1)throw new ApiException(409,'enquiry_changed','Enquiry changed or is unavailable. Refresh and try again.');
            (new AuditLogger($this->database))->write($clinic,$actor instanceof AuthContext?$actor:null,$cid,'practitioner_enquiry.status','practitioner_enquiry',$id,'success',['status'=>$status,'application_admin_id'=>$actor instanceof ApplicationAdminContext?$actor->id:null]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return ['id'=>$id,'status'=>$status,'revision'=>$revision+1];
    }
    public function retryNotification(AuthContext|ApplicationAdminContext $actor,int $clinic,int $id): array
    {
        $this->admin($actor,$clinic);return $this->notify($clinic,$id);
    }
    public function notify(int $clinic,int $id,?callable $send=null): array
    {
        $settings=$this->settings($clinic);$pdo=$this->database->connection();
        if(!$settings['mail_configured']||!$settings['recipient_email']){
            $pdo->prepare("UPDATE practitioner_enquiries SET notification_status='disabled' WHERE id=? AND clinic_id=? AND notification_status IN ('pending','failed','disabled')")->execute([$id,$clinic]);return ['notification_status'=>'disabled'];
        }
        $q=$pdo->prepare("UPDATE practitioner_enquiries SET notification_status='sending' WHERE id=? AND clinic_id=? AND notification_status IN ('pending','failed','disabled')");$q->execute([$id,$clinic]);
        if($q->rowCount()!==1)return ['notification_status'=>'unchanged'];
        try{
            $q=$pdo->prepare('SELECT e.reference,c.name FROM practitioner_enquiries e JOIN clinics c ON c.id=e.clinic_id WHERE e.id=? AND e.clinic_id=?');$q->execute([$id,$clinic]);$row=$q->fetch();
            $text="A practitioner enquiry was saved for {$row['name']}.\nReference: {$row['reference']}\n\nSign in to Application administration or the clinic portal and open Practitioner enquiries to review it. No application has been approved automatically.";
            if($send!==null)$send($settings['recipient_email'],'New practitioner enquiry',$text);
            else{
                $env=static fn($key)=>(string)($_ENV[$key]??getenv($key)?:'');
                (new GraphMailClient($env('MAIL_TENANT_ID'),$env('MAIL_CLIENT_ID'),$env('MAIL_CLIENT_SECRET'),$env('MAIL_FROM_ADDRESS')))->send($settings['recipient_email'],'New practitioner enquiry',$text);
            }
            $status='sent';
        }catch(MailSendException $e){$status=$e->ambiguous?'needs_review':'failed';}catch(Throwable){$status='failed';}
        $pdo->prepare('UPDATE practitioner_enquiries SET notification_status=? WHERE id=? AND clinic_id=?')->execute([$status,$id,$clinic]);
        return ['notification_status'=>$status];
    }
}
