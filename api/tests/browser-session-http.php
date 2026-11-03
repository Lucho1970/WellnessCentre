<?php
declare(strict_types=1);
require __DIR__.'/helpers/browser-session.php';
use Wellness\Http\SessionCookie;
use Wellness\Service\BrowserSessionGrant;
use Wellness\Service\CustomerOnboarding;

SessionTest::check(!CustomerOnboarding::sessionIsActive(['policy_version'=>1,'revoked_at'=>null,'last_activity_at'=>gmdate('Y-m-d H:i:s',1000),'expires_at'=>gmdate('Y-m-d H:i:s',10000)],1001),'New cookie session cannot downgrade to legacy session handler');

$f = new SessionTestTokens();
foreach (['workforce','practitioner','client','central'] as $realm) {
    $p=$f->policy($realm); $secret=str_repeat('a',64); $name=SessionCookie::name($p);
    $header=SessionCookie::header($p,$secret);
    SessionTest::check(str_contains($header,'; Path=/; Secure; HttpOnly; SameSite=Lax') && !str_contains($header,'Domain=') && !str_contains($header,'Max-Age='),'Host-only session cookie');
    SessionTest::check(SessionCookie::read("other=ok; $name=$secret",$p)===$secret,'Exact cookie parsed');
    SessionTest::check(SessionCookie::read('other=ok',$p)===null,'No cookie remains absent');
    foreach (["$name=$secret; $name=$secret","$name=%61","$name=$secret=extra",str_repeat('a',8193)] as $bad) SessionTest::deny(fn()=>SessionCookie::read($bad,$p),400,'Cookie pollution/length denied');
    foreach (['https://evil.example.test','null','http://'.$p->host,'https://'.$p->host.':443','https://'.$p->host.'/'] as $origin) SessionTest::deny(fn()=>SessionCookie::origin($p,$origin,$p->host),403,'Exact secure origin');
    SessionCookie::origin($p,'https://'.$p->host,$p->host);
    SessionTest::deny(fn()=>SessionCookie::origin($p,'https://'.$p->host,'other.example.test'),403,'Wrong server host');
    SessionTest::check(str_contains(SessionCookie::header($p,null),'Max-Age=0'),'Logout clears same cookie');
    $grant=new BrowserSessionGrant($secret,['idle_expires_at'=>100]);
    SessionTest::check(!str_contains(json_encode($grant,JSON_THROW_ON_ERROR),$secret),'Grant cannot serialize cookie');
}
echo SessionTest::$checks . " server-session cookie/HTTP policy checks passed\n";
