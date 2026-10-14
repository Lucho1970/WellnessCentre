<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Auth\ApplicationAdminContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Throwable;

/** Central identity registration; clinical profiles and grants remain clinic scoped. */
final class GlobalPractitionerService
{
    public function __construct(private readonly Database $database, private readonly Config $config) {}

    private function authorize(ApplicationAdminContext $actor): void
    {
        if (!$this->config->applicationAdminEnabled || !$this->config->clinicManagementEnabled) throw new ApiException(503,'application_admin_unavailable','Application administration is not enabled.');
        $q=$this->database->connection()->prepare("SELECT 1 FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active'");
        $q->execute([$actor->id,$actor->identity->issuer,$actor->identity->subject]);
        if (!$q->fetchColumn()) throw new ApiException(403,'application_admin_required','Application administrator access is required.');
    }

    public function list(ApplicationAdminContext $actor): array
    {
        $this->authorize($actor);$pdo=$this->database->connection();
        $items=$pdo->query('SELECT g.id,g.display_name,g.email,i.adapter,i.status identity_status FROM global_practitioners g JOIN product_identities i ON i.id=g.identity_id ORDER BY g.display_name,g.id')->fetchAll();
        $assignments=$pdo->query('SELECT a.global_practitioner_id,a.clinic_id,c.name clinic_name,a.practitioner_id,a.status,a.version FROM global_practitioner_clinics a JOIN clinics c ON c.id=a.clinic_id ORDER BY c.name')->fetchAll();
        foreach($items as &$item) $item['clinics']=array_values(array_filter($assignments,fn($a)=>(int)$a['global_practitioner_id']===(int)$item['id']));
        // No credentials, qualifications, clients or clinical records in the central directory.
        return ['items'=>$items];
    }

    public function candidates(ApplicationAdminContext $actor): array
    {
        $this->authorize($actor);
        return ['items'=>$this->database->connection()->query("SELECT p.id practitioner_id,u.display_name,u.email,c.name clinic_name FROM practitioners p JOIN users u ON u.id=p.user_id JOIN clinics c ON c.id=u.clinic_id LEFT JOIN global_practitioner_clinics a ON a.practitioner_id=p.id WHERE a.practitioner_id IS NULL AND u.user_type='staff' AND u.status='active' AND p.active=1 AND c.status='active' ORDER BY u.display_name,p.id")->fetchAll()];
    }

    public function locations(ApplicationAdminContext $actor,int $clinic): array
    {
        $this->authorize($actor);$q=$this->database->connection()->prepare("SELECT l.id,l.name FROM locations l JOIN clinics c ON c.id=l.clinic_id WHERE c.id=? AND c.status='active' AND l.is_bookable=1 ORDER BY l.name");$q->execute([$clinic]);return ['items'=>$q->fetchAll()];
    }

    public function create(ApplicationAdminContext $actor,array $body,string $cid): array
    {
        $this->authorize($actor);
        $name=self::text($body,'display_name',150);$email=self::text($body,'email',190);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new ApiException(422,'validation_error','Enter a valid email address.',['email'=>'Invalid email']);
        $tenant=strtolower(self::text($body,'tenant_id',64));$subject=strtolower(self::text($body,'object_id',64));
        $guid='/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';
        if($tenant!==strtolower($this->config->entraTenantId)||!preg_match($guid,$tenant))throw new ApiException(422,'validation_error','Use the configured workforce tenant.',['tenant_id'=>'Use the configured workforce tenant']);
        if(!preg_match($guid,$subject))throw new ApiException(422,'validation_error','Enter the immutable Microsoft Object ID.',['object_id'=>'Invalid Object ID']);
        $pdo=$this->database->connection();
        return $this->transaction(function()use($pdo,$actor,$name,$email,$tenant,$subject,$cid){
            $legacy=$pdo->prepare("SELECT 1 FROM identity_links WHERE provider='microsoft' AND tenant_id=? AND provider_subject=?");$legacy->execute([$tenant,$subject]);
            if($legacy->fetchColumn())throw new ApiException(409,'import_required','This practitioner already exists. Use Import existing practitioner.');
            $pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute(['https://login.microsoftonline.com/'.$tenant.'/v2.0',$subject]);$identity=(int)$pdo->lastInsertId();
            $q=$pdo->prepare("SELECT status FROM product_identities WHERE id=? FOR UPDATE");$q->execute([$identity]);if($q->fetchColumn()!=='active')throw new ApiException(409,'identity_inactive','The identity is inactive.');
            $q=$pdo->prepare('SELECT 1 FROM staff_memberships WHERE identity_id=?');$q->execute([$identity]);if($q->fetchColumn())throw new ApiException(409,'import_required','This identity has clinic access. Import its existing practitioner profile.');
            $pdo->prepare('INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,?,?)')->execute([$identity,$name,strtolower($email)]);$id=(int)$pdo->lastInsertId();
            $this->event($actor,$id,null,'practitioner.create',$cid);return ['id'=>$id];
        });
    }

    public function import(ApplicationAdminContext $actor,int $practitioner,string $cid): array
    {
        $this->authorize($actor);$pdo=$this->database->connection();
        return $this->transaction(function()use($pdo,$actor,$practitioner,$cid){
            $q=$pdo->prepare("SELECT p.id,p.user_id,u.clinic_id,u.display_name,u.email FROM practitioners p JOIN users u ON u.id=p.user_id JOIN clinics c ON c.id=u.clinic_id WHERE p.id=? AND p.active=1 AND u.status='active' AND u.user_type='staff' AND c.status='active' FOR UPDATE");$q->execute([$practitioner]);$row=$q->fetch();if(!$row)throw new ApiException(404,'practitioner_not_found','An active practitioner was not found.');
            $q=$pdo->prepare('SELECT m.identity_id,m.clinic_id,m.status,i.status identity_status,i.adapter FROM staff_memberships m JOIN product_identities i ON i.id=m.identity_id WHERE m.local_user_id=? FOR UPDATE');$q->execute([$row['user_id']]);$membership=$q->fetch();
            if(!$membership){
                $q=$pdo->prepare("SELECT tenant_id,provider_subject FROM identity_links WHERE user_id=? AND provider='microsoft'");$q->execute([$row['user_id']]);$links=$q->fetchAll();
                if(count($links)!==1 || $links[0]['tenant_id']!==$this->config->entraTenantId || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$links[0]['provider_subject']))throw new ApiException(409,'identity_review_required','A trusted identity link is required before import.');
                $pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute(['https://login.microsoftonline.com/'.$links[0]['tenant_id'].'/v2.0',$links[0]['provider_subject']]);$identity=(int)$pdo->lastInsertId();
                $q=$pdo->prepare('SELECT status FROM product_identities WHERE id=? FOR UPDATE');$q->execute([$identity]);if($q->fetchColumn()!=='active')throw new ApiException(409,'identity_inactive','The identity is inactive.');
                $pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')")->execute([$identity,$row['clinic_id'],$row['user_id']]);
            }else{
                if((int)$membership['clinic_id']!==(int)$row['clinic_id']||$membership['status']!=='active'||$membership['identity_status']!=='active'||!in_array($membership['adapter'],['entra-workforce','entra-external-staff'],true))throw new ApiException(409,'identity_review_required','The existing identity or membership needs review.');
                $identity=(int)$membership['identity_id'];
            }
            $pdo->prepare('INSERT INTO global_practitioners(identity_id,display_name,email) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$identity,$row['display_name'],$row['email']]);$id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,?,?)')->execute([$id,$row['clinic_id'],$practitioner]);
            // Preserve the source profile, role grants, qualifications, and appointment IDs.
            $this->event($actor,$id,(int)$row['clinic_id'],'practitioner.import',$cid);return ['id'=>$id];
        });
    }

    public function assign(ApplicationAdminContext $actor,int $id,array $body,string $cid): array
    {
        $this->authorize($actor);$clinic=self::positive($body,'clinic_id');$location=self::positive($body,'location_id');$pdo=$this->database->connection();
        return $this->transaction(function()use($pdo,$actor,$id,$clinic,$location,$body,$cid){
            $q=$pdo->prepare("SELECT l.id FROM locations l JOIN clinics c ON c.id=l.clinic_id WHERE l.id=? AND c.id=? AND c.status='active' AND l.is_bookable=1 FOR UPDATE");$q->execute([$location,$clinic]);if(!$q->fetchColumn())throw new ApiException(422,'validation_error','Choose an active location in the selected clinic.',['location_id'=>'Choose a location in this clinic']);
            $g=$this->global($id);
            $q=$pdo->prepare('SELECT a.*,p.user_id,p.active,u.status user_status,m.status membership_status,m.identity_id FROM global_practitioner_clinics a JOIN practitioners p ON p.id=a.practitioner_id JOIN users u ON u.id=p.user_id JOIN staff_memberships m ON m.local_user_id=u.id AND m.clinic_id=a.clinic_id WHERE a.global_practitioner_id=? AND a.clinic_id=? FOR UPDATE');$q->execute([$id,$clinic]);$existing=$q->fetch();
            if($existing){
                if($existing['status']!=='removed')throw new ApiException(409,'assignment_exists','This clinic assignment already exists.');
                if(self::positive($body,'version')!==(int)$existing['version'])throw new ApiException(409,'assignment_changed','Refresh the clinic assignment and try again.');
                if($existing['user_status']!=='active'||$existing['active']||(int)$existing['identity_id']!==(int)$g['identity_id']||!in_array($existing['membership_status'],['active','revoked'],true))throw new ApiException(409,'identity_review_required','The existing identity or membership needs review.');
                $grant=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,location_id) SELECT ?,id,? FROM roles WHERE code='practitioner'");$grant->execute([$existing['user_id'],$location]);if($grant->rowCount()!==1)throw new ApiException(500,'role_not_configured','The practitioner role is not configured.');
                $pdo->prepare("UPDATE staff_memberships SET status='active',version=version+1 WHERE local_user_id=?")->execute([$existing['user_id']]);
                $pdo->prepare('UPDATE practitioners SET active=1 WHERE id=?')->execute([$existing['practitioner_id']]);
                $pdo->prepare('UPDATE practitioner_locations SET active=0 WHERE practitioner_id=?')->execute([$existing['practitioner_id']]);
                $pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(?,?,1) ON DUPLICATE KEY UPDATE active=1')->execute([$existing['practitioner_id'],$location]);
                $pdo->prepare("UPDATE global_practitioner_clinics SET status='active',version=version+1 WHERE global_practitioner_id=? AND clinic_id=?")->execute([$id,$clinic]);
                $this->event($actor,$id,$clinic,'practitioner.restore',$cid);return ['practitioner_id'=>(int)$existing['practitioner_id'],'user_id'=>(int)$existing['user_id']];
            }
            $q=$pdo->prepare('SELECT 1 FROM staff_memberships WHERE identity_id=? AND clinic_id=?');$q->execute([$g['identity_id'],$clinic]);if($q->fetchColumn())throw new ApiException(409,'import_required','This identity already has access to this clinic. Import its existing practitioner profile instead.');
            $pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(?,?,?,'staff','active')")->execute([$clinic,$g['email'],$g['display_name']]);$user=(int)$pdo->lastInsertId();
            $grant=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,location_id) SELECT ?,id,? FROM roles WHERE code='practitioner'");$grant->execute([$user,$location]);if($grant->rowCount()!==1)throw new ApiException(500,'role_not_configured','The practitioner role is not configured.');
            $pdo->prepare('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(?,1)')->execute([$user]);
            // No discipline or credential is copied from another clinic or the private global library.
            $pdo->prepare("INSERT INTO practitioners(user_id,discipline,booking_mode) VALUES(?,'Practitioner','practitioner_managed')")->execute([$user]);$local=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(?,?,1)')->execute([$local,$location]);
            $pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')")->execute([$g['identity_id'],$clinic,$user]);
            $pdo->prepare('INSERT INTO global_practitioner_clinics(global_practitioner_id,clinic_id,practitioner_id) VALUES(?,?,?)')->execute([$id,$clinic,$local]);
            $this->event($actor,$id,$clinic,'practitioner.assign',$cid);return ['practitioner_id'=>$local,'user_id'=>$user];
        });
    }

    public function remove(ApplicationAdminContext $actor,int $id,int $clinic,array $body,string $cid): array
    {
        $this->authorize($actor);$version=self::positive($body,'version');$pdo=$this->database->connection();
        return $this->transaction(function()use($pdo,$actor,$id,$clinic,$version,$cid){
            $this->global($id);$q=$pdo->prepare("SELECT a.practitioner_id,p.user_id,a.version,a.status FROM global_practitioner_clinics a JOIN practitioners p ON p.id=a.practitioner_id WHERE a.global_practitioner_id=? AND a.clinic_id=? FOR UPDATE");$q->execute([$id,$clinic]);$a=$q->fetch();
            if(!$a)throw new ApiException(404,'assignment_not_found','Clinic assignment not found.');if((int)$a['version']!==$version||$a['status']!=='active')throw new ApiException(409,'assignment_changed','Refresh the clinic assignment and try again.');
            $q=$pdo->prepare("SELECT 1 FROM appointments WHERE practitioner_id=? AND ends_at>UTC_TIMESTAMP() AND status NOT IN('canceled_by_client','canceled_by_clinic','completed','invoiced','paid','no_show') LIMIT 1");$q->execute([$a['practitioner_id']]);if($q->fetchColumn())throw new ApiException(409,'appointments_require_review','Reassign or cancel future appointments before removing this clinic assignment.');
            $q=$pdo->prepare("SELECT 1 FROM recurring_series WHERE practitioner_id=? AND status='active' LIMIT 1");$q->execute([$a['practitioner_id']]);if($q->fetchColumn())throw new ApiException(409,'series_require_review','End active recurring series before removing this clinic assignment.');
            $pdo->prepare("DELETE ur FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code='practitioner'")->execute([$a['user_id']]);
            $q=$pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE user_id=?');$q->execute([$a['user_id']]);if((int)$q->fetchColumn()===0)$pdo->prepare("UPDATE staff_memberships SET status='revoked',version=version+1 WHERE local_user_id=?")->execute([$a['user_id']]);
            $pdo->prepare("UPDATE practitioners SET active=0,discipline='Practitioner',credentials=NULL WHERE id=?")->execute([$a['practitioner_id']]);
            $pdo->prepare("UPDATE practitioner_qualifications q JOIN practitioner_credential_shares s ON s.qualification_id=q.id SET q.status='revoked',s.shared=0 WHERE q.practitioner_id=? AND s.clinic_id=?")->execute([$a['practitioner_id'],$clinic]);
            $pdo->prepare("UPDATE public_team_profiles SET published=0 WHERE user_id=? AND clinic_id=?")->execute([$a['user_id'],$clinic]);
            $pdo->prepare("UPDATE global_practitioner_clinics SET status='removed',version=version+1 WHERE global_practitioner_id=? AND clinic_id=?")->execute([$id,$clinic]);
            $this->event($actor,$id,$clinic,'practitioner.remove',$cid);return ['status'=>'removed'];
        });
    }

    private function global(int $id): array
    {
        $q=$this->database->connection()->prepare('SELECT g.*,i.status identity_status FROM global_practitioners g JOIN product_identities i ON i.id=g.identity_id WHERE g.id=? FOR UPDATE');$q->execute([$id]);$g=$q->fetch();if(!$g)throw new ApiException(404,'practitioner_not_found','Global practitioner not found.');if($g['identity_status']!=='active')throw new ApiException(409,'identity_inactive','The identity is inactive.');return $g;
    }

    private function event(ApplicationAdminContext $actor,int $id,?int $clinic,string $action,string $cid): void
    {
        $this->database->connection()->prepare('INSERT INTO global_practitioner_events(global_practitioner_id,application_administrator_id,clinic_id,action,correlation_id) VALUES(?,?,?,?,?)')->execute([$id,$actor->id,$clinic,$action,$cid]);
    }

    private function transaction(callable $work): array
    {
        $pdo=$this->database->connection();$pdo->beginTransaction();try{$result=$work();$pdo->commit();return $result;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof \PDOException && (int)($e->errorInfo[1]??0)===1062)throw new ApiException(409,'practitioner_conflict','This practitioner, clinic assignment, or clinic email already exists. Refresh and review the existing record.');throw $e;}
    }

    public static function positive(array $body,string $field): int
    {
        $value=filter_var($body[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($value===false)throw new ApiException(422,'validation_error','Choose a valid record. ',[$field=>'Required positive ID']);return $value;
    }

    public static function text(array $body,string $field,int $max): string
    {
        $value=is_string($body[$field]??null)?trim($body[$field]):'';if($value===''||mb_strlen($value)>$max)throw new ApiException(422,'validation_error','Enter a valid text value.',[$field=>"Required; maximum $max characters"]);return $value;
    }
}
