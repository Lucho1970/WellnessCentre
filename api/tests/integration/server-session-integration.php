<?php
declare(strict_types=1);
require dirname(__DIR__).'/helpers/server-session-fixture.php';
use Wellness\{Database,SessionConnection};
use Wellness\Http\Request;
use Wellness\Service\{BrowserSessionGateway,BrowserSessionAuthority};
if(getenv('RECURRING_TEST_ALLOW_CREATE')!=='true')throw new RuntimeException('Explicit disposable database required.');
$port=(int)getenv('RECURRING_TEST_PORT');if($port<1024 || $port>65535)throw new RuntimeException('Invalid disposable port.');
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",getenv('RECURRING_TEST_USER') ?: 'root',getenv('RECURRING_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='wellness_session_integration_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE `$name`");
ServerSessionFixture::seed($pdo);ServerSessionFixture::pilot();
$config=ServerSessionFixture::config($port,$name);$database=new Database($config);$db=$database->connection();
$pdo->exec("INSERT INTO customer_sessions(token_hash,identity_id,authenticated_at,created_at,last_activity_at,expires_at,clinic_id) VALUES(REPEAT('e',64),1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),1)");
SessionTest::check($db instanceof SessionConnection,'Enabled mode uses a managed connection');
$db->beginRequest();SessionTest::check(!$db->inTransaction() && $db->requestActive(),'Outer request is separate from service transactions');
$db->beginTransaction();$db->exec("UPDATE users SET display_name='Changed' WHERE id=2");$db->rollBack();
SessionTest::check($db->query('SELECT display_name FROM users WHERE id=2')->fetchColumn()==='Synthetic Microsoft','Service savepoint rollback preserved');
$db->checkpointRequest();$db->beginTransaction();$db->exec("UPDATE users SET display_name='Nested' WHERE id=2");$db->commit();
SessionTest::check($pdo->query('SELECT display_name FROM users WHERE id=2')->fetchColumn()==='Synthetic Microsoft','Inner commit cannot escape request boundary');
$db->rollbackEffects();$db->commitRequest();
SessionTest::check($pdo->query('SELECT display_name FROM users WHERE id=2')->fetchColumn()==='Synthetic Microsoft','Rejected effects do not commit');
require dirname(__DIR__).'/helpers/server-session-http.php';
$http=new ServerSessionHttp($port,$name);
try {
    foreach(['workforce','central','practitioner','client'] as $realm){
        $s=$http->start($realm);$sessions[$realm]=$s;
        $path=match($realm){'central'=>'application/me','client'=>'customer/auth/me',default=>'auth/me'};
        $reply=$http->call($path,bearer:$s['api'],cookie:$s['cookie'],host:$s['host']);
        SessionTest::check($reply['status']===200,$realm.' existing me: '.$reply['status'].' '.($reply['body']['error']['code']??''));
        SessionTest::check(str_contains($reply['headers']['cache-control']??'','no-store'),'Private response cannot be cached');
        $reply=$http->call($path,bearer:$s['api'],cookie:$s['cookie'],headers:['Origin'=>'https://clinic-b.session.test'],host:$s['host']);
        SessionTest::check($reply['status']===403 && $reply['cookies']===[],'Sibling-origin private GET denied without clearing the owner cookie');
        $reply=$http->call($s['prefix'].'/me',bearer:$s['api'],cookie:$s['cookie'],headers:['Origin'=>'https://clinic-b.session.test'],host:$s['host']);
        SessionTest::check($reply['status']===403,'Sibling-origin session metadata GET denied');
        $reply=$http->call($path,bearer:$s['api'],host:$s['host']);
        SessionTest::check($reply['status']===401,'Bearer alone cannot bypass '.$realm.' session');
        $reply=$http->call($s['prefix'].'/activity','POST',$s['api'],$s['cookie'],[],host:$s['host']);
        SessionTest::check($reply['status']===403,'Missing CSRF rejected');
        $reply=$http->call($s['prefix'].'/activity','POST',$s['api'],$s['cookie'],[],['X-Session-CSRF'=>$s['csrf'],'Origin'=>'https://evil.example.test'],host:$s['host']);
        SessionTest::check($reply['status']===403,'Cross-origin mutation rejected');
    }
    $google=$http->start('practitioner','google');
    SessionTest::check($http->call('auth/me',bearer:$google['api'],cookie:$google['cookie'])['body']['data']['id']===5,'Google maps to its own synthetic practitioner');
    $pdo->exec('UPDATE practitioner_pilot_access SET clinic_id=2 WHERE identity_id=5');
    SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM browser_sessions WHERE product_identity_id=5 AND revoked_at IS NOT NULL')->fetchColumn()===1,'Pilot ownership move revokes old session even without status change');
    $pdo->exec('UPDATE practitioner_pilot_access SET clinic_id=1 WHERE identity_id=5');
    SessionTest::check($http->call('auth/me',bearer:$google['api'],cookie:$google['cookie'])['status']===401,'Restored pilot ownership cannot revive old session');
    $admin=$sessions['workforce'];$pr=$sessions['practitioner'];
    $body=['display_name'=>'Synthetic Microsoft','email'=>'microsoft@example.test','status'=>'inactive','roles'=>['practitioner'],'permissions'=>[]];
    $reply=$http->call('admin/staff/2','PATCH',$admin['api'],$admin['cookie'],$body,['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check($reply['status']===200,'Real Staff access save succeeds: '.$reply['status'].' '.($reply['body']['error']['code']??''));
    SessionTest::check($pdo->query('SELECT status FROM users WHERE id=2')->fetchColumn()==='inactive','Staff service commit persisted through outer boundary');
    SessionTest::check($http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'])['status']>=400,'Retained practitioner cookie blocked after Staff Inactive');
    $body['status']='active';$reply=$http->call('admin/staff/2','PATCH',$admin['api'],$admin['cookie'],$body,['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check($reply['status']===200,'Restore fixture Staff access');
    SessionTest::check($http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'])['status']===401,'Restoring authority cannot revive old session');
    $pr=$http->start('practitioner');
    $reply=$http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'],host:'clinic-b.session.test');
    SessionTest::check($reply['status']>=400,'Cross-clinic cookie is denied');
    $reply=$http->call('auth/me',bearer:$google['api'],cookie:$pr['cookie']);
    SessionTest::check($reply['status']===401,'Cross-account cookie is denied');
    $pdo->exec("UPDATE practitioner_pilot_access SET status='revoked' WHERE identity_id=2");
    SessionTest::check($http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'])['status']===403,'Pilot revocation checked on existing route');
    $pdo->exec("UPDATE practitioner_pilot_access SET status='approved' WHERE identity_id=2");
    SessionTest::check($http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'])['status']===401,'Pilot restoration cannot resurrect cookie');
    // Force the final disclosure audit to fail after the real service has committed
    // its savepoint. The outer request must roll back the business change.
    $pdo->exec("CREATE TRIGGER session_test_audit_failure BEFORE INSERT ON access_audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit unavailable'");
    $body['display_name']='Must roll back';$reply=$http->call('admin/staff/2','PATCH',$admin['api'],$admin['cookie'],$body,['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check($reply['status']===503,'Required audit failure denies response');
    SessionTest::check($pdo->query('SELECT display_name FROM users WHERE id=2')->fetchColumn()==='Synthetic Microsoft','Required audit failure rolls back service commit');
    $pdo->exec('DROP TRIGGER session_test_audit_failure');
    $pdo->prepare('INSERT INTO staff_notification_preferences(user_id,personal_email,email_code_hash,email_code_expires_at) VALUES(1,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))')->execute(['synthetic@example.test',password_hash('12345678',PASSWORD_DEFAULT)]);
    for($attempt=1;$attempt<=5;$attempt++){
        $reply=$http->call('profile/notifications/verify','POST',$admin['api'],$admin['cookie'],['code'=>'00000000'],['X-Session-CSRF'=>$admin['csrf']]);
        SessionTest::check($reply['status']===422,'Wrong verification code rejected');
        SessionTest::check((int)$pdo->query('SELECT email_code_attempts FROM staff_notification_preferences WHERE user_id=1')->fetchColumn()===$attempt,'Denial preserves verification attempt count');
    }
    $reply=$http->call('profile/notifications/verify','POST',$admin['api'],$admin['cookie'],['code'=>'12345678'],['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check(($reply['body']['error']['code']??'')==='code_expired','Correct code cannot bypass exhausted attempts');
    $reply=$http->call('profile/notifications/send-code','POST',$admin['api'],$admin['cookie'],[],['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check($reply['status']===503,'Synchronous mail fails closed until commit-safe outbox integration');
    // Rejected invitations must still consume the existing abuse bucket.
    for($attempt=0;$attempt<2;$attempt++)$http->call('customer/invitations/accept','POST',$sessions['client']['api'],$sessions['client']['cookie'],['token'=>'invalid','claimant_name'=>'Synthetic'],['X-Session-CSRF'=>$sessions['client']['csrf']]);
    SessionTest::check((int)$pdo->query('SELECT COALESCE(SUM(attempts),0) FROM customer_rate_limits')->fetchColumn()>=2,'Rejected requests retain customer rate counters');
    // Disabled scopes retain the existing bearer flow, not a guessed new mode.
    SessionTest::check($http->call('auth/me',bearer:$admin['api'],headers:['X-Test-Legacy'=>'true'])['status']===200,'Explicit default-off legacy path preserved');
    $client=$sessions['client'];$pdo->exec("UPDATE users SET status='inactive' WHERE id=3");
    SessionTest::check($http->call('customer/auth/me',bearer:$client['api'],cookie:$client['cookie'])['status']>=400,'Linked client Inactive revokes versioned session');
    SessionTest::check($pdo->query("SELECT revoked_at FROM customer_sessions WHERE token_hash=REPEAT('e',64)")->fetchColumn()===null,'Authority triggers preserve unversioned legacy customer sessions');
    SessionTest::check($http->call('customer/auth/me',bearer:$client['api'],headers:['X-Customer-Session'=>str_repeat('e',64)])['status']===401,'Legacy client header cannot bypass enabled mode');
    $pdo->exec("UPDATE users SET status='active' WHERE id=3");
    $central=$sessions['central'];$pdo->exec("UPDATE application_administrators SET status='revoked' WHERE id=1");
    SessionTest::check($http->call('application/me',bearer:$central['api'],cookie:$central['cookie'],host:$central['host'])['status']>=400,'Central administrator grant rechecked');
    $pdo->exec("UPDATE application_administrators SET status='active' WHERE id=1");
    $pr=$http->start('practitioner');
    $request=new Request('GET','/api/v1/auth/me',[],['host'=>$pr['host'],'authorization'=>'Bearer '.$pr['api'],'cookie'=>$pr['cookie']],[],'synthetic-integration-race');
    $gateway=new BrowserSessionGateway(ServerSessionFixture::policy('practitioner'),$database,$request,fn()=>$http->tokens->jwks,fn()=>new BrowserSessionAuthority(2,2,2));
    $gateway->guard();
    $worker=proc_open([PHP_BINARY,dirname(__DIR__).'/helpers/server-session-revoke-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($worker))throw new RuntimeException('Cannot start revoke worker.');
    fwrite($pipes[0],json_encode(['database'=>$name,'port'=>$port],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    stream_set_timeout($pipes[1],10);SessionTest::check(trim((string)fgets($pipes[1]))==='ready','Concurrent Staff service started');
    try{
        $waiting=false;for($n=0;$n<20;$n++){if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn()>0){$waiting=true;break;}usleep(200000);}
        SessionTest::check($waiting,'Authority mutation waits for protected request admission barrier');
        $gateway->finish(200);
        SessionTest::check(trim((string)fgets($pipes[1]))==='revoked','Staff revocation commits after protected request completes');
        SessionTest::check($http->call('auth/me',bearer:$pr['api'],cookie:$pr['cookie'])['status']>=400,'Next retained-cookie request is denied');
    }finally{$gateway->abort();fclose($pipes[1]);$workerError=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($worker);if($exit!==0)throw new RuntimeException('Disposable revocation worker failed.');}
    $pdo->exec("UPDATE users SET status='active' WHERE id=2");
    // Real registration / independent review / activation through guarded routes.
    $central=$http->start('central');$applicant=$http->start('practitioner','applicant');
    $key=fn()=>sprintf('%s-%s-4%s-8%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2))[0].substr(bin2hex(random_bytes(2)),0,2),substr(bin2hex(random_bytes(2)),0,3),bin2hex(random_bytes(6)));
    $centralCall=fn($tail,$body)=>$http->call('application/clinics/1/practitioner-applications'.$tail,'POST',$central['api'],$central['cookie'],$body+['request_key'=>$key()],['X-Session-CSRF'=>$central['csrf']],$central['host']);
    $reply=$centralCall('/settings',['revision'=>0,'enabled'=>true,'activation_enabled'=>true,'readiness_acknowledged'=>true,'terms_text'=>'Synthetic local rehearsal only. Six calendar months free. No charges, patient data, publication or automatic payment conversion.','plan_version_id'=>1,'timezone'=>'America/Toronto','reason'=>'Synthetic integration test settings']);
    SessionTest::check($reply['status']===200,'Guarded central application settings: '.$reply['status'].' '.($reply['body']['error']['code']??''));
    $offer=$http->call('public/practitioner-applications')['body']['data'];$terms=$offer['terms']['id'];
    $appCall=fn($tail,$body)=>$http->call('practitioner-applications/'.$tail,'POST',$applicant['api'],$applicant['cookie'],$body+['request_key'=>$key()],['X-Session-CSRF'=>$applicant['csrf']]);
    SessionTest::check($http->call('auth/me',bearer:$applicant['api'],cookie:$applicant['cookie'])['status']===403,'Applicant session grants no practitioner workspace');
    $reply=$appCall('me',['version'=>0,'given_name'=>'Synthetic','family_name'=>'Applicant','email'=>'applicant@example.test','phone'=>'','discipline'=>'Synthetic practice','credentials'=>'Synthetic credentials, no real qualification','service_area'=>'Toronto, Ontario','services'=>'Synthetic rehearsal only','enquiry_reference'=>'','terms_id'=>$terms,'accept_terms'=>true,'information_confirmed'=>true]);
    SessionTest::check($reply['status']===200,'Guarded application submission: '.$reply['status'].' '.($reply['body']['error']['code']??''));$application=$reply['body']['data']['id'];
    $own=$http->call('practitioner-applications/me',bearer:$applicant['api'],cookie:$applicant['cookie'])['body']['data']['application'];
    $reply=$centralCall('/'.$application.'/review',['version'=>1,'decision'=>'approved','review_message'=>'Approved only for synthetic test','reason'=>'Independent synthetic verification rehearsal','verification_code'=>$own['verification_code'],'applicant_verified'=>true,'credentials_reviewed'=>true,'allow_existing_membership'=>false,'location_id'=>1,'source_enquiry_id'=>null]);
    SessionTest::check($reply['status']===200,'Guarded independent approval: '.$reply['status'].' '.($reply['body']['error']['code']??''));
    $reply=$appCall('activate',['version'=>2,'terms_id'=>$terms,'accept_terms'=>true]);
    SessionTest::check($reply['status']===200,'Guarded activation commits: '.$reply['status'].' '.($reply['body']['error']['code']??''));
    SessionTest::check(($reply['headers']['x-session-ended']??'')==='1','Membership creation ends applicant session for fresh binding');
    SessionTest::check((int)$pdo->query('SELECT COUNT(*) FROM billing_subscriptions')->fetchColumn()===1,'One trial created by activation');
    $trial=$pdo->query('SELECT starts_at,trial_ends_at,trial_timezone FROM billing_subscriptions')->fetch();
    SessionTest::check($trial['trial_ends_at']===\Wellness\Service\SubscriptionPolicy::trialEndsAt(new DateTimeImmutable($trial['starts_at'],new DateTimeZone('UTC')),$trial['trial_timezone'])->format('Y-m-d H:i:s'),'Activation retains six calendar-month timezone policy');
    $member=$http->start('practitioner','applicant');$reply=$http->call('auth/me',bearer:$member['api'],cookie:$member['cookie']);
    SessionTest::check($reply['status']===200 && $reply['body']['data']['roles']===['practitioner'],'Fresh session binds activated practitioner-only membership');
    $pdo->exec("UPDATE users u JOIN staff_memberships m ON m.local_user_id=u.id SET u.status='inactive' WHERE m.identity_id=6");
    $pdo->exec("UPDATE practitioner_pilot_access SET status='revoked' WHERE identity_id=6");
    $secret=explode('=',$admin['cookie'],2)[1];$q=$pdo->prepare('UPDATE browser_sessions SET authenticated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE) WHERE secret_hash=?');$q->execute([hash('sha256',$secret)]);
    $reply=$http->call('admin/staff/2','PATCH',$admin['api'],$admin['cookie'],$body,['X-Session-CSRF'=>$admin['csrf']]);
    SessionTest::check($reply['status']===401 && ($reply['body']['error']['code']??'')==='session_reauth_required','Sensitive admin writes require recent interactive authentication');
    if(getenv('RUN_SERVER_SESSION_BROWSER')==='true'){
        $env=getenv();$env['SERVER_SESSION_BROWSER_RUNTIME']=$http->runtime;$env['SERVER_SESSION_BROWSER_API']=(string)$http->port;
        $command=['node',dirname(__DIR__,3).'/Frontend/node_modules/@playwright/test/cli.js','test','--config','playwright.server-session.config.ts'];
        $browser=proc_open($command,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__,3).'/Frontend',$env);
        if(!is_resource($browser))throw new RuntimeException('Cannot start browser tests.');fclose($pipes[0]);
        SessionTest::check(proc_close($browser)===0,'Real route browser integration');
    }
    echo SessionTest::$checks." session integration checks passed on ".$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)."\nSynthetic database retained: $name\n";
} finally {$http->close();}
