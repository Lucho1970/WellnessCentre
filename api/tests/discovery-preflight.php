<?php
declare(strict_types=1);
$file=tempnam(sys_get_temp_dir(),'wellness-maps-env-');$checks=0;
try{
    $env=getenv();unset($env['GOOGLE_MAPS_API_KEY'],$env['GOOGLE_MAPS_BROWSER_KEY']);
    foreach([
        ["GOOGLE_MAPS_API_KEY=synthetic-private-key\nGOOGLE_MAPS_BROWSER_KEY=synthetic-browser-key\n",[],0],
        ['',[],2],
        ['', ['--check-google'],2],
        ["GOOGLE_MAPS_API_KEY=synthetic-private-key\n",['--unknown'],1],
    ] as [$contents,$extra,$expected]){
        file_put_contents($file,$contents);
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/discovery-preflight.php','--env',$file,...$extra],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('Preflight process unavailable.');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==$expected)throw new RuntimeException('Wrong preflight exit status.');$checks++;
        if(str_contains($output,'synthetic-private-key')||str_contains($output,'synthetic-browser-key'))throw new RuntimeException('Preflight exposed key material.');$checks++;
        if($expected===0&&!str_contains($output,'no network/database connection attempted'))throw new RuntimeException('Default must be configuration-only.');
    }
    echo "Discovery preflight: $checks checks passed; no Google/database requests.\n";
}finally{if(is_file($file))unlink($file);}
