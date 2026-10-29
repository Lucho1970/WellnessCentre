<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Throwable;
use Wellness\Auth\ApplicationAdminContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

final class CommunicationsAdministrationService
{
    public function __construct(private readonly Database $database,private readonly Config $config) {}
    private function q(string $sql,array $values=[]): \PDOStatement {$q=$this->database->connection()->prepare($sql);$q->execute($values);return $q;}
    public function handle(ApplicationAdminContext $actor,int $clinic,string $method,string $path,array $body,string $cid): array
    {
        if((!$this->config->automatedCommunicationsEnabled&&!$this->config->smsBudgetEnforcementEnabled)||!$this->config->applicationAdminEnabled||!$this->config->clinicManagementEnabled)throw new ApiException(503,'communications_unavailable','Communications administration is not available.');
        $pdo=$this->database->connection();$lock=new ConsentLock($pdo);$lock->acquire($clinic);
        try{
            $pdo->beginTransaction();
            if(!$this->q("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE",[$actor->id,$actor->identity->issuer,$actor->identity->subject])->fetchColumn())throw new ApiException(403,'application_admin_required','Application administrator access is required.');
            if(!$this->q("SELECT id FROM clinics WHERE id=? AND status='active'",[$clinic])->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic not found.');
            if($method==='GET'&&$path===''){$result=$this->dashboard($clinic);$pdo->commit();return $result;}
            if($method!=='POST')throw new ApiException(404,'not_found','Route not found.');
            $key=self::text($body,'request_key',36);if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$key))self::invalid('request_key');
            $fingerprint=hash('sha256',$clinic.$path.json_encode($body,JSON_THROW_ON_ERROR));
            $old=$this->q('SELECT fingerprint,response FROM communication_requests WHERE application_administrator_id=? AND reference=?',[$actor->id,$key])->fetch();
            if($old){if(!hash_equals($old['fingerprint'],$fingerprint))self::conflict();$result=json_decode($old['response'],true,512,JSON_THROW_ON_ERROR);$pdo->commit();return $result;}
            $reason=self::text($body,'reason',1000);if(mb_strlen($reason)<5)self::invalid('reason');
            $result=match($path){'/settings'=>$this->settings($clinic,$body),'/suppression'=>$this->suppression($clinic,$body),'/reply-address'=>$this->replyAddress($clinic,$body),default=>$this->review($clinic,$path,$body)};
            $this->q('INSERT INTO communication_events(clinic_id,application_administrator_id,action,entity_id,reason,details,correlation_id) VALUES(?,?,?,?,?,?,?)',[$clinic,$actor->id,$result['action'],$result['id']??null,$reason,json_encode($result['evidence']??[],JSON_THROW_ON_ERROR),substr($cid,0,128)]);
            unset($result['evidence']);
            $this->q('INSERT INTO communication_requests(application_administrator_id,reference,fingerprint,response) VALUES(?,?,?,?)',[$actor->id,$key,$fingerprint,json_encode($result,JSON_THROW_ON_ERROR)]);
            $pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
    private function dashboard(int $clinic): array
    {
        $s=$this->q('SELECT * FROM clinic_communications WHERE clinic_id=?',[$clinic])->fetch()?:['clinic_id'=>$clinic,'revision'=>0,'email_enabled'=>false,'sms_enabled'=>false,'mail_from'=>'','reply_to'=>'','alert_email'=>'','sms_from_did'=>'','cad_micros_per_segment'=>null,'fixed_cad_micros'=>0,'tariff_valid_until'=>null,'opt_out_checked_at'=>null];
        $s['email_enabled']=(bool)$s['email_enabled'];$s['sms_enabled']=(bool)$s['sms_enabled'];
        return ['settings'=>$s,'provider_evidence_available'=>$this->config->providerEvidenceEnabled,'gates'=>['automated_email'=>$this->config->automatedCommunicationsEnabled,'sms_budgets'=>$this->config->smsBudgetEnforcementEnabled],
            'notices'=>$this->q('SELECT id,kind,recipient_kind,recipient_address,status,attempt_count,last_error,created_at,sent_at FROM communication_notices WHERE clinic_id=? ORDER BY id DESC LIMIT 100',[$clinic])->fetchAll(),
            'deliveries'=>$this->q('SELECT d.notification_event_id id,d.segments,d.estimated_cents,d.provider_message_id,e.status,e.actual_cents,e.period_start,e.reference FROM sms_notification_deliveries d JOIN sms_budget_pools p ON p.id=d.pool_id JOIN sms_budget_entries e ON e.pool_id=d.pool_id AND e.reference=d.reference WHERE p.clinic_id=? ORDER BY d.notification_event_id DESC LIMIT 100',[$clinic])->fetchAll(),
            'suppressed_count'=>(int)$this->q('SELECT COUNT(*) FROM sms_suppressions WHERE clinic_id=? AND active=1',[$clinic])->fetchColumn(),
            'held_sms'=>$this->q("SELECT id,last_error,scheduled_at FROM notification_events WHERE clinic_id=? AND channel='sms' AND last_error LIKE 'Spending policy:%' ORDER BY id DESC LIMIT 50",[$clinic])->fetchAll(),
            'members'=>$this->q("SELECT m.id,u.display_name,r.email reply_email,r.active reply_active,COALESCE(r.version,0) reply_version FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id JOIN practitioners p ON p.user_id=u.id LEFT JOIN practitioner_reply_addresses r ON r.membership_id=m.id AND r.clinic_id=m.clinic_id WHERE m.clinic_id=? AND m.status='active' AND u.status='active' AND p.active=1 ORDER BY u.display_name LIMIT 200",[$clinic])->fetchAll(),
            'health'=>$this->q("SELECT COUNT(*) total,SUM(status='needs_review') needs_review,SUM(status IN ('queued','failed') AND created_at<UTC_TIMESTAMP()-INTERVAL 30 MINUTE) overdue,SUM(status='sending' AND leased_until<UTC_TIMESTAMP()) stale_leases FROM communication_notices WHERE clinic_id=?",[$clinic])->fetch(),
            'events'=>$this->q('SELECT action,reason,created_at FROM communication_events WHERE clinic_id=? ORDER BY id DESC LIMIT 50',[$clinic])->fetchAll()];
    }
    private function settings(int $clinic,array $b): array
    {
        self::fields($b,['revision','email_enabled','sms_enabled','mail_from','reply_to','alert_email','sms_from_did','cad_micros_per_segment','fixed_cad_micros','tariff_valid_until','senders_verified','opt_out_reviewed']);
        $s=$this->q('SELECT * FROM clinic_communications WHERE clinic_id=? FOR UPDATE',[$clinic])->fetch();$revision=(int)($s['revision']??0);
        if(self::integer($b,'revision',0)!==$revision)self::conflict();
        foreach(['email_enabled','sms_enabled','senders_verified','opt_out_reviewed'] as $flag)if(!is_bool($b[$flag]??null))self::invalid($flag);
        $from=strtolower(self::text($b,'mail_from',190,false));$reply=strtolower(self::text($b,'reply_to',190,false));$alert=strtolower(self::text($b,'alert_email',190,false));
        foreach(['mail_from'=>$from,'reply_to'=>$reply,'alert_email'=>$alert] as $key=>$value)if(($value!==''||$b['email_enabled'])&&!filter_var($value,FILTER_VALIDATE_EMAIL))self::invalid($key);
        $did=self::text($b,'sms_from_did',10,false);if(($did!==''||$b['sms_enabled'])&&!CanadianSmsNumber::isAllowed('+1'.$did))self::invalid('sms_from_did');
        $rate=($b['cad_micros_per_segment']??null)===null?null:self::integer($b,'cad_micros_per_segment',1,100000000);
        $fixed=self::integer($b,'fixed_cad_micros',0,100000000);$expires=self::text($b,'tariff_valid_until',19,false);$until=$expires===''?null:\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$expires,new \DateTimeZone('UTC'));
        if($expires!==''&&(!$until||$until->format('Y-m-d H:i:s')!==$expires))self::invalid('tariff_valid_until');
        if(($b['email_enabled']||$b['sms_enabled'])&&!$b['senders_verified'])self::invalid('senders_verified');
        if($b['email_enabled']&&!$this->config->automatedCommunicationsEnabled)self::invalid('email_enabled');
        if($b['sms_enabled']&&(!$this->config->smsBudgetEnforcementEnabled||!$rate||!$until||$until->getTimestamp()<=time()||$until->getTimestamp()>time()+90*86400))self::invalid('tariff_valid_until');
        $checked=$b['opt_out_reviewed']?gmdate('Y-m-d H:i:s'):($s['opt_out_checked_at']??null);
        if($b['sms_enabled']&&(!$checked||strtotime($checked.' UTC')<time()-86400))self::invalid('opt_out_reviewed');
        $this->q('INSERT INTO clinic_communications(clinic_id,revision,email_enabled,sms_enabled,mail_from,reply_to,alert_email,sms_from_did,cad_micros_per_segment,fixed_cad_micros,tariff_valid_until,opt_out_checked_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),email_enabled=VALUES(email_enabled),sms_enabled=VALUES(sms_enabled),mail_from=VALUES(mail_from),reply_to=VALUES(reply_to),alert_email=VALUES(alert_email),sms_from_did=VALUES(sms_from_did),cad_micros_per_segment=VALUES(cad_micros_per_segment),fixed_cad_micros=VALUES(fixed_cad_micros),tariff_valid_until=VALUES(tariff_valid_until),opt_out_checked_at=VALUES(opt_out_checked_at)',[$clinic,$revision+1,(int)$b['email_enabled'],(int)$b['sms_enabled'],$from,$reply,$alert,$did,$rate,$fixed,$expires?:null,$checked]);
        return ['action'=>'communications.configure','revision'=>$revision+1,'evidence'=>['revision'=>$revision+1,'senders_verified'=>$b['senders_verified'],'opt_out_reviewed'=>$b['opt_out_reviewed'],'cad_micros_per_segment'=>$rate,'fixed_cad_micros'=>$fixed,'tariff_valid_until'=>$expires?:null]];
    }
    private function suppression(int $clinic,array $b): array
    {
        self::fields($b,['number','active','opt_in_confirmed']);$number=self::text($b,'number',12);
        if(!CanadianSmsNumber::isAllowed($number)||!is_bool($b['active']??null))self::invalid('number');
        if(!$b['active']&&($b['opt_in_confirmed']??false)!==true)self::invalid('opt_in_confirmed');
        $hash=hash('sha256',$number);$this->q('INSERT INTO sms_suppressions(clinic_id,number_hash,active) VALUES(?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active)',[$clinic,$hash,(int)$b['active']]);
        return ['action'=>'sms.suppression','evidence'=>['number_hash'=>$hash,'active'=>$b['active'],'opt_in_confirmed'=>$b['opt_in_confirmed']??false]];
    }
    private function replyAddress(int $clinic,array $b): array
    {
        self::fields($b,['membership_id','email','active','sharing_confirmed','version']);$member=self::integer($b,'membership_id',1);$email=strtolower(self::text($b,'email',190));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_bool($b['active']??null))self::invalid('email');
        if($b['active']&&($b['sharing_confirmed']??false)!==true)self::invalid('sharing_confirmed');
        if(!$this->q("SELECT m.id FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id AND u.status='active' JOIN practitioners p ON p.user_id=u.id AND p.active=1 JOIN product_identities i ON i.id=m.identity_id AND i.status='active' WHERE m.id=? AND m.clinic_id=? AND m.status='active' FOR UPDATE",[$member,$clinic])->fetchColumn())throw new ApiException(404,'membership_not_found','Membership not found.');
        $old=$this->q('SELECT version FROM practitioner_reply_addresses WHERE membership_id=? AND clinic_id=? FOR UPDATE',[$member,$clinic])->fetchColumn();$version=$old===false?0:(int)$old;
        if(self::integer($b,'version',0)!==$version)self::conflict();
        $this->q('INSERT INTO practitioner_reply_addresses(membership_id,clinic_id,version,email,active) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),email=VALUES(email),active=VALUES(active)',[$member,$clinic,$version+1,$email,(int)$b['active']]);
        return ['id'=>$member,'action'=>'communications.reply_address','evidence'=>['active'=>$b['active'],'sharing_confirmed'=>$b['sharing_confirmed']??false,'email_hash'=>hash('sha256',$email)]];
    }
    private function review(int $clinic,string $path,array $b): array
    {
        if(preg_match('#^/deliveries/([1-9][0-9]*)/reconcile$#D',$path,$m)){
            self::fields($b,['outcome','actual_cents','expected_actual_cents','checked_provider_history']);
            if(($b['checked_provider_history']??false)!==true)self::invalid('checked_provider_history');
            $delivery=$this->q('SELECT d.* FROM sms_notification_deliveries d JOIN sms_budget_pools p ON p.id=d.pool_id WHERE d.notification_event_id=? AND p.clinic_id=?',[(int)$m[1],$clinic])->fetch();
            if(!$delivery)throw new ApiException(404,'delivery_not_found','Delivery not found.');
            $this->q('SELECT id FROM sms_budget_pools WHERE id=? FOR UPDATE',[$delivery['pool_id']]);
            $entry=$this->q('SELECT * FROM sms_budget_entries WHERE pool_id=? AND reference=? FOR UPDATE',[$delivery['pool_id'],$delivery['reference']])->fetch();
            $actual=self::integer($b,'actual_cents',0,100000000);$outcome=$b['outcome']??'';
            if(!in_array($outcome,['provider_accepted','provider_not_sent','correction'],true))self::invalid('outcome');
            if($outcome==='provider_not_sent'&&$actual!==0)self::invalid('actual_cents');
            if(($b['expected_actual_cents']??null)!==($entry['actual_cents']===null?null:(int)$entry['actual_cents']))self::conflict();
            $ledger=new SmsBudgetLedger($this->database->connection(),true);
            if($outcome==='correction')$ledger->correct((int)$delivery['pool_id'],(int)$delivery['membership_id'],$delivery['reference'],self::integer($b,'expected_actual_cents',0,100000000),$actual);
            elseif($entry['status']==='reserved'&&$outcome==='provider_not_sent')$ledger->release((int)$delivery['pool_id'],(int)$delivery['membership_id'],$delivery['reference']);
            else $ledger->settle((int)$delivery['pool_id'],(int)$delivery['membership_id'],$delivery['reference'],$actual);
            $this->q("UPDATE notification_events SET status='resolved',leased_until=NULL,lease_token=NULL WHERE id=? AND status='needs_review'",[$delivery['notification_event_id']]);
            return ['id'=>(int)$m[1],'action'=>'sms.reconcile','evidence'=>['outcome'=>$outcome,'pool_id'=>(int)$delivery['pool_id'],'reference'=>$delivery['reference'],'previous_actual_cents'=>$entry['actual_cents']===null?null:(int)$entry['actual_cents'],'actual_cents'=>$actual]];
        }
        if(preg_match('#^/notices/([1-9][0-9]*)/review$#D',$path,$m)){
            self::fields($b,['decision','attempt_count','checked_provider_history','provider_not_sent']);
            $notice=$this->q('SELECT * FROM communication_notices WHERE id=? AND clinic_id=? FOR UPDATE',[(int)$m[1],$clinic])->fetch();if(!$notice)throw new ApiException(404,'notice_not_found','Notice not found.');
            if($notice['status']!=='needs_review'||(int)$notice['attempt_count']!==self::integer($b,'attempt_count',0))self::conflict();
            if(($b['checked_provider_history']??false)!==true)self::invalid('checked_provider_history');$retry=($b['decision']??'')==='retry';
            if(!in_array($b['decision']??'',['retry','resolve'],true)||($retry&&(($b['provider_not_sent']??false)!==true||(int)$notice['attempt_count']>=5)))self::invalid('decision');
            $this->q('UPDATE communication_notices SET status=?,next_attempt_at=NULL WHERE id=?',[$retry?'queued':'resolved',$notice['id']]);
            return ['id'=>(int)$notice['id'],'action'=>'notice.'.$b['decision'],'evidence'=>['attempt_count'=>(int)$notice['attempt_count'],'provider_not_sent'=>$b['provider_not_sent']??false]];
        }
        throw new ApiException(404,'not_found','Route not found.');
    }
    private static function fields(array $b,array $allowed): void {if(array_diff(array_keys($b),array_merge($allowed,['request_key','reason'])))self::invalid('request');}
    private static function text(array $b,string $key,int $max,bool $required=true): string {$v=$b[$key]??'';if(!is_string($v)||mb_strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v)||($required&&trim($v)===''))self::invalid($key);return trim($v);}
    private static function integer(array $b,string $key,int $min,int $max=PHP_INT_MAX): int {$v=$b[$key]??null;if(!is_int($v)||$v<$min||$v>$max)self::invalid($key);return $v;}
    private static function invalid(string $key): never {throw new ApiException(422,'validation_error','Check the communications fields.',[$key=>'Enter a valid value.']);}
    private static function conflict(): never {throw new ApiException(409,'communications_changed','Communications changed. Reload before trying again.');}
}
