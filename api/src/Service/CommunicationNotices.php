<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Throwable;

/** Transactional outbox recipes. No clinical content or external transport here. */
final class CommunicationNotices
{
    public function __construct(private readonly PDO $pdo) {}
    private function q(string $sql,array $values=[]): \PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($values);return $q;}
    public function application(int $clinic,int $application,int $event): void
    {
        if(!$this->q('SELECT 1 FROM clinic_communications WHERE clinic_id=? AND email_enabled=1',[$clinic])->fetchColumn())return;
        $version=(int)$this->q('SELECT version FROM practitioner_applications WHERE id=? AND clinic_id=?',[$application,$clinic])->fetchColumn();
        foreach(['applicant','operator'] as $recipient)$this->enqueue($clinic,'application:'.$event.':'.$recipient,'application',$recipient,['application_id'=>$application,'application_version'=>$version]);
    }
    public function scan(): void
    {
        // Exclude already materialized recipes before LIMIT so large trial cohorts make progress.
        $due="CASE WHEN s.trial_ends_at>UTC_TIMESTAMP()+INTERVAL 29 DAY AND s.trial_ends_at<=UTC_TIMESTAMP()+INTERVAL 30 DAY THEN 30
          WHEN s.trial_ends_at>UTC_TIMESTAMP()+INTERVAL 13 DAY AND s.trial_ends_at<=UTC_TIMESTAMP()+INTERVAL 14 DAY THEN 14
          WHEN s.trial_ends_at>UTC_TIMESTAMP()+INTERVAL 6 DAY AND s.trial_ends_at<=UTC_TIMESTAMP()+INTERVAL 7 DAY THEN 7
          WHEN s.trial_ends_at>UTC_TIMESTAMP() AND s.trial_ends_at<=UTC_TIMESTAMP()+INTERVAL 1 DAY THEN 1
          WHEN s.trial_ends_at<=UTC_TIMESTAMP() AND s.trial_ends_at>UTC_TIMESTAMP()-INTERVAL 1 DAY THEN 0 ELSE NULL END";
        $rows=$this->q("SELECT d.* FROM (SELECT s.id,s.trial_ends_at,b.clinic_id,b.membership_id,$due reminder FROM billing_subscriptions s JOIN billing_accounts b ON b.id=s.billing_account_id AND b.membership_id IS NOT NULL JOIN clinic_communications cc ON cc.clinic_id=b.clinic_id AND cc.email_enabled=1 JOIN clinics c ON c.id=b.clinic_id AND c.status='active' WHERE s.status='trialing') d WHERE d.reminder IS NOT NULL AND NOT EXISTS(SELECT 1 FROM communication_notices n WHERE n.subscription_id=d.id AND n.trial_ends_at=d.trial_ends_at AND n.threshold=d.reminder AND n.recipient_kind='member') ORDER BY d.id LIMIT 500")->fetchAll();
        foreach($rows as $row){
            $this->atomic(function()use($row):void{
                $kind=(int)$row['reminder']===0?'trial_expired':'trial_ending';
                foreach(['member','operator'] as $recipient)$this->enqueue((int)$row['clinic_id'],'trial:'.$row['id'].':'.substr(hash('sha256',$row['trial_ends_at']),0,16).':'.$row['reminder'].':'.$recipient,$kind,$recipient,['subscription_id'=>$row['id'],'membership_id'=>$row['membership_id'],'threshold'=>$row['reminder'],'trial_ends_at'=>$row['trial_ends_at']]);
            });
        }
        $pools=$this->q("SELECT p.*,b.clinic_id,b.timezone FROM sms_budget_periods p JOIN sms_budget_pools b ON b.id=p.pool_id JOIN clinic_communications cc ON cc.clinic_id=b.clinic_id AND cc.email_enabled=1 JOIN clinics c ON c.id=b.clinic_id AND c.status='active' WHERE b.status='active' AND p.period_start>=UTC_DATE()-INTERVAL 40 DAY")->fetchAll();
        foreach($pools as $pool){
            if($pool['period_start']!==SubscriptionPolicy::month(new \DateTimeImmutable('now',new \DateTimeZone('UTC')),$pool['timezone']))continue;
            $budgets=[$pool+['membership_id'=>null]];
            foreach($this->q('SELECT * FROM sms_budget_member_periods WHERE pool_id=? AND period_start=?',[$pool['pool_id'],$pool['period_start']])->fetchAll() as $member)$budgets[]=$member;
            foreach($budgets as $budget)foreach([50,80,100] as $threshold){
                if(!self::reached($budget,$threshold))continue;
                foreach($budget['membership_id']===null?['operator']:['member','operator'] as $recipient){
                    $reference='budget:'.$pool['pool_id'].':'.$pool['period_start'].':'.($budget['membership_id']??'pool').':'.$threshold.':'.$recipient;
                    $this->enqueue((int)$pool['clinic_id'],$reference,'budget',$recipient,['pool_id'=>$pool['pool_id'],'period_start'=>$pool['period_start'],'membership_id'=>$budget['membership_id'],'threshold'=>$threshold],true);
                }
            }
        }
    }
    public static function reached(array $budget,int $threshold): bool
    {return (int)$budget['limit_cents']===0?((int)$budget['spent_cents']+(int)$budget['reserved_cents']>0):((int)$budget['spent_cents']+(int)$budget['reserved_cents'])*100>=(int)$budget['limit_cents']*$threshold;}

    private function enqueue(int $clinic,string $reference,string $kind,string $recipient,array $fields,bool $rearm=false): void
    {
        $this->atomic(function()use($clinic,$reference,$kind,$recipient,$fields,$rearm):void{
            $columns=array_keys($fields);$q=$this->q('INSERT INTO communication_notices(clinic_id,reference,kind,recipient_kind,'.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($fields)+4,'?')).') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',array_merge([$clinic,$reference,$kind,$recipient],array_values($fields)));
            $changed=$q->rowCount()===1;
            if(!$changed&&$rearm)$changed=$this->q("UPDATE communication_notices SET status='queued',next_attempt_at=NULL WHERE clinic_id=? AND reference=? AND status='canceled' AND attempt_count<5",[$clinic,$reference])->rowCount()===1;
            if($changed)$this->q("INSERT INTO communication_events(clinic_id,action,reason,details,correlation_id) VALUES(?,'notice.queue','Operational notice queued.',?,'scheduler')",[$clinic,json_encode(['kind'=>$kind,'recipient_kind'=>$recipient]+$fields,JSON_THROW_ON_ERROR)]);
        });
    }
    private function atomic(callable $work): void
    {
        $own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();
        try{$work();if($own)$this->pdo->commit();}catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
