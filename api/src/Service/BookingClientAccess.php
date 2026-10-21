<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;

/** Shared SQL boundary for booking contacts; the candidate client is aliased as u. */
final class BookingClientAccess
{
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
        $relationship = '';
        if (!$actor->hasPermission('schedule_for_other_practitioners')) {
            $relationship = " AND (EXISTS(SELECT 1 FROM appointments relationship WHERE relationship.clinic_id=u.clinic_id AND relationship.client_id=u.id AND relationship.practitioner_id=booking_practitioner.id)
                OR EXISTS(SELECT 1 FROM audit_logs created WHERE created.clinic_id=u.clinic_id AND created.actor_user_id=booking_practitioner.user_id AND created.action='client.create' AND created.entity_type='client' AND created.entity_id=u.id AND created.outcome='success')";
            if (ClientFormsService::enabled()) {
                $relationship .= " OR EXISTS(SELECT 1 FROM client_form_tasks task WHERE task.clinic_id=u.clinic_id AND task.client_id=u.id AND task.practitioner_id=booking_practitioner.id AND task.status<>'revoked')";
            }
            $relationship .= ')';
        }
        return " AND $activeActor AND $notMerged AND EXISTS(SELECT 1 FROM practitioners booking_practitioner WHERE booking_practitioner.user_id=:booking_practitioner_user AND booking_practitioner.active=1 $relationship)";
    }
}
