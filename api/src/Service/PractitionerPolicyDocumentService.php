<?php
declare(strict_types=1);

namespace Wellness\Service;

use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Draft/publish controlled clinic documents and immutable practitioner acceptance. */
final class PractitionerPolicyDocumentService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    private function staff(AuthContext $actor,bool $admin=false): void
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole($admin?'super_admin':'practitioner'))throw new ApiException(403,'forbidden','Access is not authorized.');
        $role=$admin?'super_admin':'practitioner';
        $q=$this->database->connection()->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
            AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code=?)");
        $q->execute([$actor->userId,$actor->clinicId,$role]);
        if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Access is not authorized.');
    }

    private static function digest(array $row): string
    {
        return hash('sha256',implode("\n",[(string)$row['kind'],(string)$row['version_code'],(string)$row['title_en'],(string)$row['title_fr'],(string)$row['body_en'],(string)$row['body_fr']]));
    }

    private static function integrity(array $row): void
    {
        if(!hash_equals((string)$row['content_hash'],self::digest($row)))throw new ApiException(503,'document_integrity','A practitioner document requires review.');
    }

    public function adminList(AuthContext $actor): array
    {
        $this->staff($actor,true);
        $q=$this->database->connection()->prepare('SELECT id,kind,version_code,title_en,title_fr,body_en,body_fr,content_hash,status,created_at,published_at,review_reference FROM practitioner_policy_documents WHERE clinic_id=? ORDER BY id DESC LIMIT 100');
        $q->execute([$actor->clinicId]);$rows=$q->fetchAll();foreach($rows as $row)self::integrity($row);
        return ['items'=>$rows];
    }

    public function createDraft(AuthContext $actor,array $body,string $cid): array
    {
        $this->staff($actor,true);
        $kind=$body['kind']??null;$version=$body['version_code']??null;
        if(!in_array($kind,['services_agreement','records_agreement','confidentiality','departure'],true)||!is_string($version)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/D',$version))throw new ApiException(422,'validation_error','Choose a document type and version.');
        foreach(['title_en'=>190,'title_fr'=>190,'body_en'=>32000,'body_fr'=>32000] as $field=>$limit){
            if(!is_string($body[$field]??null)||trim($body[$field])===''||mb_strlen($body[$field])>$limit||str_contains($body[$field],"\0"))throw new ApiException(422,'validation_error','Provide complete English and French document text.');
        }
        $row=['kind'=>$kind,'version_code'=>$version,'title_en'=>trim($body['title_en']),'title_fr'=>trim($body['title_fr']),'body_en'=>trim($body['body_en']),'body_fr'=>trim($body['body_fr'])];
        $hash=self::digest($row);$pdo=$this->database->connection();
        if($pdo->inTransaction())throw new \LogicException('Document creation requires its own transaction.');
        try{
            $pdo->beginTransaction();$this->staff($actor,true);
            $clinic=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$clinic->execute([$actor->clinicId]);
            if(!$clinic->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $q=$pdo->prepare("INSERT INTO practitioner_policy_documents(clinic_id,kind,version_code,title_en,title_fr,body_en,body_fr,content_hash,created_by,created_at)
                VALUES(:clinic,:kind,:version,:title_en,:title_fr,:body_en,:body_fr,:hash,:actor,UTC_TIMESTAMP())");
            $q->execute(['clinic'=>$actor->clinicId,'kind'=>$kind,'version'=>$version,'title_en'=>$row['title_en'],'title_fr'=>$row['title_fr'],'body_en'=>$row['body_en'],'body_fr'=>$row['body_fr'],'hash'=>$hash,'actor'=>$actor->userId]);
            $id=(int)$pdo->lastInsertId();$this->audit->write($actor->clinicId,$actor,$cid,'practitioner_document.draft','practitioner_document',$id);
            $pdo->commit();return ['id'=>$id,'content_hash'=>$hash,'status'=>'draft'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof \PDOException&&(int)($e->errorInfo[1]??0)===1062)throw new ApiException(409,'document_version_exists','That version already exists.');throw $e;}
    }

    public function publish(AuthContext $actor,int $id,array $body,string $cid): array
    {
        $reference=$body['review_reference']??null;
        if(($body['confirmed']??null)!==true||!is_string($reference)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{7,119}$/D',$reference))throw new ApiException(422,'validation_error','Confirm legal and clinical review with a reference.');
        $pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Document publication requires its own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();$this->staff($actor,true);
            $clinic=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$clinic->execute([$actor->clinicId]);
            if(!$clinic->fetchColumn())throw new ApiException(403,'forbidden','The clinic is unavailable.');
            $q=$pdo->prepare('SELECT * FROM practitioner_policy_documents WHERE id=? AND clinic_id=? FOR UPDATE');$q->execute([$id,$actor->clinicId]);$row=$q->fetch();
            if(!$row)throw new ApiException(404,'document_not_found','Document not found.');
            self::integrity($row);
            if($row['status']!=='draft')throw new ApiException(409,'document_already_published','This document is already published.');
            $q=$pdo->prepare("SELECT 1 FROM practitioner_policy_documents WHERE clinic_id=? AND kind=? AND status='published' AND id>? LIMIT 1");$q->execute([$actor->clinicId,$row['kind'],$id]);
            if($q->fetchColumn())throw new ApiException(409,'document_version_order','A newer document is already published.');
            $q=$pdo->prepare("UPDATE practitioner_policy_documents SET status='published',published_by=?,published_at=UTC_TIMESTAMP(),review_reference=? WHERE id=? AND status='draft'");
            $q->execute([$actor->userId,$reference,$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'practitioner_document.publish','practitioner_document',$id,'success',['kind'=>$row['kind'],'version'=>$row['version_code']]);
            $pdo->commit();return ['id'=>$id,'status'=>'published','content_hash'=>$row['content_hash']];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    private function current(int $clinic): array
    {
        $q=$this->database->connection()->prepare("SELECT d.* FROM practitioner_policy_documents d WHERE d.clinic_id=:clinic AND d.status='published'
            AND d.id=(SELECT MAX(newer.id) FROM practitioner_policy_documents newer WHERE newer.clinic_id=d.clinic_id AND newer.kind=d.kind AND newer.status='published') ORDER BY d.kind");
        $q->execute(['clinic'=>$clinic]);$rows=$q->fetchAll();foreach($rows as $row)self::integrity($row);return $rows;
    }

    public function practitionerList(AuthContext $actor): array
    {
        $this->staff($actor);$items=[];
        foreach($this->current($actor->clinicId) as $row){
            $q=$this->database->connection()->prepare('SELECT accepted_at,displayed_language FROM practitioner_document_acceptances WHERE document_id=? AND practitioner_user_id=? AND accepted_hash=? AND identity_subject_hash=?');
            $q->execute([(int)$row['id'],$actor->userId,$row['content_hash'],hash('sha256',$actor->externalObjectId)]);$accepted=$q->fetch()?:null;
            $items[]=['id'=>(int)$row['id'],'kind'=>$row['kind'],'version_code'=>$row['version_code'],'title_en'=>$row['title_en'],'title_fr'=>$row['title_fr'],'body_en'=>$row['body_en'],'body_fr'=>$row['body_fr'],'content_hash'=>$row['content_hash'],'accepted'=>$accepted];
        }
        return ['items'=>$items];
    }

    public function accept(AuthContext $actor,int $id,array $body,string $cid): array
    {
        $language=$body['language']??null;$hash=$body['content_hash']??null;
        if(($body['accepted']??null)!==true||!in_array($language,['en','fr'],true)||!is_string($hash)||!preg_match('/^[0-9a-f]{64}$/D',$hash))throw new ApiException(422,'validation_error','Review the document and confirm acceptance.');
        if($actor->externalObjectId==='')throw new ApiException(403,'identity_required','A verified practitioner identity is required.');
        $pdo=$this->database->connection();if($pdo->inTransaction())throw new \LogicException('Document acceptance requires its own transaction.');
        try{
            $pdo->beginTransaction();$this->staff($actor);
            $current=$this->current($actor->clinicId);$row=null;foreach($current as $candidate)if((int)$candidate['id']===$id)$row=$candidate;
            if(!$row)throw new ApiException(409,'document_changed','The current document changed. Review the new version.');
            if(!hash_equals($row['content_hash'],$hash))throw new ApiException(409,'document_changed','The current document changed. Review the new version.');
            $q=$pdo->prepare('SELECT id,identity_subject_hash FROM practitioner_document_acceptances WHERE document_id=? AND practitioner_user_id=? FOR UPDATE');$q->execute([$id,$actor->userId]);$existing=$q->fetch();
            if($existing){
                if(!hash_equals((string)$existing['identity_subject_hash'],hash('sha256',$actor->externalObjectId)))throw new ApiException(409,'identity_changed','The practitioner identity changed. A reviewer must resolve the prior acknowledgement.');
                $pdo->commit();return ['id'=>(int)$existing['id'],'document_id'=>$id,'accepted'=>true];
            }
            $q=$pdo->prepare('INSERT INTO practitioner_document_acceptances(clinic_id,document_id,practitioner_user_id,identity_subject_hash,displayed_language,accepted_hash,correlation_id,accepted_at)
                VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$actor->clinicId,$id,$actor->userId,hash('sha256',$actor->externalObjectId),$language,$hash,$cid]);$acceptance=(int)$pdo->lastInsertId();
            $this->audit->write($actor->clinicId,$actor,$cid,'practitioner_document.accept','practitioner_document',$id,'success',['acceptance_id'=>$acceptance,'version'=>$row['version_code']]);
            $pdo->commit();return ['id'=>$acceptance,'document_id'=>$id,'accepted'=>true];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function requireAccepted(AuthContext $actor): void
    {
        $this->staff($actor);$current=$this->current($actor->clinicId);
        if($current===[])throw new ApiException(503,'practitioner_documents_unavailable','Practitioner documents are not yet available.');
        foreach($current as $row){
            $q=$this->database->connection()->prepare('SELECT 1 FROM practitioner_document_acceptances WHERE clinic_id=? AND document_id=? AND practitioner_user_id=? AND accepted_hash=? AND identity_subject_hash=?');
            $q->execute([$actor->clinicId,(int)$row['id'],$actor->userId,$row['content_hash'],hash('sha256',$actor->externalObjectId)]);
            if(!$q->fetchColumn())throw new ApiException(403,'practitioner_documents_required','Review and accept the current practitioner documents before opening clinic notes.');
        }
    }
}
