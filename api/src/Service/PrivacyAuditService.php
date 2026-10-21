<?php
declare(strict_types=1);
namespace Wellness\Service;

use DateTimeImmutable;
use DateTimeZone;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\{ApiException,Request};

/** Separate privacy oversight authority; never a clinical-record access grant. */
final class PrivacyAuditService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    public function authorize(AuthContext $actor): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin')||!$actor->hasPermission('review_privacy_audit'))$this->denied();
        // Recheck persisted authority even for direct callers holding an old AuthContext.
        $q=$this->database->connection()->prepare("SELECT 1 FROM users u JOIN clinics c ON c.id=u.clinic_id AND c.status='active'
            JOIN user_permissions up ON up.user_id=u.id JOIN permissions p ON p.id=up.permission_id AND p.code='review_privacy_audit'
            WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
              AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ('super_admin','clinic_admin'))");
        $q->execute([$actor->userId,$actor->clinicId]);if(!$q->fetchColumn())$this->denied();
    }

    private function denied(): never {throw new ApiException(403,'privacy_review_required','Clinic privacy-review permission is required.');}

    /** Only the privacy capability changes here, so care restrictions cannot prevent its revocation. */
    public function setPermission(AuthContext $actor,int $target,bool $grant,string $cid): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin')||($grant&&$target===$actor->userId))$this->denied();
        $pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Privacy grants require their own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$actor->clinicId]);if(!$q->fetchColumn())$this->denied();
            $q=$pdo->prepare("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='super_admin' WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND u.user_type='staff'");$q->execute([$actor->userId,$actor->clinicId]);if(!$q->fetchColumn())$this->denied();
            $q=$pdo->prepare("SELECT id,status FROM users WHERE id=? AND clinic_id=? AND user_type='staff' FOR UPDATE");$q->execute([$target,$actor->clinicId]);$user=$q->fetch();if(!$user)throw new ApiException(404,'staff_not_found','Staff member not found.');
            if($grant){$q=$pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code IN ('super_admin','clinic_admin')");$q->execute([$target]);if($user['status']!=='active'||!$q->fetchColumn())throw new ApiException(422,'validation_error','Privacy review requires an active clinic administrator.');}
            $q=$pdo->query("SELECT id FROM permissions WHERE code='review_privacy_audit'");$permission=$q->fetchColumn();if(!$permission)throw new ApiException(503,'permission_unavailable','Apply the privacy-review permission migration first.');
            if($grant)$pdo->prepare('INSERT IGNORE INTO user_permissions(user_id,permission_id,assigned_by) VALUES(?,?,?)')->execute([$target,$permission,$actor->userId]);
            else $pdo->prepare('DELETE FROM user_permissions WHERE user_id=? AND permission_id=?')->execute([$target,$permission]);
            $this->audit->write($actor->clinicId,$actor,$cid,$grant?'privacy_audit.grant':'privacy_audit.revoke','user',$target);$pdo->commit();return ['id'=>$target,'review_privacy_audit'=>$grant];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$lock->release();}
    }
    private static function invalid(): never {throw new ApiException(422,'validation_error','Choose valid privacy audit filters and a date range of at most 31 days.');}
    private static function id(mixed $value): int
    {
        if(!is_string($value)&&!is_int($value))self::invalid();
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)self::invalid();return $id;
    }

    public static function filters(array $query): array
    {
        if(array_diff(array_keys($query),['from','until','actor_type','actor_id','resource_type','resource_id','outcome','before']))self::invalid();
        $today=new DateTimeImmutable('today',new DateTimeZone('UTC'));
        $result=['from'=>(array_key_exists('from',$query)?$query['from']:$today->modify('-6 days')->format('Y-m-d')),'until'=>(array_key_exists('until',$query)?$query['until']:$today->modify('+1 day')->format('Y-m-d'))];
        foreach(['from','until'] as $key){$v=$result[$key];$date=is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)?DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC')):false;if(!$date||$date->format('Y-m-d')!==$v||(int)$date->format('Y')<1000)self::invalid();}
        $days=(int)(new DateTimeImmutable($result['from']))->diff(new DateTimeImmutable($result['until']))->format('%r%a');if($days<1||$days>31)self::invalid();
        foreach(['actor_type','resource_type','outcome'] as $key)if(isset($query[$key])&&$query[$key]!==''){
            $allowed=match($key){'actor_type'=>['staff','client','customer_identity','application_admin','anonymous'],'outcome'=>['success','denied','failure'],default=>AccessAudit::resourceTypes()};
            if(!is_string($query[$key])||!in_array($query[$key],$allowed,true))self::invalid();$result[$key]=$query[$key];
        }
        foreach(['actor_id','resource_id','before'] as $key)if(isset($query[$key])&&$query[$key]!=='')$result[$key]=self::id($query[$key]);
        if(isset($result['actor_id'])&&!isset($result['actor_type']))self::invalid();
        if(isset($result['resource_id'])!==isset($result['resource_type']))self::invalid();
        return $result;
    }

    private static function references(array $metadata,string $key): array
    {
        $result=[];$rows=$metadata[$key]??[];if(!is_array($rows))return [];
        foreach(array_slice($rows,0,200) as $row){if(!is_array($row)||!in_array($row['type']??null,AccessAudit::resourceTypes(),true))continue;
            $id=$row['id']??null;if((is_int($id)||is_string($id))&&filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false)$result[]=['type'=>$row['type'],'id'=>(int)$id];}
        return $result;
    }

    public function search(AuthContext $actor,array $query,string $cid): array
    {
        $pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Privacy review requires durable independent auditing.');
        $this->authorize($actor);$filters=self::filters($query);
        $params=['clinic'=>$actor->clinicId,'from'=>$filters['from'].' 00:00:00','until'=>$filters['until'].' 00:00:00'];
        $where='clinic_id=:clinic AND created_at>=:from AND created_at<:until';
        foreach(['actor_type','actor_id','outcome'] as $key)if(isset($filters[$key])){$where.=" AND $key=:$key";$params[$key]=$filters[$key];}
        if(isset($filters['before'])){$where.=' AND id<:before';$params['before']=$filters['before'];}
        if(isset($filters['resource_id'])){
            $parts=[];foreach(['resources','attempted_resources','requested_resources'] as $key){$parts[]="JSON_CONTAINS(metadata,:$key,'$.$key')";$params[$key]=json_encode(['type'=>$filters['resource_type'],'id'=>$filters['resource_id']],JSON_THROW_ON_ERROR);}
            $where.=' AND ('.implode(' OR ',$parts).')';
        }
        $q=$pdo->prepare("SELECT id,event_group,part_number,part_count,created_at,actor_type,actor_id,action,route_key,http_method,http_status,outcome,metadata FROM access_audit_events WHERE $where ORDER BY id DESC LIMIT 51");$q->execute($params);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);$items=[];
        foreach($rows as $row){
            $metadata=json_decode($row['metadata'],true,32,JSON_THROW_ON_ERROR);
            // Recanonicalize legacy paths. Never release raw metadata, IP, correlation or free text.
            $path=str_replace(':id','1',$row['route_key']);$route=AccessAudit::describe(new Request('GET',$path,[],[],[],'privacy-review'))['route'];
            $items[]=['id'=>(int)$row['id'],'event_group'=>$row['event_group'],'part_number'=>(int)$row['part_number'],'part_count'=>(int)$row['part_count'],
                'created_at'=>$row['created_at'],'actor_type'=>$row['actor_type'],'actor_id'=>$row['actor_id']===null?null:(int)$row['actor_id'],
                'action'=>in_array($row['action'],['access.read','access.search','access.response','access.download','access.denied','access.failure'],true)?$row['action']:'access.other',
                'route'=>$route,'http_method'=>in_array($row['http_method'],['GET','POST','PUT','PATCH','DELETE','HEAD','OTHER'],true)?$row['http_method']:'OTHER','http_status'=>(int)$row['http_status'],'outcome'=>$row['outcome'],
                'resources'=>self::references($metadata,'resources'),'attempted_resources'=>self::references($metadata,'attempted_resources'),'requested_resources'=>self::references($metadata,'requested_resources')];
        }
        $this->audit->write($actor->clinicId,$actor,$cid,'privacy_audit.search','audit_event',null,'success',['filters'=>$filters,'audit_event_ids'=>array_column($items,'id')]);
        return ['items'=>$items,'next_before'=>$more?(int)end($items)['id']:null,'filters'=>$filters];
    }
}
