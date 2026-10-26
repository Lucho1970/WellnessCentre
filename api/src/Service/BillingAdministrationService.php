<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Wellness\Auth\ApplicationAdminContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Central financial metadata only. No provider calls, clinical reads or clinic-role bypass. */
final class BillingAdministrationService
{
    public const FEATURES = ['booking','calendar','client_management','reporting','sms_reminders','dedicated_number','custom_email'];
    public function __construct(private readonly Database $database, private readonly Config $config) {}

    public function handle(ApplicationAdminContext $actor, string $method, string $path, array $query, array $body, string $cid): array
    {
        if (!$this->config->billingAdminEnabled || !$this->config->applicationAdminEnabled || !$this->config->clinicManagementEnabled)
            throw new ApiException(503,'billing_unavailable','Billing administration is not available.');
        if ($method==='GET' && $path==='') return $this->transaction($actor, function(PDO $pdo): array {
            $plans=$pdo->query('SELECT * FROM subscription_plan_versions ORDER BY id DESC LIMIT 200')->fetchAll();
            foreach($plans as &$plan) $plan['features']=json_decode($plan['features'],true,512,JSON_THROW_ON_ERROR);
            return ['plans'=>$plans,'features'=>self::FEATURES];
        });
        if ($method==='POST' && $path==='/plans') return $this->mutation($actor,$path,$body,$cid,fn(PDO $pdo)=>$this->plan($pdo,$actor,$body,$cid));
        if (preg_match('#^/clinics/([1-9][0-9]*)(?:/(subscriptions|pool|members|overhead)(?:/([1-9][0-9]*))?)?$#D',$path,$m)) {
            $clinic=(int)$m[1]; $section=$m[2]??''; $id=isset($m[3])?(int)$m[3]:null;
            if ($method==='GET' && $section==='') return $this->transaction($actor,fn(PDO $pdo)=>$this->dashboard($pdo,$clinic,$query));
            if ($method==='POST' && $section!=='' && (($section==='subscriptions') || $id===null))
                return $this->mutation($actor,$path,$body,$cid,function(PDO $pdo)use($actor,$clinic,$section,$id,$body,$cid):array{
                    $this->clinic($pdo,$clinic);
                    return match($section) {
                        'subscriptions'=>$this->subscription($pdo,$actor,$clinic,$id,$body,$cid),
                        'pool'=>$this->pool($pdo,$actor,$clinic,$body,$cid),
                        'members'=>$this->member($pdo,$actor,$clinic,$body,$cid),
                        'overhead'=>$this->overhead($pdo,$actor,$clinic,$body,$cid),
                    };
                });
        }
        throw new ApiException(404,'not_found','Route not found.');
    }

    private function authorize(PDO $pdo, ApplicationAdminContext $actor): void
    {
        $q=$pdo->prepare("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE");
        $q->execute([$actor->id,$actor->identity->issuer,$actor->identity->subject]);
        if (!$q->fetchColumn()) throw new ApiException(403,'application_admin_required','Application administrator access is required.');
    }

    private function transaction(ApplicationAdminContext $actor, callable $work): array
    {
        $pdo=$this->database->connection();
        if ($pdo->inTransaction()) throw new \LogicException('Billing administration owns its transaction.');
        try { $pdo->beginTransaction(); $this->authorize($pdo,$actor); $result=$work($pdo); $pdo->commit(); return $result; }
        catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }

    private function mutation(ApplicationAdminContext $actor,string $path,array $body,string $cid,callable $work): array
    {
        $reference=$body['request_key']??null;
        if (!is_string($reference) || !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D',$reference)) self::invalid('request_key');
        $hash=hash('sha256',$path.json_encode($body,JSON_THROW_ON_ERROR));
        return $this->transaction($actor,function(PDO $pdo)use($actor,$reference,$hash,$work):array{
            $q=$pdo->prepare('SELECT fingerprint,response FROM billing_admin_requests WHERE application_administrator_id=? AND reference=?');
            $q->execute([$actor->id,$reference]); $previous=$q->fetch();
            if ($previous) {
                if (!hash_equals($previous['fingerprint'],$hash)) self::conflict('billing_request_changed');
                return json_decode($previous['response'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$work($pdo);
            $pdo->prepare('INSERT INTO billing_admin_requests(application_administrator_id,reference,fingerprint,response) VALUES(?,?,?,?)')
                ->execute([$actor->id,$reference,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    private function clinic(PDO $pdo,int $id): void
    {
        $q=$pdo->prepare('SELECT id FROM clinics WHERE id=?'); $q->execute([$id]);
        if (!$q->fetchColumn()) throw new ApiException(404,'clinic_not_found','Clinic not found.');
    }

    private function event(PDO $pdo,ApplicationAdminContext $actor,?int $clinic,string $action,int $id,array $body,string $cid,array $details): void
    {
        $reason=self::text($body,'reason',500);
        if (mb_strlen($reason)<5) self::invalid('reason');
        $pdo->prepare('INSERT INTO billing_admin_events(application_administrator_id,clinic_id,action,entity_id,reason,correlation_id,details) VALUES(?,?,?,?,?,?,?)')
            ->execute([$actor->id,$clinic,$action,$id,$reason,substr($cid,0,128),json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    private function plan(PDO $pdo,ApplicationAdminContext $actor,array $body,string $cid): array
    {
        self::fields($body,['code','display_name','scope','base_monthly_cents','features']);
        $code=self::text($body,'code',80); $name=self::text($body,'display_name',160);
        if (!preg_match('/^[a-z][a-z0-9-]{1,79}$/D',$code)) self::invalid('code');
        if (!in_array($body['scope']??null,['tenant','practitioner'],true)) self::invalid('scope');
        $price=$body['base_monthly_cents']??null;
        if ($price!==null) self::integer($body,'base_monthly_cents',0,100000000);
        $features=$body['features']??null;
        if (!is_array($features) || array_diff(array_keys($features),self::FEATURES)) self::invalid('features');
        foreach($features as $enabled) if(!is_bool($enabled))self::invalid('features');
        $features=array_replace(array_fill_keys(self::FEATURES,false),$features);
        $pdo->prepare('INSERT IGNORE INTO billing_plan_codes(code) VALUES(?)')->execute([$code]);
        $q=$pdo->prepare('SELECT code FROM billing_plan_codes WHERE code=? FOR UPDATE');$q->execute([$code]);
        $q=$pdo->prepare('SELECT version,scope FROM subscription_plan_versions WHERE code=? ORDER BY version DESC LIMIT 1');$q->execute([$code]);$last=$q->fetch();
        if ($last && $last['scope']!==$body['scope']) self::conflict('billing_plan_scope_changed');
        $version=$last?(int)$last['version']+1:1;
        $pdo->prepare('INSERT INTO subscription_plan_versions(code,version,scope,base_monthly_cents,features,display_name) VALUES(?,?,?,?,?,?)')
            ->execute([$code,$version,$body['scope'],$price,json_encode($features,JSON_THROW_ON_ERROR),$name]);
        $id=(int)$pdo->lastInsertId();
        $this->event($pdo,$actor,null,'plan.version.create',$id,$body,$cid,['code'=>$code,'version'=>$version,'price_cents'=>$price,'features'=>$features]);
        return ['id'=>$id,'version'=>$version];
    }

    private function subscription(PDO $pdo,ApplicationAdminContext $actor,int $clinic,?int $id,array $body,string $cid): array
    {
        if ($id===null) {
            self::fields($body,['membership_id','plan_version_id','status','timezone']);
            $member=($body['membership_id']??null)===null?null:self::integer($body,'membership_id',1,PHP_INT_MAX);
            if ($member!==null) $this->eligible($pdo,$clinic,$member);
            $planId=self::integer($body,'plan_version_id',1,PHP_INT_MAX);
            $q=$pdo->prepare('SELECT * FROM subscription_plan_versions WHERE id=?');$q->execute([$planId]);$plan=$q->fetch();
            if (!$plan || $plan['currency']!=='CAD' || $plan['scope']!==($member===null?'tenant':'practitioner')) self::invalid('plan_version_id');
            $state=$body['status']??''; if(!in_array($state,['pending','trialing'],true))self::invalid('status');
            $timezone=self::text($body,'timezone',64); if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))self::invalid('timezone');
            $pdo->prepare('INSERT INTO billing_accounts(clinic_id,membership_id) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$clinic,$member]);
            $account=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('SELECT currency FROM billing_accounts WHERE id=? FOR UPDATE');$q->execute([$account]);
            if($q->fetchColumn()!==$plan['currency'])self::conflict('billing_currency_mismatch');
            $q=$pdo->prepare("SELECT id FROM billing_subscriptions WHERE billing_account_id=? AND status<>'canceled' FOR UPDATE");$q->execute([$account]);
            if($q->fetchColumn())self::conflict('billing_subscription_exists');
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $start=$state==='trialing'?$now->format('Y-m-d H:i:s'):null;
            $end=$state==='trialing'?SubscriptionPolicy::trialEndsAt($now,$timezone)->format('Y-m-d H:i:s'):null;
            $pdo->prepare('INSERT INTO billing_subscriptions(billing_account_id,plan_version_id,status,starts_at,trial_ends_at,trial_timezone) VALUES(?,?,?,?,?,?)')
                ->execute([$account,$planId,$state,$start,$end,$timezone]); $id=(int)$pdo->lastInsertId();
            $this->event($pdo,$actor,$clinic,'subscription.create',$id,$body,$cid,['membership_id'=>$member,'plan_version_id'=>$planId,'status'=>$state,'trial_ends_at'=>$end]);
            return ['id'=>$id,'version'=>1];
        }
        self::fields($body,['action','version','trial_last_day']);
        $version=self::integer($body,'version',1,PHP_INT_MAX);
        $q=$pdo->prepare('SELECT s.* FROM billing_subscriptions s JOIN billing_accounts a ON a.id=s.billing_account_id WHERE s.id=? AND a.clinic_id=? FOR UPDATE');
        $q->execute([$id,$clinic]);$row=$q->fetch(); if(!$row)throw new ApiException(404,'not_found','Subscription not found.');
        if((int)$row['version']!==$version)self::conflict('billing_version_changed');
        $state=$row['status']; $resume=$row['resume_status']; $start=$row['starts_at']; $end=$row['trial_ends_at'];
        $action=$body['action']??'';
        if($action==='extend' && $state==='trialing') {
            $day=self::text($body,'trial_last_day',10);
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone($row['trial_timezone']));
            if(!$date || $date->format('Y-m-d')!==$day)self::invalid('trial_last_day');
            $date=$date->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $end=$date->format('Y-m-d H:i:s');
            if($end<=($row['trial_ends_at']??'') || $date<=$now || $date>$now->modify('+2 years'))self::invalid('trial_last_day');
        } elseif($action==='activate_trial' && $state==='pending') {
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=$now->format('Y-m-d H:i:s');
            $end=SubscriptionPolicy::trialEndsAt($now,$row['trial_timezone'])->format('Y-m-d H:i:s');$state='trialing';
        } elseif($action==='suspend' && in_array($state,['pending','trialing'],true)) { $resume=$state;$state='suspended'; }
        elseif($action==='resume' && $state==='suspended' && in_array($resume,['pending','trialing'],true)) { $state=$resume;$resume=null; }
        elseif($action==='cancel' && in_array($state,['pending','trialing','suspended'],true)) { $state='canceled';$resume=null; }
        else self::conflict('billing_transition_invalid');
        $pdo->prepare('UPDATE billing_subscriptions SET status=?,resume_status=?,starts_at=?,trial_ends_at=?,version=version+1 WHERE id=?')->execute([$state,$resume,$start,$end,$id]);
        $this->event($pdo,$actor,$clinic,'subscription.'.$action,$id,$body,$cid,['before'=>['status'=>$row['status'],'trial_ends_at'=>$row['trial_ends_at']],'after'=>['status'=>$state,'trial_ends_at'=>$end],'version'=>$version+1]);
        return ['id'=>$id,'version'=>$version+1];
    }

    private function lockedPool(PDO $pdo,int $clinic): array
    {
        $q=$pdo->prepare('SELECT * FROM sms_budget_pools WHERE clinic_id=? FOR UPDATE');$q->execute([$clinic]);$pool=$q->fetch();
        if(!$pool)throw new ApiException(404,'sms_pool_not_found','Configure an SMS budget first.');
        return $pool;
    }

    private function pool(PDO $pdo,ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        self::fields($body,['revision','timezone','monthly_limit_cents','member_limit_cents','max_members','status']);
        $revision=self::integer($body,'revision',0,PHP_INT_MAX);$timezone=self::text($body,'timezone',64);
        if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))self::invalid('timezone');
        $monthly=self::integer($body,'monthly_limit_cents',0,100000000);$member=self::integer($body,'member_limit_cents',0,100000000);$max=self::integer($body,'max_members',1,1000);
        if(!in_array($body['status']??null,['active','inactive'],true))self::invalid('status');
        $q=$pdo->prepare('SELECT * FROM sms_budget_pools WHERE clinic_id=? FOR UPDATE');$q->execute([$clinic]);$before=$q->fetch();
        if(($before?(int)$before['revision']:0)!==$revision)self::conflict('billing_version_changed');
        if($before) {
            if($timezone!==$before['timezone'])self::invalid('timezone');
            $q=$pdo->prepare("SELECT COUNT(*) FROM sms_budget_members WHERE pool_id=? AND status='active'");$q->execute([$before['id']]);
            if((int)$q->fetchColumn()>$max)self::invalid('max_members');
            $pdo->prepare('UPDATE sms_budget_pools SET monthly_limit_cents=?,member_limit_cents=?,max_members=?,status=?,revision=revision+1 WHERE id=?')->execute([$monthly,$member,$max,$body['status'],$before['id']]);$id=(int)$before['id'];
        } else {
            $pdo->prepare('INSERT INTO sms_budget_pools(clinic_id,timezone,monthly_limit_cents,member_limit_cents,max_members,status) VALUES(?,?,?,?,?,?)')->execute([$clinic,$timezone,$monthly,$member,$max,$body['status']]);$id=(int)$pdo->lastInsertId();
        }
        $this->event($pdo,$actor,$clinic,'sms.pool.configure',$id,$body,$cid,['monthly_limit_cents'=>$monthly,'member_limit_cents'=>$member,'max_members'=>$max,'status'=>$body['status'],'effective'=>'new periods; status and cohort cap immediately']);
        return ['id'=>$id,'revision'=>$revision+1];
    }

    private function eligible(PDO $pdo,int $clinic,int $member): void
    {
        $q=$pdo->prepare("SELECT m.id FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id
            JOIN product_identities i ON i.id=m.identity_id JOIN practitioners p ON p.user_id=u.id JOIN clinics c ON c.id=m.clinic_id
            WHERE m.id=? AND m.clinic_id=? AND m.status='active' AND i.status='active' AND u.status='active'
            AND u.user_type='staff' AND p.active=1 AND c.status='active' FOR UPDATE");$q->execute([$member,$clinic]);
        if(!$q->fetchColumn())self::invalid('membership_id');
    }

    private function member(PDO $pdo,ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        self::fields($body,['membership_id','action','revision']);$member=self::integer($body,'membership_id',1,PHP_INT_MAX);$pool=$this->lockedPool($pdo,$clinic);
        if(self::integer($body,'revision',1,PHP_INT_MAX)!==(int)$pool['revision'])self::conflict('billing_version_changed');
        $action=$body['action']??'';
        if($action==='enroll') {
            $this->eligible($pdo,$clinic,$member);
            $q=$pdo->prepare("SELECT COUNT(*) FROM sms_budget_members WHERE pool_id=? AND status='active' AND membership_id<>?");$q->execute([$pool['id'],$member]);
            if((int)$q->fetchColumn()>=(int)$pool['max_members'])self::conflict('sms_cohort_full');
            $pdo->prepare("INSERT INTO sms_budget_members(pool_id,clinic_id,membership_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status='active'")->execute([$pool['id'],$clinic,$member]);
        } elseif($action==='remove') {
            $q=$pdo->prepare('SELECT 1 FROM sms_budget_members WHERE pool_id=? AND membership_id=?');$q->execute([$pool['id'],$member]);
            if(!$q->fetchColumn())self::invalid('membership_id');
            $pdo->prepare("UPDATE sms_budget_members SET status='removed' WHERE pool_id=? AND membership_id=?")->execute([$pool['id'],$member]);
        } else self::invalid('action');
        $pdo->prepare('UPDATE sms_budget_pools SET revision=revision+1 WHERE id=?')->execute([$pool['id']]);
        $this->event($pdo,$actor,$clinic,'sms.member.'.$action,$member,$body,$cid,['pool_id'=>(int)$pool['id']]);
        return ['id'=>$member,'revision'=>(int)$pool['revision']+1];
    }

    private function period(PDO $pdo,array $pool,string $month): array
    {
        $pdo->prepare('INSERT IGNORE INTO sms_budget_periods(pool_id,period_start,limit_cents,member_limit_cents) VALUES(?,?,?,?)')->execute([$pool['id'],$month,$pool['monthly_limit_cents'],$pool['member_limit_cents']]);
        $q=$pdo->prepare('SELECT * FROM sms_budget_periods WHERE pool_id=? AND period_start=?');$q->execute([$pool['id'],$month]);return $q->fetch();
    }

    private function overhead(PDO $pdo,ApplicationAdminContext $actor,int $clinic,array $body,string $cid): array
    {
        self::fields($body,['action','amount_cents','reference','month','expected_actual_cents']);
        $pool=$this->lockedPool($pdo,$clinic);$amount=self::integer($body,'amount_cents',0,100000000);
        $action=$body['action']??'';$reference=self::text($body,'reference',128);
        if(!preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D',$reference))self::invalid('reference');
        $month=self::month($body['month']??null,$pool['timezone']);
        $q=$pdo->prepare('SELECT * FROM sms_budget_entries WHERE pool_id=? AND reference=?');$q->execute([$pool['id'],$reference]);$entry=$q->fetch();
        $period=$this->period($pdo,$pool,$month);
        if(in_array($action,['settle','correct'],true)) {
            if(!$entry || $entry['membership_id']!==null || $entry['status']!==($action==='settle'?'reserved':'settled') || $entry['period_start']!==$month)self::conflict('billing_overhead_changed');
            if($action==='correct' && self::integer($body,'expected_actual_cents',0,100000000)!==(int)$entry['actual_cents'])self::conflict('billing_overhead_changed');
            $pdo->prepare("UPDATE sms_budget_entries SET status='settled',actual_cents=? WHERE id=?")->execute([$amount,$entry['id']]);
            $pdo->prepare('UPDATE sms_budget_periods SET reserved_cents=reserved_cents-?,spent_cents=spent_cents-?+? WHERE pool_id=? AND period_start=?')
                ->execute([$action==='settle'?$entry['estimated_cents']:0,$action==='correct'?$entry['actual_cents']:0,$amount,$pool['id'],$month]);$id=(int)$entry['id'];
        } elseif(in_array($action,['reserve','record'],true)) {
            if($entry)self::conflict('billing_reference_exists');
            if($action==='reserve' && !SubscriptionPolicy::budgetAllows((int)$period['limit_cents'],(int)$period['spent_cents'],(int)$period['reserved_cents'],$amount))self::conflict('sms_budget_exhausted');
            $pdo->prepare('INSERT INTO sms_budget_entries(pool_id,period_start,reference,estimated_cents,actual_cents,status) VALUES(?,?,?,?,?,?)')
                ->execute([$pool['id'],$month,$reference,$action==='reserve'?$amount:0,$action==='record'?$amount:null,$action==='reserve'?'reserved':'settled']);$id=(int)$pdo->lastInsertId();
            $column=$action==='reserve'?'reserved_cents':'spent_cents';
            $pdo->prepare("UPDATE sms_budget_periods SET $column=$column+? WHERE pool_id=? AND period_start=?")->execute([$amount,$pool['id'],$month]);
        } else self::invalid('action');
        $this->event($pdo,$actor,$clinic,'sms.overhead.'.$action,$id,$body,$cid,['amount_cents'=>$amount,'previous_actual_cents'=>$entry['actual_cents']??null,'month'=>$month,'pool_id'=>(int)$pool['id']]);
        return ['id'=>$id];
    }

    private function dashboard(PDO $pdo,int $clinic,array $query): array
    {
        $this->clinic($pdo,$clinic);
        $q=$pdo->prepare('SELECT * FROM sms_budget_pools WHERE clinic_id=?');$q->execute([$clinic]);$pool=$q->fetch()?:null;
        $month=self::month($query['month']??null,$pool['timezone']??'America/Toronto');
        $period=null;$members=[];$entries=[];
        if($pool) {
            $q=$pdo->prepare('SELECT * FROM sms_budget_periods WHERE pool_id=? AND period_start=?');$q->execute([$pool['id'],$month]);$period=$q->fetch()?:null;
            $q=$pdo->prepare("SELECT b.membership_id,b.status,m.status membership_status,u.display_name,
                COALESCE(p.limit_cents,?) limit_cents,COALESCE(p.spent_cents,0) spent_cents,COALESCE(p.reserved_cents,0) reserved_cents
                FROM sms_budget_members b JOIN staff_memberships m ON m.id=b.membership_id JOIN users u ON u.id=m.local_user_id
                LEFT JOIN sms_budget_member_periods p ON p.pool_id=b.pool_id AND p.membership_id=b.membership_id AND p.period_start=? WHERE b.pool_id=? ORDER BY u.display_name,b.membership_id");
            $q->execute([$period['member_limit_cents']??$pool['member_limit_cents'],$month,$pool['id']]);$members=$q->fetchAll();
            $q=$pdo->prepare("SELECT id,reference,membership_id,estimated_cents,actual_cents,status,created_at FROM sms_budget_entries WHERE pool_id=? AND period_start=? ORDER BY id DESC LIMIT 50");$q->execute([$pool['id'],$month]);$entries=$q->fetchAll();
        }
        $summary=['limit_cents'=>(int)($period['limit_cents']??$pool['monthly_limit_cents']??10000),'spent_cents'=>(int)($period['spent_cents']??0),'reserved_cents'=>(int)($period['reserved_cents']??0)];
        $summary['remaining_cents']=max(0,$summary['limit_cents']-$summary['spent_cents']-$summary['reserved_cents']);
        $summary['over_limit_cents']=max(0,$summary['spent_cents']+$summary['reserved_cents']-$summary['limit_cents']);
        $summary['threshold']=self::threshold($summary);
        if($pool) {
            $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN membership_id IS NULL THEN COALESCE(actual_cents,0) ELSE 0 END),0) overhead_cents,
                COALESCE(SUM(CASE WHEN membership_id IS NULL AND status='reserved' THEN estimated_cents ELSE 0 END),0) overhead_reserved_cents,
                SUM(status='submitted') unresolved_count FROM sms_budget_entries WHERE pool_id=? AND period_start=?");$q->execute([$pool['id'],$month]);
            foreach($q->fetch() as $key=>$value)$summary[$key]=(int)$value;
        }
        foreach($members as &$member)$member['threshold']=self::threshold($member);
        $q=$pdo->prepare("SELECT m.id,u.display_name FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id
            JOIN product_identities i ON i.id=m.identity_id JOIN practitioners p ON p.user_id=u.id WHERE m.clinic_id=? AND m.status='active' AND i.status='active' AND u.status='active' AND p.active=1 AND u.user_type='staff' ORDER BY u.display_name,m.id LIMIT 1000");$q->execute([$clinic]);$candidates=$q->fetchAll();
        $q=$pdo->prepare('SELECT s.*,a.membership_id,p.display_name plan_name,p.code,p.scope,p.version plan_version,p.base_monthly_cents,u.display_name member_name FROM billing_subscriptions s JOIN billing_accounts a ON a.id=s.billing_account_id JOIN subscription_plan_versions p ON p.id=s.plan_version_id LEFT JOIN staff_memberships m ON m.id=a.membership_id LEFT JOIN users u ON u.id=m.local_user_id WHERE a.clinic_id=? ORDER BY s.id DESC LIMIT 200');$q->execute([$clinic]);$subscriptions=$q->fetchAll();
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        foreach($subscriptions as &$subscription)$subscription['allows_new_activity']=SubscriptionPolicy::allowsNewActivity($subscription,$now);
        $q=$pdo->prepare('SELECT e.id,e.action,e.entity_id,e.reason,e.created_at,a.display_name administrator FROM billing_admin_events e JOIN application_administrators a ON a.id=e.application_administrator_id WHERE e.clinic_id=? OR e.clinic_id IS NULL ORDER BY e.id DESC LIMIT 30');$q->execute([$clinic]);
        return ['month'=>substr($month,0,7),'pool'=>$pool,'summary'=>$summary,'members'=>$members,'candidates'=>$candidates,'entries'=>$entries,'subscriptions'=>$subscriptions,'events'=>$q->fetchAll()];
    }

    private static function threshold(array $budget): int
    {
        $used=(int)$budget['spent_cents']+(int)$budget['reserved_cents'];$limit=(int)$budget['limit_cents'];
        if($limit===0)return $used>0?100:0;
        foreach([100,80,50] as $threshold)if($used*100 >= $limit*$threshold)return $threshold;
        return 0;
    }
    private static function month(mixed $value,string $timezone): string
    {
        if($value===null)return SubscriptionPolicy::month(new DateTimeImmutable('now'),$timezone);
        if(!is_string($value)||!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$value))self::invalid('month');
        return $value.'-01';
    }
    private static function fields(array $body,array $allowed): void
    {
        if(array_diff(array_keys($body),array_merge($allowed,['request_key','reason'])))self::invalid('request');
    }
    private static function text(array $body,string $key,int $max): string
    {
        $value=$body[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))self::invalid($key);
        return trim($value);
    }
    private static function integer(array $body,string $key,int $min,int $max): int
    {
        $value=$body[$key]??null;if(!is_int($value)||$value<$min||$value>$max)self::invalid($key);return $value;
    }
    private static function invalid(string $field): never { throw new ApiException(422,'validation_error','Check the billing fields.',[$field=>'Enter a valid value.']); }
    private static function conflict(string $code): never { throw new ApiException(409,$code,'Billing settings changed or this action is unavailable. Reload before trying again.'); }
}
