<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\DiscoveryMapsClient;
use Wellness\Http\ApiException;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$key='synthetic-private-server-key';$address='Synthetic private origin, Ontario, Canada';
$payload=['origins'=>[['latitude'=>43.6,'longitude'=>-79.3],['latitude'=>43.7,'longitude'=>-79.4]],'destination'=>['latitude'=>43.12345,'longitude'=>-79.12345]];
$logs=[];$requests=[];
$matrix=[['originIndex'=>1,'destinationIndex'=>0,'condition'=>'ROUTE_NOT_FOUND','status'=>['code'=>0]],['originIndex'=>0,'destinationIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1234,'status'=>['code'=>0]]];
$client=new DiscoveryMapsClient($key,static function($url,$headers,$body)use(&$requests,$matrix):array{$requests[]=[$url,$headers,$body];return ['status'=>200,'errno'=>0,'body'=>json_encode($matrix)];},static function($event)use(&$logs):void{$logs[]=$event;},'synthetic-correlation-123');
$result=$client->call('routes',$payload);
$check($result[0]===1234&&$result[1]===null,'Reordered route matrix maps distances and unreachable routes by origin index.');
[$url,$headers,$raw]=$requests[0];$sent=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
$check($url==='https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix','Fixed Routes endpoint.');
$check($sent['origins']===[['waypoint'=>['location'=>['latLng'=>$payload['origins'][0]]]],['waypoint'=>['location'=>['latLng'=>$payload['origins'][1]]]]],'Geocoded practitioner origins retain numeric coordinate waypoints.');
$check(!str_contains($raw,$address)&&!str_contains($raw,'address'),'Raw practitioner addresses are not added to Routes requests.');
$check($sent['destinations']===[['waypoint'=>['location'=>['latLng'=>$payload['destination']]]]],'Search-point destination has numeric latitude/longitude.');
$check($sent['travelMode']==='DRIVE'&&$sent['routingPreference']==='TRAFFIC_UNAWARE','Existing driving-distance policy retained.');
$check(in_array('X-Goog-Api-Key: '.$key,$headers,true)&&in_array('X-Goog-FieldMask: originIndex,destinationIndex,distanceMeters,status,condition',$headers,true),'Server key and bounded response field mask retained.');
$check($logs===[],'Successful requests add no provider diagnostics.');

$geo=new DiscoveryMapsClient($key,static function($url,$headers,$body)use($check,$key,$address):array{
    $parts=parse_url($url);parse_str($parts['query'],$query);
    $check($parts['scheme']==='https'&&$parts['host']==='maps.googleapis.com'&&$parts['path']==='/maps/api/geocode/json','Fixed Geocoding endpoint.');
    $check($query===['address'=>$address,'components'=>'country:CA','key'=>$key]&&$body===null,'Canadian geocoding parameters and GET retained.');
    return ['status'=>200,'errno'=>0,'body'=>json_encode(['status'=>'OK','results'=>[['geometry'=>['location'=>['lat'=>43.1234567,'lng'=>-79.1234567]]]]])];
});
$check($geo->call('geocode',['address'=>$address])===['latitude'=>43.12346,'longitude'=>-79.12346],'Geocoded points remain rounded to five decimals.');

$refuse=static function(string $operation,array $input,array|Closure $reply,?string $expectedProvider=null,?string $expectedReason=null)use($check,$key,$address):void{
    $events=[];
    $transport=$reply instanceof Closure?$reply:static fn()=> $reply;
    $client=new DiscoveryMapsClient($key,$transport,static function($event)use(&$events):void{$events[]=$event;},'synthetic-correlation-123');
    try{$client->call($operation,$input);throw new RuntimeException('Expected provider refusal.');}catch(ApiException $error){
        $check($error->status===503&&$error->errorCode==='maps_unavailable','Provider failures keep safe public error contract.');
        $check($error->getMessage()==='Location search is temporarily unavailable. Try again.'&&$error->fields===[],'Provider details are not exposed in public errors.');
    }
    $check(count($events)===1&&$events[0]['event']==='discovery_maps_failure'&&$events[0]['operation']===$operation&&$events[0]['correlation_id']==='synthetic-correlation-123','One correlated operation-only diagnostic.');
    $serialized=json_encode($events);
    foreach([$key,$address,'43.12345','-79.12345','maps.googleapis.com','Provider secret text'] as $secret)$check(!str_contains($serialized,$secret),'Diagnostics omit keys, addresses, coordinates, URLs and arbitrary provider text.');
    if($expectedProvider!==null)$check($events[0]['provider_status']===$expectedProvider,'Allowlisted provider status classification.');
    if($expectedReason!==null)$check(($events[0]['provider_reason']??null)===$expectedReason,'Allowlisted Google restriction reason.');
};
$denyBody=['error'=>['status'=>'PERMISSION_DENIED','message'=>'Provider secret text '.$key.' '.$address,'details'=>[['reason'=>'API_KEY_IP_ADDRESS_BLOCKED','metadata'=>['key'=>$key,'address'=>$address]]]]];
$refuse('geocode',['address'=>$address],['status'=>403,'errno'=>0,'body'=>json_encode($denyBody)],'PERMISSION_DENIED','API_KEY_IP_ADDRESS_BLOCKED');
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>json_encode(['status'=>'REQUEST_DENIED','error_message'=>'Provider secret text '.$key])],'REQUEST_DENIED');
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>json_encode(['status'=>$key,'results'=>[]])],'unspecified');
$refuse('routes',$payload,['status'=>400,'errno'=>0,'body'=>json_encode(['error'=>['status'=>'INVALID_ARGUMENT']])],'INVALID_ARGUMENT');
$refuse('routes',$payload,['status'=>429,'errno'=>0,'body'=>json_encode(['error'=>['status'=>'RESOURCE_EXHAUSTED']])],'RESOURCE_EXHAUSTED');
$refuse('geocode',['address'=>$address],['status'=>0,'errno'=>28,'body'=>'Provider secret text '.$address]);
$refuse('geocode',['address'=>$address],static function()use($key):never{throw new RuntimeException('Provider secret text '.$key);});
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>'not json '.$key]);
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>str_repeat('x',1048577)]);
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>'null']);
$refuse('geocode',['address'=>$address],['status'=>200,'errno'=>0,'body'=>json_encode(['status'=>'OK','results'=>[['geometry'=>['location'=>['lat'=>'private','lng'=>0]]]]])]);
foreach([
    [], [['originIndex'=>0]],
    [['originIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1],['originIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1]],
    [['originIndex'=>2,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1],['originIndex'=>1,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1]],
    [['originIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>-1],$matrix[0]],
    [['originIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>'1234'],$matrix[0]],
    [['originIndex'=>0,'destinationIndex'=>1,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1],$matrix[0]],
    [['originIndex'=>0,'condition'=>'ROUTE_EXISTS','distanceMeters'=>1,'status'=>['code'=>7]],$matrix[0]],
    ['malformed',$matrix[0]],
] as $bad)$refuse('routes',$payload,['status'=>200,'errno'=>0,'body'=>json_encode($bad)]);

$noNetwork=static function():never{throw new RuntimeException('Unexpected network attempt.');};
foreach([['operation'=>'unknown','input'=>[]],['operation'=>'routes','input'=>['origins'=>[],'destination'=>$payload['destination']]],['operation'=>'routes','input'=>['origins'=>array_fill(0,26,$payload['origins'][0]),'destination'=>$payload['destination']]],['operation'=>'geocode','input'=>['address'=>'']]] as $case){
    try{(new DiscoveryMapsClient($key,$noNetwork))->call($case['operation'],$case['input']);throw new RuntimeException('Invalid input accepted.');}catch(InvalidArgumentException){$check(true,'Invalid provider requests rejected before transport.');}
}
try{(new DiscoveryMapsClient($key,$noNetwork))->call('routes',['origins'=>[$address],'destination'=>$payload['destination']]);throw new RuntimeException('Raw address accepted as a route point.');}catch(ApiException $error){$check($error->errorCode==='validation_error','Raw address cannot replace an already geocoded origin.');}
try{(new DiscoveryMapsClient('',$noNetwork))->call('geocode',['address'=>$address]);throw new RuntimeException('Missing key accepted.');}catch(ApiException $error){$check($error->errorCode==='maps_not_configured','Missing server key fails before transport.');}
foreach([['status'=>'ZERO_RESULTS','results'=>[]],['status'=>'OK','results'=>[['partial_match'=>true]]],['status'=>'OK','results'=>[[],[]]]] as $ambiguous){
    try{(new DiscoveryMapsClient($key,static fn()=>['status'=>200,'errno'=>0,'body'=>json_encode($ambiguous)]))->call('geocode',['address'=>$address]);throw new RuntimeException('Expected location refinement.');}catch(ApiException $error){$check($error->status===422&&$error->errorCode==='location_not_found','Missing/ambiguous/partial geocode remains a refinement response.');}
}
echo "Discovery Maps provider contract: $checks checks passed; all network calls fake.\n";
