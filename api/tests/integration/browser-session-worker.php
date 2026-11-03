<?php
declare(strict_types=1);
require dirname(__DIR__).'/helpers/browser-session.php';
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\ApiException;
use Wellness\Service\{BrowserSessionPolicy,BrowserSessionService,BrowserSessionStore};
try {
    if (getenv('RECURRING_TEST_ALLOW_CREATE')!=='true') throw new RuntimeException('Disposable worker required.');
    $input=json_decode((string)fgets(STDIN,65536),true,16,JSON_THROW_ON_ERROR);
    if (!preg_match('/^wellness_sessions_test_[a-f0-9]{12}$/D',$input['database'] ?? '')) throw new RuntimeException('Invalid disposable database.');
    $port=(int)getenv('RECURRING_TEST_PORT'); if($port<1024 || $port>65535)throw new RuntimeException('Invalid disposable port.');
    $db=new PDO("mysql:host=127.0.0.1;port=$port;dbname={$input['database']};charset=utf8mb4",getenv('RECURRING_TEST_USER') ?: 'root',getenv('RECURRING_TEST_PASSWORD') ?: '',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET SESSION innodb_lock_wait_timeout=15');
    $f=new SessionTestTokens(); $f->now=$input['now']; $p=$f->policy(); $store=new BrowserSessionStore($db);
    $authority=static function(PDO $pdo,BrowserSessionPolicy $policy): int {
        $q=$pdo->prepare('SELECT active FROM session_test_authority WHERE scope_hash=? FOR UPDATE');$q->execute([$policy->scopeHash()]);
        if((int)$q->fetchColumn()!==1)throw new ApiException(403,'membership_inactive','Inactive');return 1;
    };
    $service=new BrowserSessionService($store,$p,new LoginProofVerifier($p,fn(bool $refresh)=>$input['jwks']),$authority,fn()=>$input['now']);
    echo "starting\n";flush();
    $go=static function():void { if(trim((string)fgets(STDIN,20))!=='go')throw new RuntimeException('Missing test barrier.'); };
    switch($input['mode']) {
        case 'protect':
            $service->protect($input['bearer'],$input['cookie'],$input['csrf'],true,'worker-protect',static function(PDO $pdo)use($p,$go):void {
                echo "locked\n";flush();$go();
                $pdo->prepare('UPDATE session_test_authority SET effects=effects+1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
            });break;
        case 'revoke':
            $store->transaction(static function()use($store,$p,$input,$go):void {
                $store->epoch($p,$input['principal'],$input['now']);echo "locked\n";flush();$go();
                $store->query('UPDATE session_test_authority SET active=0 WHERE scope_hash=?',[$p->scopeHash()]);
                $store->revokeAll($p,$input['principal'],$input['now']);
                $store->audit($p,$input['principal'],1,'worker-revoke','departure');
            });break;
        case 'start': $service->start($input['bearer'],$input['proof'],$input['challenge'],'worker-bootstrap');break;
        case 'all': $service->revokeAll($input['bearer'],$input['cookie'],$input['csrf'],$input['action'],'worker-revoke-all');break;
        default:throw new RuntimeException('Invalid worker mode.');
    }
    echo "status:200\n";
} catch(ApiException $e) { echo 'status:'.$e->status."\n"; }
catch(Throwable $e) { echo 'worker-error:'.get_class($e)."\n";exit(1); }
