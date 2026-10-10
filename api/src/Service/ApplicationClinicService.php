<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Auth\ApplicationAdminContext;
use Wellness\ClinicContext;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;
use Throwable;

/** Clinic provisioning is explicit; locations always stay inside their own clinic. */
final class ApplicationClinicService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit, private readonly Config $config) {}

    private function authorize(ApplicationAdminContext $actor): void
    {
        if (!$this->config->applicationAdminEnabled || !$this->config->clinicManagementEnabled) throw new ApiException(503,'application_admin_unavailable','Application administration is not enabled.');
        $query=$this->database->connection()->prepare("SELECT 1 FROM application_administrators WHERE id=? AND issuer=? AND subject=? AND status='active'");
        $query->execute([$actor->id,$actor->identity->issuer,$actor->identity->subject]);
        if (!$query->fetchColumn()) throw new ApiException(403,'application_admin_required','Application administrator access is required.');
    }

    public function list(ApplicationAdminContext $actor): array
    {
        $this->authorize($actor);
        $items=$this->database->connection()->query("SELECT c.id,c.name,c.status,c.website_url,h.host portal_host,(SELECT COUNT(*) FROM locations l WHERE l.clinic_id=c.id) location_count FROM clinics c LEFT JOIN clinic_hosts h ON h.clinic_id=c.id ORDER BY c.name,c.id")->fetchAll();
        return ['items'=>$items];
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
        if ($host===ClinicContext::normalizeHost($this->config->applicationAdminHost)) throw new ApiException(409,'clinic_host_in_use','The application administration hostname cannot belong to a clinic.',['portal_host'=>'Reserved for application administration']);
        foreach($this->config->clinicHostMap as $mappedHost=>$id) if (ClinicContext::normalizeHost($mappedHost)===$host && $id!==$clinicId)
            throw new ApiException(409,'clinic_host_in_use','This portal host already belongs to another clinic.',['portal_host'=>'Already assigned to another clinic']);
    }

    public function configure(ApplicationAdminContext $actor,int $id,array $body,string $cid): array
    {
        $this->authorize($actor);
        // Central registration edits never move clinic records or grant clinical access.
        $input=self::input($body); $this->hostAvailable($input['portal_host'],$id);
        $pdo=$this->database->connection();
        try {
            $pdo->beginTransaction();
            $exists=$pdo->prepare('SELECT id FROM clinics WHERE id=? FOR UPDATE');$exists->execute([$id]);
            if (!$exists->fetchColumn()) throw new ApiException(404,'clinic_not_found','Clinic not found.');
            $check=$pdo->prepare('SELECT host FROM clinic_hosts WHERE clinic_id=? FOR UPDATE');$check->execute([$id]);$existing=$check->fetchColumn();
            if ($existing && $existing!==$input['portal_host']) $pdo->prepare('UPDATE clinic_hosts SET host=? WHERE clinic_id=?')->execute([$input['portal_host'],$id]);
            $pdo->prepare('UPDATE clinics SET name=?,website_url=? WHERE id=?')->execute([$input['name'],$input['website_url'],$id]);
            if (!$existing) $pdo->prepare('INSERT INTO clinic_hosts(host,clinic_id) VALUES(?,?)')->execute([$input['portal_host'],$id]);
            $this->audit->write($id,null,$cid,'application.clinic.configure','clinic',$id,'success',['application_administrator_id'=>$actor->id]);
            $pdo->commit(); return ['id'=>$id]+$input;
        } catch(Throwable $error) { $this->rollback($error); }
    }

    public function create(ApplicationAdminContext $actor,array $body,string $cid): array
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
            // Provision a membership only for the NEW clinic, from the verified central identity.
            $pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute([$actor->identity->issuer,$actor->identity->subject]);
            $identity=(int)$pdo->lastInsertId();
            $check=$pdo->prepare('SELECT status FROM product_identities WHERE id=? FOR UPDATE');$check->execute([$identity]);
            if ($check->fetchColumn()!=='active') throw new ApiException(409,'identity_inactive','The administrator identity is inactive.');
            $pdo->prepare('INSERT INTO clinics(name,website_url) VALUES(?,?)')->execute([$input['name'],$input['website_url']]);$clinic=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO clinic_hosts(host,clinic_id) VALUES(?,?)')->execute([$input['portal_host'],$clinic]);
            $pdo->prepare('INSERT INTO locations(clinic_id,name,timezone) VALUES(?,?,?)')->execute([$clinic,$location,$timezone]);
            $pdo->prepare("INSERT INTO users(clinic_id,email,display_name,user_type,status) VALUES(?,?,?,'staff','active')")->execute([$clinic,$actor->email,$actor->displayName]);$user=(int)$pdo->lastInsertId();
            $grant=$pdo->prepare("INSERT INTO user_roles(user_id,role_id,assigned_by) SELECT ?,id,? FROM roles WHERE code='super_admin'");$grant->execute([$user,null]);
            if ($grant->rowCount()!==1) throw new ApiException(500,'role_not_configured','The Super Admin role is not configured.');
            $pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')")->execute([$identity,$clinic,$user]);
            $this->audit->write($clinic,null,$cid,'application.clinic.create','clinic',$clinic,'success',['application_administrator_id'=>$actor->id]);
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
