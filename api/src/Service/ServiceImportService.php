<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Called only inside AdminService's current clinic-wide administrator transaction/consent lock. */
final class ServiceImportService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public static function key(string $text): string
    {return mb_strtolower(preg_replace('/\s+/u',' ',trim($text))??'', 'UTF-8');}

    private static function uuid(): string
    {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}

    private static function identifier(mixed $id): string
    {if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id))throw new ApiException(422,'invalid_service_import','Invalid import reference.');return $id;}

    private function catalog(AuthContext $actor): array
    {
        $pdo=$this->database->connection();
        if(!$pdo->inTransaction())throw new \LogicException('Service import requires the clinic administrator transaction.');
        $q=$pdo->prepare('SELECT services.*,HEX(WEIGHT_STRING(CONVERT(name USING utf8mb4) COLLATE utf8mb4_unicode_ci)) import_name_key FROM services WHERE clinic_id=? ORDER BY id FOR UPDATE');$q->execute([$actor->clinicId]);$services=$q->fetchAll();
        $q=$pdo->prepare('SELECT service_categories.*,HEX(WEIGHT_STRING(CONVERT(name USING utf8mb4) COLLATE utf8mb4_unicode_ci)) import_name_key FROM service_categories WHERE clinic_id=? ORDER BY id FOR UPDATE');$q->execute([$actor->clinicId]);$categories=$q->fetchAll();
        $q=$pdo->prepare('SELECT d.* FROM service_duration_options d JOIN services s ON s.id=d.service_id WHERE s.clinic_id=? ORDER BY d.id FOR UPDATE');$q->execute([$actor->clinicId]);$durations=$q->fetchAll();
        return ['services'=>$services,'categories'=>$categories,'durations'=>$durations];
    }

    public function preview(AuthContext $actor,array $body,string $cid,callable $validate,callable $authorizeUpdate): array
    {
        if(array_diff(array_keys($body),['request_key','file_name','rows','duplicate_policy','create_categories']))throw new ApiException(422,'invalid_service_import','Unexpected import fields.');
        $request=self::identifier($body['request_key']??null);$rows=$body['rows']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>250)throw new ApiException(422,'invalid_service_import','Import between 1 and 250 service rows.');
        $policy=$body['duplicate_policy']??'skip';$create=$body['create_categories']??false;$file=$body['file_name']??'';
        if(!in_array($policy,['skip','update_content'],true)||!is_bool($create)||!is_string($file)||mb_strlen($file)>190||preg_match('/[\x00-\x1f\x7f]/',$file))throw new ApiException(422,'invalid_service_import','Check the import options.');
        $inputHash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR));$pdo=$this->database->connection();
        $q=$pdo->prepare('SELECT * FROM service_import_batches WHERE clinic_id=? AND created_by=? AND request_key=? FOR UPDATE');$q->execute([$actor->clinicId,$actor->userId,$request]);
        if($old=$q->fetch()){
            if(!hash_equals($old['input_hash'],$inputHash))throw new ApiException(409,'service_import_conflict','This import reference was already used.');
            return $this->response($old);
        }
        $catalog=$this->catalog($actor);$planned=[];$keys=[];$slugs=[];$targets=[];$rowNumbers=[];
        foreach($rows as $index=>$row){
            $errors=[];$warnings=[];$number=$index+2;
            if(!is_array($row)||array_is_list($row))$row=[];
            if(isset($row['row_number'])&&is_int($row['row_number'])&&$row['row_number']>=2&&$row['row_number']<=10000)$number=$row['row_number'];else $errors[]='invalid_row_number';
            if(isset($rowNumbers[$number]))$errors[]='duplicate_row_number';$rowNumbers[$number]=true;
            if(array_diff(array_keys($row),['row_number','category','name','name_fr','service_type','initial_consultation','durations','public_summary','public_summary_fr','description','description_fr','prices','service_code']))$errors[]='unexpected_fields';
            $text=[];
            foreach(['category'=>120,'name'=>150,'name_fr'=>150,'public_summary'=>500,'public_summary_fr'=>500,'description'=>65535,'description_fr'=>65535] as $field=>$limit){
                $v=$row[$field]??null;
                if(!is_string($v)||trim($v)===''||mb_strlen($v)>$limit||strlen($v)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)){$errors[]='invalid_'.$field;$text[$field]='';}else $text[$field]=trim($v);
            }
            $text['category']=preg_replace('/\s+/u',' ',$text['category'])??'';
            $type=$row['service_type']??null;$initial=$row['initial_consultation']??null;
            if(!in_array($type,['Consultation','Treatment','Assessment'],true)){$errors[]='invalid_type';$type=null;}
            if(!is_bool($initial)){$errors[]='invalid_initial_consultation';$initial=null;}
            $durations=$row['durations']??null;
            if(!is_array($durations)||!array_is_list($durations)||count($durations)<1||count($durations)>20||array_filter($durations,fn($n)=>!is_int($n)||$n<15||$n>480||$n%15!==0)||count(array_unique($durations,SORT_REGULAR))!==count($durations)){$errors[]='invalid_durations';$durations=[];}
            $code=$row['service_code']??'';
            if(!is_string($code)||($code!==''&&!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/D',$code))){$errors[]='invalid_service_code';$code='';}
            $slug=$code!==''?$code:trim(preg_replace('/[^a-z0-9]+/','-',strtolower($text['name']))??'','-');
            if($slug===''||strlen($slug)>120)$errors[]='invalid_service_code';
            $weights=$pdo->prepare('SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)),HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci))');
            $weights->execute([$text['name'],$text['category']]);[$nameWeight,$categoryWeight]=$weights->fetch(\PDO::FETCH_NUM);
            $key=self::key($text['name']);$keys['weight:'.$nameWeight][]=$index;$keys['name:'.$key][]=$index;$slugs[$slug][]=$index;
            $matches=array_values(array_filter($catalog['services'],fn($s)=>$s['import_name_key']===$nameWeight||self::key($s['name'])===$key||$s['slug']===$slug));
            $existing=count($matches)===1?$matches[0]:null;
            if(count($matches)>1)$errors[]='ambiguous_service';
            if($existing)$targets[(int)$existing['id']][]=$index;
            $categoryMatches=array_values(array_filter($catalog['categories'],fn($c)=>$c['import_name_key']===$categoryWeight||self::key($c['name'])===self::key($text['category'])));
            $category=count($categoryMatches)===1?$categoryMatches[0]:null;
            if(count($categoryMatches)>1)$errors[]='ambiguous_category';
            $action=$existing?($policy==='skip'?'skip':'update'):'create';
            if(!$category&&!$create&&$action!=='skip')$errors[]='missing_category';
            $payload=['name'=>$text['name'],'name_fr'=>$text['name_fr'],'public_summary'=>$text['public_summary'],'public_summary_fr'=>$text['public_summary_fr'],'description'=>$text['description'],'description_fr'=>$text['description_fr'],'category_id'=>$category?(int)$category['id']:null];
            if($action==='create'){
                $prices=$row['prices']??[];$options=[];
                if(!is_array($prices)||!array_is_list($prices)||count($prices)!==count($durations))$errors[]='missing_prices';
                else{
                    foreach($durations as $i=>$minutes){$price=$prices[$i]??null;if(!is_array($price)||array_diff(array_keys($price),['minutes','price_cents'])||($price['minutes']??null)!==$minutes||!is_int($price['price_cents']??null)||$price['price_cents']<0||$price['price_cents']>10000000){$errors[]='invalid_prices';break;}$options[]=['minutes'=>$minutes,'price_cents'=>$price['price_cents']];}
                }
                $payload+=['slug'=>$slug,'duration_options'=>$options,'active'=>false,'published'=>false];
                if($errors===[])try{$validate($payload);}catch(ApiException){$errors[]='invalid_service';}
            }elseif($action==='update'){
                $warnings[]='existing_pricing_preserved';
                // Keep existing duration, price, publication, booking rules, URL and assignments.
                if($errors===[])try{$authorizeUpdate((int)$existing['id']);}catch(ApiException){$errors[]='update_not_allowed';}
                $changed=false;foreach($payload as $field=>$value)if((string)($existing[$field]??'')!==(string)($value??''))$changed=true;
                if(!$category&&$create)$changed=true;
                if(!$changed){$action='skip';$warnings[]='unchanged';}
            }
            $planned[]=['row_number'=>$number,'category'=>$text['category'],'category_key'=>$categoryWeight,'service_id'=>$existing?(int)$existing['id']:null,'existing_name'=>$existing['name']??null,'category_id'=>$category?(int)$category['id']:null,'action'=>$action,'service_type'=>$type,'initial_consultation'=>$initial,'durations'=>$durations,'payload'=>$payload,'errors'=>array_values(array_unique($errors)),'warnings'=>$warnings];
        }
        foreach([$keys,$slugs,$targets] as $groups)foreach($groups as $indexes)if(count($indexes)>1)foreach($indexes as $i)$planned[$i]['errors'][]='duplicate_in_file';
        $counts=['create'=>0,'update'=>0,'skip'=>0,'invalid'=>0];$newCategories=[];
        foreach($planned as &$row){$row['errors']=array_values(array_unique($row['errors']));if($row['errors'])$counts['invalid']++;else{$counts[$row['action']]++;if($row['action']!=='skip'&&$row['category_id']===null)$newCategories[$row['category_key']]??=$row['category'];}}unset($row);
        $plan=['file_name'=>$file,'duplicate_policy'=>$policy,'create_categories'=>$create,'rows'=>$planned,'counts'=>$counts,'new_categories'=>array_values($newCategories),'can_import'=>$counts['invalid']===0&&($counts['create']+$counts['update']>0)];
        $json=json_encode($plan,JSON_THROW_ON_ERROR);$confirmation=hash('sha256',$json);$id=self::uuid();$expires=gmdate('Y-m-d H:i:s',time()+1800);
        $pdo->prepare('INSERT INTO service_import_batches(id,clinic_id,created_by,request_key,input_hash,snapshot_hash,confirmation_hash,plan_json,expires_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$actor->clinicId,$actor->userId,$request,$inputHash,hash('sha256',json_encode($catalog,JSON_THROW_ON_ERROR)),$confirmation,$json,$expires]);
        $this->audit->write($actor->clinicId,$actor,$cid,'service_import.preview','service_import',null,'success',['preview_id'=>$id,'counts'=>$counts]);
        return $plan+['preview_id'=>$id,'confirmation_hash'=>$confirmation,'expires_at'=>$expires,'applied'=>false];
    }

    private function owned(AuthContext $actor,mixed $id): array
    {
        $id=self::identifier($id);$q=$this->database->connection()->prepare('SELECT * FROM service_import_batches WHERE id=? AND clinic_id=? AND created_by=? FOR UPDATE');$q->execute([$id,$actor->clinicId,$actor->userId]);$row=$q->fetch();
        if(!$row)throw new ApiException(404,'service_import_not_found','Import preview not found.');return $row;
    }

    private function response(array $batch): array
    {
        return json_decode($batch['plan_json'],true,64,JSON_THROW_ON_ERROR)+['preview_id'=>$batch['id'],'confirmation_hash'=>$batch['confirmation_hash'],'expires_at'=>$batch['expires_at'],'applied'=>$batch['applied_at']!==null,'result'=>$batch['result_json']?json_decode($batch['result_json'],true,64,JSON_THROW_ON_ERROR):null];
    }

    public function get(AuthContext $actor,mixed $id): array{return $this->response($this->owned($actor,$id));}

    public function apply(AuthContext $actor,array $body,string $cid,callable $createCategory,callable $createService,callable $updateService): array
    {
        if(array_diff(array_keys($body),['preview_id','confirmation_hash'])||!is_string($body['confirmation_hash']??null))throw new ApiException(422,'invalid_service_import','Confirm a valid import preview.');
        $batch=$this->owned($actor,$body['preview_id']??null);
        if(!hash_equals($batch['confirmation_hash'],$body['confirmation_hash']))throw new ApiException(409,'service_import_conflict','The import preview changed.');
        if($batch['applied_at']!==null)return json_decode($batch['result_json'],true,64,JSON_THROW_ON_ERROR);
        if($batch['expires_at']<gmdate('Y-m-d H:i:s'))throw new ApiException(409,'service_import_expired','Preview expired. Preview the spreadsheet again.');
        $plan=json_decode($batch['plan_json'],true,64,JSON_THROW_ON_ERROR);
        if(!$plan['can_import'])throw new ApiException(422,'service_import_invalid','Resolve the import errors before applying.');
        if(!hash_equals($batch['snapshot_hash'],hash('sha256',json_encode($this->catalog($actor),JSON_THROW_ON_ERROR))))throw new ApiException(409,'service_import_stale','Services changed. Preview the spreadsheet again.');
        $categories=[];$results=[];
        foreach($plan['rows'] as $row){
            $id=$row['service_id'];
            if($row['action']!=='skip'){
                $payload=$row['payload'];$categoryKey=$row['category_key'];
                if($row['category_id']===null){if(!isset($categories[$categoryKey]))$categories[$categoryKey]=(int)$createCategory(['name'=>$row['category']])['id'];$payload['category_id']=$categories[$categoryKey];}
                if($row['action']==='create')$id=(int)$createService($payload)['id'];else $updateService((int)$id,$payload);
            }
            $results[]=['row_number'=>$row['row_number'],'service_id'=>$id,'action'=>$row['action']];
        }
        $result=['preview_id'=>$batch['id'],'counts'=>$plan['counts'],'rows'=>$results,'categories_created'=>count($categories),'applied'=>true];
        $this->database->connection()->prepare('UPDATE service_import_batches SET result_json=?,applied_at=UTC_TIMESTAMP() WHERE id=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),$batch['id']]);
        $this->audit->write($actor->clinicId,$actor,$cid,'service_import.apply','service_import',null,'success',['preview_id'=>$batch['id'],'counts'=>$plan['counts'],'categories_created'=>count($categories)]);
        return $result;
    }
}
