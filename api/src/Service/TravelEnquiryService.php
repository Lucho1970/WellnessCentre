<?php
declare(strict_types=1);
namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class TravelEnquiryService
{
    public function __construct(private readonly Database $database,private readonly LocationDiscoveryService $discovery) {}
    private function scope(AuthContext $actor): array
    {
        if($actor->userType==='client')return ['e.client_id=?',[$actor->userId]];
        if($actor->userType==='staff'&&$actor->hasAnyRole('super_admin','clinic_admin','reception'))return ['1=1',[]];
        if($actor->userType==='staff'&&$actor->hasAnyRole('practitioner'))return ['p.user_id=? AND p.active=1',[$actor->userId]];
        throw new ApiException(403,'forbidden','Travel enquiry access is unavailable.');
    }
    private function thread(AuthContext $actor,int $id,bool $lock=false): array
    {
        [$scope,$params]=$this->scope($actor);
        $q=$this->database->connection()->prepare("SELECT e.*,p.user_id practitioner_user_id,u.display_name client_name,pu.display_name practitioner_name,s.name service_name FROM travel_enquiries e JOIN practitioners p ON p.id=e.practitioner_id JOIN users u ON u.id=e.client_id JOIN users pu ON pu.id=p.user_id JOIN services s ON s.id=e.service_id WHERE e.id=? AND e.clinic_id=? AND $scope".($lock?' FOR UPDATE':''));
        $q->execute([$id,$actor->clinicId,...$params]);$row=$q->fetch();if(!$row)throw new ApiException(404,'enquiry_not_found','Travel enquiry unavailable.');return $row;
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
        $hash=hash('sha256',json_encode([$body['token'],$label,$message],JSON_THROW_ON_ERROR));$pdo=$this->database->connection();
        $q=$pdo->prepare('SELECT id,request_hash FROM travel_enquiries WHERE clinic_id=? AND client_id=? AND reference=?');$q->execute([$actor->clinicId,$actor->userId,$reference]);
        if($old=$q->fetch()){if(!hash_equals($old['request_hash'],$hash))throw new ApiException(409,'idempotency_conflict','This enquiry reference was already used.');return ['id'=>(int)$old['id']];}
        $this->discovery->limit('enquiry:'.$actor->clinicId.':'.$actor->userId,10,gmdate('YmdH'));
        $match=$this->discovery->verify($actor->clinicId,$body['token']);
        try{
            $pdo->beginTransaction();
            // Serialize duplicate submits per client, without holding a lock during map requests.
            $pdo->prepare('SELECT id FROM users WHERE id=? AND clinic_id=? FOR UPDATE')->execute([$actor->userId,$actor->clinicId]);
            $q=$pdo->prepare('SELECT id,request_hash FROM travel_enquiries WHERE clinic_id=? AND client_id=? AND reference=?');$q->execute([$actor->clinicId,$actor->userId,$reference]);
            if($old=$q->fetch()){if(!hash_equals($old['request_hash'],$hash))throw new ApiException(409,'idempotency_conflict','This enquiry reference was already used.');$pdo->commit();return ['id'=>(int)$old['id']];}
            $q=$pdo->prepare('INSERT INTO travel_enquiries(clinic_id,client_id,practitioner_id,service_id,location_id,reference,request_hash,search_label) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$actor->clinicId,$actor->userId,$match['practitioner_id'],$match['service_id'],$match['location_id'],$reference,$hash,$label]);$id=(int)$pdo->lastInsertId();
            $this->insertMessage($id,$actor,$reference,$message);
            (new AuditLogger($this->database))->write($actor->clinicId,$actor,$cid,'travel_enquiry.create','travel_enquiry',$id);
            $pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private function insertMessage(int $id,AuthContext $actor,string $reference,string $message): void
    {
        $q=$this->database->connection()->prepare('INSERT INTO travel_enquiry_messages(enquiry_id,sender_id,reference,body) VALUES(?,?,?,?)');$q->execute([$id,$actor->userId,$reference,$message]);
    }
    public function list(AuthContext $actor,array $query=[]): array
    {
        [$scope,$params]=$this->scope($actor);$pdo=$this->database->connection();
        $before=$query['before']??PHP_INT_MAX;if(filter_var($before,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new ApiException(422,'validation_error','Invalid enquiry page.');
        $seen=$actor->userType==='client'?'client_seen_id':'practitioner_seen_id';
        // Staff administrators share the practitioner's read state; clients have their own.
        $unread="EXISTS(SELECT 1 FROM travel_enquiry_messages m WHERE m.enquiry_id=e.id AND m.id>e.$seen AND ".($actor->userType==='client'?'m.sender_id<>e.client_id':'m.sender_id=e.client_id').')';
        $from=" FROM travel_enquiries e JOIN practitioners p ON p.id=e.practitioner_id JOIN users u ON u.id=e.client_id JOIN users pu ON pu.id=p.user_id JOIN services s ON s.id=e.service_id WHERE e.clinic_id=? AND $scope";
        $q=$pdo->prepare("SELECT e.id,e.status,e.search_label,e.created_at,u.display_name client_name,pu.display_name practitioner_name,s.name service_name,$unread unread$from AND e.id<? ORDER BY e.id DESC LIMIT 51");$q->execute([$actor->clinicId,...$params,(int)$before]);$items=$q->fetchAll();$more=count($items)>50;$items=array_slice($items,0,50);
        $q=$pdo->prepare("SELECT COUNT(*)$from AND $unread");$q->execute([$actor->clinicId,...$params]);
        return ['items'=>$items,'unread_count'=>(int)$q->fetchColumn(),'next_before'=>$more?(int)end($items)['id']:null];
    }
    public function detail(AuthContext $actor,int $id,array $query=[]): array
    {
        $row=$this->thread($actor,$id);$after=$query['after']??0;if(filter_var($after,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new ApiException(422,'validation_error','Invalid message page.');
        $q=$this->database->connection()->prepare('SELECT m.id,m.body,m.created_at,m.sender_id=u.id AND u.id=? own_message,CASE WHEN u.id=? THEN ? WHEN u.user_type=? THEN ? ELSE u.display_name END sender_name FROM travel_enquiry_messages m JOIN users u ON u.id=m.sender_id WHERE m.enquiry_id=? AND m.id>? ORDER BY m.id LIMIT 101');
        $q->execute([$actor->userId,$row['client_id'],'Client','staff','Clinic team',$id,(int)$after]);$messages=$q->fetchAll();$more=count($messages)>100;$messages=array_slice($messages,0,100);
        return ['id'=>$id,'status'=>$row['status'],'search_label'=>$row['search_label'],'client_name'=>$row['client_name'],'practitioner_name'=>$row['practitioner_name'],'service_name'=>$row['service_name'],'messages'=>$messages,'next_after'=>$more?(int)end($messages)['id']:null];
    }
    public function act(AuthContext $actor,int $id,string $action,array $body,string $cid): array
    {
        $pdo=$this->database->connection();$this->thread($actor,$id);
        if($action==='reply')$this->discovery->limit('reply:'.$actor->clinicId.':'.$actor->userId,60,gmdate('YmdH'));
        try{
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
            if($action!=='read')(new AuditLogger($this->database))->write($actor->clinicId,$actor,$cid,'travel_enquiry.'.$action,'travel_enquiry',$id);
            $pdo->commit();return ['saved'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
