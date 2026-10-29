<?php
declare(strict_types=1);
namespace Wellness\Service;

use Closure;
use Throwable;
use Wellness\Http\ApiException;

/** Fixed Google endpoints; provider bodies, URLs, keys and addresses never enter diagnostics. */
final class DiscoveryMapsClient
{
    private Closure $transport;
    private Closure $logger;
    public function __construct(private readonly string $key, ?Closure $transport=null, ?Closure $logger=null, private readonly ?string $correlationId=null)
    {
        $this->transport=$transport??$this->request(...);
        $this->logger=$logger??static fn(array $event)=>error_log(json_encode($event,JSON_THROW_ON_ERROR));
    }

    public function call(string $operation,array $payload): array
    {
        if(!in_array($operation,['geocode','routes'],true))throw new \InvalidArgumentException('Unsupported maps operation.');
        if($this->key==='')throw new ApiException(503,'maps_not_configured','Location search is not configured. Please contact the clinic.');
        $headers=[];$body=null;
        if($operation==='geocode'){
            $address=$payload['address']??null;
            if(!is_string($address)||trim($address)===''||strlen($address)>250)throw new \InvalidArgumentException('Invalid geocoding input.');
            $url='https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query(['address'=>$address,'components'=>'country:CA','key'=>$this->key]);
        }else{
            $origins=$payload['origins']??null;
            if(!is_array($origins)||!array_is_list($origins)||count($origins)<1||count($origins)>25)throw new \InvalidArgumentException('Invalid route origins.');
            $origins=array_map(static function($point):array{
                // Discovery geocodes private origins before calling Routes; keep
                // sending coordinates only, never add raw addresses to this API.
                return ['waypoint'=>['location'=>['latLng'=>LocationDiscoveryService::point($point)]]];
            },$origins);
            $destination=LocationDiscoveryService::point($payload['destination']??null);
            $url='https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';
            $headers=['Content-Type: application/json','X-Goog-Api-Key: '.$this->key,'X-Goog-FieldMask: originIndex,destinationIndex,distanceMeters,status,condition'];
            $body=json_encode(['origins'=>$origins,'destinations'=>[['waypoint'=>['location'=>['latLng'=>$destination]]]],'travelMode'=>'DRIVE','routingPreference'=>'TRAFFIC_UNAWARE'],JSON_THROW_ON_ERROR);
        }
        try{$reply=($this->transport)($url,$headers,$body);}catch(Throwable){$this->failure($operation,'transport_exception');}
        if(!is_array($reply)||!is_int($reply['status']??null)||!is_int($reply['errno']??null)||!is_string($reply['body']??null))$this->failure($operation,'invalid_transport');
        if($reply['errno']!==0)$this->failure($operation,'transport',$reply['status'],$reply['errno']);
        if(strlen($reply['body'])>1048576)$this->failure($operation,'response_limit',$reply['status']);
        $data=null;
        try{$data=json_decode($reply['body'],true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){$this->failure($operation,$reply['status']===200?'invalid_json':'http',$reply['status']);}
        if($reply['status']!==200)$this->failure($operation,'http',$reply['status'],0,is_array($data)?$data:[]);
        if(!is_array($data))$this->failure($operation,'invalid_json',$reply['status']);
        if($operation==='geocode'){
            if(!in_array($data['status']??null,['OK','ZERO_RESULTS'],true))$this->failure($operation,'provider_status',200,0,$data);
            $results=$data['results']??null;
            if(!is_array($results)||!array_is_list($results))$this->failure($operation,'invalid_response',200);
            $result=$results[0]??null;
            if($data['status']!=='OK'||count($results)!==1||!is_array($result)||($result['partial_match']??false))throw new ApiException(422,'location_not_found','Use a more specific Canadian city, postal code or address.');
            $location=$result['geometry']['location']??null;
            try{return LocationDiscoveryService::point(['latitude'=>$location['lat']??null,'longitude'=>$location['lng']??null]);}
            catch(ApiException){$this->failure($operation,'invalid_response',200);}
        }
        if(!array_is_list($data)||count($data)!==count($origins))$this->failure($operation,'invalid_response',200,0,$data);
        $result=[];
        foreach($data as $item){
            if(!is_array($item))$this->failure($operation,'invalid_response',200);
            $index=$item['originIndex']??null;
            if(!is_int($index)||$index<0||$index>=count($origins)||array_key_exists($index,$result)||($item['destinationIndex']??0)!==0)$this->failure($operation,'invalid_response',200);
            if(($item['status']['code']??0)!==0)$this->failure($operation,'route_status',200);
            if(($item['condition']??null)==='ROUTE_NOT_FOUND'){$result[$index]=null;continue;}
            if(($item['condition']??null)!=='ROUTE_EXISTS'||!is_int($item['distanceMeters']??null)||$item['distanceMeters']<0)$this->failure($operation,'invalid_response',200);
            $result[$index]=$item['distanceMeters'];
        }
        return $result;
    }

    private function request(string $url,array $headers,?string $body): array
    {
        $curl=curl_init($url);$response='';
        if($curl===false)return ['status'=>0,'errno'=>2,'body'=>''];
        try{
            curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>1048576)return 0;$response.=$chunk;return strlen($chunk);}]);
            if($body!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
            curl_exec($curl);
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'errno'=>curl_errno($curl),'body'=>$response];
        }finally{curl_close($curl);}
    }

    private function failure(string $operation,string $reason,int $status=0,int $errno=0,array $data=[]): never
    {
        $providerStatus=$data['error']['status']??$data['status']??null;
        $allowed=['REQUEST_DENIED','OVER_QUERY_LIMIT','OVER_DAILY_LIMIT','INVALID_REQUEST','UNKNOWN_ERROR','PERMISSION_DENIED','RESOURCE_EXHAUSTED','INVALID_ARGUMENT','UNAUTHENTICATED','UNAVAILABLE','INTERNAL','DEADLINE_EXCEEDED'];
        $event=['event'=>'discovery_maps_failure','operation'=>$operation,'reason'=>$reason,'http_status'=>$status,'curl_errno'=>$errno,'provider_status'=>in_array($providerStatus,$allowed,true)?$providerStatus:'unspecified'];
        $details=$data['error']['details']??[];
        if(is_array($details))foreach($details as $detail){
            $code=is_array($detail)?($detail['reason']??null):null;
            if(in_array($code,['API_KEY_HTTP_REFERRER_BLOCKED','API_KEY_IP_ADDRESS_BLOCKED','API_KEY_SERVICE_BLOCKED','SERVICE_DISABLED','BILLING_DISABLED','API_KEY_INVALID','CONSUMER_INVALID'],true)){$event['provider_reason']=$code;break;}
        }
        $event['correlation_id']=is_string($this->correlationId)&&preg_match('/^[a-zA-Z0-9-]{8,64}$/D',$this->correlationId)?$this->correlationId:'unavailable';
        ($this->logger)($event);
        throw new ApiException(503,'maps_unavailable','Location search is temporarily unavailable. Try again.');
    }
}
