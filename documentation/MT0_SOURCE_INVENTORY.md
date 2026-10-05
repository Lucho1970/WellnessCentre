# MT0 source inventory

Snapshot: 5 October 2026. Generated from the local workspace, including uncommitted development. This is a review index, not proof that every listed boundary is tenant-safe or deployed. Functional owners below are proposed review responsibilities, not named personnel.

## API dispatcher declarations

All registered FastRoute declarations in `api/src/Api.php` are listed. Customer routes dispatched separately must also be reviewed; their source appears below. Authentication and resource authorization must be traced through the handler, not inferred from the URL.

| Method | Path | Handler |
| --- | --- | --- |
| GET | `/api/v1/health` | `health` |
| GET | `/api/v1/health/database` | `databaseHealth` |
| GET | `/api/v1/site-config` | `siteConfig` |
| GET | `/api/v1/brand/{type:logo\|favicon}` | `brandAsset` |
| GET | `/api/v1/locations` | `locations` |
| GET | `/api/v1/services` | `services` |
| GET | `/api/v1/public/services` | `publicServices` |
| GET | `/api/v1/public/services/{slug:[a-z0-9-]+}` | `publicService` |
| GET | `/api/v1/public/practitioners` | `publicPractitioners` |
| GET | `/api/v1/public/practitioners/{slug:[a-z0-9-]+}` | `publicPractitioner` |
| GET | `/api/v1/practitioners` | `practitioners` |
| GET | `/api/v1/team` | `team` |
| GET | `/api/v1/team/{slug:[a-z0-9-]+}/image` | `teamImage` |
| GET | `/api/v1/availability` | `availability` |
| GET | `/api/v1/auth/me` | `me` |
| GET | `/api/v1/dashboard` | `dashboard` |
| GET | `/api/v1/dashboard/preferences` | `dashboardPreferences` |
| PUT | `/api/v1/dashboard/preferences` | `saveDashboardPreferences` |
| DELETE | `/api/v1/dashboard/preferences` | `resetDashboardPreferences` |
| GET | `/api/v1/admin/dashboard-widgets` | `adminDashboardWidgets` |
| GET | `/api/v1/admin/notifications` | `adminNotifications` |
| GET | `/api/v1/practitioner/notifications` | `practitionerNotifications` |
| POST | `/api/v1/admin/notifications/{id:\d+}/review` | `reviewNotification` |
| GET | `/api/v1/admin/reminder-schedules` | `reminderSchedules` |
| POST | `/api/v1/admin/reminder-schedules` | `createReminderSchedule` |
| PATCH | `/api/v1/admin/reminder-schedules/{id:\d+}` | `toggleReminderSchedule` |
| POST | `/api/v1/admin/dashboard-widgets` | `uploadDashboardWidget` |
| PATCH | `/api/v1/admin/dashboard-widgets/{id:[a-z][a-z0-9_]+}` | `toggleDashboardWidget` |
| GET | `/api/v1/admin/dashboard-widgets/{id:[a-z][a-z0-9_]+}/versions` | `dashboardWidgetVersions` |
| POST | `/api/v1/admin/dashboard-widgets/{id:[a-z][a-z0-9_]+}/versions/{version:\d+}/restore` | `restoreDashboardWidget` |
| GET | `/api/v1/customer/auth/me` | `customerMe` |
| GET | `/api/v1/profile/avatar` | `profileAvatar` |
| PUT | `/api/v1/profile/avatar` | `saveProfileAvatar` |
| DELETE | `/api/v1/profile/avatar` | `deleteProfileAvatar` |
| GET | `/api/v1/profile/public-card` | `myPublicCard` |
| PUT | `/api/v1/profile/public-card` | `updateMyPublicCard` |
| GET | `/api/v1/qualifications/types` | `qualificationTypes` |
| POST | `/api/v1/admin/qualifications/types` | `createQualificationType` |
| GET | `/api/v1/profile/qualifications` | `myQualifications` |
| POST | `/api/v1/profile/qualifications` | `submitMyQualification` |
| GET | `/api/v1/admin/practitioners/{id:\d+}/qualifications` | `practitionerQualifications` |
| POST | `/api/v1/admin/practitioners/{id:\d+}/qualifications` | `submitPractitionerQualification` |
| PATCH | `/api/v1/admin/qualifications/{id:\d+}/review` | `reviewQualification` |
| GET | `/api/v1/profile/notifications` | `staffNotificationPreferences` |
| PUT | `/api/v1/profile/notifications` | `saveStaffNotificationPreferences` |
| POST | `/api/v1/profile/notifications/send-code` | `sendStaffNotificationCode` |
| POST | `/api/v1/profile/notifications/verify` | `verifyStaffNotificationEmail` |
| PUT | `/api/v1/admin/users/{id:\\d+}/avatar` | `adminSaveAvatar` |
| GET | `/api/v1/admin/users/{id:\\d+}/avatar` | `adminAvatar` |
| DELETE | `/api/v1/admin/users/{id:\\d+}/avatar` | `adminDeleteAvatar` |
| GET | `/api/v1/appointments` | `appointments` |
| GET | `/api/v1/appointments/{id:\\d+}` | `appointmentDetails` |
| GET | `/api/v1/appointments/{id:\\d+}/reassignment-options` | `appointmentReassignmentOptions` |
| GET | `/api/v1/appointments/{id:\\d+}/logistics-notes` | `appointmentLogisticsNotes` |
| POST | `/api/v1/appointments/{id:\\d+}/logistics-notes` | `createAppointmentLogisticsNote` |
| GET | `/api/v1/practitioner/calendar` | `practitionerCalendar` |
| GET | `/api/v1/practitioner/next-onsite` | `practitionerNextOnsite` |
| POST | `/api/v1/practitioner/next-onsite/{id:\d+}/travel-estimate` | `practitionerTravelEstimate` |
| GET | `/api/v1/practitioner/today` | `practitionerToday` |
| GET | `/api/v1/practitioner/visit-summary` | `practitionerVisitSummary` |
| GET | `/api/v1/practitioner/appointments/{id:\d+}/visit-history` | `practitionerVisitHistory` |
| POST | `/api/v1/practitioner/today/{id:\d+}/milestone` | `practitionerVisitMilestone` |
| POST | `/api/v1/practitioner/today/{id:\d+}/outcome` | `practitionerVisitOutcome` |
| POST | `/api/v1/appointments` | `createAppointment` |
| PATCH | `/api/v1/appointments/{id:\\d+}` | `updateAppointment` |
| GET | `/api/v1/appointments/{id:\\d+}/availability` | `appointmentAvailability` |
| GET | `/api/v1/appointments/{id:\\d+}/cancellation-preview` | `appointmentCancellationPreview` |
| GET | `/api/v1/booking-options` | `bookingOptions` |
| GET | `/api/v1/booking-clients` | `bookingClients` |
| GET | `/api/v1/practitioner/clients` | `practitionerClients` |
| GET | `/api/v1/booking-clients/{id:\\d+}/address` | `bookingClientAddress` |
| POST | `/api/v1/address-coverage/validate` | `validateAddressCoverage` |
| POST | `/api/v1/address-coverage/approval` | `addressCoverageApproval` |
| POST | `/api/v1/address-coverage/approve` | `approveAddressCoverage` |
| POST | `/api/v1/address-coverage/revoke` | `revokeAddressCoverage` |
| GET | `/api/v1/clients` | `clients` |
| POST | `/api/v1/clients` | `createClient` |
| GET | `/api/v1/clients/{id:\\d+}` | `client` |
| PATCH | `/api/v1/clients/{id:\\d+}` | `updateClient` |
| GET | `/api/v1/clients/{survivor:\\d+}/merge-preview/{duplicate:\\d+}` | `clientMergePreview` |
| POST | `/api/v1/clients/{survivor:\\d+}/merge/{duplicate:\\d+}` | `mergeClients` |
| GET | `/api/v1/clients/{id:\\d+}/invitations` | `clientInvitations` |
| POST | `/api/v1/clients/{id:\\d+}/invitations` | `issueClientInvitation` |
| POST | `/api/v1/clients/{id:\\d+}/invitations/{invitation:\\d+}` | `reviewClientInvitation` |
| POST | `/api/v1/admin/locations` | `createLocation` |
| GET | `/api/v1/admin/locations` | `adminLocations` |
| PATCH | `/api/v1/admin/locations/{id:\\d+}` | `updateLocation` |
| POST | `/api/v1/admin/rooms` | `createRoom` |
| GET | `/api/v1/admin/rooms` | `adminRooms` |
| PATCH | `/api/v1/admin/rooms/{id:\\d+}` | `updateRoom` |
| GET | `/api/v1/admin/room-capabilities` | `roomCapabilities` |
| POST | `/api/v1/admin/room-capabilities` | `createRoomCapability` |
| PUT | `/api/v1/admin/room-capability-assignments` | `updateRoomCapabilityAssignments` |
| GET | `/api/v1/admin/room-practitioner-restrictions` | `roomPractitionerRestrictions` |
| PUT | `/api/v1/admin/rooms/{id:\\d+}/practitioners` | `updateRoomPractitioners` |
| POST | `/api/v1/admin/staff` | `createStaff` |
| GET | `/api/v1/admin/staff` | `adminStaff` |
| PATCH | `/api/v1/admin/staff/{id:\\d+}` | `updateStaff` |
| GET | `/api/v1/admin/team-profiles` | `teamProfiles` |
| PUT | `/api/v1/admin/team-profiles/{id:\\d+}` | `updateTeamProfile` |
| POST | `/api/v1/admin/practitioners` | `createPractitioner` |
| GET | `/api/v1/admin/practitioners` | `adminPractitioners` |
| POST | `/api/v1/admin/practitioners/onboard` | `onboardPractitioner` |
| PATCH | `/api/v1/admin/practitioners/{id:\\d+}` | `updatePractitioner` |
| POST | `/api/v1/admin/services` | `createService` |
| GET | `/api/v1/admin/services` | `adminServices` |
| PATCH | `/api/v1/admin/services/{id:\\d+}` | `updateService` |
| GET | `/api/v1/admin/service-assignments` | `serviceAssignments` |
| PUT | `/api/v1/admin/services/{id:\\d+}/assignments` | `updateServiceAssignments` |
| POST | `/api/v1/admin/availability-rules` | `createAvailability` |
| GET | `/api/v1/admin/availability-rules` | `availabilityRules` |
| PATCH | `/api/v1/admin/availability-rules/{id:\\d+}` | `updateAvailabilityRule` |
| DELETE | `/api/v1/admin/availability-rules/{id:\\d+}` | `deleteAvailabilityRule` |
| GET | `/api/v1/admin/schedule-exceptions` | `scheduleExceptions` |
| POST | `/api/v1/admin/availability-overrides` | `createAvailabilityOverride` |
| PATCH | `/api/v1/admin/availability-overrides/{id:\\d+}` | `updateAvailabilityOverride` |
| POST | `/api/v1/admin/time-off` | `createTimeOff` |
| POST | `/api/v1/admin/time-off/impact` | `previewTimeOffImpact` |
| GET | `/api/v1/admin/time-off/{id:\\d+}/impact` | `timeOffImpact` |
| PATCH | `/api/v1/admin/time-off/{id:\\d+}` | `updateTimeOff` |
| DELETE | `/api/v1/admin/availability-overrides/{id:\\d+}` | `deleteAvailabilityOverride` |
| DELETE | `/api/v1/admin/time-off/{id:\\d+}` | `deleteTimeOff` |
| GET | `/api/v1/practitioner/availability-context` | `practitionerAvailabilityContext` |
| PATCH | `/api/v1/admin/clinic` | `updateClinic` |
| PUT | `/api/v1/admin/clinic/portal-theme` | `updatePortalTheme` |
| PUT | `/api/v1/admin/clinic/portal-welcome` | `updatePortalWelcome` |
| GET | `/api/v1/admin/clinic/branding` | `branding` |
| PUT | `/api/v1/admin/clinic/branding/{type:logo\|favicon}` | `saveBrandAsset` |
| DELETE | `/api/v1/admin/clinic/branding/{type:logo\|favicon}` | `deleteBrandAsset` |
| GET | `/api/v1/admin/catalogue-settings` | `catalogueSettings` |
| POST | `/api/v1/admin/service-categories` | `createServiceCategory` |
| PATCH | `/api/v1/admin/service-categories/{id:\\d+}` | `updateServiceCategory` |
| POST | `/api/v1/admin/taxes` | `createTax` |
| PATCH | `/api/v1/admin/booking-settings` | `updateBookingSettings` |

## Customer dispatch and non-dispatcher entry points

Review `Api::customerRoute` and `CustomerOnboarding` for challenge, sign-in, session, registration, claim, invitations, account, appointment and logout paths. These use the configured customer clinic today. The source file remains authoritative.

| Entry point | Functional review owner |
| --- | --- |
| `api/public/index.php` | API routing / deployment configuration |
| `api/public/runtime-config.php` | Public runtime configuration / clinic routing |
| `api/deploy/netfirms/public/cron/send-notifications.php` | Worker / notification credentials and clinic dispatch |
| `api/deploy/netfirms/public/index.php` | API routing / deployment configuration |
| `api/deploy/netfirms/public/runtime-config.php` | Public runtime configuration / clinic routing |
| `api/bin/provision-admin.php` | Identity / operator bootstrap |
| `api/bin/send-notifications.php` | Worker / notification credentials and clinic dispatch |
| `hosting/netfirms/main-domain/api/wellness-notification-trigger.php` | Worker / notification credentials and clinic dispatch |
| `hosting/netfirms/portal/share-preview-lib.php` | Public projection / clinic routing |
| `hosting/netfirms/portal/share-preview.php` | Public projection / clinic routing |

## Core source modules

Every PHP source module is indexed here. Review internal helper calls as well as public routes.

| Module | Functional review owner |
| --- | --- |
| `api/src/Api.php` | Core API / infrastructure / configuration |
| `api/src/Auth/AuthContext.php` | Identity / sessions / memberships |
| `api/src/Auth/CustomerAuthenticator.php` | Identity / sessions / memberships |
| `api/src/Auth/EntraAuthenticator.php` | Identity / sessions / memberships |
| `api/src/Config.php` | Core API / infrastructure / configuration |
| `api/src/Database.php` | Core API / infrastructure / configuration |
| `api/src/Http/ApiException.php` | Core API / infrastructure / configuration |
| `api/src/Http/Request.php` | Core API / infrastructure / configuration |
| `api/src/Http/Response.php` | Core API / infrastructure / configuration |
| `api/src/Service/AddressCoverageService.php` | Core API / infrastructure / configuration |
| `api/src/Service/AdminService.php` | Clinic administration / delegated grants / shared resources |
| `api/src/Service/AppointmentCalendar.php` | Scheduling / relationship and assignment access |
| `api/src/Service/AppointmentEmail.php` | Communications / workers / provider configuration |
| `api/src/Service/AppointmentLogisticsNotesService.php` | Scheduling / relationship and assignment access |
| `api/src/Service/AppointmentReminderQueue.php` | Communications / workers / provider configuration |
| `api/src/Service/AuditLogger.php` | Audit / authorized aggregation |
| `api/src/Service/AvailabilityService.php` | Scheduling / relationship and assignment access |
| `api/src/Service/BookingRequest.php` | Scheduling / relationship and assignment access |
| `api/src/Service/BookingService.php` | Scheduling / relationship and assignment access |
| `api/src/Service/CanadianSmsNumber.php` | Communications / workers / provider configuration |
| `api/src/Service/CancellationPolicy.php` | Scheduling / relationship and assignment access |
| `api/src/Service/CatalogService.php` | Public projection / profiles / practitioner ownership |
| `api/src/Service/ClientService.php` | Client relationships / profiles / merge |
| `api/src/Service/CustomerOnboarding.php` | Identity / sessions / memberships |
| `api/src/Service/DashboardService.php` | Audit / authorized aggregation |
| `api/src/Service/Delivery.php` | Scheduling / relationship and assignment access |
| `api/src/Service/GraphMailClient.php` | Communications / workers / provider configuration |
| `api/src/Service/ImmediateNotificationDispatch.php` | Communications / workers / provider configuration |
| `api/src/Service/MailSendException.php` | Communications / workers / provider configuration |
| `api/src/Service/NotificationActivityWindow.php` | Communications / workers / provider configuration |
| `api/src/Service/NotificationReviewService.php` | Communications / workers / provider configuration |
| `api/src/Service/NotificationSchedulerHealth.php` | Communications / workers / provider configuration |
| `api/src/Service/NotificationStatusService.php` | Communications / workers / provider configuration |
| `api/src/Service/NotificationWorker.php` | Communications / workers / provider configuration |
| `api/src/Service/PractitionerPublicProfileService.php` | Public projection / profiles / practitioner ownership |
| `api/src/Service/PractitionerQualificationService.php` | Public projection / profiles / practitioner ownership |
| `api/src/Service/PractitionerTravelService.php` | Scheduling / relationship and assignment access |
| `api/src/Service/PractitionerVisitService.php` | Scheduling / relationship and assignment access |
| `api/src/Service/ProfileService.php` | Public projection / profiles / practitioner ownership |
| `api/src/Service/PublicCardContact.php` | Public projection / profiles / practitioner ownership |
| `api/src/Service/PublicRuntimeConfig.php` | Core API / infrastructure / configuration |
| `api/src/Service/ReminderScheduleService.php` | Communications / workers / provider configuration |
| `api/src/Service/ScheduleIntervals.php` | Scheduling / relationship and assignment access |
| `api/src/Service/SmsSendException.php` | Communications / workers / provider configuration |
| `api/src/Service/StaffAppointmentEmail.php` | Communications / workers / provider configuration |
| `api/src/Service/StaffAppointmentSms.php` | Communications / workers / provider configuration |
| `api/src/Service/StaffNotificationPreferences.php` | Communications / workers / provider configuration |
| `api/src/Service/StaffNotificationQueue.php` | Communications / workers / provider configuration |
| `api/src/Service/VoipMsSmsClient.php` | Communications / workers / provider configuration |

## Data tables and migration sources

Names are taken from CREATE TABLE statements across the fresh-install schema and migrations. ALTER-only changes remain in their migration files; this listing does not certify upgrade parity. Tables without an explicit clinic key require ownership through parent records and review of all joins.

| Table | Definition source(s) |
| --- | --- |
| `accounting_connections` | `api/database/schema.sql` |
| `accounting_mappings` | `api/database/schema.sql` |
| `accounting_sync_records` | `api/database/schema.sql` |
| `appointment_attendees` | `api/database/schema.sql` |
| `appointment_logistics_notes` | `api/database/schema.sql`; `api/database/migrations/025_appointment_logistics_notes.sql` |
| `appointment_reassignments` | `api/database/schema.sql`; `api/database/migrations/029_appointment_reassignment.sql` |
| `appointment_status_history` | `api/database/schema.sql` |
| `appointment_visit_events` | `api/database/schema.sql`; `api/database/migrations/024_visit_progress.sql` |
| `appointments` | `api/database/schema.sql` |
| `audit_logs` | `api/database/schema.sql` |
| `availability_overrides` | `api/database/schema.sql` |
| `availability_rules` | `api/database/schema.sql` |
| `cancellation_adjustments` | `api/database/schema.sql` |
| `client_contact_addresses` | `api/database/migrations/005_customer_onboarding.sql` |
| `client_email_addresses` | `api/database/schema.sql`; `api/database/migrations/006_client_merge.sql` |
| `client_link_claims` | `api/database/migrations/005_customer_onboarding.sql` |
| `client_link_invitations` | `api/database/migrations/005_customer_onboarding.sql` |
| `client_merge_records` | `api/database/schema.sql`; `api/database/migrations/006_client_merge.sql` |
| `client_profiles` | `api/database/schema.sql` |
| `clinic_booking_settings` | `api/database/schema.sql`; `api/database/migrations/003_catalogue_settings.sql` |
| `clinic_brand_assets` | `api/database/schema.sql`; `api/database/migrations/011_clinic_brand_assets.sql` |
| `clinic_portal_themes` | `api/database/schema.sql`; `api/database/migrations/027_clinic_portal_theme.sql` |
| `clinics` | `api/database/schema.sql` |
| `consent_records` | `api/database/schema.sql` |
| `customer_auth_challenges` | `api/database/migrations/005_customer_onboarding.sql` |
| `customer_client_links` | `api/database/migrations/005_customer_onboarding.sql` |
| `customer_identities` | `api/database/migrations/005_customer_onboarding.sql` |
| `customer_rate_limits` | `api/database/migrations/005_customer_onboarding.sql` |
| `customer_sessions` | `api/database/migrations/005_customer_onboarding.sql` |
| `dashboard_preferences` | `api/database/schema.sql`; `api/database/migrations/012_dashboard_preferences.sql` |
| `dashboard_widget_versions` | `api/database/schema.sql`; `api/database/migrations/013_dashboard_widget_catalogue.sql` |
| `dashboard_widgets` | `api/database/schema.sql`; `api/database/migrations/013_dashboard_widget_catalogue.sql` |
| `data_export_requests` | `api/database/schema.sql` |
| `form_assignments` | `api/database/schema.sql` |
| `form_submissions` | `api/database/schema.sql` |
| `form_templates` | `api/database/schema.sql` |
| `identity_links` | `api/database/schema.sql` |
| `imported_calendar_entries` | `api/database/schema.sql` |
| `invoice_line_items` | `api/database/schema.sql` |
| `invoices` | `api/database/schema.sql` |
| `locations` | `api/database/schema.sql` |
| `notification_events` | `api/database/schema.sql` |
| `notification_reviews` | `api/database/schema.sql`; `api/database/migrations/022_notification_review.sql` |
| `notification_scheduler_probe` | `api/database/migrations/017_notification_scheduler_probe.sql` |
| `notification_scheduler_state` | `api/database/schema.sql`; `api/database/migrations/023_notification_scheduler_health.sql` |
| `notification_templates` | `api/database/schema.sql` |
| `onsite_area_approvals` | `api/database/schema.sql`; `api/database/migrations/020_onsite_area_approvals.sql` |
| `operational_tasks` | `api/database/schema.sql` |
| `payments` | `api/database/schema.sql` |
| `permissions` | `api/database/schema.sql`; `api/database/migrations/007_staff_scheduling_permissions.sql` |
| `practitioner_client_notes` | `api/database/schema.sql` |
| `practitioner_locations` | `api/database/schema.sql` |
| `practitioner_qualifications` | `api/database/schema.sql`; `api/database/migrations/026_practitioner_qualifications.sql` |
| `practitioner_services` | `api/database/schema.sql` |
| `practitioners` | `api/database/schema.sql` |
| `public_team_profiles` | `api/database/schema.sql`; `api/database/migrations/008_public_team_profiles.sql` |
| `qualification_types` | `api/database/schema.sql`; `api/database/migrations/026_practitioner_qualifications.sql` |
| `recurring_series` | `api/database/schema.sql` |
| `refunds` | `api/database/schema.sql` |
| `reminder_schedules` | `api/database/schema.sql` |
| `retention_policies` | `api/database/schema.sql` |
| `roles` | `api/database/schema.sql` |
| `room_capabilities` | `api/database/schema.sql` |
| `room_capability_assignments` | `api/database/schema.sql` |
| `room_practitioner_restrictions` | `api/database/schema.sql` |
| `rooms` | `api/database/schema.sql` |
| `service_categories` | `api/database/schema.sql` |
| `service_duration_options` | `api/database/schema.sql` |
| `service_locations` | `api/database/schema.sql`; `api/database/migrations/002_service_delivery_assignments.sql` |
| `service_room_capability_requirements` | `api/database/schema.sql` |
| `services` | `api/database/schema.sql` |
| `staff_accounts` | `api/database/schema.sql` |
| `staff_notification_preferences` | `api/database/schema.sql`; `api/database/migrations/018_staff_notification_preferences.sql` |
| `taxes` | `api/database/schema.sql` |
| `time_off` | `api/database/schema.sql` |
| `user_permissions` | `api/database/schema.sql`; `api/database/migrations/007_staff_scheduling_permissions.sql` |
| `user_profile_images` | `api/database/schema.sql`; `api/database/migrations/001_user_profile_images.sql` |
| `user_roles` | `api/database/schema.sql` |
| `users` | `api/database/schema.sql` |
| `waitlist_entries` | `api/database/schema.sql` |
| `waitlist_offers` | `api/database/schema.sql` |

## Shared-resource inventory and unresolved work

| Resource | Current source / review obligation |
| --- | --- |
| Frontend identity and URL wiring | `Frontend/src/auth`, `Frontend/src/customer`, `Frontend/src/shared/urls.ts`, `runtimeConfig.ts`, Vite surface configuration; replace only after adapter/host-context acceptance. |
| Database blobs / images | `ProfileService`, `CatalogService`, `AdminService`, brand/profile tables; verify tenant/publication ownership on fetch and cache revalidation. |
| JWKS cache | `EntraAuthenticator`, `CustomerAuthenticator`; review cache directory, issuer partitioning, refresh and rotation behavior. |
| Notification queue / scheduler / trigger | Notification and reminder modules, cron/bin entry points and main-domain bridge; clinic context, recipient scope and secrets must follow the selected event. |
| Provider settings | `Config.php`, private `.env`, mail/SMS/maps clients; currently shared deployment settings. Inventory key names and owners only; never copy secret values. |
| Logs and audit | `AuditLogger`, response correlation IDs, PHP error logging and notification review; scoped retention/support search and redaction require operational review. |
| Backup and maintenance | Database migrations/maintenance, deployment scripts and hosted test runbook; verify live backup/restore and per-clinic exports with synthetic data. |
| Additional inventory | Review all frontend API callers, database maintenance scripts and temporary hosted tools before MT2 acceptance; listing code alone does not establish authorization. |

## Snapshot counts

- 134 registered route declarations.
- 49 PHP core modules.
- 81 table names from CREATE TABLE statements.

The migration design and next implementation slice are in [MT0 identity and practice architecture](MT0_IDENTITY_AND_PRACTICE_ARCHITECTURE.md).
