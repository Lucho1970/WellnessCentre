<?php
declare(strict_types=1);
$file=tempnam(sys_get_temp_dir(),'wellness-pilot-env-');$checks=0;
try {
    $env=getenv();foreach(['PILOT_MODE','PILOT_ALLOWED_IDENTITIES','PILOT_ALLOWED_EMAILS','PILOT_ALLOWED_SMS'] as $key)unset($env[$key]);
    $run=static function(string $contents)use($file,$env):array{
        file_put_contents($file,$contents);
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/pilot-preflight.php','--env',$file],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('Preflight test process unavailable.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($process),$out.$error];
    };
    $identity='[{"adapter":"entra-workforce","issuer":"https://issuer.example.test/v2.0","subject":"private-test-subject"}]';
    foreach([
        ["PILOT_MODE=true\nPILOT_ALLOWED_IDENTITIES=$identity\n",0],
        ["PILOT_MODE=true\nPILOT_ALLOWED_IDENTITIES=[]\n",2],
        ["PILOT_MODE=false\nPILOT_ALLOWED_IDENTITIES=$identity\n",2],
        ["PILOT_MODE=flase\nPILOT_ALLOWED_IDENTITIES=$identity\n",1],
        ["PILOT_MODE=true\nPILOT_ALLOWED_IDENTITIES=$identity\nPILOT_ALLOWED_EMAILS=[\"private-recipient@example.test\",\"bad-address\"]\n",1],
    ] as [$contents,$expected]){
        [$code,$text]=$run($contents);if($code!==$expected)throw new RuntimeException('Unexpected pilot preflight result.');$checks++;
        if(str_contains($text,'private-test-subject')||str_contains($text,'private-recipient@example.test')||str_contains($text,'issuer.example.test'))throw new RuntimeException('Preflight exposed allowlist values.');$checks++;
    }
    echo "Pilot preflight: $checks checks passed; no database/provider configuration required.\n";
} finally {if(is_file($file))unlink($file);}
