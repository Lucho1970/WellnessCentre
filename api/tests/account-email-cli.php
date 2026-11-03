<?php
declare(strict_types=1);
// Exercise an exact copy with a synthetic autoload tripwire, never the real private environment.
$runtime=sys_get_temp_dir().'/wellness-account-email-cli-'.bin2hex(random_bytes(6));
mkdir($runtime.'/bin',0700,true);mkdir($runtime.'/vendor',0700,true);
copy(dirname(__DIR__).'/bin/send-account-emails.php',$runtime.'/bin/send-account-emails.php');
file_put_contents($runtime.'/bin/probe.php','<?php echo "synthetic-router-ready";');
file_put_contents($runtime.'/vendor/autoload.php','<?php file_put_contents(dirname(__DIR__)."/autoload-used","synthetic");throw new RuntimeException("Synthetic autoload tripwire");');
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
if (!$listener) throw new RuntimeException('Cannot reserve synthetic test port.');
$port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);fclose($listener);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$runtime.'/bin'],[0=>['pipe','r'],1=>['file',$runtime.'/server.log','a'],2=>['file',$runtime.'/server.log','a']],$pipes,null,null,['bypass_shell'=>true]);
if (!is_resource($server)) throw new RuntimeException('Cannot start synthetic HTTP server.');
fclose($pipes[0]);
try {
    $ready=false;
    for($n=0;$n<100;$n++){
        $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$message,.1);
        if ($socket) {fclose($socket);$ready=true;break;}
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('Synthetic HTTP server did not start.');
    $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5,'follow_location'=>0]]);
    if (file_get_contents('http://127.0.0.1:'.$port.'/probe.php',false,$context)!=='synthetic-router-ready') throw new RuntimeException('Synthetic HTTP routing failed.');
    $body=file_get_contents('http://127.0.0.1:'.$port.'/send-account-emails.php',false,$context);
    if (!str_contains($http_response_header[0],' 404 ') || $body!=='') throw new RuntimeException('HTTP reached the private email entry point.');
    if (is_file($runtime.'/autoload-used')) throw new RuntimeException('HTTP loaded private configuration bootstrap.');
    $cli=proc_open([PHP_BINARY,$runtime.'/bin/send-account-emails.php'],[0=>['pipe','r'],1=>['file',$runtime.'/cli.log','a'],2=>['file',$runtime.'/cli.log','a']],$cliPipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($cli)) throw new RuntimeException('Cannot run synthetic CLI.');fclose($cliPipes[0]);
    $exit=proc_close($cli);
    if ($exit===0 || !is_file($runtime.'/autoload-used')) throw new RuntimeException('CLI did not reach its synthetic bootstrap.');
    echo "Account email CLI boundary: 4 checks passed.\n";
} finally {proc_terminate($server);proc_close($server);}
