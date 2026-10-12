<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\ClinicContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Throwable;

/** Clinic provisioning is explicit; locations always stay inside their own clinic. */
final class ClinicManagementService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit, private readonly Config $config) {}

    private function authorize(AuthContext $actor): void
    {
        if (!$this->config->clinicManagementEnabled) throw new ApiException(503,'clinic_management_unavailable','Clinic management is not enabled.');
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin')) throw new ApiException(403,'forbidden','Super Admin access is required.');
    }

    public function list(AuthContext $actor): array
    {
        $this->authorize($actor);
        $query=$this->database->connection()->prepare("SELECT c.id,c.name,c.status,c.website_url,h.host portal_host,
            (SELECT COUNT(*) FROM locations l WHERE l.clinic_id=c.id) location_count
            FROM clinics c LEFT JOIN clinic_hosts h ON h.clinic_id=c.id
            WHERE c.id=:current OR EXISTS(
              SELECT 1 FROM staff_memberships mine JOIN staff_memberships other ON other.identity_id=mine.identity_id
              JOIN product_identities i ON i.id=mine.identity_id AND i.status='active'
              JOIN users u ON u.id=other.local_user_id AND u.clinic_id=other.clinic_id AND u.status='active'
              JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='super_admin'
              WHERE mine.local_user_id=:actor AND mine.status='active' AND other.status='active' AND other.clinic_id=c.id)
            ORDER BY c.name,c.id");
        $query->execute(['current'=>$actor->clinicId,'actor'=>$actor->userId]);
        $items=$query->fetchAll();
        foreach($items as &$item) {
            $item['current']=(int)$item['id']===$actor->clinicId;
            if (!$item['portal_host']) foreach($this->config->clinicHostMap as $host=>$id) {
                if ($id===(int)$item['id'] && !str_contains($host,':') && !str_starts_with($host,'localhost')) { $item['portal_host']=$host; break; }
            }
        }
        return ['items'=>$items,'current_clinic_id'=>$actor->clinicId];
    }

    public static function input(array $body): array
    {
        foreach (['name','portal_host','website_url'] as $field) if (isset($body[$field]) && !is_string($body[$field]))
            throw new ApiException(422,'validation_error','Enter a text value.',[$field=>'Enter a text value']);
        $name=trim((string)($body['name']??''));
        if ($name==='' || mb_strlen($name)>160) throw new ApiException(422,'validation_error','Enter a clinic name.',['name'=>'Required; maximum 160 characters']);
        $host=strtolower(trim((string)($body['portal_host']??'')));
        try { $host=ClinicContext::normalizeHost($host); }
        catch(ApiException) { throw new ApiException(422,'validation_error','Enter a portal hostname without a scheme or path.',['portal_host'=>'Enter a hostname, for example livinlively.copihue.ca']); }
        if (!str_contains($host,'.') || str_contains($host,':') || filter_var($host,FILTER_VALIDATE_IP)) throw new ApiException(422,'validation_error','Use a public portal hostname.',['portal_host'=>'Use a public hostname without a port']);
        $website=trim((string)($body['website_url']??''));
        $parts=parse_url($website);
        if ($website!=='' && (strlen($website)>2048 || !filter_var($website,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))) throw new ApiException(422,'validation_error','Enter an HTTPS public website URL.',['website_url'=>'Enter an HTTPS URL without credentials, query or fragment']);
        return ['name'=>$name,'portal_host'=>$host,'website_url'=>$website?:null];
    }

    private function hostAvailable(string $host, ?int $clinicId=null): void
    {
        foreach($this->config->clinicHostMap as $mappedHost=>$id) if (ClinicContext::normalizeHost($mappedHost)===$host && $id!==$clinicId)
            throw new ApiException(409,'clinic_host_in_use','This portal host already belongs to another clinic.',['portal_host'=>'Already assigned to another clinic']);
    }

    public function configure(AuthContext $actor,int $id,array $body,string $cid): array
    {
        $this->authorize($actor);
        // Configure only the clinic of the authenticated host. Never move its locations.
        if ($id!==$actor->clinicId) throw new ApiException(404,'clinic_not_found','Open that clinic portal to edit its settings.');
        $input=self::input($body); $this->hostAvailable($input['portal_host'],$id);
        $pdo=$this->database->connection();
        try {
            $pdo->beginTransaction();
            $check=$pdo->prepare('SELECT host FROM clinic_hosts WHERE clinic_id=? FOR UPDATE');$check->execute([$id]);$existing=$check->fetchColumn();
            if ($existing && $existing!==$input['portal_host']) throw new ApiException(409,'clinic_host_immutable','The portal hostname cannot be changed here.');
            $pdo->prepare('UPDATE clinics SET name=?,website_url=? WHERE id=?')->execute([$input['name'],$input['website_url'],$id]);
            if (!$existing) $pdo->prepare('INSERT INTO clinic_hosts(host,clinic_id) VALUES(?,?)')->execute([$input['portal_host'],$id]);
            $this->audit->write($id,$actor,$cid,'clinic.configure','clinic',$id);
            $pdo->commit(); return ['id'=>$id]+$input;
        } catch(Throwable $error) { $this->rollback($error); }
    }

    public function create(AuthContext $actor,array $body,string $cid): array
    {
        $this->authorize($actor); $input=self::input($body); $this->hostAvailable($input['portal_host']);
        foreach (['initial_location_name','timezone'] as $field) if (isset($body[$field]) && !is_string($body[$field]))
            throw new ApiException(422,'validation_error','Enter a text value.',[$field=>'Enter a text value']);
        $location=trim((string)($body['initial_location_name']??$input['name']));
        if ($location==='' || mb_strlen($location)>120) throw new ApiException(422,'validation_error','Enter an initial location name.',['initial_location_name'=>'Required; maximum 120 characters']);
        $timezone=(string)($body['timezone']??'America/Toronto');
        if (!in_array($timezone,\DateTimeZone::listIdentifiers(),true)) throw new ApiException(422,'validation_error','Select a valid timezone.',['timezone'=>'Invalid timezone']);
        $pdo=$this->database->connection();
        try {
            $pdo->beginTransaction();
            // Bootstrap from the acting user's immutable verified workforce identity, never an email match.
            $query=$pdo->prepare("SELECT i.id FROM product_identities i JOIN staff_memberships m ON m.identity_id=i.id WHERE m.local_user_id=? AND m.clinic_id=? AND m.status='active' AND i.status='active' AND i.adapter='entra-workforce' AND i.subject=? FOR UPDATE");
            $query->execute([$actor->userId,$actor->clinicId,$actor->externalObjectId]);$identity=$query->fetchColumn();
            if (!$identity) {
                $query=$pdo->prepare("SELECT tenant_id,provider_subject FROM identity_links WHERE user_id=? AND provider='microsoft' AND tenant_id=? FOR UPDATE");
                $query->execute([$actor->userId,$this->config->entraTenantId]);$legacy=$query->fetch();
                if (!$legacy || $legacy['provider_subject']!==$actor->externalObjectId) throw new ApiException(403,'staff_identity_required','A verified workforce administrator is required to create a clinic.');
                $pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute(['https://login.microsoftonline.com/'.$legacy['tenant_id'].'/v2.0',$legacy['provider_subject']]);
                $identity=(int)$pdo->lastInsertId();
                $check=$pdo->prepare('SELECT status FROM product_identities WHERE id=? FOR UPDATE');$check->execute([$identity]);
                if ($check->fetchColumn()!=='active') throw new ApiException(409,'identity_inactive','The administrator identity is inactive.');
                $pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')")->execute([$identity,$actor->clinicId,$actor->userId]);
            }
            $pdo->prepare('INSERT INTO clinics(name,website_url) VALUES(?,?)')->execute([$input['name'],$input['website_url']]);$clinic=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO clinic_hosts(host,clinic_id) VALUES(?,?)')->execute([$input['portal_host'],$clinic]);
            $pdo->prepare('INSERT INTO locations(clinic_id,name,timezone) VALUES(?,?,?)')->execute([$clinic,$location,$timezone]);
            $pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(?,?,?,'staff','active')")->execute([$clinic,$actor->email,$actor->displayName]);$user=(int)$pdo->lastInsertId();
            $grant=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT ?,id,? FROM roles WHERE code='super_admin'");$grant->execute([$user,$actor->userId]);
            if ($grant->rowCount()!==1) throw new ApiException(500,'role_not_configured','The Super Admin role is not configured.');
            $pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')")->execute([$identity,$clinic,$user]);
            $this->audit->write($clinic,$actor,$cid,'clinic.create','clinic',$clinic);
            $pdo->commit(); return ['id'=>$clinic]+$input;
        } catch(Throwable $error) { $this->rollback($error); }
    }

    private function rollback(Throwable $error): never
    {
        $pdo=$this->database->connection();if($pdo->inTransaction())$pdo->rollBack();
        if ($error instanceof \PDOException && (int)($error->errorInfo[1]??0)===1062) throw new ApiException(409,'clinic_conflict','The clinic or portal host already exists. Refresh and try again.',['portal_host'=>'This host or account is already configured']);
        throw $error;
    }
}
