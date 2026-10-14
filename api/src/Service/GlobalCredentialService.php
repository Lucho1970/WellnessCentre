<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;
use Throwable;

/** Only the practitioner owns the library and selects what each clinic receives. */
final class GlobalCredentialService
{
    public function __construct(private readonly Database $database,private readonly AuditLogger $audit) {}

    private function owner(AuthContext $actor): array
    {
        if($actor->userType!=='staff'||!$actor->hasAnyRole('practitioner'))throw new ApiException(403,'practitioner_required','Practitioner access is required.');
        $q=$this->database->connection()->prepare("SELECT g.id,a.practitioner_id FROM global_practitioners g JOIN global_practitioner_clinics a ON a.global_practitioner_id=g.id JOIN practitioners p ON p.id=a.practitioner_id JOIN users u ON u.id=p.user_id JOIN staff_memberships m ON m.local_user_id=u.id AND m.identity_id=g.identity_id AND m.clinic_id=a.clinic_id JOIN product_identities i ON i.id=g.identity_id WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND p.active=1 AND a.status='active' AND m.status='active' AND i.status='active' FOR UPDATE");
        $q->execute([$actor->userId,$actor->clinicId]);$row=$q->fetch();if(!$row)throw new ApiException(409,'global_registration_required','Ask Application Administration to import your practitioner profile into the global directory.');return $row;
    }

    public function library(AuthContext $actor): array
    {
        $owner=$this->owner($actor);$q=$this->database->connection()->prepare('SELECT q.id,q.name,q.issuer,q.issued_on,q.expires_on,COALESCE(s.shared,0) shared_here FROM global_practitioner_credentials q LEFT JOIN practitioner_credential_shares s ON s.credential_id=q.id AND s.clinic_id=? WHERE q.global_practitioner_id=? ORDER BY q.name,q.id');$q->execute([$actor->clinicId,$owner['id']]);
        return ['items'=>$q->fetchAll(),'clinic_id'=>$actor->clinicId];
    }

    public function create(AuthContext $actor,array $body,string $cid): array
    {
        $name=GlobalPractitionerService::text($body,'name',150);$issuer=GlobalPractitionerService::text($body,'issuer',150);$issued=self::date($body['issued_on']??null);$expires=self::date($body['expires_on']??null,true);
        if($expires!==null&&$expires<$issued)throw new ApiException(422,'validation_error','The expiry date cannot precede the issue date.',['expires_on'=>'Must follow the issue date']);
        return $this->transaction(function()use($actor,$name,$issuer,$issued,$expires,$cid){$owner=$this->owner($actor);$pdo=$this->database->connection();$pdo->prepare('INSERT INTO global_practitioner_credentials(global_practitioner_id,name,issuer,issued_on,expires_on) VALUES(?,?,?,?,?)')->execute([$owner['id'],$name,$issuer,$issued,$expires]);$id=(int)$pdo->lastInsertId();$this->event($actor,(int)$owner['id'],'credential.create',$cid);return ['id'=>$id];});
    }

    public function share(AuthContext $actor,int $credential,array $body,string $cid): array
    {
        if(!is_bool($body['shared']??null))throw new ApiException(422,'validation_error','Choose whether to share this credential.');$shared=$body['shared'];
        // The hostname/current actor fixes the target clinic. A caller cannot choose another clinic or owner.
        return $this->transaction(function()use($actor,$credential,$shared,$cid){
            $owner=$this->owner($actor);$pdo=$this->database->connection();
            $q=$pdo->prepare('SELECT * FROM global_practitioner_credentials WHERE id=? AND global_practitioner_id=? FOR UPDATE');$q->execute([$credential,$owner['id']]);$record=$q->fetch();if(!$record)throw new ApiException(404,'credential_not_found','Credential not found.');
            $q=$pdo->prepare('SELECT qualification_id,shared FROM practitioner_credential_shares WHERE credential_id=? AND clinic_id=? FOR UPDATE');$q->execute([$credential,$actor->clinicId]);$existing=$q->fetch();
            if($existing && (bool)$existing['shared']===$shared)return ['shared'=>$shared];
            if($shared){
                // Sharing submits a clinic review; it never grants automatic verification.
                $pdo->prepare('INSERT INTO qualification_types(clinic_id,name,requires_expiry) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$actor->clinicId,$record['name'],$record['expires_on']!==null?1:0]);$type=(int)$pdo->lastInsertId();
                $q=$pdo->prepare('SELECT active,requires_expiry FROM qualification_types WHERE id=?');$q->execute([$type]);$policy=$q->fetch();if(!$policy['active']||($policy['requires_expiry']&&$record['expires_on']===null))throw new ApiException(422,'qualification_policy','This clinic requires an active qualification type and its required expiry date.');
                if($existing){$pdo->prepare("UPDATE practitioner_qualifications SET status='pending',submitted_by=?,reviewed_by=NULL,reviewed_at=NULL,review_note=NULL WHERE id=?")->execute([$actor->userId,$existing['qualification_id']]);$local=(int)$existing['qualification_id'];}
                else{$pdo->prepare("INSERT INTO practitioner_qualifications(clinic_id,practitioner_id,qualification_type_id,issuer,issued_on,expires_on,submitted_by) VALUES(?,?,?,?,?,?,?)")->execute([$actor->clinicId,$owner['practitioner_id'],$type,$record['issuer'],$record['issued_on'],$record['expires_on'],$actor->userId]);$local=(int)$pdo->lastInsertId();}
                $pdo->prepare('INSERT INTO practitioner_credential_shares(credential_id,clinic_id,qualification_id,shared) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE shared=1')->execute([$credential,$actor->clinicId,$local]);
            }elseif($existing){$pdo->prepare('UPDATE practitioner_credential_shares SET shared=0 WHERE credential_id=? AND clinic_id=?')->execute([$credential,$actor->clinicId]);$pdo->prepare("UPDATE practitioner_qualifications SET status='revoked' WHERE id=?")->execute([$existing['qualification_id']]);}
            $q=$pdo->prepare('SELECT q.name FROM global_practitioner_credentials q JOIN practitioner_credential_shares s ON s.credential_id=q.id WHERE q.global_practitioner_id=? AND s.clinic_id=? AND s.shared=1 ORDER BY q.name,q.id');$q->execute([$owner['id'],$actor->clinicId]);$names=$q->fetchAll(\PDO::FETCH_COLUMN);
            $title=implode(', ',$names);$discipline=mb_strlen($title)<=100?$title:mb_substr($title,0,97).'…';
            $pdo->prepare('UPDATE practitioners SET discipline=?,credentials=? WHERE id=?')->execute([$discipline?:'Practitioner',$title!==''?mb_substr($title,0,500):null,$owner['practitioner_id']]);
            $pdo->prepare('UPDATE public_team_profiles SET public_title=?,public_title_fr=NULL WHERE user_id=? AND clinic_id=?')->execute([mb_substr($title?:'Practitioner',0,150),$actor->userId,$actor->clinicId]);
            $this->audit->write($actor->clinicId,$actor,$cid,$shared?'credential.share':'credential.withdraw','global_credential',$credential);
            $this->event($actor,(int)$owner['id'],$shared?'credential.share':'credential.withdraw',$cid);return ['shared'=>$shared];
        });
    }

    private function event(AuthContext $actor,int $owner,string $action,string $cid): void
    {
        $this->database->connection()->prepare('INSERT INTO global_practitioner_events(global_practitioner_id,actor_user_id,clinic_id,action,correlation_id) VALUES(?,?,?,?,?)')->execute([$owner,$actor->userId,$actor->clinicId,$action,$cid]);
    }

    private function transaction(callable $work): array
    {
        $pdo=$this->database->connection();$pdo->beginTransaction();try{$result=$work();$pdo->commit();return $result;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function date(mixed $value,bool $optional=false): ?string
    {
        if($optional&&($value===null||$value===''))return null;$date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;if(!$date||$date->format('Y-m-d')!==$value)throw new ApiException(422,'validation_error','Enter a valid date.');return $value;
    }
}
