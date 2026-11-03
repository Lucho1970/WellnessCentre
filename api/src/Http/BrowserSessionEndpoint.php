<?php
declare(strict_types=1);
namespace Wellness\Http;

use Wellness\Service\{BrowserSessionGrant,BrowserSessionPolicy,BrowserSessionService};

/** Explicit opt-in transport; the API gateway separately controls activation. */
final class BrowserSessionEndpoint
{
    public function __construct(private readonly BrowserSessionPolicy $policy, private readonly BrowserSessionService $service, private readonly bool $enabled = false) {}
    public function handle(Request $request, string $serverAbuseHash): BrowserSessionResponse
    {
        if (!$this->enabled) throw new ApiException(404,'not_found','Route not found.');
        $prefix = match($this->policy->realm) { 'workforce'=>'staff','practitioner'=>'practitioner','central'=>'application','client'=>'customer/auth' };
        $prefix = '/api/v1/' . $prefix . ($this->policy->realm === 'client' ? '/' : '/session/');
        $action = str_starts_with($request->path,$prefix) ? substr($request->path,strlen($prefix)) : '';
        if ($this->policy->realm === 'client') {
            // Preserve the existing customer auth/session route contract. Do not
            // introduce a second bootstrap URL when this adapter is adopted.
            $action = $action === 'session' ? 'start' : ($action === 'start' ? '' : $action);
        }
        if (!in_array($action,['challenge','start','me','activity','logout','action-challenge','action-proof','revoke-all'],true)
            || $request->method !== ($action === 'me' ? 'GET' : 'POST')) throw new ApiException(404,'not_found','Route not found.');
        $host = $request->headers['host'] ?? '';
        if (!is_string($host) || $host !== $this->policy->host) throw new ApiException(404,'not_found','Route not found.');
        if ($request->method !== 'GET' || array_key_exists('origin',$request->headers)) SessionCookie::origin($this->policy,self::header($request,'origin'),$host);
        if ($request->query !== [] || strlen(json_encode($request->body,JSON_THROW_ON_ERROR)) > 65536) throw new ApiException(400,'invalid_session_request','Invalid session request.');
        $allowed = in_array($action,['start','action-proof'],true) ? ['id_token'] : [];
        if (array_diff(array_keys($request->body),$allowed) !== []) throw new ApiException(400,'invalid_session_request','Invalid session request.');
        $proof = $request->body['id_token'] ?? null;
        if ($allowed !== [] && (!is_string($proof) || strlen($proof) > 16384 || $proof === '')) throw new ApiException(400,'invalid_session_request','Invalid session request.');
        $cookieHeader = self::header($request,'cookie');
        $cookie = SessionCookie::read($cookieHeader,$this->policy);
        $challenge = SessionCookie::read($cookieHeader,$this->policy,true);
        $csrf = self::header($request,'x-session-csrf');
        $bearer = $request->bearerToken(); $cid = $request->correlationId;
        $result = match($action) {
            'challenge'=>$this->service->challenge($serverAbuseHash),
            'start'=>$this->service->start($bearer,$proof,$challenge,$cid,$cookie),
            'me'=>$this->service->me($bearer,$cookie,$cid),
            'activity'=>$this->service->activity($bearer,$cookie,$csrf,$cid),
            'logout'=>$this->logout($cookie,$csrf,$cid),
            'action-challenge'=>$this->service->actionChallenge($bearer,$cookie,$csrf,$cid),
            'action-proof'=>$this->service->actionProof($bearer,$cookie,$csrf,$proof,$challenge,$cid),
            'revoke-all'=>$this->revoke($bearer,$cookie,$csrf,self::header($request,'x-action-proof'),$cid),
        };
        $headers = ['Cache-Control'=>'no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer'];
        if ($result instanceof BrowserSessionGrant) $headers['Set-Cookie'] = SessionCookie::header($this->policy,$result->cookieValue(),$action !== 'start');
        if (in_array($action,['start','action-proof'],true)) $headers['Set-Cookie'] = array_merge((array)($headers['Set-Cookie'] ?? []),[SessionCookie::header($this->policy,null,true)]);
        if (in_array($action,['logout','revoke-all'],true)) $headers['Set-Cookie'] = SessionCookie::header($this->policy,null);
        return new BrowserSessionResponse($result instanceof BrowserSessionGrant ? $result->metadata : $result,$headers);
    }
    private static function header(Request $r, string $name): string
    {
        $value = $r->headers[$name] ?? '';
        if (!is_string($value)) throw new ApiException(400,'invalid_session_request','Invalid session request.');
        return $value;
    }
    private function logout(?string $cookie, string $csrf, string $cid): array { $this->service->logout($cookie,$csrf,$cid); return ['signed_out'=>true]; }
    private function revoke(?string $bearer, ?string $cookie, string $csrf, string $proof, string $cid): array
    { $this->service->revokeAll($bearer,$cookie,$csrf,$proof,$cid); return ['signed_out'=>true]; }
}
