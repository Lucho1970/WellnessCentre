<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;

/** Shared SQL boundary for booking contacts; the candidate client is aliased as u. */
final class BookingClientAccess
{
    /** Current practitioner relationship for appointment reads and writes; callers supply a fixed SQL client expression. */
    public static function careCondition(AuthContext $actor, array &$params, string $clientExpression): string
    {
        if (!in_array($clientExpression, ['a.client_id', 'u.id'], true)) throw new \LogicException('Unsupported client expression.');
        if ($actor->userType!=='staff' || $actor->hasAnyRole('super_admin','clinic_admin','reception') || !$actor->hasAnyRole('practitioner')) return '';
        $params['care_actor']=$actor->userId;
        $params['care_clinic']=$actor->clinicId;
        return " AND EXISTS(SELECT 1 FROM practitioners care_practitioner
            JOIN users care_staff ON care_staff.id=care_practitioner.user_id AND care_staff.clinic_id=:care_clinic AND care_staff.user_type='staff' AND care_staff.status='active'
            JOIN client_care_assignments care ON care.clinic_id=care_staff.clinic_id AND care.client_id={$clientExpression}
                AND care.practitioner_id=care_practitioner.id AND care.active=1
            WHERE care_practitioner.user_id=:care_actor AND care_practitioner.active=1
                AND EXISTS(SELECT 1 FROM user_roles care_user_role JOIN roles care_role ON care_role.id=care_user_role.role_id AND care_role.code='practitioner'
                    WHERE care_user_role.user_id=care_staff.id))";
    }

    public static function condition(AuthContext $actor, array &$params): string
    {
        BookingService::authorizeBookingClientAddress($actor);
        $params['booking_actor'] = $actor->userId;
        $activeActor = "EXISTS(SELECT 1 FROM users booking_actor WHERE booking_actor.id=:booking_actor AND booking_actor.clinic_id=u.clinic_id AND booking_actor.user_type='staff' AND booking_actor.status='active')";
        $activeActor .= ConsentAccess::condition($actor,$params);
        $notMerged = 'NOT EXISTS(SELECT 1 FROM client_merge_records merged WHERE merged.duplicate_client_id=u.id)';
        if ($actor->hasAnyRole('super_admin', 'clinic_admin', 'reception')) {
            return " AND $activeActor AND $notMerged";
        }
        $params['booking_practitioner_user'] = $actor->userId;
        return " AND $activeActor AND $notMerged AND EXISTS(SELECT 1 FROM practitioners booking_practitioner
            JOIN client_care_assignments care ON care.clinic_id=u.clinic_id AND care.client_id=u.id
                AND care.practitioner_id=booking_practitioner.id AND care.active=1
            WHERE booking_practitioner.user_id=:booking_practitioner_user AND booking_practitioner.active=1)";
    }
}
