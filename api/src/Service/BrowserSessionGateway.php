<?php
declare(strict_types=1);
namespace Wellness\Service;

use Closure;
use Wellness\{Config,Database,SessionConnection,ClinicContext};
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\{ApiException,BrowserSessionEndpoint,BrowserSessionResponse,Request,SessionCookie};

/** Request integration. Configuration is server-only, scoped to a whole clinic
 * (including aliases), or central administration. No bearer-only fallback.
 */
final class BrowserSessionGateway
{
    private ?array $lease = null;
    private ?string $cookie = null;
    private array $headers = [];
    private readonly BrowserSessionStore $store;
    private readonly LoginProofVerifier $verifier;
    private readonly BrowserSessionService $service;
    private readonly SessionConnection $connection;
    public function __construct(private readonly BrowserSessionPolicy $policy, Database $database,
        private readonly Request $request, Closure $keys, Closure $authority, private readonly ?Closure $clock=null, private readonly ?Closure $prepare=null)
    {
        $pdo=$database->connection();
        if(!$pdo instanceof SessionConnection)throw new ApiException(503,'session_unavailable','Secure sessions are not configured.');
        $this->connection=$pdo;$this->store=new BrowserSessionStore($pdo);$this->verifier=new LoginProofVerifier($policy,$keys);
        $this->service=new BrowserSessionService($this->store,$policy,$this->verifier,function($db,$p,$claims)use($authority){
            $this->barriers();return $authority($p->realm,$claims);
        },$clock);
    }
    public static function enabled(Config $c,?ClinicContext $clinic,bool $central=false): bool
    {return $central ? $c->browserSessionCentralEnabled : $clinic!==null && in_array($clinic->clinicId,$c->browserSessionClinicIds,true);}
    public static function policy(Config $c,string $realm,string $host,?int $clinic): BrowserSessionPolicy
    {
        [$tenant,$api,$spa,$scope]=match($realm){
            'client'=>[$c->customerTenantId,$c->customerApiClientId,$c->customerSpaClientId,'access_as_client'],
            'practitioner'=>[$c->staffExternalTenantId,$c->staffExternalApiClientId,$c->staffExternalSpaClientId,'access_as_staff'],
            default=>[$c->entraTenantId,$c->entraApiClientId,$c->entraSpaClientId,$c->entraRequiredScope],
        };
        try{return new BrowserSessionPolicy($realm,$host,$clinic,$tenant,$api,$spa,$scope);}
        catch(\InvalidArgumentException){throw new ApiException(503,'session_unavailable','Secure sessions are not configured.');}
    }
    private function now():int{return $this->clock ? ($this->clock)() : time();}
    private function barriers():void
    {
        $keys=['global'];if($this->policy->clinicId!==null)$keys[]='clinic:'.$this->policy->clinicId;
        foreach($keys as $key){
            $row=$this->store->query('SELECT version FROM browser_authority_barriers WHERE scope_key=? LOCK IN SHARE MODE',[$key])->fetchColumn();
            if($row===false)throw new ApiException(503,'session_unavailable','Secure sessions are not configured.');
        }
    }
    public function endpoint():BrowserSessionResponse
    {
        if($this->request->bearerToken()!==null){$this->verifier->access($this->request->bearerToken(),$this->now());if($this->prepare)($this->prepare)();}
        return (new BrowserSessionEndpoint($this->policy,$this->service,true))->handle($this->request,hash('sha256',($this->request->headers['host']??'').':'.($_SERVER['REMOTE_ADDR']??'unknown')));
    }
    public function queueHeaders(array $headers):void{$this->headers=$headers;}
    public function guard():void
    {
        if($this->lease!==null)return;
        $r=$this->request;$this->cookie=SessionCookie::read((string)($r->headers['cookie']??''),$this->policy);
        if(!in_array($r->method,['GET','HEAD'],true) || array_key_exists('origin',$r->headers))SessionCookie::origin($this->policy,(string)($r->headers['origin']??''),(string)($r->headers['host']??''));
        $this->verifier->access($r->bearerToken(),$this->now());if($this->prepare)($this->prepare)();
        $this->connection->beginRequest();
        try{
            $this->lease=$this->service->protect($r->bearerToken(),$this->cookie,$r->headers['x-session-csrf']??null,!in_array($r->method,['GET','HEAD'],true),$r->correlationId,
                function($db,$row,$claims,$actorId,BrowserSessionAuthority $binding)use($r){
                    if($this->policy->realm==='client'){
                        if((int)($row['identity_id']??0)!==$binding->actorId)throw new ApiException(401,'session_required','Please sign in again.');
                    }else foreach($binding->columns() as $key=>$value)if(($row[$key]===null?null:(int)$row[$key])!==$value)throw new ApiException(401,'session_required','Please sign in again.');
                    if(!in_array($r->method,['GET','HEAD'],true) && ($this->policy->realm==='central' || str_starts_with($r->path,'/api/v1/admin/'))
                        && BrowserSessionStore::time($row['authenticated_at'])<$this->now()-300)throw new ApiException(401,'session_reauth_required','Sign in again before making administrative changes.');
                    return ['idle_expires_at'=>BrowserSessionStore::time($row['last_activity_at'])+$this->policy->idleSeconds];
                });
            $this->connection->checkpointRequest();
        }catch(\Throwable $error){$this->connection->abortRequest();throw $error;}
    }
    public function clientSession():array
    {
        $this->guard();$claims=$this->verifier->access($this->request->bearerToken(),$this->now());
        $q=$this->store->query('SELECT id FROM customer_identities WHERE identity_hash=?',[$this->policy->principalHash($claims)]);
        $id=$q->fetchColumn();if(!$id)throw new ApiException(401,'session_required','Please sign in again.');
        $metadata=$this->service->me($this->request->bearerToken(),$this->cookie,$this->request->correlationId);
        return ['identity_id'=>(int)$id]+$metadata;
    }
    public function beforeAudit(int $status):void
    {
        // Keep admission/authority locks through disclosure of validation results
        // (which can include authorized duplicate candidates), while undoing work.
        if($status>=400 && $this->connection->requestActive())$this->connection->rollbackEffects();
        if($status===401)$this->headers['X-Session-Ended']='1';
    }
    public function finish(int $status):void
    {
        if($this->connection->requestActive()){
            try{
                $this->verifier->access($this->request->bearerToken(),$this->now());
                $row=$this->store->find($this->policy,hash('sha256',$this->cookie));
                if(!$row || BrowserSessionStore::time($row['absolute_expires_at'])<=$this->now()
                    || BrowserSessionStore::time($row['last_activity_at'])+$this->policy->idleSeconds<=$this->now())throw new ApiException(401,'session_required','Please sign in again.');
                if($row['revoked_at']!==null){$this->headers['Set-Cookie']=SessionCookie::header($this->policy,null);$this->headers['X-Session-Ended']='1';}
                $this->connection->commitRequest();
            }catch(\Throwable $error){$this->connection->abortRequest();throw $error;}
        }
        foreach($this->headers as $key=>$values)foreach((array)$values as $value)header($key.': '.$value,strtolower($key)!=='set-cookie');
    }
    public function abort():void{if($this->connection->requestActive())$this->connection->abortRequest();}
    public function reject():void{$this->headers['X-Session-Ended']='1';}
}
