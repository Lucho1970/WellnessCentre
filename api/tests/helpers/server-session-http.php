<?php
declare(strict_types=1);
final class ServerSessionHttp
{
    private $process;
    public readonly string $runtime;
    public readonly int $port;
    public readonly SessionTestTokens $tokens;
    public function __construct(int $databasePort,string $name,bool $accountEmail=false)
    {
        $this->tokens=new SessionTestTokens();
        $this->runtime=dirname(__DIR__,3).'/.tmp/server-session-integration-'.bin2hex(random_bytes(6));mkdir($this->runtime,0700,true);
        file_put_contents($this->runtime.'/settings.json',json_encode(['port'=>$databasePort,'database'=>$name,'account_email'=>$accountEmail,'email_key'=>$accountEmail?bin2hex(random_bytes(32)):''],JSON_THROW_ON_ERROR));
        file_put_contents($this->runtime.'/jwks.json',json_encode($this->tokens->jwks,JSON_THROW_ON_ERROR));
        openssl_pkey_export($this->tokens->key,$private);file_put_contents($this->runtime.'/private.pem',$private);unset($private);
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$listener)throw new RuntimeException('Cannot reserve test port.');
        $this->port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);fclose($listener);
        $env=getenv();$env['SERVER_SESSION_TEST']='disposable-loopback-only';$env['SERVER_SESSION_TEST_RUNTIME']=$this->runtime;
        $this->process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$this->port,__DIR__.'/server-session-router.php'],[0=>['pipe','r'],1=>['file',$this->runtime.'/server.log','a'],2=>['file',$this->runtime.'/server.log','a']],$pipes,dirname(__DIR__,3),$env,['bypass_shell'=>true]);
        if(!is_resource($this->process))throw new RuntimeException('Cannot start disposable API.');fclose($pipes[0]);
        $ready=false;for($n=0;$n<100;$n++){$s=@stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$message,.1);if($s){fclose($s);$ready=true;break;}usleep(50000);}
        if(!$ready){$this->close();throw new RuntimeException('Disposable API did not start.');}
    }
    public function call(string $path,string $method='GET',?string $bearer=null,string $cookie='',?array $body=null,array $headers=[],string $host='clinic-a.session.test'):array
    {
        $h=['Host'=>$host,'Origin'=>'https://'.$host,'Content-Type'=>'application/json'];if($bearer!==null)$h['Authorization']='Bearer '.$bearer;if($cookie!=='')$h['Cookie']=$cookie;
        $h=array_replace($h,$headers);$lines=[];foreach($h as $key=>$value)$lines[]=$key.': '.$value;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'content'=>$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
        $text=file_get_contents('http://127.0.0.1:'.$this->port.'/api/v1/'.$path,false,$context);if($text===false)throw new RuntimeException('Disposable API transport failed.');
        $status=(int)explode(' ',$http_response_header[0])[1];$result=[];$cookies=[];
        foreach(array_slice($http_response_header,1) as $line){$parts=explode(':',$line,2);if(count($parts)!==2)continue;$key=strtolower($parts[0]);$result[$key]=trim($parts[1]);if($key==='set-cookie')$cookies[]=trim($parts[1]);}
        return ['status'=>$status,'headers'=>$result,'cookies'=>$cookies,'body'=>$text===''?[]:json_decode($text,true,flags:JSON_THROW_ON_ERROR)];
    }
    public static function prefix(string $realm):string{return match($realm){'client'=>'customer/auth','workforce'=>'staff/session','central'=>'application/session',default=>'practitioner/session'};}
    public function start(string $realm,string $profile=''):array
    {
        $host=$realm==='central'?'admin.session.test':'clinic-a.session.test';$prefix=self::prefix($realm);
        $c=$this->call($prefix.'/challenge','POST',body:[],host:$host);SessionTest::check($c['status']===200,$realm.' challenge: '.$c['status']);
        $api=$this->tokens->sign(ServerSessionFixture::claims($realm,profile:$profile?:null));$id=$this->tokens->sign(ServerSessionFixture::claims($realm,$c['body']['data']['nonce'],true,$profile?:null));
        $cookie=implode('; ',array_map(fn($s)=>explode(';',$s)[0],$c['cookies']));
        $start=$this->call($prefix.($realm==='client'?'/session':'/start'),'POST',$api,$cookie,['id_token'=>$id],host:$host);
        SessionTest::check($start['status']===200,$realm.' start: '.$start['status'].' '.($start['body']['error']['code']??''));
        $cookie=implode('; ',array_filter(array_map(fn($s)=>str_contains($s,'Max-Age=0')?'':explode(';',$s)[0],$start['cookies'])));
        foreach($start['cookies'] as $line)SessionTest::check(str_contains($line,'Secure')&&str_contains($line,'HttpOnly')&&str_contains($line,'SameSite=Lax')&&!str_contains($line,'Domain='),'Secure host cookie attributes');
        SessionTest::check(!str_contains(json_encode($start['body']),$api),'No bearer token in session JSON');
        return ['realm'=>$realm,'api'=>$api,'cookie'=>$cookie,'csrf'=>$start['body']['data']['csrf'],'host'=>$host,'prefix'=>$prefix];
    }
    public function close():void
    {
        if(is_resource($this->process)){proc_terminate($this->process);proc_close($this->process);}
        foreach(['private.pem','settings.json','jwks.json','clock'] as $file)if(is_file($this->runtime.'/'.$file))unlink($this->runtime.'/'.$file);
    }
}
