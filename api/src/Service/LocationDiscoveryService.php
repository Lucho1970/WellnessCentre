<?php
declare(strict_types=1);
namespace Wellness\Service;

use Closure;
use Throwable;
use Wellness\Auth\{AuthContext,ApplicationAdminContext};
use Wellness\{Config,Database};
use Wellness\Http\ApiException;

/** Discovery never creates a booking coverage token or reveals a practitioner origin. */
final class LocationDiscoveryService
{
    private int $providerCalls=0;
    private float $started;
    public function __construct(private readonly Database $database,private readonly Config $config,private readonly ?Closure $transport=null) {$this->started=microtime(true);}

    public function settings(): array
    {
        $row=$this->database->connection()->query('SELECT extra_km,cap_km,revision FROM discovery_settings WHERE id=1')->fetch();
        if(!$row)throw new ApiException(503,'discovery_unavailable','Location search is temporarily unavailable.');
        return array_map('intval',$row);
    }
    public function options(): array
    {
        $key=(string)($_ENV['GOOGLE_MAPS_BROWSER_KEY']??getenv('GOOGLE_MAPS_BROWSER_KEY')?:'');
        return [...$this->settings(),'map_browser_key'=>$key];
    }
    public function saveSettings(ApplicationAdminContext $actor,array $body,string $cid): array
    {
        if(array_diff(array_keys($body),['extra_km','cap_km','revision']))throw new ApiException(422,'validation_error','Unexpected discovery settings.');
        foreach(['extra_km'=>[0,200],'cap_km'=>[1,500],'revision'=>[1,PHP_INT_MAX]] as $key=>[$min,$max])if(!is_int($body[$key]??null)||$body[$key]<$min||$body[$key]>$max)throw new ApiException(422,'validation_error','Enter valid discovery distances and reload outdated settings.');
        $pdo=$this->database->connection();
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare('UPDATE discovery_settings SET extra_km=?,cap_km=?,revision=revision+1 WHERE id=1 AND revision=?');$q->execute([$body['extra_km'],$body['cap_km'],$body['revision']]);
            if(!$q->rowCount())throw new ApiException(409,'settings_changed','Discovery settings changed. Reload and try again.');
            $pdo->prepare('INSERT INTO discovery_settings_events(administrator_id,extra_km,cap_km,correlation_id) VALUES(?,?,?,?)')->execute([$actor->id,$body['extra_km'],$body['cap_km'],$cid]);
            $pdo->commit();return $this->settings();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public static function point(mixed $point): array
    {
        if(!is_array($point)||array_diff(array_keys($point),['latitude','longitude']))throw new ApiException(422,'validation_error','Choose a valid search location.');
        foreach(['latitude'=>90,'longitude'=>180] as $key=>$limit){$v=$point[$key]??null;if((!is_float($v)&&!is_int($v))||!is_finite((float)$v)||abs($v)>$limit)throw new ApiException(422,'validation_error','Choose a valid search location.');}
        return ['latitude'=>round((float)$point['latitude'],5),'longitude'=>round((float)$point['longitude'],5)];
    }
    public static function status(int $meters,int $maximum,bool $extra,array $settings): ?string
    {
        if($maximum<1||$meters<0||$meters>$settings['cap_km']*1000)return null;
        if($meters<=$maximum*1000)return 'within_area';
        return $extra&&$meters<=($maximum+$settings['extra_km'])*1000?'enquiry_only':null;
    }
    public function limit(string $key,int $maximum,string $bucket): void
    {
        $pdo=$this->database->connection();$hash=$this->hash($key);
        // Atomic increments and a locking transaction prevent concurrent quota bypass.
        try{
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO discovery_limits(key_hash,bucket) VALUES(?,?) ON DUPLICATE KEY UPDATE attempts=attempts+1')->execute([$hash,$bucket]);
            $q=$pdo->prepare('SELECT attempts FROM discovery_limits WHERE key_hash=? AND bucket=? FOR UPDATE');$q->execute([$hash,$bucket]);$attempts=(int)$q->fetchColumn();
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        if($attempts>$maximum)throw new ApiException(429,'rate_limited','Too many location requests. Please try again later.');
    }
    public function search(int $clinic,array $body,string $ip): array
    {
        if(array_diff(array_keys($body),['point','area','only_within']))throw new ApiException(422,'validation_error','Unexpected location search fields.');
        $this->limit('search-ip:'.$clinic.':'.$ip,30,gmdate('YmdHi'));
        $this->limit('search-clinic:'.$clinic,1500,gmdate('Ymd'));
        if(isset($body['point'])===isset($body['area']))throw new ApiException(422,'validation_error','Choose one search point or address.');
        $only=$body['only_within']??false;if(!is_bool($only))throw new ApiException(422,'validation_error','Choose a valid travel filter.');
        $label='Selected search point';
        if(isset($body['area'])){
            if(!is_string($body['area'])||strlen(trim($body['area']))<3||strlen($body['area'])>250||preg_match('/[\x00-\x1f\x7f]/',$body['area']))throw new ApiException(422,'validation_error','Enter a city, postal code or address (up to 250 characters).');
            $label=trim($body['area']);$point=$this->geocode($label);
        }else $point=self::point($body['point']);
        $matches=$this->matches($clinic,$point);
        if($only)$matches=array_values(array_filter($matches,fn($row)=>$row['status']==='within_area'));
        $pdo=$this->database->connection();
        $pdo->exec('DELETE FROM discovery_geocodes WHERE expires_at<UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM discovery_distances WHERE expires_at<UTC_TIMESTAMP()');
        $q=$pdo->prepare('DELETE FROM discovery_limits WHERE bucket<?');$q->execute([gmdate('Ymd',time()-172800)]);
        return ['point'=>$point,'label'=>$label,'matches'=>$matches,'settings'=>$this->settings(),'approximate_search'=>true];
    }
    private function candidates(int $clinic): array
    {
        $q=$this->database->connection()->prepare("SELECT p.id practitioner_id,s.id service_id,l.id location_id,t.slug practitioner_slug,s.slug service_slug,
            ps.mobile_radius_km,COALESCE(dp.allow_extra,1) allow_extra,l.address_line1,l.address_line2,l.city,l.province,l.postal_code,
            (SELECT MIN(COALESCE(ps.price_override_cents,d.price_cents,s.price_cents)) FROM service_duration_options d WHERE d.service_id=s.id AND d.active=1) starting_price_cents,
            pl.home_address,pl.work_address,pl.work_same_as_home
            FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.status='active'
            JOIN public_team_profiles t ON t.user_id=u.id AND t.clinic_id=u.clinic_id AND t.published=1 AND t.section='practitioner' AND t.show_booking_action=1
            JOIN practitioner_services ps ON ps.practitioner_id=p.id AND ps.active=1 AND ps.offers_mobile=1
            JOIN services s ON s.id=ps.service_id AND s.clinic_id=u.clinic_id AND s.active=1 AND s.published=1
            JOIN service_locations sl ON sl.service_id=s.id AND sl.active=1
            JOIN locations l ON l.id=sl.location_id AND l.clinic_id=s.clinic_id AND l.is_bookable=1
            JOIN practitioner_locations plink ON plink.practitioner_id=p.id AND plink.location_id=l.id AND plink.active=1
            LEFT JOIN practitioner_private_locations pl ON pl.practitioner_id=p.id
            LEFT JOIN discovery_preferences dp ON dp.clinic_id=s.clinic_id AND dp.practitioner_id=p.id AND dp.service_id=s.id
            WHERE p.active=1 AND ps.mobile_radius_km>0 AND EXISTS(SELECT 1 FROM service_duration_options d WHERE d.service_id=s.id AND d.active=1)
            ORDER BY p.id,s.id,l.id LIMIT 2001");
        $q->execute([$clinic]);$rows=$q->fetchAll();
        if(count($rows)>2000)throw new ApiException(503,'discovery_capacity','Location search needs a capacity update. Please contact the clinic.');
        return $rows;
    }
    private function origin(array $row): string
    {
        $raw=$row[(bool)$row['work_same_as_home']?'home_address':'work_address']??null;
        $address=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):$row;
        foreach(['address_line1','city','province','postal_code'] as $field)if(empty($address[$field]))return '';
        return implode(', ',array_filter(array_map(fn($field)=>trim((string)($address[$field]??'')),['address_line1','address_line2','city','province','postal_code']))).', Canada';
    }
    public function matches(int $clinic,array $point): array
    {
        $settings=$this->settings();$rows=$this->candidates($clinic);$origins=[];$eligible=[];$distances=[];$missing=[];
        foreach($rows as $row){
            $address=$this->origin($row);if($address==='')continue;
            $originKey=$this->hash($address);$origin=$origins[$originKey]??=$this->geocode($address);
            if(self::straightDistance($origin,$point)>min($settings['cap_km'],(int)$row['mobile_radius_km']+((bool)$row['allow_extra']?$settings['extra_km']:0))*1000)continue;
            $key=$this->hash($originKey.':'.json_encode($point));$eligible[]=[$row,$key];
            if(array_key_exists($key,$distances)||isset($missing[$key]))continue;
            $q=$this->database->connection()->prepare('SELECT distance_meters FROM discovery_distances WHERE cache_key=? AND expires_at>UTC_TIMESTAMP()');$q->execute([$key]);$cache=$q->fetch();
            if($cache)$distances[$key]=$cache['distance_meters']===null?null:(int)$cache['distance_meters'];else $missing[$key]=$origin;
        }
        foreach(array_chunk($missing,25,true) as $batch){
            $result=$this->call('routes',['origins'=>array_values($batch),'destination'=>$point]);
            foreach(array_keys($batch) as $index=>$key){
                if(!array_key_exists($index,$result)||($result[$index]!==null&&(!is_int($result[$index])||$result[$index]<0)))throw new ApiException(503,'discovery_unavailable','Driving distances are temporarily unavailable.');
                $distances[$key]=$result[$index];
                $this->database->connection()->prepare('INSERT INTO discovery_distances(cache_key,distance_meters,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE distance_meters=VALUES(distance_meters),expires_at=VALUES(expires_at)')->execute([$key,$result[$index]]);
            }
        }
        $matches=[];
        foreach($eligible as [$row,$key]){
            $meters=$distances[$key];if($meters===null)continue;
            $status=self::status($meters,(int)$row['mobile_radius_km'],(bool)$row['allow_extra'],$settings);if($status===null)continue;
            $match=['practitioner_id'=>(int)$row['practitioner_id'],'service_id'=>(int)$row['service_id'],'location_id'=>(int)$row['location_id'],'practitioner_slug'=>$row['practitioner_slug'],'service_slug'=>$row['service_slug'],'status'=>$status,'starting_price_cents'=>(int)$row['starting_price_cents']];
            $match['token']=$this->seal(['clinic'=>$clinic,'point'=>$point,'practitioner_id'=>$match['practitioner_id'],'service_id'=>$match['service_id'],'location_id'=>$match['location_id'],'exp'=>time()+1800]);$matches[]=$match;
        }
        return $matches;
    }
    public function verify(int $clinic,string $token): array
    {
        $raw=base64_decode($token,true);$plain=is_string($raw)&&strlen($raw)>28?openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)):false;
        $proof=$plain?json_decode($plain,true):null;
        if(!is_array($proof)||($proof['clinic']??null)!==$clinic||($proof['exp']??0)<time())throw new ApiException(422,'search_expired','Refresh the location search before enquiring.');
        foreach($this->matches($clinic,self::point($proof['point']??null)) as $match)if($match['practitioner_id']===$proof['practitioner_id']&&$match['service_id']===$proof['service_id']&&$match['location_id']===$proof['location_id']&&$match['status']==='enquiry_only')return $match;
        throw new ApiException(422,'travel_enquiry_unavailable','This travel enquiry is no longer available. Refresh the search.');
    }
    private function seal(array $value): string
    {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($value,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new ApiException(503,'discovery_unavailable','Location search is temporarily unavailable.');
        return base64_encode($iv.$tag.$cipher);
    }
    private function key(): string{return hash('sha256',$this->config->addressValidationSigningKey?:$this->config->appKey,true);}
    private function hash(string $value): string{return hash_hmac('sha256',$value,$this->key());}
    public static function straightDistance(array $a,array $b): float
    {
        $lat=deg2rad($b['latitude']-$a['latitude']);$lng=deg2rad($b['longitude']-$a['longitude']);
        $v=sin($lat/2)**2+cos(deg2rad($a['latitude']))*cos(deg2rad($b['latitude']))*sin($lng/2)**2;
        return 6371000*2*asin(min(1,sqrt($v)));
    }
    private function geocode(string $address): array
    {
        $key=$this->hash(strtolower(trim($address)));$pdo=$this->database->connection();
        $q=$pdo->prepare('SELECT latitude,longitude FROM discovery_geocodes WHERE cache_key=? AND expires_at>UTC_TIMESTAMP()');$q->execute([$key]);$row=$q->fetch();
        if($row)return ['latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude']];
        $point=self::point($this->call('geocode',['address'=>$address]));
        $pdo->prepare('INSERT INTO discovery_geocodes(cache_key,latitude,longitude,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),expires_at=VALUES(expires_at)')->execute([$key,$point['latitude'],$point['longitude']]);
        return $point;
    }
    private function call(string $operation,array $payload): array
    {
        if($this->transport)return ($this->transport)($operation,$payload);
        if(++$this->providerCalls>40||microtime(true)-$this->started>18)throw new ApiException(503,'discovery_preparing','Travel areas are being prepared. Please retry the search.');
        $this->limit('maps-global',5000,gmdate('Ymd'));
        if($this->config->googleMapsApiKey==='')throw new ApiException(503,'maps_not_configured','Location search is not configured. Please contact the clinic.');
        $key=$this->config->googleMapsApiKey;$headers=[];$body=null;
        if($operation==='geocode')$url='https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query(['address'=>$payload['address'],'components'=>'country:CA','key'=>$key]);
        else{
            $url='https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';$headers=['Content-Type: application/json','X-Goog-Api-Key: '.$key,'X-Goog-FieldMask: originIndex,destinationIndex,distanceMeters,status,condition'];
            $waypoint=fn($p)=>['waypoint'=>['location'=>['latLng'=>$p]]];
            $body=json_encode(['origins'=>array_map($waypoint,$payload['origins']),'destinations'=>[$waypoint($payload['destination'])],'travelMode'=>'DRIVE','routingPreference'=>'TRAFFIC_UNAWARE'],JSON_THROW_ON_ERROR);
        }
        $curl=curl_init($url);$response='';
        try{
            curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>1048576)return 0;$response.=$chunk;return strlen($chunk);}]);
            if($body!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
            if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)throw new ApiException(503,'maps_unavailable','Location search is temporarily unavailable. Try again.');
        }finally{curl_close($curl);}
        try{$data=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new ApiException(503,'maps_unavailable','Location search is temporarily unavailable. Try again.');}
        if(!is_array($data))throw new ApiException(503,'maps_unavailable','Location search is temporarily unavailable. Try again.');
        if($operation==='geocode'){
            if(!in_array($data['status']??'',['OK','ZERO_RESULTS'],true))throw new ApiException(503,'maps_unavailable','Location search is temporarily unavailable. Try again.');
            $result=$data['results'][0]??null;
            if(($data['status']??'')!=='OK'||count($data['results']??[])!==1||!$result||($result['partial_match']??false))throw new ApiException(422,'location_not_found','Use a more specific Canadian city, postal code or address.');
            return ['latitude'=>$result['geometry']['location']['lat'],'longitude'=>$result['geometry']['location']['lng']];
        }
        $result=[];
        foreach($data as $item){
            if(!isset($item['originIndex']))throw new ApiException(503,'maps_unavailable','Driving distances are temporarily unavailable.');
            if(($item['status']['code']??0)!==0)throw new ApiException(503,'maps_unavailable','Driving distances are temporarily unavailable.');
            $result[(int)$item['originIndex']]=($item['condition']??'')==='ROUTE_EXISTS'&&isset($item['distanceMeters'])?(int)$item['distanceMeters']:null;
        }
        return $result;
    }
    public function preferences(AuthContext $actor,?array $body=null,string $cid=''): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'forbidden','Practitioner access is required.');
        $pdo=$this->database->connection();
        if($body!==null){
            if(array_diff(array_keys($body),['service_id','allow_extra'])||!is_int($body['service_id']??null)||!is_bool($body['allow_extra']??null))throw new ApiException(422,'validation_error','Choose a valid travel enquiry preference.');
            $q=$pdo->prepare("INSERT INTO discovery_preferences(clinic_id,practitioner_id,service_id,allow_extra)
              SELECT u.clinic_id,p.id,ps.service_id,? FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.status='active'
              JOIN practitioner_services ps ON ps.practitioner_id=p.id AND ps.active=1 AND ps.offers_mobile=1 JOIN services s ON s.id=ps.service_id AND s.clinic_id=u.clinic_id AND s.active=1
              WHERE p.user_id=? AND p.active=1 AND ps.service_id=? ON DUPLICATE KEY UPDATE allow_extra=VALUES(allow_extra)");
            $q->execute([(int)$body['allow_extra'],$actor->clinicId,$actor->userId,$body['service_id']]);
            // Verify membership even when saving an unchanged preference (PDO rowCount can be zero).
            $rows=$this->preferences($actor);if(!in_array($body['service_id'],array_column($rows['items'],'service_id'),true))throw new ApiException(404,'assignment_not_found','Assigned service not found.');
            (new AuditLogger($this->database))->write($actor->clinicId,$actor,$cid,'discovery.preference','service',$body['service_id'],'success',['allow_extra'=>$body['allow_extra']]);return $rows;
        }
        $q=$pdo->prepare("SELECT s.id service_id,s.name,COALESCE(dp.allow_extra,1) allow_extra FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=? AND u.status='active' JOIN practitioner_services ps ON ps.practitioner_id=p.id AND ps.active=1 AND ps.offers_mobile=1 JOIN services s ON s.id=ps.service_id AND s.clinic_id=u.clinic_id AND s.active=1 LEFT JOIN discovery_preferences dp ON dp.clinic_id=u.clinic_id AND dp.practitioner_id=p.id AND dp.service_id=s.id WHERE p.user_id=? AND p.active=1 ORDER BY s.name");$q->execute([$actor->clinicId,$actor->userId]);
        return ['items'=>array_map(fn($row)=>['service_id'=>(int)$row['service_id'],'name'=>$row['name'],'allow_extra'=>(bool)$row['allow_extra']],$q->fetchAll())];
    }
}
