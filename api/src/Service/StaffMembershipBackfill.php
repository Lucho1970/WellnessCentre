<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use RuntimeException;

final class StaffMembershipBackfill
{
    public function __construct(private readonly PDO $pdo, private readonly string $tenantId) {}

    public static function candidate(array $rows, string $tenantId): array
    {
        if (count($rows) !== 1) throw new RuntimeException('Selected staff account must have exactly one Microsoft link.');
        $row=$rows[0];
        if ($row['user_type']!=='staff' || $row['status']!=='active' || $row['clinic_status']!=='active') throw new RuntimeException('Selected staff account and clinic must be active.');
        $guid='/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD';
        if ($tenantId==='' || $row['tenant_id']!==$tenantId || !preg_match($guid,$tenantId)
            || !is_string($row['provider_subject']) || !preg_match($guid,$row['provider_subject'])) throw new RuntimeException('Unrecognized legacy tenant or subject; review before importing.');
        return ['user_id'=>(int)$row['user_id'],'clinic_id'=>(int)$row['clinic_id'],
            'issuer'=>'https://login.microsoftonline.com/'.$tenantId.'/v2.0','subject'=>$row['provider_subject']];
    }

    /** No email lookup, grant copying, status reactivation, or legacy-link mutation. */
    public function run(array $userIds, bool $apply=false, ?array $expectedBindings=null): array
    {
        if ($userIds===[] || count($userIds)>20 || count(array_unique($userIds))!==count($userIds)) throw new RuntimeException('Select 1–20 distinct local staff user IDs.');
        foreach($userIds as $id) if(!is_int($id)||$id<1)throw new RuntimeException('Invalid local user ID.');
        $this->pdo->beginTransaction();
        try {
            $report=[];
            foreach($userIds as $id){
                $q=$this->pdo->prepare("SELECT u.id user_id,u.clinic_id,u.user_type,u.status,c.status clinic_status,i.tenant_id,i.provider_subject
                    FROM users u JOIN clinics c ON c.id=u.clinic_id LEFT JOIN identity_links i ON i.user_id=u.id AND i.provider='microsoft'
                    WHERE u.id=:id".($apply?' FOR UPDATE':''));
                $q->execute(['id'=>$id]);
                $candidate=self::candidate($q->fetchAll(),$this->tenantId);
                $binding=hash('sha256',json_encode($candidate,JSON_THROW_ON_ERROR));
                if ($expectedBindings !== null && ($expectedBindings[$id] ?? null) !== $binding) throw new RuntimeException('Source identity binding changed after review; run a new dry-run.');
                $q=$this->pdo->prepare("SELECT id,status FROM product_identities WHERE adapter='entra-workforce' AND issuer=:issuer AND subject=:subject".($apply?' FOR UPDATE':''));
                $q->execute(['issuer'=>$candidate['issuer'],'subject'=>$candidate['subject']]);$identity=$q->fetch();
                if($identity && $identity['status']!=='active')throw new RuntimeException('Existing identity is inactive; import refuses to reactivate it.');
                $q=$this->pdo->prepare('SELECT identity_id,clinic_id,local_user_id,status FROM staff_memberships WHERE local_user_id=:user OR (identity_id=:identity AND clinic_id=:clinic)'.($apply?' FOR UPDATE':''));
                $q->execute(['user'=>$id,'identity'=>$identity?(int)$identity['id']:0,'clinic'=>$candidate['clinic_id']]);$members=$q->fetchAll();
                if($members!==[]){
                    if(count($members)!==1 || !$identity || (int)$members[0]['identity_id']!==(int)$identity['id'] || (int)$members[0]['local_user_id']!==$id || (int)$members[0]['clinic_id']!==$candidate['clinic_id'] || $members[0]['status']!=='active')throw new RuntimeException('Existing membership conflicts or is not active; review required.');
                    $report[]=['user_id'=>$id,'clinic_id'=>$candidate['clinic_id'],'action'=>'already_imported','binding_hash'=>$binding];continue;
                }
                if($apply){
                    if(!$identity){$q=$this->pdo->prepare("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-workforce',:issuer,:subject)");$q->execute(['issuer'=>$candidate['issuer'],'subject'=>$candidate['subject']]);$identity=['id'=>(int)$this->pdo->lastInsertId()];}
                    $q=$this->pdo->prepare("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(:identity,:clinic,:user,'active')");
                    $q->execute(['identity'=>$identity['id'],'clinic'=>$candidate['clinic_id'],'user'=>$id]);
                }
                $report[]=['user_id'=>$id,'clinic_id'=>$candidate['clinic_id'],'action'=>$apply?'imported':'would_import','binding_hash'=>$binding];
            }
            if($apply)$this->pdo->commit();else $this->pdo->rollBack();
            return $report;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
