<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Throwable;

final class CommunicationNoticeWorker
{
    public function __construct(private readonly PDO $pdo,private readonly GraphMailClient $mailer,private readonly string $centralHost='portal.copihue.ca') {}
    private function q(string $sql,array $values=[]): \PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($values);return $q;}
    public function run(int $limit=5): array
    {
        \Wellness\PilotPolicy::fromEnvironment();
        $summary=['sent'=>0,'retry'=>0,'review'=>0,'canceled'=>0];
        $this->q("UPDATE communication_notices SET status='needs_review',lease_token=NULL,leased_until=NULL,last_error='Delivery outcome unknown after expired worker lease.' WHERE status='sending' AND leased_until<UTC_TIMESTAMP()");
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $notice=$this->claim();if(!$notice)break;$lock=new ConsentLock($this->pdo);
            try{
                $lock->acquire((int)$notice['clinic_id']);$message=$this->prepare($notice);
                if(!$message){$this->finish($notice,'canceled');$summary['canceled']++;continue;}
                $this->q("UPDATE communication_notices SET recipient_address=? WHERE id=? AND status='sending' AND lease_token=?",[$message['recipient'],$notice['id'],$notice['lease_token']]);
                $route=(new ClinicMailRouter($this->pdo,$this->mailer))->route((int)$notice['clinic_id']);
                $route['mailer']->send($message['recipient'],$message['subject'],$message['body'],null,$route['reply_to']);
                $this->finish($notice,'sent');$summary['sent']++;
            }catch(\Wellness\PilotDeliveryBlocked $e){
                $this->finish($notice,'canceled',0,$e->getMessage());$summary['canceled']++;
            }catch(MailSendException $e){
                $retry=$e->retryable&&!$e->ambiguous&&(int)$notice['attempt_count']<5;
                $this->finish($notice,$retry?'failed':'needs_review',$retry?max($e->retryAfterSeconds,NotificationWorker::backoff((int)$notice['attempt_count'])):0);
                $summary[$retry?'retry':'review']++;
            }catch(Throwable){$this->finish($notice,'needs_review');$summary['review']++;}
            finally{$lock->release();}
        }
        return $summary;
    }
    private function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try{
            $notice=$this->q("SELECT * FROM communication_notices WHERE status IN ('queued','failed') AND attempt_count<5 AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
            if(!$notice){$this->pdo->commit();return null;}$token=bin2hex(random_bytes(16));
            $this->q("UPDATE communication_notices SET status='sending',attempt_count=attempt_count+1,lease_token=?,leased_until=UTC_TIMESTAMP()+INTERVAL 5 MINUTE,next_attempt_at=NULL WHERE id=?",[$token,$notice['id']]);$this->pdo->commit();
            $notice['lease_token']=$token;$notice['attempt_count']=(int)$notice['attempt_count']+1;return $notice;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function prepare(array $n): ?array
    {
        $s=$this->q("SELECT s.*,c.name clinic_name FROM clinic_communications s JOIN clinics c ON c.id=s.clinic_id AND c.status='active' WHERE s.clinic_id=? AND s.email_enabled=1",[$n['clinic_id']])->fetch();if(!$s)return null;
        $portal=ClinicPortalUrl::resolve($this->pdo,(int)$n['clinic_id'],'',true);$origin='https://'.parse_url($portal,PHP_URL_HOST);
        $central=\Wellness\ClinicContext::normalizeHost($this->centralHost);if($central==='')throw new \RuntimeException('Central portal is not configured.');
        $operator=$n['recipient_kind']==='operator';$recipient=$operator?$s['alert_email']:null;$url=$operator?'https://'.$central.'/admin/':$origin.'/staff/external';
        if($n['kind']==='application'){
            $app=$this->q('SELECT a.* FROM practitioner_applications a JOIN product_identities i ON i.id=a.identity_id AND i.status=\'active\' WHERE a.id=? AND a.clinic_id=?',[$n['application_id'],$n['clinic_id']])->fetch();
            if(!$app || (int)$app['version']!==(int)$n['application_version'])return null;
            if(!$operator){$recipient=$app['email'];$url=$origin.'/staff/apply';}
            $text=$operator?'A practitioner application has been updated. Sign in to review it.':'There is an update to your clinic application. Sign in to review it.';
            $french=$operator?'Une candidature de praticien a été mise à jour. Connectez-vous pour l’examiner.':'Votre candidature à la clinique a été mise à jour. Connectez-vous pour la consulter.';
        }elseif(in_array($n['kind'],['trial_ending','trial_expired'],true)){
            $sub=$this->q("SELECT s.* FROM billing_subscriptions s JOIN billing_accounts b ON b.id=s.billing_account_id WHERE s.id=? AND b.clinic_id=? AND b.membership_id=? AND s.status='trialing'",[$n['subscription_id'],$n['clinic_id'],$n['membership_id']])->fetch();
            if(!$sub || $sub['trial_ends_at']!==$n['trial_ends_at'])return null;
            $seconds=strtotime($sub['trial_ends_at'].' UTC')-time();$days=(int)$n['threshold'];
            if($days===0?($seconds>0||$seconds<=-86400):($seconds<=($days-1)*86400||$seconds>$days*86400))return null;
            if(!$operator)$recipient=$this->member((int)$n['clinic_id'],(int)$n['membership_id']);
            $text=$days===0?'A recorded membership free trial has ended. Sign in to review membership options.':'A recorded membership free trial ends at '.$sub['trial_ends_at'].' UTC. Sign in to review membership options.';
            $text.=' No payment has been collected by this notice. There is no automatic paid conversion.';
            $french=$days===0?'Une période d’essai d’adhésion enregistrée est terminée. Connectez-vous pour consulter les options d’adhésion.':'Une période d’essai d’adhésion enregistrée se termine le '.$sub['trial_ends_at'].' UTC. Connectez-vous pour consulter les options d’adhésion.';
            $french.=' Cet avis ne prélève aucun paiement et ne déclenche aucune conversion payante.';
        }else{
            $pool=$this->q('SELECT * FROM sms_budget_pools WHERE id=? AND clinic_id=?',[$n['pool_id'],$n['clinic_id']])->fetch();
            if(!$pool || $n['period_start']!==SubscriptionPolicy::month(new \DateTimeImmutable('now',new \DateTimeZone('UTC')),$pool['timezone']))return null;
            $table=$n['membership_id']===null?'sms_budget_periods':'sms_budget_member_periods';$params=[$n['pool_id'],$n['period_start']];if($n['membership_id']!==null)$params[]=$n['membership_id'];
            $budget=$this->q("SELECT * FROM $table WHERE pool_id=? AND period_start=?".($n['membership_id']===null?'':' AND membership_id=?'),$params)->fetch();
            if(!$budget || !CommunicationNotices::reached($budget,(int)$n['threshold']))return null;
            if(!$operator)$recipient=$this->member((int)$n['clinic_id'],(int)$n['membership_id']);
            $text='An SMS allowance has reached at least '.$n['threshold'].'% including committed costs. Review the current budget. This notice does not authorize additional spending.';
            $french='Une allocation SMS atteint au moins '.$n['threshold'].' %, y compris les coûts engagés. Consultez le budget actuel. Cet avis n’autorise aucune dépense supplémentaire.';
        }
        if(!is_string($recipient)||!filter_var($recipient,FILTER_VALIDATE_EMAIL))return null;
        return ['recipient'=>$recipient,'subject'=>$s['clinic_name'].' — account notification / avis de compte','body'=>$s['clinic_name']."\n\n".$text."\n\n".$french."\n\n".$url."\n"];
    }
    private function member(int $clinic,int $membership): ?string
    {
        $q=$this->q("SELECT u.email FROM staff_memberships m JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id AND u.status='active' JOIN product_identities i ON i.id=m.identity_id AND i.status='active' WHERE m.id=? AND m.clinic_id=? AND m.status='active'",[$membership,$clinic]);return $q->fetchColumn()?:null;
    }
    private function finish(array $n,string $status,int $retry=0,?string $policyReason=null): void
    {
        $next=$retry?gmdate('Y-m-d H:i:s',time()+$retry):null;
        $q=$this->q("UPDATE communication_notices SET status=?,lease_token=NULL,leased_until=NULL,next_attempt_at=?,last_error=?,sent_at=CASE WHEN ?='sent' THEN UTC_TIMESTAMP() ELSE sent_at END WHERE id=? AND status='sending' AND lease_token=?",[$status,$next,$policyReason??($status==='needs_review'?'Delivery requires operator review.':null),$status,$n['id'],$n['lease_token']]);
        if($q->rowCount()!==1)throw new \RuntimeException('Communication notice lease was lost.');
    }
}
