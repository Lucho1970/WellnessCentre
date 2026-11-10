<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\{AuthContext,ApplicationAdminContext};
use Wellness\Http\ApiException;
use Wellness\SessionConnection;

/** Transactional outbox for three account/recruitment messages, never appointment content.
 * Only the dedicated worker performs transport. No request hook can escape its final audit/commit.
 */
final class CommitSafeEmail
{
    public function __construct(private readonly PDO $db) {}

    public static function enabled(): bool
    {
        $enabled=filter_var(self::env('COMMIT_SAFE_EMAIL_ENABLED'),FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);
        if ($enabled===null) throw new ApiException(503,'mail_unavailable','Queued email configuration is invalid.');
        return $enabled;
    }

    private static function env(string $key): string
    { return trim((string)($_ENV[$key] ?? getenv($key) ?: '')); }

    private static function key(): string
    {
        $key = self::env('COMMIT_SAFE_EMAIL_KEY');
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $key)) throw new ApiException(503,'mail_unavailable','Queued email is not configured.');
        return hex2bin($key);
    }

    public static function requireConfigured(): void
    {
        self::key();
        if (!self::enabled() || !filter_var(self::env('MAIL_ENABLED'), FILTER_VALIDATE_BOOL))
            throw new ApiException(503,'mail_unavailable','Email delivery is not enabled.');
    }

    private function query(string $sql,array $params=[]): \PDOStatement
    { $q=$this->db->prepare($sql);$q->execute($params);return $q; }

    private function barriers(int $clinic): array
    {
        // Global first, matching the session gateway. Shared locks serialize with authority triggers.
        $global=$this->query("SELECT version FROM browser_authority_barriers WHERE scope_key='global' LOCK IN SHARE MODE")->fetchColumn();
        $local=$this->query('SELECT version FROM browser_authority_barriers WHERE scope_key=? LOCK IN SHARE MODE',['clinic:'.$clinic])->fetchColumn();
        if ($global===false || $local===false) throw new \RuntimeException('Email authority barrier is unavailable.');
        return [(string)$global,(string)$local];
    }

    /** Must be called inside the SAME transaction as the domain mutation and its required audit. */
    public function enqueue(string $kind,int $clinic,int $resource,AuthContext|ApplicationAdminContext $actor,string $cid,array $payload,string $expires): string
    {
        self::requireConfigured();
        if (!$this->db->inTransaction()) throw new \LogicException('Email enqueue requires a domain transaction.');
        [$global,$local]=$this->barriers($clinic);
        $row=['reference'=>bin2hex(random_bytes(24)),'clinic_id'=>$clinic,'kind'=>$kind,'resource_id'=>$resource,
            'actor_user_id'=>$actor instanceof AuthContext?$actor->userId:null,
            'application_admin_id'=>$actor instanceof ApplicationAdminContext?$actor->id:null,
            'correlation_id'=>$cid,'authority_global'=>$global,'authority_clinic'=>$local,'expires_at'=>$expires];
        $payload['sender']=self::env('MAIL_FROM_ADDRESS');
        $payload['transport']=hash('sha256',self::env('MAIL_TENANT_ID').'|'.self::env('MAIL_CLIENT_ID'));
        if ($actor instanceof ApplicationAdminContext) $payload['principal']=['issuer'=>$actor->identity->issuer,'subject'=>$actor->identity->subject,'adapter'=>$actor->identity->adapter];
        else $payload['principal']=['external'=>$actor->externalObjectId,'roles'=>$actor->roles,'permissions'=>$actor->permissions,'binding'=>$this->binding($actor)];
        $row['actor_realm']=$actor instanceof ApplicationAdminContext?'central':($payload['principal']['binding']['adapter']==='entra-external-staff'?'practitioner':'workforce');
        // Also validates current persisted authority and resource ownership before saving any secret.
        $this->current($row,$payload);
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,self::aad($row));
        if ($cipher===false) throw new \RuntimeException('Email encryption failed.');
        $row['ciphertext']=base64_encode($iv.$tag.$cipher);
        $this->query('INSERT INTO commit_safe_emails(actor_realm,reference,clinic_id,kind,resource_id,actor_user_id,application_admin_id,correlation_id,authority_global,authority_clinic,expires_at,ciphertext) VALUES(:actor_realm,:reference,:clinic_id,:kind,:resource_id,:actor_user_id,:application_admin_id,:correlation_id,:authority_global,:authority_clinic,:expires_at,:ciphertext)',$row);
        $row['id']=(int)$this->db->lastInsertId();$this->audit($row,'queued');
        return $row['reference'];
    }

    private static function aad(array $row): string
    {
        $parts=[];foreach(['actor_realm','reference','clinic_id','kind','resource_id','actor_user_id','application_admin_id','correlation_id','authority_global','authority_clinic','expires_at'] as $key)
            $parts[$key]=$row[$key]===null?null:(string)$row[$key];
        return json_encode($parts,JSON_THROW_ON_ERROR);
    }

    private function decrypt(array $row): array
    {
        $raw=base64_decode((string)$row['ciphertext'],true);
        if ($raw===false || strlen($raw)<29) throw new \RuntimeException('Email envelope is invalid.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),self::aad($row));
        if ($plain===false) throw new \RuntimeException('Email envelope cannot be authenticated.');
        $payload=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new \RuntimeException('Email payload is invalid.');
        return $payload;
    }

    private function binding(AuthContext $actor): array
    {
        $legacy=$this->query("SELECT tenant_id,provider_subject FROM identity_links WHERE user_id=? AND provider='microsoft' AND provider_subject=?",[$actor->userId,$actor->externalObjectId])->fetchAll();
        $members=$this->query("SELECT i.id,i.adapter,i.issuer,i.subject FROM product_identities i JOIN staff_memberships m ON m.identity_id=i.id WHERE m.local_user_id=? AND m.clinic_id=? AND m.status='active' AND i.status='active' AND i.subject=?",[$actor->userId,$actor->clinicId,$actor->externalObjectId])->fetchAll();
        if (count($legacy)===1 && $members===[]) return ['source'=>'legacy','adapter'=>'entra-workforce','issuer'=>'https://login.microsoftonline.com/'.$legacy[0]['tenant_id'].'/v2.0','subject'=>$legacy[0]['provider_subject']];
        if ($legacy===[] && count($members)===1) return ['source'=>'membership','adapter'=>$members[0]['adapter'],'issuer'=>$members[0]['issuer'],'subject'=>$members[0]['subject'],'identity_id'=>(int)$members[0]['id']];
        throw new ApiException(409,'email_stale','An unambiguous current staff identity is required.');
    }

    private function pilot(array $principal,int $clinic): void
    {
        $policy=\Wellness\PilotPolicy::fromEnvironment();
        if ($principal['adapter']==='entra-external-staff' && $policy->enabled && filter_var(self::env('PRACTITIONER_PILOT_ACCESS_ENABLED'),FILTER_VALIDATE_BOOL)) {
            if ($policy->hasExternalApprovals() || !$this->query("SELECT 1 FROM practitioner_pilot_access WHERE clinic_id=? AND identity_id=? AND status='approved' FOR UPDATE",[$clinic,$principal['identity_id']])->fetchColumn())
                throw new ApiException(409,'email_stale','Practitioner pilot access changed.');
        } else $policy->assertIdentity($principal['adapter'],$principal['issuer'],$principal['subject']);
    }

    /** Current source rows are locked until provider submission finishes. Never trust a queued address alone. */
    private function current(array $job,array $payload): void
    {
        $clinic=(int)$job['clinic_id'];$resource=(int)$job['resource_id'];
        if (!$this->query("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE",[$clinic])->fetchColumn())
            throw new ApiException(409,'email_stale','Clinic is unavailable.');
        if ($job['actor_user_id']!==null) {
            $id=(int)$job['actor_user_id'];
            if (!$this->query("SELECT id FROM users WHERE id=? AND clinic_id=? AND user_type='staff' AND status='active' FOR UPDATE",[$id,$clinic])->fetchColumn())
                throw new ApiException(409,'email_stale','Staff access changed.');
            // Initial actor is supplied only by an authenticated route. Both persisted roles and the
            // original effective roles are required; a later grant cannot broaden this queued request.
            $roles=$this->query('SELECT r.code FROM user_roles u JOIN roles r ON r.id=u.role_id WHERE u.user_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN);
            $roles=array_intersect($roles,$payload['principal']['roles']);
            if ($roles===[]) throw new ApiException(409,'email_stale','Staff roles changed.');
            $actor=new AuthContext($id,$clinic,$payload['principal']['external'],'','','staff',$payload['principal']['roles'],$payload['principal']['permissions']);
            $binding=$this->binding($actor);
            if ($binding!==$payload['principal']['binding']) throw new ApiException(409,'email_stale','Staff identity changed.');
            $this->pilot($binding,$clinic);
            if ($job['kind']==='enquiry_retry' && !array_intersect($roles,['super_admin','clinic_admin'])) throw new ApiException(409,'email_stale','Administrator access changed.');
            if ($job['kind']==='client_invitation' && !array_intersect($roles,['super_admin','clinic_admin','reception'])) {
                $permission=$this->query("SELECT 1 FROM user_permissions up JOIN permissions p ON p.id=up.permission_id WHERE up.user_id=? AND p.code='add_clients'",[$id])->fetchColumn();
                $assigned=$this->query("SELECT 1 FROM practitioners p JOIN client_care_assignments care ON care.practitioner_id=p.id AND care.clinic_id=? AND care.client_id=? AND care.active=1 WHERE p.user_id=? AND p.active=1 LIMIT 1",[$clinic,$payload['client_id'],$id])->fetchColumn();
                if (!in_array('practitioner',$roles,true) || !in_array('add_clients',$payload['principal']['permissions'],true) || !$permission || !$assigned)
                    throw new ApiException(409,'email_stale','Invitation authority changed.');
            }
        } elseif ($job['application_admin_id']!==null && $job['kind']==='enquiry_retry') {
            if ($payload['principal']['adapter']!=='entra-workforce' || !$this->query("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE",[$job['application_admin_id'],$payload['principal']['issuer'],$payload['principal']['subject']])->fetchColumn())
                throw new ApiException(409,'email_stale','Administrator access changed.');
            $this->pilot($payload['principal'],$clinic);
        } else throw new ApiException(409,'email_stale','Email authority is unavailable.');

        if ($job['kind']==='staff_verification') {
            $r=$this->query('SELECT personal_email,email_code_hash,personal_email_verified_at,email_code_expires_at,email_code_attempts FROM staff_notification_preferences WHERE user_id=? FOR UPDATE',[$resource])->fetch();
            if ($resource!==(int)$job['actor_user_id'] || !$r || $r['personal_email_verified_at']!==null || (int)$r['email_code_attempts']>=5 || strtotime($r['email_code_expires_at'].' UTC')<=time()
                || !hash_equals((string)$r['email_code_hash'],$payload['code_hash']) || strcasecmp((string)$r['personal_email'],$payload['recipient'])!==0)
                throw new ApiException(409,'email_stale','Verification request changed.');
        } elseif ($job['kind']==='client_invitation') {
            $directives=$this->query('SELECT scope,target_staff_id,restricted FROM client_consent_directives WHERE clinic_id=? AND client_id=? FOR UPDATE',[$clinic,$payload['client_id']])->fetchAll();
            foreach ($directives as $directive) {
                if ((bool)$directive['restricted'] && ($directive['scope']==='patient_communications' || in_array((int)$directive['target_staff_id'],[0,(int)$job['actor_user_id']],true)))
                    throw new ApiException(409,'email_stale','Recipient restrictions changed.');
            }
            $r=$this->query("SELECT i.client_id,i.token_hash,i.revoked_at,i.consumed_at,i.expires_at,u.email,u.status FROM client_link_invitations i JOIN users u ON u.id=i.client_id AND u.clinic_id=i.clinic_id AND u.user_type='client' WHERE i.id=? AND i.clinic_id=? FOR UPDATE",[$resource,$clinic])->fetch();
            if (!$r || $r['status']!=='active' || $r['revoked_at']!==null || $r['consumed_at']!==null || strtotime($r['expires_at'].' UTC')<=time() || (int)$r['client_id']!==$payload['client_id']
                || !hash_equals((string)$r['token_hash'],hash('sha256',$payload['token'])) || strcasecmp($r['email'],$payload['recipient'])!==0
                || $this->query('SELECT 1 FROM customer_client_links WHERE client_id=?',[$r['client_id']])->fetchColumn())
                throw new ApiException(409,'email_stale','Invitation or recipient restrictions changed.');
        } elseif ($job['kind']==='enquiry_retry') {
            $r=$this->query('SELECT e.reference,e.revision,e.notification_status,s.recipient_email,s.enabled,s.revision settings_revision FROM practitioner_enquiries e JOIN practitioner_enquiry_settings s ON s.clinic_id=e.clinic_id WHERE e.id=? AND e.clinic_id=? FOR UPDATE',[$resource,$clinic])->fetch();
            if (!$r || !$r['enabled'] || !in_array($r['notification_status'],['queued','pending','failed','disabled'],true) || (int)$r['revision']!==$payload['revision'] || (int)$r['settings_revision']!==$payload['settings_revision'] || $r['reference']!==$payload['enquiry_reference'] || strcasecmp((string)$r['recipient_email'],$payload['recipient'])!==0)
                throw new ApiException(409,'email_stale','Enquiry notification changed.');
        } else throw new \RuntimeException('Unknown queued email kind.');
        if (!filter_var($payload['recipient'],FILTER_VALIDATE_EMAIL) || $payload['sender']!==self::env('MAIL_FROM_ADDRESS')
            || $payload['transport']!==hash('sha256',self::env('MAIL_TENANT_ID').'|'.self::env('MAIL_CLIENT_ID')))
            throw new ApiException(409,'email_stale','Email routing changed.');
        \Wellness\PilotPolicy::fromEnvironment()->assertMail($payload['recipient'],$payload['sender'],null,null);
    }

    private function audit(array $job,string $outcome): void
    {
        $this->query("INSERT INTO audit_logs(clinic_id,actor_user_id,correlation_id,action,entity_type,entity_id,outcome,metadata) VALUES(?,?,?,'account_email.delivery','commit_safe_email',?,?,?)",
            [$job['clinic_id'],$job['actor_user_id'],$job['correlation_id'],$job['id'],match($outcome){'canceled'=>'denied','failed','needs_review'=>'failure',default=>'success'},json_encode(['kind'=>$job['kind'],'state'=>$outcome,'application_admin_id'=>$job['application_admin_id'],'realm'=>$job['actor_realm']],JSON_THROW_ON_ERROR)]);
    }

    private function finish(array $job,string $status,string $reason): void
    {
        $this->query('UPDATE commit_safe_emails SET status=?,reason_code=?,ciphertext=NULL,lease_until=NULL,completed_at=UTC_TIMESTAMP() WHERE id=?',[$status,$reason,$job['id']]);
        if ($job['kind']==='enquiry_retry') {
            $state=match($status){'accepted'=>'accepted','failed'=>'failed',default=>'needs_review'};
            $this->query("UPDATE practitioner_enquiries SET notification_status=? WHERE id=? AND clinic_id=? AND notification_status='queued'",[$state,$job['resource_id'],$job['clinic_id']]);
        }
        $this->audit($job,$status);
    }

    /** Dedicated worker only; one durable claim, no automatic retransmission after any attempt. */
    public function run(GraphMailClient $mailer,int $limit=20): array
    {
        if ($this->db->inTransaction() || ($this->db instanceof SessionConnection && $this->db->requestActive())) throw new \LogicException('Email worker cannot run inside an originating transaction.');
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Email batch must be between 1 and 100.');
        self::requireConfigured();
        $counts=['accepted'=>0,'failed'=>0,'canceled'=>0,'needs_review'=>0];
        // Candidate scan is bounded. Each item is re-read under its clinic lock and SQL row lock.
        $ids=$this->query("SELECT id,clinic_id FROM commit_safe_emails WHERE status='queued' OR (status='sending' AND lease_until<UTC_TIMESTAMP()) ORDER BY id LIMIT ".$limit)->fetchAll();
        foreach ($ids as $candidate) {
            $lock=new ConsentLock($this->db);$lock->acquire((int)$candidate['clinic_id']);
            try {
                $this->db->beginTransaction();
                $versions=$this->barriers((int)$candidate['clinic_id']);
                $job=$this->query('SELECT * FROM commit_safe_emails WHERE id=? FOR UPDATE',[$candidate['id']])->fetch();
                if (!$job || !in_array($job['status'],['queued','sending'],true)) { $this->db->rollBack();continue; }
                if ($job['status']==='sending') {
                    if (strtotime($job['lease_until'].' UTC')>=time()) { $this->db->rollBack();continue; }
                    $this->finish($job,'needs_review','interrupted_submission');$this->db->commit();$counts['needs_review']++;continue;
                }
                try {
                    $payload=$this->decrypt($job);
                    if ($versions!==[(string)$job['authority_global'],(string)$job['authority_clinic']] || strtotime($job['expires_at'].' UTC')<=time())
                        throw new ApiException(409,'email_stale','Queued request expired or authority changed.');
                    $this->current($job,$payload);
                    if (strcasecmp($mailer->senderAddress(),$payload['sender'])!==0) throw new ApiException(409,'email_stale','Sender changed.');
                } catch (Throwable $error) {
                    // DB/authority outages must roll back, not misclassify a transient check as permission.
                    if ($error instanceof \PDOException) throw $error;
                    $state=($error instanceof ApiException && in_array($error->status,[403,409],true)) || $error instanceof \Wellness\PilotDeliveryBlocked?'canceled':'needs_review';
                    $this->finish($job,$state,$state==='canceled'?'source_changed':'envelope_unavailable');$this->db->commit();$counts[$state]++;continue;
                }
                $this->query("UPDATE commit_safe_emails SET status='sending',lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=?",[$job['id']]);
                $this->audit($job,'submission_intent');
                $this->db->commit(); // Durable claim and required audit BEFORE transport, even if PHP stops.

                $this->db->beginTransaction();
                $versions=$this->barriers((int)$job['clinic_id']);
                $claimed=$this->query('SELECT * FROM commit_safe_emails WHERE id=? FOR UPDATE',[$job['id']])->fetch();
                if (!$claimed || $claimed['status']!=='sending' || $claimed['reference']!==$job['reference']) {
                    $this->db->rollBack();continue;
                }
                if (self::aad($claimed)!==self::aad($job) || !hash_equals((string)$job['ciphertext'],(string)$claimed['ciphertext'])) {
                    $this->finish($claimed,'needs_review','envelope_changed');$this->db->commit();$counts['needs_review']++;continue;
                }
                if ($versions!==[(string)$job['authority_global'],(string)$job['authority_clinic']]) {
                    $this->finish($job,'canceled','authority_changed');$this->db->commit();$counts['canceled']++;continue;
                }
                try { $this->current($job,$payload); }
                catch (ApiException|\Wellness\PilotDeliveryBlocked) { $this->finish($job,'canceled','source_changed');$this->db->commit();$counts['canceled']++;continue; }
                // A worker-owned transaction holds current source/authority locks across this single
                // submission. The originating domain transaction has already physically committed.
                try {
                    $mailer->send($payload['recipient'],$payload['subject'],$payload['text']);
                    $state='accepted';$reason='provider_accepted';
                } catch (MailSendException $error) {
                    $state=$error->ambiguous || $error->retryable?'needs_review':'failed';$reason='provider_not_confirmed';
                } catch (Throwable) { $state='needs_review';$reason='provider_not_confirmed'; }
                $this->finish($job,$state,$reason);$this->db->commit();$counts[$state]++;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                // If claim committed, it remains 'sending'; lease recovery requires review, never resend.
                throw $error;
            } finally { $lock->release(); }
        }
        return $counts;
    }
}
