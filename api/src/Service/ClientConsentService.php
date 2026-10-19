<?php
declare(strict_types=1);
namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class ClientConsentService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    private function authorize(AuthContext $actor,int $client): void
    {
        if (!($actor->userType==='client' && $actor->userId===$client)
            && !($actor->userType==='staff' && $actor->hasPermission('manage_client_consent')))
            throw new ApiException(403,'forbidden','Consent management is not permitted.');
        $q=$this->database->connection()->prepare("SELECT 1 FROM users c JOIN users actor ON actor.id=? AND actor.clinic_id=c.clinic_id AND actor.status='active' WHERE c.id=? AND c.clinic_id=? AND c.user_type='client' AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=c.id)");
        $q->execute([$actor->userId,$client,$actor->clinicId]);
        if(!$q->fetchColumn())throw new ApiException(404,'client_not_found','Client not found.');
    }

    public function list(AuthContext $actor,int $client): array
    {
        $this->authorize($actor,$client);
        $q=$this->database->connection()->prepare('SELECT id,client_id,scope,target_staff_id,restricted,version,updated_at FROM client_consent_directives WHERE clinic_id=? AND client_id=? ORDER BY id');
        $q->execute([$actor->clinicId,$client]);
        $items=$q->fetchAll();foreach($items as &$item)$item['restricted']=(bool)$item['restricted'];
        $staff=$this->database->connection()->prepare("SELECT id,display_name FROM users WHERE clinic_id=? AND user_type='staff' ORDER BY display_name,id");$staff->execute([$actor->clinicId]);
        return ['items'=>$items,'staff'=>$staff->fetchAll()];
    }

    public function save(AuthContext $actor,int $client,array $body,string $cid): array
    {
        $this->authorize($actor,$client);
        if(array_diff(array_keys($body),['scope','target_staff_id','restricted','version','instruction_reference'])!==[])throw new ApiException(422,'validation_error','Unknown consent instruction field.');
        $scope=$body['scope']??null;$target=$body['target_staff_id']??0;$version=$body['version']??null;
        if(!in_array($scope,['staff_access','patient_communications'],true)||!is_bool($body['restricted']??null)
            ||!is_int($target)||$target<0||!is_int($version)||$version<0||($scope==='patient_communications'&&$target!==0))
            throw new ApiException(422,'validation_error','Select a valid scope, staff target, restriction and current version.');
        // A named restricted staff member must never clear their own lockbox, even as a privacy manager.
        if($actor->userType==='staff'&&!$body['restricted']&&($target===0||$target===$actor->userId))
            throw new ApiException(403,'consent_self_restore','The patient must restore this access, or another authorized privacy manager must record a named-staff instruction.');
        $reference=null;
        if($actor->userType==='staff') {
            $reference=$body['instruction_reference']??null;
            if(!is_string($reference)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,119}$/D',$reference))
                throw new ApiException(422,'validation_error','A verified instruction reference is required; use a reference code, not clinical details.');
        }
        $pdo=$this->database->connection();$lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try {
            $this->authorize($actor,$client);
            $pdo->beginTransaction();
            if($target!==0){$q=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND clinic_id=? AND user_type='staff'");$q->execute([$target,$actor->clinicId]);if(!$q->fetchColumn())throw new ApiException(422,'invalid_staff','Select staff in this clinic.');}
            $q=$pdo->prepare('SELECT id,version FROM client_consent_directives WHERE clinic_id=? AND client_id=? AND scope=? AND target_staff_id=? FOR UPDATE');$q->execute([$actor->clinicId,$client,$scope,$target]);$row=$q->fetch();
            if(($row?(int)$row['version']:0)!==$version)throw new ApiException(409,'consent_changed','Consent instructions changed. Reload before saving.');
            $next=$version+1;
            if($row){$id=(int)$row['id'];$pdo->prepare('UPDATE client_consent_directives SET restricted=?,version=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([(int)$body['restricted'],$next,$id]);}
            else{$pdo->prepare('INSERT INTO client_consent_directives(clinic_id,client_id,scope,target_staff_id,restricted,version) VALUES(?,?,?,?,?,?)')->execute([$actor->clinicId,$client,$scope,$target,(int)$body['restricted'],$next]);$id=(int)$pdo->lastInsertId();}
            $pdo->prepare('INSERT INTO client_consent_events(directive_id,version,restricted,actor_user_id,source,instruction_reference,correlation_id) VALUES(?,?,?,?,?,?,?)')->execute([$id,$next,(int)$body['restricted'],$actor->userId,$actor->userType==='client'?'patient_portal':'verified_instruction',$reference,$cid]);
            $this->audit->write($actor->clinicId,$actor,$cid,'consent.directive','client',$client,'success',['directive_id'=>$id,'version'=>$next,'scope'=>$scope,'target_staff_id'=>$target,'restricted'=>$body['restricted']]);
            $pdo->commit();return $this->list($actor,$client);
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }
}
