<?php
declare(strict_types=1);
// This router never loads an environment file and cannot attach to hosted data.
require __DIR__.'/server-session-fixture.php';
if(getenv('SERVER_SESSION_TEST')!=='disposable-loopback-only' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
// Isolate synthetic browser abuse buckets without weakening the real limiter.
// This header is recognized only by this hard-gated test router.
$client=$_SERVER['HTTP_X_TEST_CLIENT']??'';
if(preg_match('/^[a-f0-9]{16}$/D',$client))$_SERVER['REMOTE_ADDR']='127.'.hexdec(substr($client,0,2)).'.'.hexdec(substr($client,2,2)).'.'.hexdec(substr($client,4,2));
$runtime=getenv('SERVER_SESSION_TEST_RUNTIME');
$settings=json_decode(file_get_contents($runtime.'/settings.json'),true,flags:JSON_THROW_ON_ERROR);
if(!preg_match('/^wellness_session_integration_[a-f0-9]{12}$/D',$settings['database']))throw new RuntimeException('Unsafe fixture database.');
ServerSessionFixture::pilot();
if (($settings['account_email']??false)===true) {
    putenv('COMMIT_SAFE_EMAIL_ENABLED=true');putenv('COMMIT_SAFE_EMAIL_KEY='.$settings['email_key']);
    putenv('MAIL_ENABLED=true');putenv('MAIL_FROM_ADDRESS=sender@example.test');
    putenv('MAIL_TENANT_ID=11111111-1111-4111-8111-111111111111');putenv('MAIL_CLIENT_ID=22222222-2222-4222-8222-222222222222');
    putenv('PILOT_ALLOWED_EMAILS=["sender@example.test","personal@example.test","fresh@example.test","inbox@example.test"]');
} else {putenv('COMMIT_SAFE_EMAIL_ENABLED=false');putenv('COMMIT_SAFE_EMAIL_KEY=');}

$config=ServerSessionFixture::config($settings['port'],$settings['database'],!isset($_SERVER['HTTP_X_TEST_LEGACY']));
$keys=fn()=>json_decode(file_get_contents($runtime.'/jwks.json'),true,flags:JSON_THROW_ON_ERROR);
$clock=fn()=>time()+(int)(is_file($runtime.'/clock')?file_get_contents($runtime.'/clock'):0);
(new Wellness\Api($config,new Wellness\Database($config),$keys,$clock))->handle();
