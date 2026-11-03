<?php
declare(strict_types=1);
// Included only by the disposable SQL suite. No real browser/TLS claims: this
// verifies HTTP framing, parsing, headers and the real endpoint/PDO implementation.
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$listener)throw new RuntimeException('Cannot reserve loopback test port.');
$address=stream_socket_get_name($listener,false);$httpPort=(int)substr(strrchr($address,':'),1);fclose($listener);
$control=bin2hex(random_bytes(32));
$environment=getenv();
$environment['SESSION_CORE_HTTP_TEST']='synthetic-only';$environment['SESSION_CORE_HTTP_SECRET']=$control;
$environment['SESSION_CORE_HTTP_DATABASE']=$name;$environment['SESSION_CORE_HTTP_NOW']=(string)$f->now;
$environment['SESSION_CORE_HTTP_JWKS']=json_encode($f->jwks,JSON_THROW_ON_ERROR);
$process=proc_open([PHP_BINARY,'-S',"127.0.0.1:$httpPort",__DIR__.'/browser-session-router.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$httpPipes,null,$environment);
if(!is_resource($process))throw new RuntimeException('Cannot start loopback HTTP fixture.');
stream_set_blocking($httpPipes[1],false);stream_set_blocking($httpPipes[2],false);
$http=static function(string $action,string $method='POST',array $body=[],array $headers=[],?string $raw=null)use($httpPort,$control,$p):array {
    $curl=curl_init("http://127.0.0.1:$httpPort/api/v1/practitioner/session/$action");$received=[];
    $default=['Host'=>$p->host,'Origin'=>'https://'.$p->host,'Content-Type'=>'application/json','X-Session-Test-Control'=>$control];
    $lines=[];foreach(array_replace($default,$headers) as $key=>$value)$lines[]=$key.': '.$value;
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,CURLOPT_POSTFIELDS=>$raw ?? json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,
        CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$received):int { $pair=explode(':',$line,2);if(count($pair)===2)$received[strtolower($pair[0])][]=trim($pair[1]);return strlen($line); }]);
    $text=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if(!is_string($text))throw new RuntimeException('Loopback fixture transport failed.');
    return ['status'=>$status,'headers'=>$received,'json'=>json_decode($text,true,16,JSON_THROW_ON_ERROR)];
};
try {
    $ready=false;for($i=0;$i<100;$i++){ $socket=@fsockopen('127.0.0.1',$httpPort,$errno,$error,.1);if($socket){fclose($socket);$ready=true;break;}usleep(20000); }
    if(!$ready)throw new RuntimeException('Loopback fixture unavailable.');
    $pdo->prepare('UPDATE session_test_authority SET active=1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    $r=$http('challenge');SessionTest::check($r['status']===200 && $r['headers']['cache-control']===['no-store'],'Real HTTP challenge/no-store');
    $challengeCookie=explode(';',$r['headers']['set-cookie'][0])[0];$nonce=$r['json']['data']['nonce'];
    $api=$f->sign($f->claims($p));$proof=$f->sign($f->claims($p,$nonce,true));
    $r=$http('start',body:['id_token'=>$proof],headers:['Authorization'=>'Bearer '.$api,'Cookie'=>$challengeCookie]);
    SessionTest::check($r['status']===200 && count($r['headers']['set-cookie'])===2,'HTTP bootstrap sets session and clears challenge after commit');
    $sessionHeader=$r['headers']['set-cookie'][0];$cookie=explode(';',$sessionHeader)[0];$csrf=$r['json']['data']['csrf'];
    SessionTest::check(str_contains($sessionHeader,'Secure; HttpOnly; SameSite=Lax') && !str_contains($sessionHeader,'Domain='),'Actual PHP cookie header attributes');
    SessionTest::check(!isset($r['json']['data']['session_token']) && !str_contains(json_encode($r['json'],JSON_THROW_ON_ERROR),substr($cookie,strpos($cookie,'=')+1)),'No cookie credential in HTTP JSON');
    $headers=['Authorization'=>'Bearer '.$api,'Cookie'=>$cookie,'X-Session-CSRF'=>$csrf];
    SessionTest::check($http('me','GET',headers:$headers)['status']===200,'Protected HTTP metadata');
    SessionTest::check($http('me','GET',headers:['Authorization'=>'Bearer '.$api])['status']===401,'HTTP bearer-only bypass denied');
    SessionTest::check($http('activity',headers:array_replace($headers,['Origin'=>'https://evil.example.test']))['status']===403,'HTTP hostile origin');
    SessionTest::check($http('activity',headers:array_replace($headers,['X-Session-CSRF'=>'bad']))['status']===403,'HTTP CSRF denial');
    SessionTest::check($http('me','GET',headers:array_replace($headers,['Cookie'=>$cookie.'; '.$cookie]))['status']===400,'HTTP duplicate cookie denied');
    SessionTest::check($http('me','GET',headers:array_replace($headers,['Host'=>'other.example.test','X-Forwarded-Host'=>$p->host]))['status']===404,'HTTP host bypass denied');
    SessionTest::check($http('start',body:['id_token'=>$proof],headers:['Authorization'=>'Bearer '.$api,'Cookie'=>$challengeCookie])['status']===401,'HTTP proof replay denied');
    SessionTest::check($http('activity',headers:$headers,raw:str_repeat(' ',65537))['status']===413,'Actual byte limit enforced before JSON decode');
    SessionTest::check($http('activity',headers:$headers,raw:'broken')['status']===400,'Malformed HTTP JSON denied');
    $pdo->prepare('UPDATE session_test_authority SET active=0 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    SessionTest::check($http('me','GET',headers:$headers)['status']===403,'Retained HTTP session sees current membership denial');
    $r=$http('logout',headers:['Cookie'=>$cookie,'X-Session-CSRF'=>$csrf]);
    SessionTest::check($r['status']===200 && str_contains($r['headers']['set-cookie'][0],'Max-Age=0'),'Own HTTP logout after departure without bearer');
    $pdo->prepare('UPDATE session_test_authority SET active=1 WHERE scope_hash=?')->execute([$p->scopeHash()]);
    SessionTest::check($http('me','GET',headers:$headers)['status']===401,'Logged-out cookie remains denied after reactivation');
} finally {
    if(proc_get_status($process)['running'])proc_terminate($process);
    foreach($httpPipes as $pipe)fclose($pipe);proc_close($process);
}
