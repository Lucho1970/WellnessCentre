<?php
declare(strict_types=1);
require __DIR__.'/helpers/browser-session.php';
use Wellness\Auth\LoginProofVerifier;
use Wellness\Http\ApiException;
use Wellness\Service\CustomerOnboarding;
use Firebase\JWT\JWT;

$f = new SessionTestTokens();
foreach (['workforce','practitioner','client','central'] as $realm) {
    $p = $f->policy($realm); $v = new LoginProofVerifier($p,fn(bool $refresh) => $f->jwks);
    $api = $f->claims($p); $nonce = str_repeat('a',64); $id = $f->claims($p,$nonce,true);
    $access = $v->access($f->sign($api),$f->now);
    foreach ([$id,$id+['azp'=>$p->spaAudience]] as $claims) {
        SessionTest::check($v->proof($f->sign($claims),$access,hash('sha256',$nonce),$f->now,$f->now)['sub'] !== $access['sub'],'Stable oid binds pairwise subjects');
    }
    foreach (['iss'=>'https://wrong.example.test','tid'=>str_repeat('0',36),'aud'=>$p->apiAudience,'azp'=>null,'ver'=>'1.0','sub'=>'','oid'=>'bad','iat'=>$f->now+61,'exp'=>$f->now,'nbf'=>$f->now+1,'nonce'=>str_repeat('b',64),'auth_time'=>$f->now-601] as $key=>$value) {
        SessionTest::deny(fn() => $v->proof($f->sign(array_replace($id,[$key=>$value])),$access,hash('sha256',$nonce),$f->now,$f->now),401,'Invalid signed proof '.$key);
    }
    foreach (['','other-client',1,[$p->spaAudience]] as $azp) SessionTest::deny(fn() => $v->proof($f->sign($id+['azp'=>$azp]),$access,hash('sha256',$nonce),$f->now,$f->now),401,'Present malformed proof azp');
    foreach (['nonce','auth_time'] as $field) { $missing=$id;unset($missing[$field]);SessionTest::deny(fn()=>$v->proof($f->sign($missing),$access,hash('sha256',$nonce),$f->now,$f->now),401,'Proof field required '.$field); }
    SessionTest::deny(fn()=>$v->proof($f->sign(array_replace($id,['aud'=>[$p->spaAudience]])),$access,hash('sha256',$nonce),$f->now,$f->now),401,'Proof audience must be exact string');
    SessionTest::deny(fn()=>$v->access($f->sign(array_replace($api,['azp'=>'another-client'])),$f->now),401,'Another API caller denied');
    SessionTest::deny(fn()=>$v->access($f->sign(array_replace($api,['nbf'=>(string)($f->now-5)])),$f->now),401,'API nbf has numeric-date type');
    SessionTest::deny(fn()=>$v->proof($f->sign(array_replace($id,['nbf'=>(string)($f->now-5)])),$access,hash('sha256',$nonce),$f->now,$f->now),401,'Proof nbf has numeric-date type');
    foreach (['azp','aud','exp','iat','sub','oid','tid'] as $field) {
        $missing=$api; unset($missing[$field]); SessionTest::deny(fn() => $v->access($f->sign($missing),$f->now),401,'Required API '.$field);
    }
    foreach (['aud'=>$p->spaAudience,'azp'=>null,'iss'=>'https://wrong.example.test','exp'=>$f->now,'nbf'=>$f->now+1] as $key=>$value) SessionTest::deny(fn() => $v->access($f->sign(array_replace($api,[$key=>$value])),$f->now),401,'API binding '.$key);
    SessionTest::deny(fn() => $v->access($f->sign($api+['unused'=>true]),$f->now+86400),401,'Exact JWT expiry');
    foreach ([null,'unrelated_scope',$p->scope.'_extra'] as $scope) SessionTest::deny(fn() => $v->access($f->sign(array_replace($api,['scp'=>$scope])),$f->now),403,'Exact API scope');
    SessionTest::deny(fn() => $v->access($f->sign($id),$f->now),401,'ID cannot be API bearer');
    $other=new SessionTestTokens(); SessionTest::deny(fn() => $v->access($other->sign($api),$f->now),401,'Untrusted key');
    SessionTest::deny(fn() => $v->access(JWT::encode($api,str_repeat('a',64),'HS256','session-test'),$f->now),401,'Algorithm confusion');
    foreach ([null,'bad',str_repeat('a',16385)] as $bad) SessionTest::deny(fn() => $v->access($bad,$f->now),401,'Malformed token');
    $rotation = new LoginProofVerifier($p,fn(bool $refresh) => $refresh ? $f->jwks : ['keys'=>[]]);
    SessionTest::check($rotation->access($f->sign($api),$f->now)['oid'] === $api['oid'],'Key rotation');
    $down = new LoginProofVerifier($p,static function () { throw new ApiException(503,'identity_unavailable','Unavailable'); });
    SessionTest::deny(fn() => $down->access($f->sign($api),$f->now),503,'Unavailable key authority');
    SessionTest::check(JWT::$timestamp === null && JWT::$leeway === 0,'Library clock/leeway restored');
    if ($realm === 'client') SessionTest::check($p->principalHash($api) === CustomerOnboarding::identityHash($api),'Customer mapping exactly preserved');
}
echo SessionTest::$checks . " server-session signed proof checks passed\n";
