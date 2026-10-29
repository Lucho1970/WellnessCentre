<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Wellness\Http\ApiException;

/** Called while the notification worker holds the clinic consent lock. */
final class SmsDeliveryGuard
{
    public function __construct(private readonly PDO $pdo) {}
    private function q(string $sql,array $values=[]): \PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($values);return $q;}

    /** A successful result authorizes exactly one provider attempt, never another on replay. */
    public function authorize(array $event,string $message): array
    {
        $old=$this->q('SELECT d.*,e.status ledger_status FROM sms_notification_deliveries d JOIN sms_budget_entries e ON e.pool_id=d.pool_id AND e.reference=d.reference WHERE d.notification_event_id=?',[$event['id']])->fetch();
        if($old && $old['ledger_status']!=='reserved')throw new ApiException(409,'sms_already_submitted','SMS already submitted or reconciled; do not resend.');
        $settings=$this->settings((int)$event['clinic_id']);
        $member=$this->q("SELECT m.id,p.id pool_id FROM staff_memberships m JOIN sms_budget_pools p ON p.clinic_id=m.clinic_id WHERE m.clinic_id=? AND m.local_user_id=? AND m.status='active'",[$event['clinic_id'],$event['recipient_user_id']])->fetch();
        if(!$member)self::deny('sms_not_funded');
        $estimate=SmsCostEstimate::estimate($message,(int)$settings['cad_micros_per_segment'],(int)$settings['fixed_cad_micros']);
        $reference='notice-'.hash('sha256',(string)$event['id']);$hash=hash('sha256',$message);$ledger=new SmsBudgetLedger($this->pdo);
        $bind=function(PDO $pdo,array $pool)use($event,$member,$estimate,$reference,$settings,$hash):void{
            if((int)$pool['clinic_id']!==(int)$event['clinic_id'])self::deny('sms_clinic_mismatch');
            $existing=$this->q('SELECT * FROM sms_notification_deliveries WHERE notification_event_id=? FOR UPDATE',[$event['id']])->fetch();
            if($existing){if($existing['message_hash']!==$hash || (int)$existing['settings_revision']!==(int)$settings['revision'] || (int)$existing['membership_id']!==(int)$member['id'])self::deny('sms_delivery_changed');return;}
            $this->q('INSERT INTO sms_notification_deliveries(notification_event_id,pool_id,membership_id,reference,settings_revision,segments,estimated_cents,message_hash,sender_did) VALUES(?,?,?,?,?,?,?,?,?)',[$event['id'],$pool['id'],$member['id'],$reference,$settings['revision'],$estimate['segments'],$estimate['estimated_cents'],$hash,$settings['sms_from_did']]);
        };
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $ledger->reserve((int)$member['pool_id'],(int)$member['id'],$reference,$estimate['estimated_cents'],$now,$bind);
        try {
            $claimed=$ledger->claim((int)$member['pool_id'],(int)$member['id'],$reference,new DateTimeImmutable('now',new DateTimeZone('UTC')),function(PDO $pdo,array $pool)use($event,$member,$settings,$reference):void{
                $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $period=$this->q('SELECT period_start FROM sms_budget_entries WHERE pool_id=? AND reference=?',[$pool['id'],$reference])->fetchColumn();
                if($period!==SubscriptionPolicy::month($now,$pool['timezone'])||strtotime($event['scheduled_at'].' UTC')<time()-3600)self::deny('sms_reservation_expired');
                if(!$this->q("SELECT 1 FROM sms_budget_entries WHERE pool_id=? AND period_start=? AND membership_id IS NULL AND status IN ('reserved','settled') LIMIT 1",[$pool['id'],$period])->fetchColumn())self::deny('sms_overhead_not_reviewed');
                $current=$this->settings((int)$event['clinic_id']);if((int)$current['revision']!==(int)$settings['revision'])self::deny('sms_sender_changed');
                if($this->q('SELECT 1 FROM sms_suppressions WHERE clinic_id=? AND number_hash=? AND active=1',[$event['clinic_id'],hash('sha256',(string)$event['recipient_address'])])->fetchColumn())self::deny('sms_opted_out');
                $sub=$this->q("SELECT s.*,v.features,v.scope,v.currency FROM billing_accounts b JOIN billing_subscriptions s ON s.billing_account_id=b.id AND s.status<>'canceled' JOIN subscription_plan_versions v ON v.id=s.plan_version_id WHERE b.clinic_id=? AND b.membership_id=? AND b.currency='CAD' FOR UPDATE",[$event['clinic_id'],$member['id']])->fetch();
                if(!$sub || $sub['scope']!=='practitioner' || $sub['currency']!=='CAD' || !SubscriptionPolicy::hasFeature($sub,json_decode($sub['features'],true,512,JSON_THROW_ON_ERROR),'sms_reminders',$now))self::deny('sms_not_entitled');
                if(!$this->q("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code='practitioner'",[$event['recipient_user_id']])->fetchColumn())self::deny('sms_recipient_changed');
                if(!(new ConsentAccess($pdo))->notificationAllowed($event) || !StaffNotificationRecipient::allowed($pdo,$event))self::deny('sms_recipient_changed');
            });
            if(!$claimed)throw new ApiException(409,'sms_already_submitted','SMS already submitted or reconciled; do not resend.');
        } catch(\Throwable $e) {
            // claim() rolls back on a failed preflight: no provider attempt was authorized.
            if($e instanceof ApiException && $e->errorCode!=='sms_already_submitted')$ledger->release((int)$member['pool_id'],(int)$member['id'],$reference);
            throw $e;
        }
        return ['sender_did'=>$settings['sms_from_did'],'reference'=>$reference]+$estimate;
    }
    public function accepted(int $event,?string $providerId): void
    { $this->q('UPDATE sms_notification_deliveries SET provider_message_id=? WHERE notification_event_id=?',[$providerId,$event]); }

    private function settings(int $clinic): array
    {
        $s=$this->q("SELECT s.* FROM clinic_communications s JOIN clinics c ON c.id=s.clinic_id AND c.status='active' WHERE s.clinic_id=?",[$clinic])->fetch();
        if(!$s || !$s['sms_enabled'] || !preg_match('/^[2-9][0-9]{9}$/D',$s['sms_from_did']) || !(int)$s['cad_micros_per_segment']
            || !$s['tariff_valid_until'] || strtotime($s['tariff_valid_until'].' UTC')<=time() || !$s['opt_out_checked_at'] || strtotime($s['opt_out_checked_at'].' UTC')<time()-86400)
            self::deny('sms_configuration_not_ready');
        return $s;
    }
    private static function deny(string $code): never {throw new ApiException(409,$code,'SMS held by the communications spending policy. Eligible email notices remain queued independently.');}
}
