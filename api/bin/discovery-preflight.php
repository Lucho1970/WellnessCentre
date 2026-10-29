<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';

try{
    $arguments=array_slice($argv,1);$checkGoogle=false;
    if(end($arguments)==='--check-google'){$checkGoogle=true;array_pop($arguments);}
    if($arguments===[])$envFile=dirname(__DIR__).'/.env';
    elseif(count($arguments)===2&&$arguments[0]==='--env')$envFile=$arguments[1];
    else throw new RuntimeException('Invalid arguments.');
    if(!is_file($envFile))throw new RuntimeException('Private configuration is unavailable.');
    \Wellness\Config::loadEnvFile($envFile);
    $key=$_ENV['GOOGLE_MAPS_API_KEY']??getenv('GOOGLE_MAPS_API_KEY');
    if($key===false)$key='';
    if(!is_string($key))throw new RuntimeException('Invalid key configuration.');
    $browserKey=$_ENV['GOOGLE_MAPS_BROWSER_KEY']??getenv('GOOGLE_MAPS_BROWSER_KEY');
    echo json_encode(['server_key_configured'=>$key!=='','browser_key_configured'=>is_string($browserKey)&&$browserKey!=='','google_checks_requested'=>$checkGoogle],JSON_THROW_ON_ERROR).PHP_EOL;
    if($key===''){fwrite(STDERR,"Location discovery's private server key is missing. No network/database connection attempted.\n");exit(2);}
    if(!$checkGoogle){echo "Configuration presence only; no network/database connection attempted. Add --check-google to test public landmarks against Google using the host's effective server key.\n";exit;}
    // Explicit operator opt-in: at most one geocode and one route-matrix request,
    // using public Toronto landmarks, with no clinic/patient database access.
    $client=new \Wellness\Service\DiscoveryMapsClient($key,logger:static fn(array $event)=>fwrite(STDERR,json_encode($event,JSON_THROW_ON_ERROR).PHP_EOL),correlationId:'maps-preflight-'.bin2hex(random_bytes(8)));
    $point=$client->call('geocode',['address'=>'Toronto City Hall, Toronto, Ontario, Canada']);
    echo "Geocoding API: passed.\n";
    $client->call('routes',['origins'=>[$point],'destination'=>['latitude'=>43.6677,'longitude'=>-79.3948]]);
    echo "Routes API: passed. No database connection, private address or provider-setting change. Hosted application/clinic acceptance is still required.\n";
}catch(\Wellness\Http\ApiException $error){
    fwrite(STDERR,'Maps preflight failed ('.$error->errorCode."). Use the redacted diagnostic; do not paste keys, private configuration or addresses.\n");exit(1);
}catch(Throwable){
    fwrite(STDERR,"Maps preflight failed. Check private configuration and usage: php api/bin/discovery-preflight.php [--env private-env-file] [--check-google].\n");exit(1);
}
