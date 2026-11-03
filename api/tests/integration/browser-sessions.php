<?php
declare(strict_types=1);
require dirname(__DIR__).'/helpers/browser-session.php';
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\{ApiException,BrowserSessionEndpoint,Request,SessionCookie};
use Wellness\Service\{BrowserSessionPolicy,BrowserSessionService,BrowserSessionStore};
use Wellness\{Config};
use Wellness\Service\CustomerOnboarding;

if (getenv('RECURRING_TEST_ALLOW_CREATE') !== 'true') throw new RuntimeException('Explicit disposable database setup required.');
$port = (int)getenv('RECURRING_TEST_PORT');
if ($port < 1024 || $port > 65535) throw new RuntimeException('Explicit disposable port required.');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('RECURRING_TEST_USER') ?: 'root',getenv('RECURRING_TEST_PASSWORD') ?: '',$options);
$name = 'wellness_sessions_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo->exec("USE `$name`");
$pdo->exec("SET time_zone='+00:00'");
$pdo->exec("CREATE TABLE clinics(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(15) NOT NULL DEFAULT 'active') ENGINE=InnoDB; CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; INSERT INTO clinics(id) VALUES(1),(2); INSERT INTO users VALUES(1)");
$migration = fn(string $file) => $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/migrations/'.$file));
$migration('005_customer_onboarding.sql'); $migration('039_separate_clinic_portals.sql'); $migration('049_access_audit_events.sql');
$pdo->exec("INSERT INTO customer_identities(id,identity_hash,issuer,subject,created_at) VALUES(1,REPEAT('f',64),'https://legacy.example.test','legacy',UTC_TIMESTAMP()); INSERT INTO customer_sessions(token_hash,identity_id,authenticated_at,created_at,last_activity_at,expires_at,clinic_id) VALUES(REPEAT('e',64),1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),1)");
$migration('059_browser_sessions.sql');
SessionTest::check($pdo->query('SELECT scope_hash FROM customer_sessions')->fetchColumn()===null,'Legacy session not backfilled');
try { $migration('059_browser_sessions.sql'); throw new RuntimeException('Apply-once migration unexpectedly repeated'); }
catch (PDOException $e) { SessionTest::check((int)$e->errorInfo[1]===1050,'Rerun stops at existing table without changing records'); }
SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM customer_sessions')->fetchColumn()===1,'Rerun preserves legacy history');
$pdo->exec('CREATE TABLE session_test_authority(scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,active INT NOT NULL,effects INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
$f=new SessionTestTokens(); $store=new BrowserSessionStore($pdo); $cid='session-test-correlation';
$authority=static function(PDO $db,BrowserSessionPolicy $p,array $claims): int {
    $q=$db->prepare('SELECT active FROM session_test_authority WHERE scope_hash=? FOR UPDATE'); $q->execute([$p->scopeHash()]);
    if ((int)$q->fetchColumn()!==1) throw new ApiException(403,'membership_inactive','Access is no longer active.');
    return 1;
};
$make=static fn(BrowserSessionPolicy $p,?Closure $auth=null)=>new BrowserSessionService($store,$p,new LoginProofVerifier($p,fn(bool $refresh)=>$f->jwks),$auth ?? $authority,fn()=>$f->now);
$begin=static function(BrowserSessionService $s,BrowserSessionPolicy $p,?string $old=null)use($f,$cid):array {
    $challenge=$s->challenge(hash('sha256',random_bytes(32)));
    $api=$f->sign($f->claims($p)); $proof=$f->sign($f->claims($p,$challenge->metadata['nonce'],true));
    return [$s->start($api,$proof,$challenge->cookieValue(),$cid,$old),$api,$proof,$challenge];
};
foreach (['workforce','practitioner','client','central'] as $realm) {
    $p=$f->policy($realm); $s=$make($p);
    $pdo->prepare('INSERT INTO session_test_authority(scope_hash,active) VALUES(?,1)')->execute([$p->scopeHash()]);
    [$grant,$api,$proof,$challenge]=$begin($s,$p); $cookie=$grant->cookieValue(); $csrf=$grant->metadata['csrf'];
    SessionTest::check($s->me($api,$cookie,$cid)['csrf']===$csrf,'Current metadata/CSRF can be fetched by same principal');
    $prefix=match($realm){'client'=>'customer/auth','workforce'=>'staff/session','central'=>'application/session',default=>'practitioner/session'};
    $endpoint=new BrowserSessionEndpoint($p,$s,true);
    $headers=['host'=>$p->host,'origin'=>'https://'.$p->host,'authorization'=>'Bearer '.$api,'cookie'=>SessionCookie::name($p).'='.$cookie];
    $me=new Request('GET','/api/v1/'.$prefix.'/me',[],$headers,[],$cid);
    SessionTest::check($endpoint->handle($me,hash('sha256','realm-http'))->body['csrf']===$csrf,'Realm endpoint metadata contract');
    if($realm==='client') {
        $c=$s->challenge(hash('sha256','client-http-bootstrap'));
        $newProof=$f->sign($f->claims($p,$c->metadata['nonce'],true));
        $startHeaders=array_replace($headers,['cookie'=>SessionCookie::name($p,true).'='.$c->cookieValue()]);
        $start=new Request('POST','/api/v1/customer/auth/session',[],$startHeaders,['id_token'=>$newProof],$cid);
        SessionTest::check(isset($endpoint->handle($start,hash('sha256','realm-http'))->body['csrf']),'Existing customer auth/session bootstrap path retained');
        $alias=new Request('POST','/api/v1/customer/auth/start',[],$startHeaders,['id_token'=>$newProof],$cid);
        SessionTest::deny(fn()=>$endpoint->handle($alias,hash('sha256','realm-http')),404,'No competing customer bootstrap URL');
    }
    SessionTest::check(!str_contains(json_encode($grant,JSON_THROW_ON_ERROR),$cookie),'No session credential in JSON');
    SessionTest::deny(fn()=>$s->start($api,$proof,$challenge->cookieValue(),$cid),401,'One-use login proof');
    SessionTest::deny(fn()=>$s->me($api,null,$cid),401,'Valid JWT alone never sufficient');
    SessionTest::deny(fn()=>$s->activity($api,$cookie,'wrong',$cid),403,'CSRF required');
    $foreign=$f->claims($p); $foreign['sub']='other-subject'; $foreign['oid']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    SessionTest::deny(fn()=>$s->me($f->sign($foreign),$cookie,$cid),401,'Another principal cannot pair shared cookie');
    $other=$f->policy($realm,'other.example.test',2); $otherService=$make($other);
    SessionTest::deny(fn()=>$otherService->me($api,$cookie,$cid),401,'Host/clinic separation');
    SessionTest::deny(fn()=>$make($f->policy($realm,version:2))->me($api,$cookie,$cid),401,'Policy upgrade rejects old rows');
    $pdo->prepare('UPDATE session_test_authority SET active=0 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    SessionTest::deny(fn()=>$s->me($api,$cookie,$cid),403,'Current authority overrides stale session');
    $pdo->prepare('UPDATE session_test_authority SET active=1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    // Exact idle boundary with independent JWT renewal; no wall-clock sleeps.
    $f->now += $p->idleSeconds-1; SessionTest::check($s->me($api,$cookie,$cid)['idle_expires_at']===$f->now+1,'Me does not slide idle');
    $f->now++; SessionTest::deny(fn()=>$s->activity($f->sign($f->claims($p)),$cookie,$csrf,$cid),401,'Exact idle expiry cannot revive');
    $s->logout($cookie,$csrf,$cid); SessionTest::check(true,'Own logout works after app/token expiry without JWT');
    [$grant,$api]=$begin($s,$p); $cookie=$grant->cookieValue(); $csrf=$grant->metadata['csrf'];
    [$rotated,$newApi]=$begin($s,$p,$cookie);
    SessionTest::deny(fn()=>$s->me($api,$cookie,$cid),401,'Same-browser rotation retires old secret');
    SessionTest::check($s->me($api,$rotated->cookieValue(),$cid)['csrf']===$rotated->metadata['csrf'],'Other same-account tab recovers current CSRF');
    $cookie=$rotated->cookieValue(); $csrf=$rotated->metadata['csrf']; $api=$newApi;
    $action=$s->actionChallenge($api,$cookie,$csrf,$cid);
    $fresh=$f->sign($f->claims($p,$action->metadata['nonce'],true));
    $pdo->prepare('UPDATE browser_auth_challenges SET action_name=? WHERE secret_hash=?')->execute(['unapproved.action',hash('sha256',$action->cookieValue())]);
    SessionTest::deny(fn()=>$s->actionProof($api,$cookie,$csrf,$fresh,$action->cookieValue(),$cid),401,'Stored action purpose cannot widen fresh proof');
    $pdo->prepare('UPDATE browser_auth_challenges SET action_name=? WHERE secret_hash=?')->execute(['session.revoke_all',hash('sha256',$action->cookieValue())]);
    $actionGrant=$s->actionProof($api,$cookie,$csrf,$fresh,$action->cookieValue(),$cid);
    SessionTest::deny(fn()=>$s->actionProof($api,$cookie,$csrf,$fresh,$action->cookieValue(),$cid),401,'Action challenge single use');
    SessionTest::deny(fn()=>$s->revokeAll($api,$cookie,$csrf,str_repeat('a',64),$cid),401,'No unproven revocation');
    $s->revokeAll($api,$cookie,$csrf,$actionGrant['action_proof'],$cid);
    SessionTest::deny(fn()=>$s->me($api,$cookie,$cid),401,'Epoch revocation denies retained session');
    SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM browser_action_proofs WHERE consumed_at IS NOT NULL')->fetchColumn()>0,'Action consumption committed with revocation');
    [$grant,$api]=$begin($s,$p); $cookie=$grant->cookieValue(); $csrf=$grant->metadata['csrf'];
    $absolute=$grant->metadata['absolute_expires_at'];
    while ($f->now+800 < $absolute) { $f->now+=800; $s->activity($api,$cookie,$csrf,$cid); }
    $f->now=$absolute; SessionTest::deny(fn()=>$s->activity($f->sign($f->claims($p)),$cookie,$csrf,$cid),401,'Activity never slides absolute deadline');
    if ($realm==='client') {
        SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM browser_sessions WHERE realm=\'client\'')->fetchColumn()===0,'No duplicate client store');
        $q=$pdo->prepare('SELECT identity_hash FROM customer_identities WHERE issuer=? AND subject=?'); $claims=$f->claims($p); $q->execute([$claims['iss'],$claims['sub']]);
        SessionTest::check($q->fetchColumn()===$p->principalHash($claims),'Existing customer canonical hash preserved');
        $config=new Config('test',false,'synthetic',[],'127.0.0.1',$port,$name,'root','','','','',300,customerOnboardingEnabled:true,customerClinicId:1,clinicManagementEnabled:true);
        $legacy=new CustomerOnboarding($pdo,$config,1);
        SessionTest::deny(fn()=>$legacy->session($f->claims($p),$cookie),401,'Legacy header transport rejects new policy rows');
        SessionTest::deny(fn()=>$legacy->logout($cookie,$cid),401,'Legacy logout cannot bypass new origin/CSRF/session guard');
    }
}

$p=$f->policy(); $s=$make($p); [$grant,$api]=$begin($s,$p); $cookie=$grant->cookieValue(); $csrf=$grant->metadata['csrf'];
SessionTest::deny(fn()=>$s->me('invalid-token',$cookie,'invalid-identity'),401,'Invalid bearer is denied and audited');
$denial=$pdo->query("SELECT actor_type,actor_id,metadata FROM access_audit_events WHERE correlation_id='invalid-identity' ORDER BY id DESC LIMIT 1")->fetch();
SessionTest::check($denial['actor_type']==='anonymous' && $denial['actor_id']===null && json_decode($denial['metadata'],true)['principal_hash']===null,'Invalid token never creates an authenticated audit actor');
// A delayed challenge, old action, or action tied to another session cannot be used.
$c=$s->challenge(hash('sha256','expired-challenge'));$proof=$f->sign($f->claims($p,$c->metadata['nonce'],true));
$f->now+=600;SessionTest::deny(fn()=>$s->start($api,$proof,$c->cookieValue(),$cid),401,'Exact challenge expiry');
[$grant,$api]=$begin($s,$p);$cookie=$grant->cookieValue();$csrf=$grant->metadata['csrf'];
$c=$s->actionChallenge($api,$cookie,$csrf,$cid);$proof=$s->actionProof($api,$cookie,$csrf,$f->sign($f->claims($p,$c->metadata['nonce'],true)),$c->cookieValue(),$cid);
[$other,$otherApi]=$begin($s,$p);
SessionTest::deny(fn()=>$s->revokeAll($otherApi,$other->cookieValue(),$other->metadata['csrf'],$proof['action_proof'],$cid),401,'Fresh action cannot cross sessions');
$f->now+=300;SessionTest::deny(fn()=>$s->revokeAll($api,$cookie,$csrf,$proof['action_proof'],$cid),401,'Exact action-proof expiry');
[$grant,$api]=$begin($s,$p);$cookie=$grant->cookieValue();$csrf=$grant->metadata['csrf'];
// An effect and its required audit share a transaction. A trigger simulates audit outage.
$pdo->exec("CREATE TRIGGER session_audit_failure BEFORE INSERT ON access_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit unavailable'");
$count=(int)$pdo->query('SELECT COUNT(*) FROM browser_sessions')->fetchColumn();
$c=$s->challenge(hash('sha256','audit-bootstrap'));
SessionTest::deny(fn()=>$s->start($api,$f->sign($f->claims($p,$c->metadata['nonce'],true)),$c->cookieValue(),$cid),503,'Audit outage prevents bootstrap');
SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM browser_sessions')->fetchColumn()===$count,'No session committed without audit');
SessionTest::check($pdo->query("SELECT consumed_at FROM browser_auth_challenges WHERE secret_hash='".hash('sha256',$c->cookieValue())."'")->fetchColumn()===null,'Audit rollback restores challenge');
$effect=static function(PDO $db)use($p):void { $db->prepare('UPDATE session_test_authority SET effects=effects+1 WHERE scope_hash=?')->execute([$p->scopeHash()]); };
SessionTest::deny(fn()=>$s->protect($api,$cookie,$csrf,true,$cid,$effect),503,'Audit outage blocks protected write');
SessionTest::check((int)$pdo->query("SELECT effects FROM session_test_authority WHERE scope_hash='".$p->scopeHash()."'")->fetchColumn()===0,'Business write rolled back with audit');
SessionTest::deny(fn()=>$s->me($api,null,$cid),503,'Required denial audit outage fails closed');
$pdo->exec('DROP TRIGGER session_audit_failure');
SessionTest::check(!$pdo->inTransaction(),'Failures release transactions');
// Time can elapse during authority lookup or work. Recheck before effect and commit.
$slow=$make($p,static function(PDO $db,BrowserSessionPolicy $policy,array $claims)use($f,$authority):int { $actor=$authority($db,$policy,$claims); $f->now+=900; return $actor; });
SessionTest::deny(fn()=>$slow->protect($api,$cookie,$csrf,true,$cid,$effect),401,'Idle expiry while authority waits blocks effect');
[$grant,$api]=$begin($s,$p); $cookie=$grant->cookieValue(); $csrf=$grant->metadata['csrf'];
SessionTest::deny(fn()=>$s->protect($api,$cookie,$csrf,true,$cid,static function(PDO $db)use($effect,$f):void { $effect($db); $f->now+=900; }),401,'Expiry during effect rolls back before commit');
SessionTest::check((int)$pdo->query("SELECT effects FROM session_test_authority WHERE scope_hash='".$p->scopeHash()."'")->fetchColumn()===0,'Interrupted effect never committed');
// Anonymous challenge dispatch remains bounded, even though denied attempts persist.
$abuse=hash('sha256','same-synthetic-source'); for($i=0;$i<20;$i++)$s->challenge($abuse);
SessionTest::deny(fn()=>$s->challenge($abuse),429,'Challenge allowance enforced');
// Explicit endpoint composition is disabled by default and accepts no clinic selector.
$endpoint=new BrowserSessionEndpoint($p,$s);
$request=new Request('POST','/api/v1/practitioner/session/challenge',[],['host'=>$p->host,'origin'=>'https://'.$p->host],[],$cid);
SessionTest::deny(fn()=>$endpoint->handle($request,hash('sha256','http-fixture')),404,'Default-off endpoint');
$endpoint=new BrowserSessionEndpoint($p,$s,true);
$reply=$endpoint->handle($request,hash('sha256','http-fixture'));
SessionTest::check($reply->headers()['Cache-Control']==='no-store' && !str_contains(json_encode($reply,JSON_THROW_ON_ERROR),'Set-Cookie'),'HTTP reply separates private cookie from JSON');
$bad=new Request('POST',$request->path,[],['host'=>$p->host,'origin'=>'https://evil.example.test','x-forwarded-host'=>$p->host],[],$cid);
SessionTest::deny(fn()=>$endpoint->handle($bad,hash('sha256','http-fixture')),403,'Origin and forwarded-host injection');
$bad=new Request('POST',$request->path,[],['host'=>$p->host,'origin'=>'https://'.$p->host],['clinic_id'=>2],$cid);
SessionTest::deny(fn()=>$endpoint->handle($bad,hash('sha256','http-fixture')),400,'No body-selected clinic');
SessionTest::check((int)$pdo->query("SELECT COUNT(*) FROM customer_sessions WHERE token_hash=REPEAT('e',64) AND revoked_at IS NULL")->fetchColumn()===1,'Legacy record remains unchanged');
// Stored JSON and rows never contain raw JWTs, cookie secrets, ID proofs or clinical data.
$stored=json_encode($pdo->query('SELECT * FROM browser_sessions')->fetchAll(),JSON_THROW_ON_ERROR).json_encode($pdo->query('SELECT * FROM access_audit_events')->fetchAll(),JSON_THROW_ON_ERROR);
SessionTest::check(!str_contains($stored,$cookie) && !str_contains($stored,$api) && !str_contains($stored,'id_token'),'Stored rows/audit contain no credentials');
for($repeat=0;$repeat<3;$repeat++)require dirname(__DIR__).'/helpers/browser-session-races.php';
require dirname(__DIR__).'/helpers/browser-session-network.php';
echo SessionTest::$checks . " server-session SQL/transaction/endpoint checks passed on " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
echo "Synthetic database retained: $name\n";
