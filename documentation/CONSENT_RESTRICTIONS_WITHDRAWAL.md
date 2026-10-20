# Consent restrictions and withdrawal enforcement

Implemented on `feature/consent-restrictions-withdrawal`, based on the preceding booking-contact and sensitive-access audit branches. This is an application safeguard for review, not a PHIPA certification or permission to use real patient data.

## Meaning and supported scope

The IPC describes withdrawal as prospective and recognizes restrictions on particular recipients or information. Withdrawal does not undo earlier authorized activity. See [IPC: consent and your personal health information](https://www.ipc.on.ca/en/health-individuals/consent-and-your-personal-health-information) and the [IPC PHIPA FAQ](https://www.ipc.on.ca/sites/default/files/legacy/2015/11/phipa-faq.pdf). A privacy/clinical reviewer must approve the clinic's handling of consent, capacity, representatives, legally required recording and statutory exceptions.

The implemented directives are deliberately coarse negative permissions:

| Directive | Effect |
| --- | --- |
| `staff_access`, `target_staff_id: 0` | Withhold the patient's records from all clinic staff through the clinic API. |
| `staff_access`, named staff user ID | Withhold the patient's records from that staff account, including practitioner, administrator and delegated scheduling access. |
| `patient_communications`, target `0` | Stop future appointment email/reminder delivery to the patient through `NotificationWorker`. It does not stop authentication/security messages, change treatment consent or opt the patient out of all correspondence. |

An active global restriction and an active named restriction both apply. Restoring one does not clear the other. No directive grants a role, relationship or access that was otherwise unavailable. Historical appointments, record-creation audits, assigned forms and form consent answers cannot override a restriction.

Existing clinical records, original consent answers, appointment history and earlier evidence are retained. Patients retain their own authenticated profile, forms, receipts, appointment and calendar access. Withdrawal does not cancel appointments. Creating or previewing a booking assigned to a restricted practitioner is denied even if another authorized staff member or the patient submits it.

## Recording and restoration

Linked patients use **My client account → Privacy instructions** to withdraw or restore staff access and appointment messages. They may select all clinic staff or a named account. Controls show the current state, explain care implications and require a reload after a conflicting save. The interface is available in English and French.

API contracts:

- `GET /api/v1/customer/consent` and `PUT /api/v1/customer/consent` resolve the client exclusively from the verified customer session and clinic link.
- `GET /api/v1/clients/{id}/consent` and `PUT /api/v1/clients/{id}/consent` require the explicit local-staff permission `manage_client_consent`. Administrator roles alone do not qualify. A Super Admin can assign the permission through Staff Administration; self-role changes remain prohibited. External practitioner membership permissions do not include it.
- PUT accepts `scope`, integer `target_staff_id`, boolean `restricted`, and integer `version`. Version `0` means no directive exists for that scope/target. A successful save advances the version. Other fields are rejected.
- Staff PUT additionally requires `instruction_reference`, a 1–120 character reference code using letters, numbers and `._:/-`. Store the verified instruction/identity evidence in the clinic's controlled process; do not put clinical details or contact information in this field. A reference is an attestation by the authorized staff member, not automated proof of patient identity or capacity.
- Staff cannot restore their own named access or clear a global restriction. The patient can restore a global restriction; another authorized privacy manager can record restoration for a named staff member. Global restrictions remain effective until the patient restores them. An offline/global restoration and representative workflow remain to be designed and approved.
- The GET response returns current directives and a minimal same-clinic staff selector (ID and display name only). It does not return clinical records or grant their access.

Policy updates, append-only `client_consent_events` evidence and the `consent.directive` business audit commit together. A failed required business audit rolls back the update. The response-boundary access audit also records disclosure/denial, actor, clinic, correlation and resource IDs without copying instruction references or clinical text. If that separate response audit fails after a committed save, the caller receives an error and must reload to establish the committed version.

## Enforcement boundaries

- [Room maintenance](CONSENT_AUTHORIZED_ROOMS.md) now checks current clinic-wide Super Admin authority and complete turnover effects, with guarded relocation and atomic audits. Capability and practitioner assignments remain separately blocked.
- [Clinic location administration](CONSENT_AUTHORIZED_LOCATIONS.md) now checks current clinic-wide Super Admin authority and all appointment/approval effects of address/timezone edits before atomic changes. Ordinary metadata edits and creation do not grant patient access.
- [Practitioner enquiry review](CONSENT_AUTHORIZED_PRACTITIONER_ENQUIRIES.md) now rechecks current clinic or central authority for recruitment inbox review and audited notification claims. Recruitment submissions do not restore patient access or create staff/patient relationships.
- [Receipt-template maintenance](CONSENT_AUTHORIZED_RECEIPT_TEMPLATES.md) now rechecks current clinic administrator authority for future issuer configuration, with atomic version/audit writes. Patient-scoped receipt access remains restricted.
- [Staff avatar moderation](CONSENT_AUTHORIZED_STAFF_AVATARS.md) now authorizes current clinic Super Admin access to staff photos only, with separate self-service scope and atomic image/audit changes. Patient targets are excluded.
- [Practitioner qualifications](CONSENT_AUTHORIZED_QUALIFICATIONS.md) now permit authorized credential review and revocation under unrelated restrictions, with current clinic roles, own-profile scope, independent verification and atomic audits.
- [Staff invitations](CONSENT_AUTHORIZED_STAFF_INVITATIONS.md) now permit authorized invitation management and revocation despite unrelated patient restrictions; verified claims, least-privilege memberships, named/global directives and current local authority remain enforced.
- [Practitioner account management](CONSENT_AUTHORIZED_PRACTITIONER_MANAGEMENT.md) now permits authorized local account deactivation, reactivation and onboarding despite unrelated restrictions, with current authority checks and atomic audits. It does not transfer identities or clear patient directives.
- [Dashboard widget management](CONSENT_AUTHORIZED_DASHBOARD_WIDGETS.md) now permits patient-free declarative configuration with current clinic Super Admin authority, stored-definition revalidation and atomic audit writes. Published widgets retain viewer-specific consent and practitioner filters.
- [Reminder settings](CONSENT_AUTHORIZED_REMINDER_SCHEDULES.md) now separate patient-free configuration reads from complete authorization of matching pending queue effects. Worker communication restrictions remain independent; malformed timing payloads cannot bypass the authorization predicate through numeric coercion.
- [Privacy-audit review](PRIVACY_AUDIT_REVIEW.md) has separate, explicit clinic oversight authority for minimal access-history references. It preserves ordinary clinical restrictions; narrowly scoped permission revocation remains available under those restrictions.
- Client searches/directories, booking contacts, appointment lists and practitioner calendars filter restricted clients in SQL before ordering, pagination or `has_more` calculation.
- Client detail/history, form tasks and answers, saved addresses, appointment actions and receipts are checked before handler reads or writes. Existing role, clinic, active-account and relationship checks still apply. Core booking, client and form services also apply the negative permission.
- Reassignment and new/recurring booking paths validate the destination practitioner; ordinary writes cannot establish a relationship to defeat withdrawal.
- Merge and merge preview refuse either client with any directive history, including restored directives. This prevents losing instructions/history or moving them to another identity without a reviewed migration.
- [Client registration](CONSENT_AUTHORIZED_CLIENT_REGISTRATION.md) now checks all matching identities before duplicate suggestions or confirmation. Unrelated restrictions no longer block distinct registration; hidden matches require review without exposing details or creating a replacement access relationship. The follow-up also inventories remaining conservative route groups.
- [Address coverage](CONSENT_AUTHORIZED_ADDRESS_COVERAGE.md) now checks the patient and selected practitioner before private-origin/provider activity or approval reads/writes. Proofs are patient-bound and approval/revocation writes commit with their audits; unrelated restrictions no longer block permitted coverage workflows.
- [Recurring availability and one-time overrides](CONSENT_AUTHORIZED_AVAILABILITY.md) now authorize buffered appointment overlaps in old/new schedule windows, including unbounded recurrence, before atomic schedule/audit writes. Unrelated restrictions no longer block permitted windows.
- For affected staff, routes without a proven patient filter fail closed with `consent_scope_restricted`. Unclassified routes retain this boundary. **These entire operations may be unavailable even when some included patients are unrestricted.** Follow-up changes provide tested filtering for [dashboards and schedules](CONSENT_FILTERED_DASHBOARDS_SCHEDULES.md), [notification lists and reviews](CONSENT_FILTERED_NOTIFICATIONS.md), and [travel enquiries and messages](CONSENT_FILTERED_TRAVEL_ENQUIRIES.md). [Bulk time-off changes](CONSENT_AUTHORIZED_TIME_OFF.md) now authorize every affected appointment in the existing and proposed periods before mutation. Extending other routes requires patient authorization and tests, not merely adding them to the safe-route list.
- New unclassified authenticated staff routes inherit that fail-closed boundary. Non-patient configuration/template routes have an explicit safe list. Anonymous published catalogs and aggregate public slot availability remain available.
- Protected staff and customer responses use `Cache-Control: no-store`. Previously displayed/downloaded information and previously delivered messages cannot be recalled; staff handling and incident procedures must address those copies.

The notification worker checks the current directive before composing an appointment email, calendar attachment, action link or staff SMS/email. Queued/failed messages that are no longer permitted become `canceled`; restoration does not resurrect them. Patient-message permission and staff-recipient permission are evaluated independently. Other existing contact/preference/assignment checks remain in effect.

## Concurrency and deployment

Apply [migration 050](../api/database/migrations/050_consent_directives.sql) once, after all preceding migrations including [049](../api/database/migrations/049_access_audit_events.sql), **before deploying the API and every notification worker**. The fresh-schema definition includes the tables. Existing records are not backfilled into implied grants. Missing consent storage blocks protected disclosure; an empty result requiring no patient query may still succeed.

API requests, directive changes and notification delivery acquire a database-specific, clinic-wide MySQL advisory lock. It is independent of business transactions and held through the protected response or provider send. A concurrent withdrawal waits; if the lock cannot be obtained within five seconds, the operation fails closed. A worker unable to prepare a message sends nothing and puts it into review. Thus a message already in flight must finish before withdrawal commits; there is no claim that earlier delivery can be undone.

This serializes protected work per clinic and can reduce throughput. Confirm `GET_LOCK`/`RELEASE_LOCK` support, connection lifetime, timeout behavior and performance on the intended host. It assumes one primary database lock namespace; multi-primary routing or independent replicas require a different distributed coordination design. Stop/drain older API and worker versions during rollout so none can bypass the new boundary. Direct database access, old application deployments and separate integrations are outside this API control.

Use separate migration credentials. The runtime needs SELECT/INSERT/UPDATE on current directives, SELECT/INSERT on consent events, and required audit permissions; it should not have UPDATE/DELETE on consent events or audit trails. Approve retention, backup, access review and monitoring for these sensitive metadata tables.

Before real data, rehearse the migration/rollback and synthetic authorization/withdrawal scenarios on the hosted database, verify the advisory lock with concurrent API and worker connections, validate authorized operational workflows, and obtain privacy/clinical approval. Remaining work includes finer information/purpose restrictions, capacity/substitute decision-makers, reviewed statutory exceptions and required recording, offline global restoration, consent evidence review, secure data-subject requests, retention and the other release gates in the original [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md). There is no automatic administrator/emergency bypass in this release.

## Local validation

The versioned SQL/HTTP suite is [consent-directives.php](../api/tests/integration/consent-directives.php), available through `scripts/test-recurring-sql.ps1 -IntegrationTest consent-directives.php`. It runs against a disposable localhost database, synthetic signed staff/customer identities and a loopback API. It includes existing booking-contact and access-audit regressions, stale writes, cross-client/clinic authorization, delegated/admin bypass attempts, retained patient access, restrictive searches, queued delivery, transaction rollback, missing storage and concurrent connections. It never loads a private `.env` or hosted patient data.

Browser coverage in `Frontend/tests/customer.spec.ts` exercises named/global instructions, versioned restoration, care-impact text, mobile conflict/reload recovery and layout.

Local validation on 19 October 2026:

- Consent SQL/HTTP authorization: 71 checks passed on MariaDB 11.4.8, together with 74 existing booking-contact and 28 access-audit checks.
- Separate recurring-booking SQL regression: 34 checks passed, including the updated migration baseline.
- All 51 root PHP policy/unit scripts passed; 90 PHP source/public files passed syntax checks.
- Selected customer, client-overview and appointment-action-link browser regression: 40 tests passed, including two new privacy scenarios.
- Full public, portal and application-admin frontend build passed, including content, TypeScript and 2,072 bilingual translation checks. Existing bundle-size warnings remain.

These are local checks. Hosted migration, identity, vendor, transport and organizational acceptance have not been performed. The earlier readiness review's unrelated full-browser role-policy failure remains outside this change; this is not a claim that the entire browser suite was rerun or is green.

Room capabilities and practitioner assignments now use [explicit clinic authority and booking-scope consent checks](CONSENT_AUTHORIZED_ROOM_ASSIGNMENTS.md); existing room selections require rollout review before deploying the corrected booking allowlist enforcement.

Subsequent service work: [service configuration and assignments](CONSENT_AUTHORIZED_SERVICES.md) now have current clinic authority, complete booking/series and approval-effect checks, atomic replacement and individual approval revocation audits. Tax creation and clinic booking defaults remain separately blocked.

Subsequent configuration work: [tax creation and clinic booking defaults](CONSENT_AUTHORIZED_TAX_BOOKING_SETTINGS.md) now have current clinic authority, bounded validation and atomic audits, without propagating configuration into existing patient records. Clinic profile, branding, theme and welcome routes remain separately blocked.
