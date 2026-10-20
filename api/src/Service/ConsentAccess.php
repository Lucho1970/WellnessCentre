<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Wellness\Auth\AuthContext;
use Wellness\Http\{ApiException,Request};

/** Negative permission only. Never replaces an existing relationship/role check. */
final class ConsentAccess
{
    public function __construct(private readonly PDO $pdo) {}

    public static function condition(AuthContext $actor,array &$params,string $client='u.id'): string
    {
        if($actor->userType!=='staff')return '';
        $params['consent_actor']=$actor->userId;$params['consent_clinic']=$actor->clinicId;
        return " AND NOT EXISTS(SELECT 1 FROM client_consent_directives consent WHERE consent.clinic_id=:consent_clinic AND consent.client_id=$client AND consent.scope='staff_access' AND consent.restricted=1 AND consent.target_staff_id IN (0,:consent_actor))";
    }

    /** Only count notifications whose patient can be resolved in the current clinic.
     * Appointment-less patient messages use their client recipient; orphan/staff-only
     * events have no proven patient scope and are excluded from patient activity totals.
     */
    public static function notificationCondition(AuthContext $actor,array &$params): string
    {
        $visible=self::condition($actor,$params,'consent_client.id');
        return " AND EXISTS(SELECT 1 FROM users consent_client WHERE consent_client.user_type='client'
            AND consent_client.clinic_id=n.clinic_id AND consent_client.id=CASE
                WHEN n.appointment_id IS NULL THEN n.recipient_user_id
                ELSE (SELECT consent_appointment.client_id FROM appointments consent_appointment
                      WHERE consent_appointment.id=n.appointment_id AND consent_appointment.clinic_id=n.clinic_id)
            END $visible)";
    }

    public function restricted(int $clinic,int $client,int $staff,bool $current=false): bool
    {
        $q=$this->pdo->prepare("SELECT 1 FROM client_consent_directives WHERE clinic_id=? AND client_id=? AND scope='staff_access' AND restricted=1 AND target_staff_id IN (0,?) LIMIT 1".($current?' FOR UPDATE':''));
        $q->execute([$clinic,$client,$staff]);return (bool)$q->fetchColumn();
    }

    public function assertClient(AuthContext $actor,int $client): void
    {
        if($actor->userType==='staff'&&$this->restricted($actor->clinicId,$client,$actor->userId))
            throw new ApiException(403,'consent_restricted','Access is restricted by a patient privacy instruction.');
    }

    public function assertRecipient(int $clinic,int $client,int $practitioner): void
    {
        $q=$this->pdo->prepare('SELECT user_id FROM practitioners WHERE id=?');$q->execute([$practitioner]);$staff=$q->fetchColumn();
        if($staff!==false&&$this->restricted($clinic,$client,(int)$staff))throw new ApiException(403,'consent_restricted','This booking cannot be assigned under the current privacy instructions.');
    }

    public function notificationAllowed(array $event): bool
    {
        if(str_starts_with((string)$event['event_code'],'staff_'))return !$this->restricted((int)$event['clinic_id'],(int)$event['client_id'],(int)$event['recipient_user_id']);
        $q=$this->pdo->prepare("SELECT 1 FROM client_consent_directives WHERE clinic_id=? AND client_id=? AND scope='patient_communications' AND restricted=1 LIMIT 1");
        $q->execute([$event['clinic_id'],$event['client_id']]);return !$q->fetchColumn();
    }

    /** Safety boundary for routes without a proven row-level filter. Checked before any side effect. */
    public function request(AuthContext $actor,Request $r,string $route): void
    {
        if($actor->userType!=='staff')return;
        if(preg_match('#^/api/v1/travel-enquiries(?:/\d+(?:/(?:reply|read|close))?)?$#D',$r->path)
            && in_array($r->method,['GET','POST'],true))return; // TravelEnquiryService owns row-level authorization.
        // These routes contain no patient records, or enforce their own filtered boundary below.
        if(in_array($route,[
            'catalogueSettings','createServiceCategory','updateServiceCategory','createService','updateService','serviceAssignments','updateServiceAssignments',
            'roomCapabilities','createRoomCapability','updateRoomCapabilityAssignments','roomPractitionerRestrictions','updateRoomPractitioners',
            'createRoom','updateRoom', // AdminService owns current clinic-wide authority, turnover effects and safe relocation.
            'createLocation','updateLocation', // AdminService authorizes approval revocations and timezone appointment effects before mutation.
            'practitionerEnquiries','updatePractitionerEnquiry','notifyPractitionerEnquiry', // Recruitment inbox only; service owns current authority, clinic scope and audited send claims.
            'saveReceiptTemplate', // SessionReceiptService checks current clinic administration; future issuer configuration only.
            'privacyAuditHistory','grantPrivacyReview','revokePrivacyReview','adminStaff', // Minimal oversight history has separate, explicit authority in PrivacyAuditService.
            'reminderSchedules','createReminderSchedule','toggleReminderSchedule', // ReminderScheduleService authorizes pending effects.
            // Declarative configuration only; DashboardService rechecks current clinic Super Admin authority.
            'adminDashboardWidgets','uploadDashboardWidget','toggleDashboardWidget','dashboardWidgetVersions','restoreDashboardWidget',
            'adminNotifications','practitionerNotifications','reviewNotification',
            'dashboard','practitionerToday','practitionerVisitSummary','practitionerNextOnsite',
            'availabilityRules','scheduleExceptions','timeOffImpact','previewTimeOffImpact','practitionerAvailabilityContext',
            'createAvailability','updateAvailabilityRule','deleteAvailabilityRule','createAvailabilityOverride','updateAvailabilityOverride','deleteAvailabilityOverride',
            'createTimeOff','updateTimeOff','deleteTimeOff', // AdminService authorizes complete old/new affected sets.
            'me','dashboardPreferences','saveDashboardPreferences','resetDashboardPreferences',
            'myTravelSettings','saveMyTravelSettings','myWorkLocation','saveMyWorkLocation',
            'adminAvatar','adminSaveAvatar','adminDeleteAvatar', // ProfileService permits only current clinic Super Admin moderation of staff photos.
            'profileAvatar','saveProfileAvatar','deleteProfileAvatar','myPublicCard','updateMyPublicCard',
            'createQualificationType','practitionerQualifications','submitPractitionerQualification','reviewQualification', // Qualification service owns current authority and independent review; no patient data.
            'qualificationTypes','myQualifications','submitMyQualification','globalCredentials','createGlobalCredential','shareGlobalCredential',
            'staffNotificationPreferences','saveStaffNotificationPreferences','sendStaffNotificationCode','verifyStaffNotificationEmail',
            'validateAddressCoverage','addressCoverageApproval','approveAddressCoverage','revokeAddressCoverage', // AddressCoverageService owns patient/recipient authorization.
            'staffInvitations','createStaffInvitation','approveStaffInvitation','revokeStaffInvitation', // StaffInvitationService owns current authority, verified claims and membership scope.
            'createPractitioner','onboardPractitioner','updatePractitioner', // AdminService rechecks current clinic authority; no patient-record or identity transfer.
            'createClient', // ClientService checks registration matches, consent and current creator scope.
            'bookingOptions','clientConsent','saveClientConsent','clients','practitionerClients','bookingClients','appointments','practitionerCalendar',
            'formTemplates','publishForm','publishFormVersion','formHistory','formDrafts','saveFormDraft','updateFormDraft','formDraftDetail','importFormDraft','validateFormSource',
            'formLibrary','submitLibraryForm','libraryFormDetail','reviewLibraryForm','copyLibraryForm',
            'adminStaff','updateStaff','createStaff','adminLocations','adminRooms','adminServices','adminPractitioners',
        ],true))return;

        $clients=[];
        if(preg_match('#^/api/v1/(?:clients|booking-clients)/(\d+)(?:/|$)#',$r->path,$m)){
            $clients[]=(int)$m[1];
            if(preg_match('#/(?:merge|merge-preview)/(\d+)$#',$r->path,$merge))$clients[]=(int)$merge[1];
            if(in_array($route,['mergeClients','clientMergePreview'],true)){
                foreach($clients as $client){$q=$this->pdo->prepare('SELECT 1 FROM client_consent_directives WHERE clinic_id=? AND client_id=? LIMIT 1');$q->execute([$actor->clinicId,$client]);if($q->fetchColumn())throw new ApiException(409,'consent_merge_review','Client records with consent instructions require a reviewed migration before merging.');}
            }
        } elseif(preg_match('#^/api/v1/(?:appointments|practitioner/(?:appointments|today|next-onsite))/(\d+)(?:/|$)#',$r->path,$m)){
            $clients=$this->clientIds('appointments',(int)$m[1],$actor->clinicId);
        } elseif(preg_match('#^/api/v1/forms/tasks/(\d+)(?:/|$)#',$r->path,$m)){
            $clients=$this->clientIds('client_form_tasks',(int)$m[1],$actor->clinicId);
        } elseif(preg_match('#^/api/v1/recurring-series/(\d+)(?:/|$)#',$r->path,$m)){
            $clients=$this->clientIds('recurring_series',(int)$m[1],$actor->clinicId);
        } elseif(preg_match('#^/api/v1/receipts/(\d+)(?:/|$)#',$r->path,$m)){
            $clients=$this->clientIds('session_receipts',(int)$m[1],$actor->clinicId);
        } elseif(in_array($route,['createAppointment','recurringCreate','recurringPreview'],true)){
            $clients[]=(int)($r->body['client_id']??0);
        }
        if($clients!==[]){
            foreach($clients as $client){$this->assertClient($actor,$client);if(isset($r->body['practitioner_id']))$this->assertRecipient($actor->clinicId,$client,(int)$r->body['practitioner_id']);}
            return;
        }
        // Unknown/new routes must not silently bypass restrictions (including aggregate counts,
        // exports and duplicate candidates).
        $q=$this->pdo->prepare("SELECT 1 FROM client_consent_directives WHERE clinic_id=? AND scope='staff_access' AND restricted=1 AND target_staff_id IN (0,?) LIMIT 1");
        $q->execute([$actor->clinicId,$actor->userId]);
        if($q->fetchColumn())throw new ApiException(403,'consent_scope_restricted','This operation requires a privacy review because it may include restricted records.');
    }

    private function clientIds(string $table,int $id,int $clinic): array
    {
        // Only internal constant table names reach this method.
        $q=$this->pdo->prepare("SELECT client_id FROM $table WHERE id=? AND clinic_id=?");$q->execute([$id,$clinic]);
        return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
}
