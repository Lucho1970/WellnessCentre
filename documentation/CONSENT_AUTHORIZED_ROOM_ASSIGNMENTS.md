# Consent-authorized room capabilities and practitioner assignments

This change classifies the five room-capability and practitioner-restriction routes independently of the blanket staff consent fallback. Every read and mutation requires a staff principal with a current active local account, active clinic and persisted **clinic-wide Super Admin** role. Clinic administrators, location-scoped grants, stale tokens, clients and application administrators cannot use these routes. Patient access remains separately authorized.

## Configuration and mutation boundaries

Catalogs include only current-clinic capabilities and assignments whose capability/practitioner belongs to the same clinic. Invalid stored cross-clinic relationships are omitted from reads and require privacy review before replacement. New practitioner selections must be active local staff practitioners; this does not create a practitioner/location relationship or bypass independent booking checks.

Replacement commands require positive integer target IDs and explicit arrays of positive integer IDs. Omitted arrays, malformed values, foreign/missing capabilities and foreign/missing practitioners cannot silently clear existing policy. Duplicate IDs are normalized. Room-only and service-only commands are supported. Combined commands validate and authorize both targets before either writes. Empty arrays explicitly clear the selected policy. Capability names have bounded length, control-character validation and a bounded unique clinic-local generated code.

Every changed room assignment checks the complete noncanceled appointment scope for that room. Every changed service requirement checks the complete noncanceled appointment scope for that service across locations, including bookings with no room. Checks include historical and inactive-subject records and have no preview limit. The actor and associated practitioner must both be permitted under current patient staff-access instructions. Malformed clinic/client/staff/service/location/room relationships fail closed with a generic review conflict. No patient inventory is returned in a refusal. Unrelated restrictions, patient-free catalogs and unchanged assignment sets remain usable. This is a deliberately scope-based authorization policy: even an equipment change that leaves a particular booking eligible requires authorization for that booking when its room/service policy changes.

The clinic consent advisory lock and an owned SQL transaction cover authority checks, validation, current consent checks, replacements and business audits. Audit failure rolls back all changes. Nested caller transactions are refused without rolling back the caller. API access/denial events include typed room, service, practitioner and room-capability references without configuration notes or raw request data. Clinical rows and consent directives are never rewritten. No external provider is called.

## Booking behavior and deployment review

The prior writer stored selected practitioners with `allowed=1`, but the room availability query only checked `allowed=0`; selections therefore did not exclude unselected practitioners. Booking availability now interprets any positive selection set as a room allowlist. A practitioner must be selected, and an explicit deny still wins. With no positive selections the room is open except for explicit denies. This applies dynamically to new practitioners too; it does not materialize a list of current excluded accounts.

**Before deployment**, review existing positive room selections with clinic operators, including inactive/foreign relationships and rooms used by other practitioners. Existing bookings are not automatically reconciled, moved or canceled. Existing assignments now affect future booking eligibility as intended; review current bookings operationally. No migration is required. Empty-list clearing is a privileged audited replacement and cannot bypass consent checks for affected bookings.

The capabilities editor clears catalogs, draft names and selections after failed reloads or 401/403/404 mutations. Controls stay disabled until an authorized retry succeeds. A successful mutation followed by a denied reload does not display success. Validation/review errors retain editable drafts.

## Validation and remaining gates

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-room-assignments.php` for disposable synthetic SQL/HTTP checks. The suite exercises persisted roles, tenancy, complete appointment scope, actor/practitioner/global restrictions, invalid IDs, combined rollback, audit failure, malformed stored references, current route authorization, typed audit resources and actual booking allowlist behavior. The room/location fallback probes use tax creation after the subsequent service authorization change.

Validated locally on 20 October 2026:

- New assignment SQL/HTTP suite: 187 checks; room regression: 244 checks. Each also passed inherited 74 booking-contact and 28 access-audit checks.
- All 53 root PHP test scripts and syntax checks for 95 PHP files passed.
- Full public, portal and application-admin build passed, including TypeScript, four bilingual content entries and 2,113 translation checks. Existing bundle-size warnings remain.
- Seven selected browser cases passed: two new capability-denial/reload tests, four room authorization cases and the room/location list workflow.
- Review base: `feature/consent-authorized-rooms` (`56520e1`); this branch builds on that unmerged checkpoint. Local checks do not constitute hosted database acceptance or PHIPA certification. Before real patient data, rehearse these workflows and consent locking on the hosted database, review existing assignment semantics, and complete the operational gates in [the readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md).

Remaining conservative configuration groups include service configuration/assignments and clinic profile, branding, themes and booking settings. Client merges with any consent history still require reviewed reconciliation. Unknown staff routes retain the fail-closed fallback.

Subsequent service work: [service configuration and assignments](CONSENT_AUTHORIZED_SERVICES.md) now have current clinic authority, complete booking/series and approval-effect checks, atomic replacement and individual approval revocation audits. Tax creation and clinic booking defaults remain separately blocked.
