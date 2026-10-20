<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Auth\{AuthContext,ApplicationAdminContext};
use Wellness\Database;
use Wellness\Http\{ApiException,Response};
use Throwable;

final class PractitionerEnquiryService
{
    private readonly ?\Closure $send;
    public function __construct(private readonly Database $database,?callable $send=null)
    {$this->send=$send===null?null:\Closure::fromCallable($send);}

    /** Called inside our transaction; system delivery has no caller-supplied principal. */
    private function authorize(AuthContext|ApplicationAdminContext|null $actor,int $clinic): void
    {
        $pdo=$this->database->connection();
        $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$clinic]);
        if(!$q->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic unavailable.');
        if($actor instanceof AuthContext){
            if($actor->clinicId!==$clinic||$actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin'))throw new ApiException(403,'forbidden','Clinic administrator access is required.');
            $q=$pdo->prepare("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
                WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
                AND r.code IN ('super_admin','clinic_admin') LIMIT 1 FOR UPDATE");$q->execute([$actor->userId,$clinic]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Clinic administrator access is required.');
        }elseif($actor instanceof ApplicationAdminContext){
            $q=$pdo->prepare("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE");
            $q->execute([$actor->id,$actor->identity->issuer,$actor->identity->subject]);
            if(!$q->fetchColumn())throw new ApiException(403,'application_admin_required','Application administrator access is required.');
        }
    }
    private function access(AuthContext|ApplicationAdminContext $actor,int $clinic,callable $work): array
    {
        $pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Enquiry operations require their own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($clinic);
        try{$pdo->beginTransaction();$this->authorize($actor,$clinic);$result=$work($pdo);$pdo->commit();return $result;}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
    private function audit(AuthContext|ApplicationAdminContext|null $actor,int $clinic,string $cid,string $action,string $entity,int $id,array $metadata=[]): void
    {
        if($actor instanceof ApplicationAdminContext)$metadata['application_admin_id']=$actor->id;
        (new AuditLogger($this->database))->write($clinic,$actor instanceof AuthContext?$actor:null,$cid,$action,$entity,$id,'success',$metadata);
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
        if($pdo->inTransaction())throw new \LogicException('Enquiry submission requires its own transaction.');
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
        return $this->access($actor,$clinic,function($pdo)use($clinic,$query):array{
            $page=filter_var($query['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
            if($page===false)throw new ApiException(422,'validation_error','Invalid page.');
            $offset=($page-1)*25;
            $q=$pdo->prepare("SELECT id,reference,full_name,email,phone,discipline,credentials,service_area,message,status,revision,notification_status,created_at FROM practitioner_enquiries WHERE clinic_id=? ORDER BY id DESC LIMIT 26 OFFSET $offset");$q->execute([$clinic]);$items=$q->fetchAll();
            return ['items'=>array_slice($items,0,25),'page'=>$page,'has_more'=>count($items)>25,'settings'=>$this->settings($clinic)];
        });
    }
    public function saveSettings(ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        return $this->access($actor,$clinic,function($pdo)use($actor,$clinic,$body,$cid):array{
            $recipient=$body['recipient_email']??'';$enabled=$body['enabled']??null;$revision=$body['revision']??null;
            if(!is_string($recipient)||strlen($recipient)>254||!is_bool($enabled)||!is_int($revision)||$revision<0||($recipient!==''&&!filter_var($recipient,FILTER_VALIDATE_EMAIL)))throw new ApiException(422,'validation_error','Enter a valid enquiry notification email.');
            if($this->settings($clinic)['revision']!==$revision)throw new ApiException(409,'settings_changed','Settings changed. Refresh and try again.');
            $pdo->prepare('INSERT INTO practitioner_enquiry_settings(clinic_id,recipient_email,enabled,revision) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE recipient_email=VALUES(recipient_email),enabled=VALUES(enabled),revision=revision+1')->execute([$clinic,$recipient===''?null:strtolower($recipient),$enabled?1:0]);
            $this->audit($actor,$clinic,$cid,'practitioner_enquiry.settings','clinic',$clinic,['enabled'=>$enabled]);
            return $this->settings($clinic);
        });
    }
    public function update(AuthContext|ApplicationAdminContext $actor,int $clinic,int $id,array $body,string $cid): array
    {
        return $this->access($actor,$clinic,function($pdo)use($actor,$clinic,$id,$body,$cid):array{
            $status=$body['status']??null;$revision=$body['revision']??null;
            if(!in_array($status,['new','contacted','closed'],true)||!is_int($revision)||$revision<1)throw new ApiException(422,'validation_error','Choose a valid enquiry status.');
            $q=$pdo->prepare('SELECT revision FROM practitioner_enquiries WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$id,$clinic]);$current=$q->fetchColumn();
            if($current===false)throw new ApiException(404,'enquiry_not_found','Enquiry not found.');
            if((int)$current!==$revision)throw new ApiException(409,'enquiry_changed','Enquiry changed or is unavailable. Refresh and try again.');
            $pdo->prepare('UPDATE practitioner_enquiries SET status=?,revision=revision+1 WHERE id=? AND clinic_id=?')->execute([$status,$id,$clinic]);
            $this->audit($actor,$clinic,$cid,'practitioner_enquiry.status','practitioner_enquiry',$id,['status'=>$status]);
            return ['id'=>$id,'status'=>$status,'revision'=>$revision+1];
        });
    }
    public function retryNotification(AuthContext|ApplicationAdminContext $actor,int $clinic,int $id,string $cid): array
    {return $this->deliver($actor,$clinic,$id,$cid,$this->send);}

    /** Trusted saved-enquiry delivery, called after public submission. Not an HTTP entry point. */
    public function notify(int $clinic,int $id,?callable $send=null): array
    {return $this->deliver(null,$clinic,$id,'enquiry-'.bin2hex(random_bytes(12)),$send??$this->send);}

    private function deliver(AuthContext|ApplicationAdminContext|null $actor,int $clinic,int $id,string $cid,?callable $send): array
    {
        $pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Enquiry notification requires its own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($clinic);
        try{
            $pdo->beginTransaction();$this->authorize($actor,$clinic);
            $q=$pdo->prepare('SELECT e.reference,e.notification_status,c.name FROM practitioner_enquiries e JOIN clinics c ON c.id=e.clinic_id WHERE e.id=? AND e.clinic_id=? FOR UPDATE');$q->execute([$id,$clinic]);$row=$q->fetch();
            if(!$row)throw new ApiException(404,'enquiry_not_found','Enquiry not found.');
            $settings=$this->settings($clinic);
            $status=!in_array($row['notification_status'],['pending','failed','disabled'],true)?'unchanged':(!$settings['mail_configured']||!$settings['recipient_email']?'disabled':'sending');
            if($status!=='unchanged')$pdo->prepare('UPDATE practitioner_enquiries SET notification_status=? WHERE id=? AND clinic_id=?')->execute([$status,$id,$clinic]);
            // Commit an attributed claim before any provider call. Uncertain outcomes are never resent.
            $this->audit($actor,$clinic,$cid,'practitioner_enquiry.notification_claim','practitioner_enquiry',$id,['notification_status'=>$status]);
            $pdo->commit();
            if($status!=='sending')return ['id'=>$id,'notification_status'=>$status];
            $attempted=false;
            try{
                $text="A practitioner enquiry was saved for {$row['name']}.\nReference: {$row['reference']}\n\nSign in to Application administration or the clinic portal and open Practitioner enquiries to review it. No application has been approved automatically.";
                if($send!==null){$attempted=true;$send($settings['recipient_email'],'New practitioner enquiry',$text);}
                else{
                    $env=static fn($key)=>(string)($_ENV[$key]??getenv($key)?:'');
                    $mailer=new GraphMailClient($env('MAIL_TENANT_ID'),$env('MAIL_CLIENT_ID'),$env('MAIL_CLIENT_SECRET'),$env('MAIL_FROM_ADDRESS'));
                    $attempted=true;$mailer->send($settings['recipient_email'],'New practitioner enquiry',$text);
                }
                $status='sent';
            }catch(MailSendException $e){$status=$e->ambiguous?'needs_review':'failed';}
            catch(Throwable){$status=$attempted?'needs_review':'failed';}
            $pdo->beginTransaction();
            $q=$pdo->prepare("UPDATE practitioner_enquiries SET notification_status=? WHERE id=? AND clinic_id=? AND notification_status='sending'");$q->execute([$status,$id,$clinic]);
            if($q->rowCount()!==1)throw new ApiException(409,'enquiry_changed','Enquiry changed or is unavailable. Refresh and try again.');
            $this->audit($actor,$clinic,$cid,'practitioner_enquiry.notification_result','practitioner_enquiry',$id,['notification_status'=>$status]);
            $pdo->commit();return ['id'=>$id,'notification_status'=>$status];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
}
