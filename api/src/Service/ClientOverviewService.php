<?php
declare(strict_types=1);

namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Config;
use Wellness\Http\ApiException;

/** Read-only administration projections; never adds a client or practitioner grant. */
final class ClientOverviewService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit, private readonly ?Config $config = null) {}

    private function client(AuthContext $actor, int $id): array
    {
        ClientService::authorize($actor);
        $query = $this->database->connection()->prepare("SELECT u.id,u.display_name,u.status FROM users u
            WHERE u.id=:client AND u.clinic_id=:clinic AND u.user_type='client'
            AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=u.id)");
        $query->execute(['client'=>$id, 'clinic'=>$actor->clinicId]);
        $client = $query->fetch();
        if (!$client) throw new ApiException(404, 'client_not_found', 'Client not found.');
        return $client;
    }

    public static function page(array $query): int
    {
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) throw new ApiException(422, 'validation_error', 'Invalid page.');
        return $page;
    }

    public static function view(array $query): string
    {
        $view = $query['view'] ?? 'all';
        if (!is_string($view) || !in_array($view, ['all','upcoming','past','canceled'], true)) {
            throw new ApiException(422, 'validation_error', 'Invalid appointment view.');
        }
        return $view;
    }

    public function appointments(AuthContext $actor, int $id, array $query, string $correlationId): array
    {
        ClientService::authorize($actor);
        $page = self::page($query);
        $view = self::view($query);
        $client = $this->client($actor, $id);
        $offset = ($page - 1) * 25;
        $upcoming = "a.ends_at>=UTC_TIMESTAMP() AND a.status IN ('requested','confirmed','rescheduled')";
        $canceled = "a.status IN ('canceled_by_client','canceled_by_clinic')";
        $condition = match ($view) {
            'upcoming' => $upcoming,
            'past' => 'a.ends_at<UTC_TIMESTAMP()',
            'canceled' => $canceled,
            default => '1=1',
        };
        $sql = "SELECT a.id,a.client_id,a.practitioner_id,a.starts_at,a.ends_at,a.status,a.delivery_mode,
                a.base_price_cents,a.mobile_fee_cents,a.cancellation_fee_cents,a.currency,a.created_at,a.source,
                s.name service_name,s.name_fr service_name_fr,pu.display_name practitioner_name,
                l.name location_name,l.timezone,r.name room_name
            FROM appointments a
            JOIN users client ON client.id=a.client_id AND client.clinic_id=a.clinic_id AND client.user_type='client'
            JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
            JOIN practitioners p ON p.id=a.practitioner_id
            JOIN users pu ON pu.id=p.user_id AND pu.clinic_id=a.clinic_id
            JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
            LEFT JOIN rooms r ON r.id=a.room_id AND r.location_id=a.location_id
            WHERE a.clinic_id=:clinic AND a.client_id=:client AND {$condition}";
        $sql .= $view === 'upcoming' ? ' ORDER BY a.starts_at ASC,a.id ASC' : ' ORDER BY a.starts_at DESC,a.id DESC';
        $sql .= " LIMIT 26 OFFSET {$offset}";
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute(['clinic'=>$actor->clinicId, 'client'=>$id]);
        $rows = $statement->fetchAll();
        $counts = $this->database->connection()->prepare("SELECT COUNT(*) total,
            COALESCE(SUM({$upcoming}),0) upcoming,COALESCE(SUM(a.ends_at<UTC_TIMESTAMP()),0) past,
            COALESCE(SUM({$canceled}),0) canceled FROM appointments a WHERE a.clinic_id=:clinic AND a.client_id=:client");
        $counts->execute(['clinic'=>$actor->clinicId, 'client'=>$id]);
        $summary = array_map('intval', $counts->fetch());
        $this->audit->write($actor->clinicId, $actor, $correlationId, 'client.appointments.view', 'client', $id, 'success', ['page'=>$page,'view'=>$view]);
        return ['client'=>$client, 'items'=>array_slice($rows,0,25), 'counts'=>$summary, 'page'=>$page, 'view'=>$view, 'has_more'=>count($rows)>25];
    }

    /** Saved operational history only; it cannot reconstruct unrecorded old appointment times. */
    public function appointmentChanges(AuthContext $actor, int $clientId, int $appointmentId, array $query, string $correlationId): array
    {
        ClientService::authorize($actor);
        $page = self::page($query);
        $this->client($actor, $clientId);
        $statement = $this->database->connection()->prepare('SELECT a.id,l.timezone,a.currency FROM appointments a
            JOIN locations l ON l.id=a.location_id AND l.clinic_id=a.clinic_id
            WHERE a.id=:appointment AND a.client_id=:client AND a.clinic_id=:clinic');
        $statement->execute(['appointment'=>$appointmentId,'client'=>$clientId,'clinic'=>$actor->clinicId]);
        $appointment = $statement->fetch();
        if (!$appointment) throw new ApiException(404,'appointment_not_found','Appointment not found.');
        $offset = ($page - 1) * 25;
        $statement = $this->database->connection()->prepare("SELECT e.kind,e.id,e.created_at,e.from_status,e.to_status,e.reason,
            e.fee_triggered_cents,e.original_fee_cents,e.adjusted_fee_cents,actor.display_name actor_name,
            previous.display_name previous_practitioner,next_user.display_name next_practitioner
            FROM (
                SELECT 'status' kind,id,appointment_id,actor_user_id,from_status,to_status,reason,fee_triggered_cents,
                    NULL original_fee_cents,NULL adjusted_fee_cents,NULL old_practitioner_id,NULL new_practitioner_id,created_at
                    FROM appointment_status_history WHERE appointment_id=:status_appointment
                UNION ALL
                SELECT 'reassignment',id,appointment_id,actor_user_id,NULL,NULL,reason,NULL,NULL,NULL,old_practitioner_id,new_practitioner_id,created_at
                    FROM appointment_reassignments WHERE appointment_id=:reassignment_appointment AND clinic_id=:reassignment_clinic
                UNION ALL
                SELECT 'fee_adjustment',id,appointment_id,authorized_by,NULL,NULL,reason,NULL,original_fee_cents,adjusted_fee_cents,NULL,NULL,created_at
                    FROM cancellation_adjustments WHERE appointment_id=:fee_appointment
            ) e JOIN appointments a ON a.id=e.appointment_id
            LEFT JOIN users actor ON actor.id=e.actor_user_id AND actor.clinic_id=a.clinic_id
            LEFT JOIN practitioners old_p ON old_p.id=e.old_practitioner_id
            LEFT JOIN users previous ON previous.id=old_p.user_id AND previous.clinic_id=a.clinic_id
            LEFT JOIN practitioners new_p ON new_p.id=e.new_practitioner_id
            LEFT JOIN users next_user ON next_user.id=new_p.user_id AND next_user.clinic_id=a.clinic_id
            WHERE a.id=:appointment AND a.client_id=:client AND a.clinic_id=:clinic
            ORDER BY e.created_at DESC,e.kind,e.id DESC LIMIT 26 OFFSET {$offset}");
        $statement->execute(['status_appointment'=>$appointmentId,'reassignment_appointment'=>$appointmentId,'reassignment_clinic'=>$actor->clinicId,
            'fee_appointment'=>$appointmentId,'appointment'=>$appointmentId,'client'=>$clientId,'clinic'=>$actor->clinicId]);
        $rows = $statement->fetchAll();
        $this->audit->write($actor->clinicId,$actor,$correlationId,'client.appointment_changes.view','client',$clientId,'success',['appointment_id'=>$appointmentId,'page'=>$page]);
        return ['appointment'=>$appointment,'items'=>array_slice($rows,0,25),'page'=>$page,'has_more'=>count($rows)>25];
    }

    /** Local permissions explain eligibility, not whether Entra will authorize a future sign-in. */
    public static function configuredAccess(array $row, string $clientStatus = 'active'): array
    {
        $roles = $row['roles'] ? explode(',', $row['roles']) : [];
        $permissions = $row['permissions'] ? explode(',', $row['permissions']) : [];
        if (($row['identity_adapter'] ?? '') === 'entra-external-staff') {
            $roles = array_values(array_intersect($roles, ['practitioner']));
            $permissions = array_values(array_intersect($permissions, ['schedule_for_other_practitioners','add_clients','approve_onsite_service_area']));
        }
        $active = $row['account_status'] === 'active';
        if (($row['identity_adapter'] ?? '') === 'entra-external-staff' || ($row['membership_required'] ?? false)) {
            $active = $active && ($row['membership_status'] ?? '') === 'active' && ($row['identity_status'] ?? '') === 'active';
        }
        $operator = $active && (bool)array_intersect($roles, ['super_admin','clinic_admin','reception']);
        $practitioner = $active && in_array('practitioner', $roles, true);
        $related = (int)$row['appointment_count'] > 0 || (bool)$row['created_client'];
        $clinicScheduling = $operator || ($practitioner && in_array('schedule_for_other_practitioners', $permissions, true));
        return [
            'client_directory' => $operator || ($practitioner && $related),
            'staff_configuration_active' => $active,
            // The existing booking search and saved-address APIs permit clinic-wide contact lookup.
            'booking_contact' => $clientStatus === 'active' && ($operator || $practitioner),
            'client_administration' => $operator,
            'appointments' => $clinicScheduling ? 'clinic' : ($practitioner ? 'own' : 'none'),
            'appointment_changes' => $clinicScheduling ? 'clinic' : ($practitioner && (bool)$row['practitioner_active'] && $row['booking_mode']==='practitioner_managed' ? 'own' : 'none'),
            // Scheduling permission does not broaden access to another practitioner's logistics notes.
            'logistics_notes' => $operator ? 'clinic' : ($practitioner ? 'own' : 'none'),
        ];
    }

    public function practitionerAccess(AuthContext $actor, int $id, array $query, string $correlationId): array
    {
        ClientService::authorize($actor);
        $page = self::page($query);
        $client = $this->client($actor, $id);
        $offset = ($page - 1) * 25;
        $statement = $this->database->connection()->prepare("SELECT p.id practitioner_id,u.id user_id,
            u.display_name,p.discipline,p.active practitioner_active,p.booking_mode,u.status account_status,
            identity.adapter identity_adapter,identity.status identity_status,membership.status membership_status,
            (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) roles,
            (SELECT GROUP_CONCAT(DISTINCT perm.code ORDER BY perm.code) FROM user_permissions up JOIN permissions perm ON perm.id=up.permission_id WHERE up.user_id=u.id) permissions,
            COALESCE(history.appointment_count,0) appointment_count,history.first_appointment_at,history.last_appointment_at,
            EXISTS(SELECT 1 FROM audit_logs created WHERE created.clinic_id=u.clinic_id AND created.actor_user_id=u.id
                AND created.action='client.create' AND created.entity_type='client' AND created.entity_id=:created_client AND created.outcome='success') created_client
            FROM practitioners p JOIN users u ON u.id=p.user_id AND u.clinic_id=:clinic AND u.user_type='staff'
            LEFT JOIN staff_memberships membership ON membership.local_user_id=u.id AND membership.clinic_id=u.clinic_id
            LEFT JOIN product_identities identity ON identity.id=membership.identity_id
            LEFT JOIN (SELECT a.practitioner_id,COUNT(*) appointment_count,MIN(a.starts_at) first_appointment_at,MAX(a.starts_at) last_appointment_at
                FROM appointments a WHERE a.clinic_id=:history_clinic AND a.client_id=:history_client GROUP BY a.practitioner_id) history ON history.practitioner_id=p.id
            ORDER BY (COALESCE(history.appointment_count,0)>0 OR created_client) DESC,(u.status='active') DESC,u.display_name,p.id
            LIMIT 26 OFFSET {$offset}");
        $statement->execute(['created_client'=>$id,'clinic'=>$actor->clinicId,'history_clinic'=>$actor->clinicId,'history_client'=>$id]);
        $rows = $statement->fetchAll();
        $items = [];
        foreach (array_slice($rows,0,25) as $row) {
            $row['membership_required'] = ($row['identity_adapter'] ?? '') === 'entra-external-staff'
                || ($this->config?->staffMembershipPilotEnabled && in_array((int)$row['user_id'], $this->config->staffMembershipPilotUserIds, true));
            $row['configured_access'] = self::configuredAccess($row, $client['status']);
            $row['appointment_count'] = (int)$row['appointment_count'];
            $row['created_client'] = (bool)$row['created_client'];
            $row['practitioner_active'] = (bool)$row['practitioner_active'];
            $row['roles'] = $row['roles'] ? explode(',', $row['roles']) : [];
            $row['permissions'] = $row['permissions'] ? explode(',', $row['permissions']) : [];
            $items[] = $row;
        }
        $this->audit->write($actor->clinicId, $actor, $correlationId, 'client.practitioner_access.view', 'client', $id, 'success', ['page'=>$page]);
        return ['client'=>$client,'items'=>$items,'page'=>$page,'has_more'=>count($rows)>25,'basis'=>'local_configuration'];
    }
}
