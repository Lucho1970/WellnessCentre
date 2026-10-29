<?php
declare(strict_types=1);
namespace Wellness\Service;
use PDO;
use Throwable;
use Wellness\Auth\ApplicationAdminContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Reviewed normalized provider evidence; never infers charges from missing history or tariffs. */
final class ProviderEvidenceService
{
    public function __construct(private readonly Database $database,private readonly Config $config) {}
    private function q(string $sql,array $values=[]): \PDOStatement {$q=$this->database->connection()->prepare($sql);$q->execute($values);return $q;}
    private function authorize(ApplicationAdminContext $actor,int $clinic): void
    {
        if(!$this->q("SELECT id FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active' FOR UPDATE",[$actor->id,$actor->identity->issuer,$actor->identity->subject])->fetchColumn())throw new ApiException(403,'application_admin_required','Application administrator access is required.');
        if(!$this->q("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE",[$clinic])->fetchColumn())throw new ApiException(404,'clinic_not_found','Clinic not found.');
    }
    public function handle(ApplicationAdminContext $actor,int $clinic,string $method,string $path,array $body,string $cid): array
    {
        if(!$this->config->providerEvidenceEnabled||!$this->config->applicationAdminEnabled||!$this->config->clinicManagementEnabled)throw new ApiException(503,'provider_evidence_unavailable','Provider evidence import is not enabled.');
        $pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Provider evidence owns its transaction.');
        $consent=new ConsentLock($pdo);$sender=new ProviderSenderLock($pdo);
        try{
            $consent->acquire($clinic);
            // Read the authorized batch before acquiring sender locks, then recheck authority/state.
            $pdo->beginTransaction();$this->authorize($actor,$clinic);
            $apply=$method==='POST'&&preg_match('#^/([1-9][0-9]*)/apply$#D',$path,$m);
            $clear=$method==='POST'&&$path==='/clear-stop';
            if($apply){$batch=$this->batch((int)$m[1],$clinic);$dids=array_column(json_decode($batch['rows_json'],true,512,JSON_THROW_ON_ERROR),'sender_did');}
            elseif($clear){$dids=[self::text($body,'sender_did',10)];if(!CanadianSmsNumber::isAllowed('+1'.$dids[0]))self::invalid();$this->ownedSender($clinic,$dids[0]);}
            else $dids=[];
            $pdo->commit();if($dids)$sender->acquire($dids);
            $pdo->beginTransaction();$this->authorize($actor,$clinic);
            if($method==='GET'){
                if($path==='')$result=['items'=>$this->q('SELECT id,statement_reference,status,created_at,applied_at FROM provider_evidence_batches WHERE clinic_id=? ORDER BY id DESC LIMIT 30',[$clinic])->fetchAll()];
                elseif(preg_match('#^/([1-9][0-9]*)$#D',$path,$m)){$batch=$this->batch((int)$m[1],$clinic);$result=$this->report($batch,$this->resolve($clinic,json_decode($batch['rows_json'],true,512,JSON_THROW_ON_ERROR),$batch));}
                else throw new ApiException(404,'not_found','Route not found.');
                $pdo->commit();return $result;
            }
            if($method!=='POST')throw new ApiException(404,'not_found','Route not found.');
            $key=self::text($body,'request_key',36);if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$key))self::invalid();
            $fingerprint=hash('sha256','provider:'.$clinic.$path.json_encode($body,JSON_THROW_ON_ERROR));
            $old=$this->q('SELECT fingerprint,response FROM communication_requests WHERE application_administrator_id=? AND reference=?',[$actor->id,$key])->fetch();
            if($old){if(!hash_equals($old['fingerprint'],$fingerprint))self::conflict();$result=self::canonical(json_decode($old['response'],true,512,JSON_THROW_ON_ERROR));$pdo->commit();return $result;}
            $reason=self::text($body,'reason',1000);if(mb_strlen($reason)<5)self::invalid();
            if($path==='/preview')$result=$this->preview($actor,$clinic,$body);
            elseif($apply)$result=$this->apply($clinic,(int)$m[1],$body);
            elseif($clear)$result=$this->clearStop($clinic,$body);
            else throw new ApiException(404,'not_found','Route not found.');
            $this->q('INSERT INTO communication_events(clinic_id,application_administrator_id,action,entity_id,reason,details,correlation_id) VALUES(?,?,?,?,?,?,?)',[$clinic,$actor->id,$result['action'],$result['id']??null,$reason,json_encode($result['evidence']??[],JSON_THROW_ON_ERROR),substr($cid,0,128)]);unset($result['evidence']);$result=self::canonical($result);
            $this->q('INSERT INTO communication_requests(application_administrator_id,reference,fingerprint,response) VALUES(?,?,?,?)',[$actor->id,$key,$fingerprint,json_encode($result,JSON_THROW_ON_ERROR)]);$pdo->commit();return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$sender->release();$consent->release();}
    }
    private function preview(ApplicationAdminContext $actor,int $clinic,array $body): array
    {
        self::fields($body,['csv','statement_reference','usd_cad_rate','fx_date','fx_reference']);
        $rows=ProviderEvidenceCsv::parse(self::text($body,'csv',50000,true));$reference=self::text($body,'statement_reference',120);
        $hasUsd=in_array('USD',array_column($rows,'currency'),true);$rate=null;$date=null;$quote='';
        if($hasUsd){$rate=ProviderEvidenceCsv::decimal(self::text($body,'usd_cad_rate',20),100000000);if($rate<1)self::invalid();$date=self::text($body,'fx_date',10);$instant=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));if(!$instant||$instant->format('Y-m-d')!==$date||$date>gmdate('Y-m-d'))self::invalid();$quote=self::text($body,'fx_reference',190);}
        $batch=['fx_rate_micros'=>$rate,'fx_date'=>$date,'fx_reference'=>$quote];$resolved=$this->resolve($clinic,$rows,$batch);$hash=self::previewHash($resolved);
        $this->q('INSERT INTO provider_evidence_batches(clinic_id,administrator_id,statement_reference,source_hash,fx_rate_micros,fx_date,fx_reference,rows_json,preview_hash) VALUES(?,?,?,?,?,?,?,?,?)',[$clinic,$actor->id,$reference,hash('sha256',$body['csv']),$rate,$date,$quote,json_encode($rows,JSON_THROW_ON_ERROR),$hash]);
        $batch+=['id'=>(int)$this->database->connection()->lastInsertId(),'status'=>'preview','statement_reference'=>$reference,'preview_hash'=>$hash];
        return $this->report($batch,$resolved)+['action'=>'provider.preview','evidence'=>['source_hash'=>hash('sha256',$body['csv']),'rows'=>count($rows)]];
    }
    private function resolve(int $clinic,array $rows,array $batch): array
    {
        $result=[];
        foreach($rows as $row){
            $this->ownedSender($clinic,$row['sender_did']);
            $proof=self::digest([array_diff_key($row,['line'=>true]),$row['currency']==='USD'?[(int)$batch['fx_rate_micros'],$batch['fx_date'],$batch['fx_reference']]:null]);
            $status='ready';$actual=null;$target=null;$state=null;
            if($row['type']==='stop'){
                $record=$this->q('SELECT evidence_hash FROM provider_stop_evidence WHERE source_key=?',[$row['source_key']])->fetchColumn();
                if($record!==false)$status=hash_equals($record,$proof)?'already_recorded':'conflict';
                $state=$this->q('SELECT last_stop_at,cleared_after,version FROM provider_sender_stops WHERE sender_did=? AND number_hash=?',[$row['sender_did'],$row['number_hash']])->fetch()?:null;
            }else{
                $actual=ProviderEvidenceCsv::cents((int)$row['amount_micros'],$row['currency']==='CAD'?1000000:(int)$batch['fx_rate_micros']);
                $matches=$this->q('SELECT d.notification_event_id,d.pool_id,d.membership_id,d.reference,d.provider_message_id,n.recipient_address,e.created_at,e.status,e.actual_cents,e.period_start FROM sms_notification_deliveries d JOIN notification_events n ON n.id=d.notification_event_id JOIN sms_budget_entries e ON e.pool_id=d.pool_id AND e.reference=d.reference WHERE n.clinic_id=? AND d.sender_did=? AND d.provider_message_id=? AND BINARY d.provider_message_id=BINARY ? LIMIT 2',[$clinic,$row['sender_did'],$row['reference'],$row['reference']])->fetchAll();
                if(count($matches)!==1)$status=count($matches)===0?'unmatched':'conflict';
                else{
                    $target=$matches[0];
                    if(hash('sha256',$target['recipient_address'])!==$row['number_hash']||strtotime($row['occurred_at'].' UTC')<strtotime($target['created_at'].' UTC')-300)$status='conflict';
                    $record=$this->q('SELECT * FROM provider_evidence_charges WHERE source_key=? OR notification_event_id=?',[$row['source_key'],$target['notification_event_id']])->fetchAll();
                    if($record){$status=count($record)===1&&(int)$record[0]['notification_event_id']===(int)$target['notification_event_id']&&$record[0]['source_key']===$row['source_key']&&hash_equals($record[0]['evidence_hash'],$proof)&&$target['status']==='settled'&&(int)$target['actual_cents']===$actual?'already_recorded':'conflict';}
                    elseif($target['status']!=='submitted'&&!($target['status']==='settled'&&(int)$target['actual_cents']===$actual))$status='conflict';
                    $state=['id'=>(int)$target['notification_event_id'],'period'=>$target['period_start'],'status'=>$target['status'],'actual_cents'=>$target['actual_cents']===null?null:(int)$target['actual_cents'],'recipient_hash'=>hash('sha256',$target['recipient_address'])];
                }
            }
            $result[]=['line'=>$row['line'],'type'=>$row['type'],'reference'=>$row['reference'],'sender_did'=>$row['sender_did'],'currency'=>$row['currency'],'amount_micros'=>$row['amount_micros'],'status'=>$status,'actual_cents'=>$actual,'proof'=>$proof,'source_key'=>$row['source_key'],'state'=>$state,'target'=>$target,'input'=>$row];
        }
        return $result;
    }
    private function apply(int $clinic,int $id,array $body): array
    {
        self::fields($body,['preview_hash','provider_records_verified','conversion_verified']);
        if(($body['provider_records_verified']??false)!==true||($body['conversion_verified']??false)!==true)self::invalid();
        $batch=$this->batch($id,$clinic);if($batch['status']!=='preview'||!hash_equals($batch['preview_hash'],self::text($body,'preview_hash',64)))self::conflict();
        $this->q('SELECT id FROM sms_budget_pools WHERE clinic_id=? ORDER BY id FOR UPDATE',[$clinic]);
        $rows=$this->resolve($clinic,json_decode($batch['rows_json'],true,512,JSON_THROW_ON_ERROR),$batch);
        if(!hash_equals($batch['preview_hash'],self::previewHash($rows)))self::conflict();
        foreach($rows as $row)if(!in_array($row['status'],['ready','already_recorded'],true))throw new ApiException(409,'provider_evidence_unmatched','Every evidence row must match before applying the batch.');
        $charged=0;$stops=0;$ledger=new SmsBudgetLedger($this->database->connection(),true);
        foreach($rows as $row){
            if($row['status']==='already_recorded')continue;
            if($row['type']==='charge'){
                $t=$row['target'];$ledger->settle((int)$t['pool_id'],(int)$t['membership_id'],$t['reference'],$row['actual_cents']);
                $this->q('INSERT INTO provider_evidence_charges(source_key,batch_id,notification_event_id,evidence_hash,actual_cents) VALUES(?,?,?,?,?)',[$row['source_key'],$id,$t['notification_event_id'],$row['proof'],$row['actual_cents']]);$charged++;
                $this->q("UPDATE notification_events SET status='resolved',lease_token=NULL,leased_until=NULL WHERE id=? AND clinic_id=? AND status='needs_review'",[$t['notification_event_id'],$clinic]);
            }else{
                $r=$row['input'];$this->q('INSERT INTO provider_sender_stops(sender_did,number_hash,last_stop_at,batch_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_stop_at=GREATEST(last_stop_at,VALUES(last_stop_at)),batch_id=VALUES(batch_id),version=version+1',[$r['sender_did'],$r['number_hash'],$r['occurred_at'],$id]);
                $this->q('INSERT INTO provider_stop_evidence(source_key,evidence_hash,batch_id) VALUES(?,?,?)',[$row['source_key'],$row['proof'],$id]);$stops++;
            }
        }
        $this->q("UPDATE provider_evidence_batches SET status='applied',applied_at=UTC_TIMESTAMP() WHERE id=?",[$id]);
        return ['id'=>$id,'status'=>'applied','action'=>'provider.apply','charged'=>$charged,'stops'=>$stops,'evidence'=>['charged'=>$charged,'stops'=>$stops,'preview_hash'=>$batch['preview_hash']]];
    }
    private function clearStop(int $clinic,array $body): array
    {
        self::fields($body,['sender_did','destination','opt_in_at','provider_clearance_verified']);
        if(($body['provider_clearance_verified']??false)!==true)self::invalid();$did=self::text($body,'sender_did',10);$this->ownedSender($clinic,$did);$number=self::text($body,'destination',12);if(!CanadianSmsNumber::isAllowed($number))self::invalid();
        $date=self::text($body,'opt_in_at',19);$time=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new \DateTimeZone('UTC'));if(!$time||$time->format('Y-m-d H:i:s')!==$date||$time->getTimestamp()>time())self::invalid();
        $row=$this->q('SELECT * FROM provider_sender_stops WHERE sender_did=? AND number_hash=? FOR UPDATE',[$did,hash('sha256',$number)])->fetch();if(!$row)throw new ApiException(404,'provider_stop_not_found','Imported provider STOP not found.');
        if($date<=$row['last_stop_at']||($row['cleared_after']!==null&&$date<$row['cleared_after']))self::conflict();
        $this->q('UPDATE provider_sender_stops SET cleared_after=?,version=version+1 WHERE sender_did=? AND number_hash=?',[$date,$did,hash('sha256',$number)]);
        return ['action'=>'provider.stop.clear','evidence'=>['sender_did'=>$did,'number_hash'=>hash('sha256',$number),'opt_in_at'=>$date]];
    }
    private function ownedSender(int $clinic,string $did): void
    {
        if(!$this->q('SELECT 1 FROM clinic_communications WHERE clinic_id=? AND sms_from_did=? UNION ALL SELECT 1 FROM sms_notification_deliveries d JOIN sms_budget_pools p ON p.id=d.pool_id WHERE p.clinic_id=? AND d.sender_did=? LIMIT 1',[$clinic,$did,$clinic,$did])->fetchColumn())throw new ApiException(422,'provider_sender_mismatch','The evidence sender does not belong to this clinic.');
    }
    private function batch(int $id,int $clinic): array {$row=$this->q('SELECT * FROM provider_evidence_batches WHERE id=? AND clinic_id=? FOR UPDATE',[$id,$clinic])->fetch();if(!$row)throw new ApiException(404,'provider_batch_not_found','Provider batch not found.');return $row;}
    private function report(array $batch,array $rows): array
    {
        $canApply=$batch['status']==='preview'&&hash_equals($batch['preview_hash'],self::previewHash($rows));foreach($rows as $row)if(!in_array($row['status'],['ready','already_recorded'],true))$canApply=false;
        return ['id'=>(int)$batch['id'],'status'=>$batch['status'],'statement_reference'=>$batch['statement_reference'],'preview_hash'=>$batch['preview_hash'],'can_apply'=>$canApply,'rows'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['line','type','reference','sender_did','currency','amount_micros','status','actual_cents'])),$rows),'fx'=>['rate_micros'=>$batch['fx_rate_micros'],'date'=>$batch['fx_date'],'reference'=>$batch['fx_reference']]];
    }
    private static function previewHash(array $rows): string {return self::digest(array_map(fn($r)=>array_diff_key($r,['target'=>true,'input'=>true]),$rows));}
    private static function digest(array $value): string
    {
        return hash('sha256',json_encode(self::canonical($value),JSON_THROW_ON_ERROR));
    }
    private static function canonical(mixed $item): mixed {if(!is_array($item))return $item;if(!array_is_list($item))ksort($item,SORT_STRING);foreach($item as &$child)$child=self::canonical($child);return $item;}
    private static function fields(array $body,array $allowed): void {if(array_diff(array_keys($body),array_merge($allowed,['request_key','reason'])))self::invalid();}
    private static function text(array $body,string $key,int $max,bool $multiline=false): string {$v=$body[$key]??'';if(!is_string($v)||mb_strlen($v)>$max||trim($v)===''||preg_match($multiline?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$v))self::invalid();return $v;}
    private static function invalid(): never {throw new ApiException(422,'provider_evidence_invalid','Check the normalized provider evidence and review confirmations.');}
    private static function conflict(): never {throw new ApiException(409,'provider_evidence_changed','Provider evidence or ledger state changed. Create a fresh preview.');}
}
