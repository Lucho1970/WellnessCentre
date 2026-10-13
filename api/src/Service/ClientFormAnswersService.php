<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;

/** Called only after ClientFormsService has authorized and (for writes) locked the task. */
final class ClientFormAnswersService
{
    private ?bool $storageAvailable=null;
    public function __construct(private readonly PDO $pdo) {}
    public static function enabled(): bool {
        return filter_var($_ENV['CLIENT_FORM_ANSWERS_ENABLED']??getenv('CLIENT_FORM_ANSWERS_ENABLED')?:'false',FILTER_VALIDATE_BOOL);
    }
    public function available(): bool {
        if($this->storageAvailable===null){
            $this->storageAvailable=true;
            foreach(['client_form_answer_drafts','client_form_amendments','client_form_answer_requests'] as $table)if(!$this->pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn())$this->storageAvailable=false;
        }
        return $this->storageAvailable;
    }
    public function decorate(array $row,bool $client): array {
        $row['answer_changes_enabled']=self::enabled()&&$this->available();
        $row['draft']=null;$row['amendments']=[];$row['original_answers']=$row['answers'];
        if(!$this->available())return $row;
        if($client&&$row['status']==='pending'){
            $s=$this->pdo->prepare('SELECT CAST(response_data AS BINARY) response_data,updated_at FROM client_form_answer_drafts WHERE task_id=?');$s->execute([$row['id']]);
            if($draft=$s->fetch())$row['draft']=['answers'=>json_decode($draft['response_data'],true,32,JSON_THROW_ON_ERROR),'updated_at'=>$draft['updated_at']];
        }
        $s=$this->pdo->prepare('SELECT id,CAST(response_data AS BINARY) response_data,CAST(reason AS BINARY) reason,created_at,reviewed_at FROM client_form_amendments WHERE task_id=? ORDER BY id LIMIT 50');$s->execute([$row['id']]);
        foreach($s->fetchAll() as $amendment){$amendment['id']=(int)$amendment['id'];$amendment['answers']=json_decode($amendment['response_data'],true,32,JSON_THROW_ON_ERROR);unset($amendment['response_data']);$row['amendments'][]=$amendment;$row['answers']=$amendment['answers'];}
        return $row;
    }
    public function write(AuthContext $actor,array $row,array $body,bool $amend): array {
        if(!self::enabled()||!$this->available())throw new ApiException(503,'answer_changes_disabled','Client answer drafts and amendments are not enabled.');
        $key=$body['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new ApiException(422,'invalid_form','A save key is required.');
        if(!is_int($body['version']??null))throw new ApiException(422,'invalid_form','Check the form version.');
        $definition=json_decode($row['definition'],true,32,JSON_THROW_ON_ERROR);
        $answers=ClientFormDefinition::answers($definition,$body['answers']??null,!$amend);
        $reason=$amend?($body['reason']??null):'';
        if($amend&&(!is_string($reason)||trim($reason)===''||strlen($reason)>1000))throw new ApiException(422,'invalid_form','Enter a reason for the amendment.');
        if($amend&&($body['confirmed']??false)!==true)throw new ApiException(422,'invalid_form','Confirm the amendment before saving.');
        $reason=trim($reason);
        $hash=hash('sha256',json_encode([$actor->userId,$amend,$body['version'],$answers,$reason],JSON_THROW_ON_ERROR));
        $s=$this->pdo->prepare('SELECT request_hash,CAST(result_data AS BINARY) result_data FROM client_form_answer_requests WHERE task_id=? AND request_key=?');$s->execute([$row['id'],$key]);
        if($replay=$s->fetch()){
            if(!hash_equals($replay['request_hash'],$hash))throw new ApiException(409,'idempotency_conflict','This key was used for another request.');
            return json_decode($replay['result_data'],true,32,JSON_THROW_ON_ERROR);
        }
        if($body['version']!==(int)$row['version'])throw new ApiException(409,'form_changed','The form changed. Reload it before saving.');
        $result=['id'=>(int)$row['id'],'version'=>(int)$row['version']+1,'status'=>$amend?'submitted':'pending'];
        if(!$amend){
            if($row['status']!=='pending'||$row['submission_id'])throw new ApiException(409,'form_changed','Only pending forms can have answer drafts.');
            $s=$this->pdo->prepare('INSERT INTO client_form_answer_drafts(task_id,response_data,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE response_data=VALUES(response_data),updated_at=VALUES(updated_at)');$s->execute([$row['id'],json_encode($answers,JSON_THROW_ON_ERROR)]);
            $this->pdo->prepare('UPDATE client_form_tasks SET version=version+1 WHERE id=?')->execute([$row['id']]);
        }else{
            if(!$row['submission_id']||!in_array($row['status'],['submitted','reviewed'],true))throw new ApiException(409,'form_changed','Only submitted forms can be amended.');
            $decorated=$row;$decorated['answers']=null;
            $s=$this->pdo->prepare('SELECT CAST(response_data AS BINARY) response_data FROM form_submissions WHERE id=?');$s->execute([$row['submission_id']]);$original=json_decode($s->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
            foreach($definition['questions'] as $q)if($q['type']==='consent'&&($answers[$q['id']]??null)!==($original[$q['id']]??null))throw new ApiException(422,'invalid_form','Consent answers cannot be changed through an amendment.');
            $decorated['answers']=$original;$decorated=$this->decorate($decorated,false);
            if($answers===$decorated['answers'])throw new ApiException(422,'invalid_form','Change at least one answer before saving an amendment.');
            if(count($decorated['amendments'])>=50)throw new ApiException(409,'amendment_limit','Contact the practitioner for a new form assignment.');
            $s=$this->pdo->prepare('INSERT INTO client_form_amendments(task_id,submission_id,author_client_id,response_data,reason,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())');$s->execute([$row['id'],$row['submission_id'],$actor->userId,json_encode($answers,JSON_THROW_ON_ERROR),$reason]);
            $result['amendment_id']=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE client_form_tasks SET status='submitted',reviewed_by=NULL,reviewed_at=NULL,version=version+1 WHERE id=?")->execute([$row['id']]);
        }
        $s=$this->pdo->prepare('INSERT INTO client_form_answer_requests(task_id,request_key,request_hash,result_data) VALUES(?,?,?,?)');$s->execute([$row['id'],$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
        return $result;
    }
    public function clearDraft(int $task): void {
        if($this->available())$this->pdo->prepare('DELETE FROM client_form_answer_drafts WHERE task_id=?')->execute([$task]);
    }
    public function reviewLatest(int $task,int $reviewer): bool {
        if(!$this->available())return false;
        $s=$this->pdo->prepare('SELECT id FROM client_form_amendments WHERE task_id=? ORDER BY id DESC LIMIT 1');$s->execute([$task]);$id=$s->fetchColumn();
        if(!$id)return false;
        $this->pdo->prepare('UPDATE client_form_amendments SET reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$reviewer,$id]);return true;
    }
}
