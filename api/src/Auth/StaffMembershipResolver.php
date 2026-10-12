<?php
declare(strict_types=1);
namespace Wellness\Auth;

use Wellness\Database;
use Wellness\ClinicContext;
use Wellness\Http\ApiException;

/** Resolves an exact, active staff identity and clinic membership. */
final class StaffMembershipResolver
{
    public function __construct(private readonly Database $database) {}

    public function resolve(VerifiedIdentity $identity, ClinicContext $clinic): AuthContext
    {
        // Customer adapters must never grant workforce access.
        if (!in_array($identity->adapter, ['entra-workforce','entra-external-staff'],true)) throw new ApiException(403, 'staff_identity_required', 'A staff identity is required.');
        $query = $this->database->connection()->prepare("SELECT u.id,u.clinic_id,u.email,u.display_name,u.user_type,u.status,
            GROUP_CONCAT(DISTINCT r.code ORDER BY r.code) roles,
            GROUP_CONCAT(DISTINCT p.code ORDER BY p.code) permissions
            FROM product_identities i JOIN staff_memberships m ON m.identity_id=i.id
            JOIN clinics c ON c.id=m.clinic_id AND c.status='active'
            JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id AND u.user_type='staff' AND u.status='active'
            LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
            LEFT JOIN user_permissions up ON up.user_id=u.id LEFT JOIN permissions p ON p.id=up.permission_id
            WHERE i.adapter=:adapter AND i.issuer=:issuer AND i.subject=:subject AND i.status='active'
            AND m.clinic_id=:clinic AND m.status='active'
            GROUP BY u.id,u.clinic_id,u.email,u.display_name,u.user_type,u.status");
        $query->execute(['adapter'=>$identity->adapter,'issuer'=>$identity->issuer,'subject'=>$identity->subject,'clinic'=>$clinic->clinicId]);
        $user = $query->fetch();
        if (!$user) throw new ApiException(403, 'membership_required', 'An active staff membership is required.');
        $databaseRoles = $user['roles'] ? explode(',', $user['roles']) : [];
        $roles = $identity->adapter === 'entra-workforce' ? array_values(array_intersect($databaseRoles, $identity->directoryRoles)) : array_values(array_intersect($databaseRoles,['practitioner']));
        if ($roles === []) throw new ApiException(403, 'role_assignment_mismatch', 'No application role is assigned in both Microsoft Entra and the Wellness Centre.');
        $permissions = $user['permissions'] ? explode(',', $user['permissions']) : [];
        if ($identity->adapter === 'entra-external-staff') $permissions = array_values(array_intersect($permissions,
            ['schedule_for_other_practitioners','add_clients','approve_onsite_service_area']));
        return $clinic->assertActor(new AuthContext((int)$user['id'],(int)$user['clinic_id'],$identity->subject,
            $user['email'],$user['display_name'],$user['user_type'],$roles,$permissions));
    }
}
