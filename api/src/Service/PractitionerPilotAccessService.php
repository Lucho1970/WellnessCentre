<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\{ApplicationAdminContext,VerifiedIdentity};
use Wellness\{Config,Database,PilotPolicy};
use Wellness\Http\ApiException;

/** Central identity administration only; never supplies a clinical membership or role. */
final class PractitionerPilotAccessService
{
    private ?PractitionerPilotLock $executionLock = null;
    private ?string $executionScope = null;
    private const COLUMNS = 'p.id,p.clinic_id,p.label,p.status,p.version,p.created_at,p.updated_at,p.approved_at,p.revoked_at,i.adapter,i.issuer,i.subject,i.status identity_status';
    private const LIST_COLUMNS = 'p.id,p.clinic_id,p.label,p.status,p.version,p.updated_at,i.status identity_status';

    public function __construct(private readonly Database $database, private readonly Config $config) {}

    private function query(string $sql, array $values = []): \PDOStatement
    {
        $query = $this->database->connection()->prepare($sql);
        $query->execute($values);
        return $query;
    }

    private function enabled(): void
    {
        $policy = PilotPolicy::fromEnvironment();
        if (!$this->config->practitionerPilotAccessEnabled || !$this->config->applicationAdminEnabled
            || !$this->config->clinicManagementEnabled || !$policy->enabled) {
            throw new ApiException(503,'pilot_administration_unavailable','Practitioner pilot administration is not available.');
        }
        if ($policy->hasExternalApprovals()) {
            throw new ApiException(503,'pilot_administration_unavailable','Migrate private external practitioner approvals before enabling managed pilot access.');
        }
    }

    /** Called only with an identity returned by the complete external JWT verifier. */
    public function assertAllowed(VerifiedIdentity $identity, int $clinic): void
    {
        $this->enabled();
        if ($identity->adapter !== 'entra-external-staff') self::denied();
        $scope=hash('sha256',json_encode([$clinic,$identity->adapter,$identity->issuer,$identity->subject],JSON_THROW_ON_ERROR));
        if($this->executionScope!==null && !hash_equals($this->executionScope,$scope))throw new \LogicException('Pilot execution cannot change identity or clinic.');
        $this->executionScope=$scope;
        $this->executionLock ??= new PractitionerPilotLock($this->database->connection(),$clinic,$identity);
        $this->executionLock->acquire();
        try {
            $approved = $this->query("SELECT p.id FROM practitioner_pilot_access p
            JOIN product_identities i ON i.id=p.identity_id AND i.status='active'
            JOIN clinics c ON c.id=p.clinic_id AND c.status='active'
            WHERE p.clinic_id=? AND p.status='approved' AND i.adapter=? AND i.issuer=? AND i.subject=?",
            [$clinic,$identity->adapter,$identity->issuer,$identity->subject])->fetchColumn();
            if (!$approved) self::denied();
        } catch(Throwable $error) { $this->release(); throw $error; }
    }

    public function release(): void
    { $this->executionLock?->release(); $this->executionLock=null; $this->executionScope=null; }

    private static function denied(): never
    {
        throw new ApiException(403,'pilot_access_denied','This pilot is limited to approved test accounts.');
    }

    private function authorize(ApplicationAdminContext $actor, int $clinic): void
    {
        if ($actor->identity->adapter !== 'entra-workforce' || !$this->query(
            "SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE",
            [$actor->id,$actor->identity->issuer,$actor->identity->subject])->fetchColumn()) {
            throw new ApiException(403,'application_admin_required','Application administrator access is required.');
        }
        if (!$this->query("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE",[$clinic])->fetchColumn()) {
            throw new ApiException(404,'clinic_not_found','Clinic not found.');
        }
    }

    private function transaction(ApplicationAdminContext $actor, int $clinic, callable $work): array
    {
        $this->enabled();
        $pdo = $this->database->connection();
        if ($pdo->inTransaction()) throw new \LogicException('Pilot administration owns its transaction.');
        try {
            $pdo->beginTransaction();
            $this->authorize($actor,$clinic);
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof \PDOException && (int)($error->errorInfo[1]??0) === 1062) {
                throw new ApiException(409,'pilot_access_exists','This practitioner already has a pilot access entry for this clinic.');
            }
            throw $error;
        }
    }

    /** Reference encoding helps an operator locate an account; it is not proof of ownership. */
    private function reference(string $reference): VerifiedIdentity
    {
        $compact = preg_replace('/\s+/', '', $reference);
        if (!is_string($compact) || !preg_match('/^WPI1([A-Za-z0-9_-]+)$/D',$compact,$match)) self::invalid('reference');
        $raw = base64_decode(strtr($match[1],'-_','+/'),true);
        try { $tuple = is_string($raw) ? json_decode($raw,true,8,JSON_THROW_ON_ERROR) : null; }
        catch (\JsonException) { self::invalid('reference'); }
        if (!is_array($tuple) || count($tuple)!==3 || array_diff(array_keys($tuple),['adapter','issuer','subject'])
            || ($tuple['adapter']??null)!=='entra-external-staff'
            || ($tuple['issuer']??null)!=="https://{$this->config->staffExternalTenantId}.ciamlogin.com/{$this->config->staffExternalTenantId}/v2.0"
            || !is_string($tuple['subject']??null) || !preg_match('/^[\x21-\x7e]{1,255}$/D',$tuple['subject'])) self::invalid('reference');
        return new VerifiedIdentity($tuple['adapter'],$tuple['issuer'],$tuple['subject'],$this->config->staffExternalTenantId);
    }

    private function row(int $clinic, int $id, bool $lock = false): array
    {
        $row = $this->query('SELECT '.self::COLUMNS.' FROM practitioner_pilot_access p JOIN product_identities i ON i.id=p.identity_id WHERE p.clinic_id=? AND p.id=?'.($lock?' FOR UPDATE':''),[$clinic,$id])->fetch();
        if (!$row) throw new ApiException(404,'pilot_access_not_found','Pilot access entry not found.');
        return $row;
    }

    public function handle(ApplicationAdminContext $actor,int $clinic,string $method,string $tail,array $query,array $body,string $correlation): array
    {
        if ($method==='GET' && $tail==='') return $this->transaction($actor,$clinic,function()use($clinic,$query):array {
            foreach(array_keys($query) as $key)if(!in_array($key,['before_id','status'],true))self::invalid($key);
            $values=[$clinic];$filter='';
            if(isset($query['before_id'])){
                if(!is_string($query['before_id']) || !preg_match('/^[1-9][0-9]{0,17}$/D',$query['before_id']))self::invalid('before_id');
                $filter.=' AND p.id<?';$values[]=(int)$query['before_id'];
            }
            if(isset($query['status'])){
                if(!is_string($query['status']) || !in_array($query['status'],['pending','approved','revoked'],true))self::invalid('status');
                $filter.=' AND p.status=?';$values[]=$query['status'];
            }
            $items=$this->query('SELECT '.self::LIST_COLUMNS.' FROM practitioner_pilot_access p JOIN product_identities i ON i.id=p.identity_id WHERE p.clinic_id=?'.$filter.' ORDER BY p.id DESC LIMIT 51',$values)->fetchAll();
            $more=count($items)>50;if($more)array_pop($items);
            return ['items'=>$items,'next_before_id'=>$more?(int)$items[count($items)-1]['id']:null];
        });
        if ($method==='GET' && preg_match('#^/([1-9][0-9]{0,17})$#D',$tail,$match)) {
            return $this->transaction($actor,$clinic,fn():array=>[
                'entry'=>$this->row($clinic,(int)$match[1]),
                'events'=>$this->query('SELECT e.id,e.action,e.reason,e.version,e.created_at,a.display_name operator_name FROM practitioner_pilot_events e JOIN application_administrators a ON a.id=e.application_administrator_id WHERE e.clinic_id=? AND e.access_id=? ORDER BY e.id DESC LIMIT 50',[$clinic,(int)$match[1]])->fetchAll(),
            ]);
        }
        if ($method==='POST' && $tail==='') {
            self::fields($body,['request_key','reference','label','reason']);
            $identity=$this->reference(self::text($body,'reference',2048));
            $label=self::text($body,'label',160);
            return $this->write($actor,$clinic,$identity,'record',$body,$correlation,function()use($clinic,$identity,$label,$actor):array {
                $id=$this->query('SELECT id,status FROM product_identities WHERE adapter=? AND issuer=? AND subject=? FOR UPDATE',[$identity->adapter,$identity->issuer,$identity->subject])->fetch();
                if($id && $id['status']!=='active')throw new ApiException(403,'identity_inactive','This identity is inactive.');
                if(!$id){$this->query('INSERT INTO product_identities(adapter,issuer,subject) VALUES(?,?,?)',[$identity->adapter,$identity->issuer,$identity->subject]);$identityId=(int)$this->database->connection()->lastInsertId();}
                else $identityId=(int)$id['id'];
                $this->query('INSERT INTO practitioner_pilot_access(clinic_id,identity_id,label,created_by) VALUES(?,?,?,?)',[$clinic,$identityId,$label,$actor->id]);
                return ['id'=>(int)$this->database->connection()->lastInsertId(),'version'=>1,'status'=>'pending'];
            });
        }
        if ($method==='POST' && preg_match('#^/([1-9][0-9]{0,17})/(approve|revoke)$#D',$tail,$match)) {
            self::fields($body,['request_key','version','reason','identity_verified']);
            // Locator only. Immutable tuple and scope are checked again while locked.
            $this->enabled();
            $locator=$this->transaction($actor,$clinic,fn():array=>$this->row($clinic,(int)$match[1]));
            $identity=new VerifiedIdentity($locator['adapter'],$locator['issuer'],$locator['subject'],$this->config->staffExternalTenantId);
            $action=$match[2];$id=(int)$match[1];
            if($action==='approve' && ($body['identity_verified']??false)!==true)self::invalid('identity_verified');
            if(isset($body['identity_verified']) && !is_bool($body['identity_verified']))self::invalid('identity_verified');
            return $this->write($actor,$clinic,$identity,$action,$body,$correlation,function()use($actor,$clinic,$id,$action,$body):array {
                $entry=$this->row($clinic,$id,true);
                if($entry['identity_status']!=='active' && $action==='approve')throw new ApiException(403,'identity_inactive','This identity is inactive.');
                if(self::version($body)!==(int)$entry['version'])self::stale();
                $status=$action==='approve'?'approved':'revoked';
                if($entry['status']===$status)self::stale();
                $this->query("UPDATE practitioner_pilot_access SET status=?,version=version+1,decided_by=?,approved_at=CASE WHEN ?='approved' THEN UTC_TIMESTAMP() ELSE approved_at END,revoked_at=CASE WHEN ?='revoked' THEN UTC_TIMESTAMP() ELSE revoked_at END WHERE id=? AND clinic_id=?",[$status,$actor->id,$status,$status,$id,$clinic]);
                return ['id'=>$id,'version'=>(int)$entry['version']+1,'status'=>$status];
            });
        }
        throw new ApiException(404,'not_found','Route not found.');
    }

    private function write(ApplicationAdminContext $actor,int $clinic,VerifiedIdentity $identity,string $action,array $body,string $correlation,callable $work): array
    {
        $this->enabled();$key=self::text($body,'request_key',36);$reason=self::text($body,'reason',500);
        if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$key))self::invalid('request_key');
        $fingerprint=hash('sha256',json_encode([$action,$identity->adapter,$identity->issuer,$identity->subject,$body],JSON_THROW_ON_ERROR));
        $lock=new PractitionerPilotLock($this->database->connection(),$clinic,$identity);$lock->acquire();
        try {
            return $this->transaction($actor,$clinic,function()use($actor,$clinic,$key,$fingerprint,$action,$reason,$correlation,$work):array {
                $old=$this->query('SELECT fingerprint,response FROM practitioner_pilot_requests WHERE clinic_id=? AND application_administrator_id=? AND reference=?',[$clinic,$actor->id,$key])->fetch();
                if($old){
                    if(!hash_equals($old['fingerprint'],$fingerprint))throw new ApiException(409,'pilot_request_changed','The pilot access request changed. Refresh and try again.');
                    $response=json_decode($old['response'],true,8,JSON_THROW_ON_ERROR);$current=$this->row($clinic,(int)$response['id'],true);
                    if((int)$current['version']!==$response['version'] || $current['status']!==$response['status'])self::stale();
                    return $response;
                }
                $result=$work();
                $this->query('INSERT INTO practitioner_pilot_events(clinic_id,access_id,application_administrator_id,action,reason,version,correlation_id) VALUES(?,?,?,?,?,?,?)',[$clinic,$result['id'],$actor->id,$action,$reason,$result['version'],substr($correlation,0,128)]);
                $this->query('INSERT INTO practitioner_pilot_requests(clinic_id,application_administrator_id,reference,fingerprint,response) VALUES(?,?,?,?,?)',[$clinic,$actor->id,$key,$fingerprint,json_encode($result,JSON_THROW_ON_ERROR)]);
                return $result;
            });
        } finally { $lock->release(); }
    }

    private static function fields(array $body,array $allowed): void
    { foreach(array_keys($body) as $key)if(!in_array($key,$allowed,true))self::invalid((string)$key); }

    private static function text(array $body,string $key,int $max): string
    {
        $value=$body[$key]??null;
        if(!is_string($value) || trim($value)==='' || mb_strlen($value)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))self::invalid($key);
        return trim($value);
    }

    private static function version(array $body): int
    { if(!is_int($body['version']??null) || $body['version']<1)self::invalid('version');return $body['version']; }

    private static function invalid(string $field): never
    { throw new ApiException(422,'validation_failed','Check the pilot access details.',[$field=>'Check this value.']); }

    private static function stale(): never
    { throw new ApiException(409,'pilot_access_version_changed','Pilot access changed. Refresh and review the current entry.'); }
}
