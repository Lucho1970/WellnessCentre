# Consent-authorized receipt templates

## Scope and authority

The clinic issuer-template save route previously inherited the conservative consent block: an unrelated patient's restriction prevented otherwise authorized administrators from maintaining future receipt settings. Only `PUT /api/v1/receipts/template` is newly classified as configuration without a patient target. It changes issuer name, business address, contact details and an optional validated logo for future receipts.

The service requires staff identity and Super Admin or Clinic Admin authority in the supplied principal, then rechecks the persisted active account, same clinic, active clinic and current administrator role under transaction locks. Practitioner, reception, accountant, client, application-admin identity and privacy-review permission alone do not grant template authority. Stale principals fail after role revocation, account deactivation or clinic reassignment. An independently authorized administrator in another clinic can maintain only that clinic's own template.

Submitted clinic, client and appointment IDs do not redirect configuration or create an access relationship. The stored JSON contains only the four supported issuer fields. The existing receipt feature flag, text/logo validation and optimistic version requirement remain mandatory. This change introduces no patient consent override.

## Integrity, auditing and patient boundaries

Template maintenance acquires the shared clinic consent lock, owns its transaction and locks the active clinic row and current administrator account/role. The clinic coordination row also serializes template edits with receipt issuance. A stale version returns 409; business-audit failure rolls back contents, version and updater together. Lock contention returns 503 and nested caller transactions are refused without committing or rolling back the caller's work.

Successful responses include the authorized `clinic_id` alongside the saved version, so central access auditing references the clinic configuration. The canonical template path is recognized by the audit vocabulary. No issuer text, contact information or logo is copied into access-audit metadata. Submitted numeric patient/appointment IDs can appear separately as requested resources under the existing audit policy; they are never recorded as disclosed resources by this save. The existing API no-store and fail-closed audit behavior remains. Successful mutation business audits are atomic with the save; the central response audit remains a separate boundary.

Appointment receipt context, practitioner receipt-profile edits, receipt issuance, PDF downloads and voiding retain their patient-scoped consent checks. Unknown authenticated staff routes retain the conservative fallback. Template changes do not rewrite issued receipt snapshots/PDF bytes, profiles, invoices, payments, appointments, patient accounts, notices or consent instructions.

The portal continues to obtain receipt setup through an authorized appointment. This task adds no standalone template screen and does not expose restricted appointment details to reach configuration. Server-denied template controls are hidden; a rejected save shows the existing permission error and does not claim success. Refresh reloads the server's permissions.

## Validation and rollout

No new production migration or environment variable is required. Existing receipt tables/migration 043, staff role/identity foundations, consent migration 050 and access-audit storage remain prerequisites. Keep session receipts disabled until their existing rollout gates are satisfied. Reconcile administrators' persisted clinic role grants before rollout. Deploy the matching API after review and drain old API instances for consistent current-role enforcement.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-receipt-templates.php` for disposable local SQL/HTTP acceptance. The test retains the booking-contact and central access-audit regressions. Existing receipt and onsite-fee integration fixtures now seed the persisted administrator grant that their template setup requires.

Local validation on 20 October 2026:

- 86 new SQL/HTTP authorization checks passed on MariaDB 11.4.8: current account/role/clinic checks, direct-service and real authenticated API access, global/targeted patient restrictions, body-ID isolation, cross-clinic denial, version conflicts, input validation, audit rollback, patient receipt denials, immutable patient/financial records, lock contention and caller-transaction preservation. The run also passed 74 inherited booking-contact and 28 central access-audit checks.
- Existing SQL regressions passed: 31 session-receipt checks and 79 onsite-distance/waiver checks. Each of those runs also passed 34 recurring-booking checks. Receipt assertions include concurrent issuance and immutable PDFs.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new/updated integration PHP files and maintained PowerShell runner passed syntax checks.
- Three selected browser scenarios passed: the existing template/profile/issuance/void workflow, hidden controls when the server denies template authority, and a rejected template save followed by refreshed permissions.
- Full public, portal and application-admin production build passed, including TypeScript, content checks and 2,111 English/French translations. Existing bundle-size warnings remain.

All fixtures are synthetic and local; no real patient data, hosted database, private environment or external mail/provider operation is used. Hosted database/identity/concurrency acceptance and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open. The complete browser suite is outside this checkpoint.

Remaining conservative blocks include practitioner enquiries, room/location/service configuration and assignments, clinic branding/themes/booking settings, and reviewed reconciliation for client merges with any consent history. Each needs its own complete authorization and effects review before exemption.
