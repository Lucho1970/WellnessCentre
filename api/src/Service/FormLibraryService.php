<?php
declare(strict_types=1);
namespace Wellness\Service;

use Throwable;
use Wellness\Auth\{AuthContext,ApplicationAdminContext};
use Wellness\Database;
use Wellness\Http\ApiException;

final class FormLibraryService
{
    public function __construct(private readonly Database $database) {}

    public static function enabled(): bool { return filter_var($_ENV['FORM_LIBRARY_ENABLED']??getenv('FORM_LIBRARY_ENABLED')?:'false',FILTER_VALIDATE_BOOL); }

    private function guard(AuthContext|ApplicationAdminContext $actor): void
    {
        if (!self::enabled()) throw new ApiException(503,'form_library_disabled','Form library is not enabled.');
        if ($actor instanceof ApplicationAdminContext) return;
        if ($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin','clinic_admin','practitioner')) throw new ApiException(403,'forbidden','Form author access is required.');
        if (!$this->clinicAdmin($actor)) {
            $q=$this->database->connection()->prepare("SELECT p.id FROM practitioners p JOIN users u ON u.id=p.user_id WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND p.active=1");
            $q->execute([$actor->userId,$actor->clinicId]);
            if (!$q->fetchColumn()) throw new ApiException(403,'forbidden','Active practitioner access is required.');
        }
        if (PHP_SAPI!=='cli') header('Cache-Control: no-store');
    }
    private function clinicAdmin(AuthContext $actor): bool { return $actor->hasAnyRole('super_admin','clinic_admin'); }
    private function manager(AuthContext|ApplicationAdminContext $actor,array $row): bool
    {
        return $actor instanceof ApplicationAdminContext ? $row['scope']==='global' : $row['scope']==='clinic'&&(int)$row['clinic_id']===$actor->clinicId&&$this->clinicAdmin($actor);
    }
    private function author(AuthContext|ApplicationAdminContext $actor,array $row): bool
    {
        return $this->manager($actor,$row)||($actor instanceof AuthContext&&(int)$row['clinic_id']===$actor->clinicId&&(int)$row['created_by_user_id']===$actor->userId);
    }
    private function event(AuthContext|ApplicationAdminContext $actor,int $id,string $action,string $cid,array $metadata=[]): void
    {
        $this->database->connection()->prepare('INSERT INTO form_library_events(version_id,clinic_id,actor_user_id,actor_admin_id,action,correlation_id,metadata) VALUES(?,?,?,?,?,?,?)')->execute([
            $id,$actor instanceof AuthContext?$actor->clinicId:null,$actor instanceof AuthContext?$actor->userId:null,$actor instanceof ApplicationAdminContext?$actor->id:null,$action,$cid,json_encode($metadata,JSON_THROW_ON_ERROR),
        ]);
    }
    private function select(): string { return 'SELECT v.*,CAST(v.definition AS BINARY) definition,e.scope,e.clinic_id,e.created_by_user_id,e.created_by_admin_id,e.latest_version,e.approved_version_id,e.archived FROM form_library_versions v JOIN form_library_entries e ON e.id=v.entry_id'; }
    private function row(int $id,bool $lock=false): array
    {
        $q=$this->database->connection()->prepare($this->select().' WHERE v.id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);
        if (!$row=$q->fetch()) throw new ApiException(404,'library_not_found','Library template not found.');
        return $row;
    }
    private function present(AuthContext|ApplicationAdminContext $actor,array $row,bool $definition=false): array
    {
        $result=array_intersect_key($row,array_flip(['id','entry_id','version','revision','name','description','form_type','scope','status','review_note','created_at']));
        foreach (['id','entry_id','version','revision'] as $key) $result[$key]=(int)$result[$key];
        $result['review_note']=$this->author($actor,$row)?$row['review_note']:'';
        $result['can_review']=$this->manager($actor,$row)&&!(bool)$row['archived']&&$row['status']==='pending';
        $result['can_revise']=$this->author($actor,$row)&&!(bool)$row['archived'];
        $result['can_retire']=$this->manager($actor,$row)&&!(bool)$row['archived']&&(int)$row['approved_version_id']===(int)$row['id'];
        $result['can_use']=$actor instanceof AuthContext&&!(bool)$row['archived']&&(int)$row['approved_version_id']===(int)$row['id'];
        if ($definition) $result['definition']=json_decode($row['definition'],true,32,JSON_THROW_ON_ERROR);
        return $result;
    }
    public function list(AuthContext|ApplicationAdminContext $actor,array $query): array
    {
        $this->guard($actor);$mode=$query['mode']??'browse';$scope=$query['scope']??'global';
        if (!in_array($mode,['browse','submissions','review'],true)||!in_array($scope,['global','clinic'],true)) throw new ApiException(422,'validation_error','Choose a library and view.');
        if ($actor instanceof ApplicationAdminContext&&$scope!=='global') throw new ApiException(403,'forbidden','Global library access is required.');
        $params=[];$where=' WHERE e.archived=0 AND e.scope=?';$params[]=$scope;
        if ($scope==='clinic') {$where.=' AND e.clinic_id=?';$params[]=$actor->clinicId;}
        if ($mode==='browse') $where.=' AND v.id=e.approved_version_id';
        elseif ($mode==='review') {
            if (!($actor instanceof ApplicationAdminContext)&&!($scope==='clinic'&&$this->clinicAdmin($actor))) throw new ApiException(403,'forbidden','Library reviewer access is required.');
            $where.=" AND v.version=e.latest_version AND v.status='pending'";
        } else {
            $where.=' AND v.version=e.latest_version';
            if ($actor instanceof AuthContext) { $where.=' AND e.clinic_id=? AND e.created_by_user_id=?';$params[]=$actor->clinicId;$params[]=$actor->userId; }
        }
        $term=trim((string)($query['q']??''));if(mb_strlen($term)>100)throw new ApiException(422,'validation_error','Search is too long.');
        if($term!==''){$where.=" AND (v.name LIKE ? ESCAPE '!' OR v.description LIKE ? ESCAPE '!')";$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';$params[]=$pattern;$params[]=$pattern;}
        $page=ClientOverviewService::page($query);$offset=($page-1)*20;
        $q=$this->database->connection()->prepare($this->select().$where." ORDER BY v.id DESC LIMIT 21 OFFSET $offset");$q->execute($params);$rows=$q->fetchAll();
        return ['items'=>array_map(fn($r)=>$this->present($actor,$r),array_slice($rows,0,20)),'has_more'=>count($rows)>20,'page'=>$page,'can_review'=>$actor instanceof ApplicationAdminContext||($scope==='clinic'&&$this->clinicAdmin($actor)),'max_questions'=>ClientFormDefinition::maxQuestions()];
    }
    public function detail(AuthContext|ApplicationAdminContext $actor,int $id): array
    {
        $this->guard($actor);$row=$this->row($id);
        $published=!(bool)$row['archived']&&(int)$row['approved_version_id']===$id&&($row['scope']==='global'||($actor instanceof AuthContext&&(int)$row['clinic_id']===$actor->clinicId));
        if (!$published&&!$this->author($actor,$row)) throw new ApiException(404,'library_not_found','Library template not found.');
        return $this->present($actor,$row,true);
    }
    private function document(array $body): array
    {
        $name=trim(is_string($body['name']??null)?$body['name']:'');$description=trim(is_string($body['description']??null)?$body['description']:'');$type=$body['form_type']??null;
        if ($name===''||strlen($name)>190||$description===''||mb_strlen($description)>2000||!in_array($type,['intake','consent','follow_up','questionnaire'],true)) throw new ApiException(422,'validation_error','Enter a template name, description and form type.');
        return ['name'=>$name,'description'=>$description,'form_type'=>$type,'definition'=>ClientFormDefinition::definition($body['definition']??null,$type)];
    }
    public function submit(AuthContext|ApplicationAdminContext $actor,array $body,string $cid): array
    {
        $this->guard($actor);$doc=$this->document($body);$scope=$body['scope']??null;$key=$body['idempotency_key']??null;
        if(!in_array($scope,['global','clinic'],true)||($actor instanceof ApplicationAdminContext&&$scope!=='global')) throw new ApiException(422,'validation_error','Choose a library and view.');
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new ApiException(422,'validation_error','A library request key is required.');
        $identity=$actor instanceof ApplicationAdminContext?'admin:'.$actor->id:'clinic:'.$actor->clinicId.':user:'.$actor->userId;
        $entry=$body['entry_id']??null;if($entry!==null&&(!is_int($entry)||$entry<1))throw new ApiException(422,'validation_error','Invalid library entry.');
        $hash=hash('sha256',json_encode([$identity,$scope,$doc,$entry,$body['expected_version']??null],JSON_THROW_ON_ERROR));$pdo=$this->database->connection();
        try {
            $pdo->beginTransaction();
            // Serialize creation retries for an author, then revisions for an entry.
            if($actor instanceof ApplicationAdminContext)$pdo->prepare('SELECT id FROM application_administrators WHERE id=? FOR UPDATE')->execute([$actor->id]);
            else $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$actor->userId]);
            if($entry===null){
                $q=$pdo->prepare('SELECT * FROM form_library_entries WHERE creation_actor=? AND creation_key=? FOR UPDATE');$q->execute([$identity,$key]);$existing=$q->fetch();
                if($existing){if($existing['creation_hash']!==$hash)throw new ApiException(409,'idempotency_conflict','This key was used for another request.');$q=$pdo->prepare('SELECT id FROM form_library_versions WHERE entry_id=? AND version=1');$q->execute([$existing['id']]);$id=(int)$q->fetchColumn();$pdo->commit();return $this->detail($actor,$id);}
                $pdo->prepare('INSERT INTO form_library_entries(scope,clinic_id,created_by_user_id,created_by_admin_id,creation_key,creation_actor,creation_hash) VALUES(?,?,?,?,?,?,?)')->execute([$scope,$actor instanceof AuthContext?$actor->clinicId:null,$actor instanceof AuthContext?$actor->userId:null,$actor instanceof ApplicationAdminContext?$actor->id:null,$key,$identity,$hash]);$entry=(int)$pdo->lastInsertId();$version=1;
            } else {
                $q=$pdo->prepare('SELECT id FROM form_library_entries WHERE id=? FOR UPDATE');$q->execute([$entry]);
                $q=$pdo->prepare($this->select().' WHERE e.id=? AND v.version=e.latest_version');$q->execute([$entry]);$row=$q->fetch();
                if(!$row||!$this->author($actor,$row)||$row['scope']!==$scope||$row['archived'])throw new ApiException(404,'library_not_found','Library template not found.');
                $q=$pdo->prepare('SELECT id,request_hash FROM form_library_versions WHERE entry_id=? AND request_key=?');$q->execute([$entry,$key]);$retry=$q->fetch();
                if($retry){if($retry['request_hash']!==$hash)throw new ApiException(409,'idempotency_conflict','This key was used for another request.');$pdo->commit();return $this->detail($actor,(int)$retry['id']);}
                if($row['status']==='pending'||!is_int($body['expected_version']??null)||$body['expected_version']!==(int)$row['version'])throw new ApiException(409,'library_changed','Review the pending version or reload the library before submitting another version.');
                $version=(int)$row['version']+1;$pdo->prepare('UPDATE form_library_entries SET latest_version=? WHERE id=?')->execute([$version,$entry]);
            }
            $pdo->prepare('INSERT INTO form_library_versions(entry_id,version,name,description,form_type,definition,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?)')->execute([$entry,$version,$doc['name'],$doc['description'],$doc['form_type'],json_encode($doc['definition'],JSON_THROW_ON_ERROR),$key,$hash]);$id=(int)$pdo->lastInsertId();$this->event($actor,$id,'submit',$cid);$pdo->commit();return $this->detail($actor,$id);
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function review(AuthContext|ApplicationAdminContext $actor,int $id,array $body,string $cid): array
    {
        $this->guard($actor);$action=$body['action']??null;$note=$body['review_note']??'';
        if(!in_array($action,['approve','reject','retire'],true)||!is_int($body['revision']??null)||!is_string($note)||mb_strlen($note)>1000||($action==='reject'&&trim($note)===''))throw new ApiException(422,'validation_error','Choose a review action and give a reason when rejecting.');
        $pdo=$this->database->connection();
        try{$pdo->beginTransaction();$initial=$this->row($id);$pdo->prepare('SELECT id FROM form_library_entries WHERE id=? FOR UPDATE')->execute([$initial['entry_id']]);$row=$this->row($id,true);
            if(!$this->manager($actor,$row))throw new ApiException(403,'forbidden','Library reviewer access is required.');
            if($row['archived']||(int)$row['revision']!==$body['revision']||($action==='retire'?(int)$row['approved_version_id']!==$id:($row['status']!=='pending'||(int)$row['version']!==(int)$row['latest_version'])))throw new ApiException(409,'library_changed','The library template changed. Reload it before reviewing.');
            if($action==='retire')$pdo->prepare('UPDATE form_library_entries SET archived=1 WHERE id=?')->execute([$row['entry_id']]);
            else {$pdo->prepare('UPDATE form_library_versions SET status=?,review_note=?,revision=revision+1 WHERE id=?')->execute([$action==='approve'?'approved':'rejected',trim($note),$id]);if($action==='approve')$pdo->prepare('UPDATE form_library_entries SET approved_version_id=? WHERE id=?')->execute([$id,$row['entry_id']]);}
            $this->event($actor,$id,$action,$cid,['review_note'=>trim($note)]);$pdo->commit();return $this->detail($actor,$id);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function copy(AuthContext $actor,int $id,array $body,string $cid): array
    {
        $row=$this->detail($actor,$id);
        if(!$row['can_use'])throw new ApiException(409,'library_changed','This template is no longer available. Reload the library.');
        $draft=(new ClientFormDraftsService($this->database,new AuditLogger($this->database)))->import($actor,['document'=>['format'=>'wellness-form','format_version'=>1,'name'=>$row['name'],'form_type'=>$row['form_type'],'definition'=>$row['definition']],'owner_practitioner_id'=>$body['owner_practitioner_id']??null,'idempotency_key'=>$body['idempotency_key']??null],$cid);
        $this->event($actor,$id,'copy',$cid,['draft_id'=>$draft['id']]);return $draft;
    }
}
