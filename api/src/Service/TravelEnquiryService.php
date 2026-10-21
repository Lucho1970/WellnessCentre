<?php
declare(strict_types=1);
namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class TravelEnquiryService
{
    private const FROM = " FROM travel_enquiries e
        JOIN practitioners p ON p.id=e.practitioner_id
        JOIN users u ON u.id=e.client_id AND u.clinic_id=e.clinic_id AND u.user_type='client'
        JOIN users pu ON pu.id=p.user_id AND pu.clinic_id=e.clinic_id AND pu.user_type='staff'
        JOIN services s ON s.id=e.service_id AND s.clinic_id=e.clinic_id
        JOIN locations l ON l.id=e.location_id AND l.clinic_id=e.clinic_id";

    public function __construct(private readonly Database $database,private readonly LocationDiscoveryService $discovery) {}

    /** Positive role/ownership scope plus the shared negative consent boundary. */
    private function scope(AuthContext $actor): array
    {
        $params=['scope_actor'=>$actor->userId,'scope_type'=>$actor->userType];
        $scope="EXISTS(SELECT 1 FROM users travel_actor WHERE travel_actor.id=:scope_actor AND travel_actor.clinic_id=e.clinic_id AND travel_actor.user_type=:scope_type AND travel_actor.status='active')
            AND NOT EXISTS(SELECT 1 FROM client_merge_records merged WHERE merged.duplicate_client_id=e.client_id)";
        if($actor->userType==='client'){$scope.=' AND e.client_id=:client';$params['client']=$actor->userId;}
        elseif($actor->userType==='staff'&&$actor->hasAnyRole('super_admin','clinic_admin','reception')){}
        elseif($actor->userType==='staff'&&$actor->hasAnyRole('practitioner')){$scope.=' AND p.user_id=:practitioner_user AND p.active=1';$params['practitioner_user']=$actor->userId;}
        else throw new ApiException(403,'forbidden','Travel enquiry access is unavailable.');
        $scope.=ConsentAccess::condition($actor,$params,'e.client_id');
        return [$scope,$params];
    }

    private function lock(AuthContext $actor): ConsentLock
    {
        $lock=new ConsentLock($this->database->connection());$lock->acquire($actor->clinicId);return $lock;
    }

    private function thread(AuthContext $actor,int $id,bool $lock=false): array
    {
        [$scope,$params]=$this->scope($actor);$params+=['id'=>$id,'clinic'=>$actor->clinicId];
        $q=$this->database->connection()->prepare('SELECT e.*,p.user_id practitioner_user_id,u.display_name client_name,pu.display_name practitioner_name,s.name service_name'.self::FROM." WHERE e.id=:id AND e.clinic_id=:clinic AND $scope".($lock?' FOR UPDATE':''));
        $q->execute($params);$row=$q->fetch();
        if(!$row)throw new ApiException(404,'enquiry_not_found','Travel enquiry unavailable.');
        return $row;
    }

    /** Patient-authored messages must not silently restore a restricted/inactive recipient. */
    private function recipient(AuthContext $actor,int $practitioner): void
    {
        $pdo=$this->database->connection();
        $q=$pdo->prepare("SELECT u.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.active=1 AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'");
        $q->execute([$practitioner,$actor->clinicId]);$staff=$q->fetchColumn();
        if(!$staff||(new ConsentAccess($pdo))->restricted($actor->clinicId,$actor->userId,(int)$staff))
            throw new ApiException(409,'travel_recipient_unavailable','This enquiry cannot receive new messages under the current access permissions.');
    }

    private static function text(mixed $value,int $max): string
    {
        if(!is_string($value)||trim($value)===''||strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new ApiException(422,'validation_error','Enter a message up to 2000 characters and a short visit area.');return trim($value);
    }
    private static function reference(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$value))throw new ApiException(422,'validation_error','Invalid message reference.');return $value;
    }

    public function create(AuthContext $actor,array $body,string $cid): array
    {
        if($actor->userType!=='client')throw new ApiException(403,'forbidden','Sign in as a client to enquire.');
        if(array_diff(array_keys($body),['reference','token','search_label','message']))throw new ApiException(422,'validation_error','Unexpected enquiry fields.');
        $reference=self::reference($body['reference']??null);$message=self::text($body['message']??null,2000);$label=self::text($body['search_label']??null,250);
        if(!is_string($body['token']??null)||strlen($body['token'])>3000)throw new ApiException(422,'validation_error','Refresh the location search.');
        $hash=hash('sha256',json_encode([$body['token'],$label,$message],JSON_THROW_ON_ERROR));$pdo=$this->database->connection();$lock=$this->lock($actor);
        try{
            $q=$pdo->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='client' AND u.status='active' AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=u.id)");$q->execute([$actor->userId,$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(404,'client_not_found','Client not found.');
            $q=$pdo->prepare('SELECT id,request_hash FROM travel_enquiries WHERE clinic_id=? AND client_id=? AND reference=?');$q->execute([$actor->clinicId,$actor->userId,$reference]);
            if($old=$q->fetch()){
                $row=$this->thread($actor,(int)$old['id']);$this->recipient($actor,(int)$row['practitioner_id']);
                if(!hash_equals($old['request_hash'],$hash))throw new ApiException(409,'idempotency_conflict','This enquiry reference was already used.');return ['id'=>(int)$old['id']];
            }
            $this->discovery->limit('enquiry:'.$actor->clinicId.':'.$actor->userId,10,gmdate('YmdH'));
            $match=$this->discovery->verify($actor->clinicId,$body['token']);$this->recipient($actor,(int)$match['practitioner_id']);
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM users WHERE id=? AND clinic_id=? FOR UPDATE')->execute([$actor->userId,$actor->clinicId]);
            $q=$pdo->prepare('INSERT INTO travel_enquiries(clinic_id,client_id,practitioner_id,service_id,location_id,reference,request_hash,search_label) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$actor->clinicId,$actor->userId,$match['practitioner_id'],$match['service_id'],$match['location_id'],$reference,$hash,$label]);$id=(int)$pdo->lastInsertId();
            $this->insertMessage($id,$actor,$reference,$message);
            (new AuditLogger($this->database))->write($actor->clinicId,$actor,$cid,'travel_enquiry.create','travel_enquiry',$id);
            $pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    private function insertMessage(int $id,AuthContext $actor,string $reference,string $message): void
    {
        $q=$this->database->connection()->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)');$q->execute([$id,$actor->userId,$reference,$message]);
    }

    public function list(AuthContext $actor,array $query=[]): array
    {
        [$scope,$params]=$this->scope($actor);$params['clinic']=$actor->clinicId;
        $before=$query['before']??PHP_INT_MAX;if(filter_var($before,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new ApiException(422,'validation_error','Invalid enquiry page.');
        $pdo=$this->database->connection();$lock=$this->lock($actor);
        try{
            $seen=$actor->userType==='client'?'client_seen_id':'practitioner_seen_id';
            // Administrators retain their shared staff read state; only permitted threads contribute.
            $unread="EXISTS(SELECT 1 FROM travel_enquiry_messages m JOIN users author ON author.id=m.sender_id AND author.clinic_id=e.clinic_id AND (author.id=e.client_id OR author.user_type='staff') WHERE m.enquiry_id=e.id AND m.id>e.$seen AND ".($actor->userType==='client'?'m.sender_id<>e.client_id':'m.sender_id=e.client_id').')';
            $from=self::FROM." WHERE e.clinic_id=:clinic AND $scope";
            $q=$pdo->prepare("SELECT e.id,e.status,e.search_label,e.created_at,u.display_name client_name,pu.display_name practitioner_name,s.name service_name,$unread unread$from AND e.id<:before ORDER BY e.id DESC LIMIT 51");$q->execute($params+['before'=>(int)$before]);$items=$q->fetchAll();$more=count($items)>50;$items=array_slice($items,0,50);
            $q=$pdo->prepare("SELECT COUNT(*)$from AND $unread");$q->execute($params);
            return ['items'=>$items,'unread_count'=>(int)$q->fetchColumn(),'next_before'=>$more?(int)end($items)['id']:null];
        }finally{$lock->release();}
    }

    public function detail(AuthContext $actor,int $id,array $query=[]): array
    {
        $this->scope($actor);$lock=$this->lock($actor);
        try{
            $row=$this->thread($actor,$id);$after=$query['after']??0;if(filter_var($after,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new ApiException(422,'validation_error','Invalid message page.');
            $q=$this->database->connection()->prepare("SELECT m.id,m.body,m.created_at,u.id=:viewer own_message,CASE WHEN u.id=:client THEN 'Client' WHEN u.user_type='staff' THEN 'Clinic team' ELSE u.display_name END sender_name FROM travel_enquiry_messages m JOIN users u ON u.id=m.sender_id AND u.clinic_id=:clinic WHERE m.enquiry_id=:id AND m.id>:after AND (u.id=:client_author OR u.user_type='staff') ORDER BY m.id LIMIT 101");
            $q->execute(['viewer'=>$actor->userId,'client'=>$row['client_id'],'clinic'=>$actor->clinicId,'id'=>$id,'after'=>(int)$after,'client_author'=>$row['client_id']]);$messages=$q->fetchAll();$more=count($messages)>100;$messages=array_slice($messages,0,100);
            return ['id'=>$id,'status'=>$row['status'],'search_label'=>$row['search_label'],'client_name'=>$row['client_name'],'practitioner_name'=>$row['practitioner_name'],'service_name'=>$row['service_name'],'messages'=>$messages,'next_after'=>$more?(int)end($messages)['id']:null];
        }finally{$lock->release();}
    }

    public function act(AuthContext $actor,int $id,string $action,array $body,string $cid): array
    {
        $this->scope($actor);$pdo=$this->database->connection();$lock=$this->lock($actor);
        try{
            $row=$this->thread($actor,$id);
            if($action==='reply'&&$actor->userType==='client')$this->recipient($actor,(int)$row['practitioner_id']);
            if($action==='reply')$this->discovery->limit('reply:'.$actor->clinicId.':'.$actor->userId,60,gmdate('YmdH'));
            $pdo->beginTransaction();$row=$this->thread($actor,$id,true);
            if($action==='read'){
                if(!is_int($body['message_id']??null)||$body['message_id']<0)throw new ApiException(422,'validation_error','Invalid read position.');
                $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM travel_enquiry_messages WHERE enquiry_id=?');$q->execute([$id]);$latest=(int)$q->fetchColumn();
                $seen=$actor->userType==='client'?'client_seen_id':'practitioner_seen_id';$pdo->prepare("UPDATE travel_enquiries SET $seen=GREATEST($seen,?) WHERE id=?")->execute([min($body['message_id'],$latest),$id]);
            }elseif($action==='close'){
                if($actor->userType!=='staff')throw new ApiException(403,'forbidden','Clinic staff can close travel enquiries.');
                $pdo->prepare("UPDATE travel_enquiries SET status='closed' WHERE id=?")->execute([$id]);
            }elseif($action==='reply'){
                $reference=self::reference($body['reference']??null);$message=self::text($body['message']??null,2000);
                if(array_diff(array_keys($body),['reference','message']))throw new ApiException(422,'validation_error','Unexpected message fields.');
                $q=$pdo->prepare('SELECT sender_id,body FROM travel_enquiry_messages WHERE enquiry_id=? AND reference=?');$q->execute([$id,$reference]);$old=$q->fetch();
                if($old){if((int)$old['sender_id']!==$actor->userId||$old['body']!==$message)throw new ApiException(409,'idempotency_conflict','This message reference was already used.');}
                else{if($row['status']!=='open')throw new ApiException(409,'enquiry_closed','This enquiry is closed.');$this->insertMessage($id,$actor,$reference,$message);}
            }else throw new ApiException(404,'not_found','Route not found.');
            (new AuditLogger($this->database))->write($actor->clinicId,$actor,$cid,'travel_enquiry.'.$action,'travel_enquiry',$id);
            $pdo->commit();return ['saved'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
}
