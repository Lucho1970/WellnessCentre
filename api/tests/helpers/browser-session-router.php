<?php
declare(strict_types=1);
// CLI loopback fixture only. No .env, live identity calls, PHI or provider transport.
header('Cache-Control: no-store');header('Content-Type: application/json');
if(PHP_SAPI!=='cli-server' || getenv('SESSION_CORE_HTTP_TEST')!=='synthetic-only'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !hash_equals((string)getenv('SESSION_CORE_HTTP_SECRET'),(string)($_SERVER['HTTP_X_SESSION_TEST_CONTROL'] ?? ''))){http_response_code(404);echo '{}';return;}
require __DIR__.'/browser-session.php';
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\{ApiException,BrowserSessionEndpoint,Request};
use Wellness\Service\{BrowserSessionPolicy,BrowserSessionService,BrowserSessionStore};
try {
    $name=(string)getenv('SESSION_CORE_HTTP_DATABASE');$port=(int)getenv('RECURRING_TEST_PORT');
    if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true' || !preg_match('/^wellness_sessions_test_[a-f0-9]{12}$/D',$name) || $port<1024 || $port>65535)throw new RuntimeException('Disposable fixture required.');
    $pdo=new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4",getenv('RECURRING_TEST_USER') ?: 'root',getenv('RECURRING_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $f=new SessionTestTokens();$f->now=(int)getenv('SESSION_CORE_HTTP_NOW');$p=$f->policy();
    $keys=json_decode((string)getenv('SESSION_CORE_HTTP_JWKS'),true,8,JSON_THROW_ON_ERROR);
    $authority=static function(PDO $db,BrowserSessionPolicy $policy):int {
        $q=$db->prepare('SELECT active FROM session_test_authority WHERE scope_hash=? FOR UPDATE');$q->execute([$policy->scopeHash()]);
        if((int)$q->fetchColumn()!==1)throw new ApiException(403,'membership_inactive','Access is no longer active.');return 1;
    };
    $service=new BrowserSessionService(new BrowserSessionStore($pdo),$p,new LoginProofVerifier($p,fn(bool $refresh)=>$keys),$authority,fn()=>$f->now);
    $reply=(new BrowserSessionEndpoint($p,$service,true))->handle(Request::capture(),hash('sha256','synthetic-loopback-source'));
    foreach($reply->headers() as $key=>$values)foreach((array)$values as $value)header($key.': '.$value,strtolower($key)!=='set-cookie');
    echo json_encode($reply,JSON_THROW_ON_ERROR);
} catch(ApiException $e) {http_response_code($e->status);echo json_encode(['error'=>['code'=>$e->errorCode]],JSON_THROW_ON_ERROR);}
catch(Throwable $e) {http_response_code(500);echo '{"error":{"code":"fixture_failure"}}';}
