# Consent-authorized service configuration and assignments

Service lists, catalogue settings, service creation/editing, category creation/editing and service assignment lists/replacements now require a staff principal with an active local account, an active clinic and a persisted clinic-wide Super Admin role. Token roles alone, location-only grants, Clinic Admin, practitioner, reception, client, application administrator and foreign-host context do not grant this authority. The exact route names are classified in ConsentAccess; other configuration routes remain fail closed. Tax creation and clinic booking defaults remain for separate review.

## Patient-free configuration

Service/catalogue lists return only clinic-owned configuration. Assignment lists additionally require each practitioner user or location to belong to the service clinic; legacy foreign references are hidden. Categories contain public presentation text only. Creating a service or category creates no patient relationship and does not grant access to bookings, contacts, forms or receipts. Optional initial practitioner IDs must be an explicit list of active local staff practitioners; missing/foreign/inactive IDs cannot silently disappear. Creation honors explicit inactive state.

Service edits lock the owned target and preserve omitted values and active duration options. Explicit duration changes reuse existing duration IDs and deactivate removed options instead of deleting referenced history. Visit modes, flags, identifiers, durations, prices and travel settings reject malformed/coerced values before writing. Bounded text and numeric limits protect configuration storage. Assignment commands require both explicit practitioner and location lists; empty lists intentionally clear the corresponding active assignments. Duplicate practitioner entries, invalid visit modes and foreign/missing IDs are rejected rather than skipped. Existing price overrides are preserved.

## Patient effects

Every service edit authorizes the complete appointment history and recurring-series history for that service, including canceled bookings and inactive patients/practitioners. Past appointments display the current service name, so even a descriptive edit has a patient-history effect. There is no result cap or inner join that drops malformed patient links. Each record must belong to the clinic and resolve a local client and staff practitioner; appointment locations and optional rooms must be consistent. Malformed relationships require a generic privacy-review conflict.

A changed assignment command authorizes all noncanceled appointments, active recurring series (including series without generated appointments), and every saved On-Site coverage approval for the service before any assignments change. Both the acting administrator and each stored practitioner must be permitted under current staff-access instructions. Global restrictions apply. Unrelated patient restrictions do not block patient-free work or work on other services. This is a scope-based policy: an assignment replacement checks the complete affected service scope even if some bookings remain eligible afterward.

Identical normalized assignment retries preserve coverage approvals and succeed without relying on the database changed-row count. Changed assignments retain the existing behavior of invalidating every saved approval for that service, after explicit authorization; each revoked approval now receives an individual client-scoped `onsite_area.revoke` audit. No administrator override is introduced. Missing consent storage or a busy consent lock prevents patient-affecting changes.

The clinic consent advisory lock and an owned transaction cover persisted authority, validation, complete consent checks, replacement and business audits. Any audit/write failure rolls back configuration, duration options, active assignments and approvals together. A caller-owned transaction is refused without rolling back that caller. Booking times, amounts, cancellation snapshots, room references, recurring records and consent directives are never rewritten. No maps, identity or messaging provider is called.

## Frontend and access history

The service editor clears cached service/category lists, drawers, drafts, selections and assignment dialogs after failed loads or 401/403/404 save refusals. The assignment editor clears its catalogues and drafts on failed loads and permission/target refusals. Actions remain disabled until authorized retry succeeds. A successful save followed by a denied reload does not display success. Validation and review conflicts preserve the draft. English/French review messages explain the refusal.

API read/response/denial audits include typed service, category, tax, practitioner and location references. Request target IDs remain distinct from disclosed resources. Configuration descriptions, patient inventories and raw drafts are excluded from access-audit metadata.

## Validation and rollout

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-services.php` for a disposable synthetic SQL/HTTP fixture. It covers persisted roles, revoked/inactive authority, tenancy, invalid replacements, late restricted bookings, historical records, approval-only patients, recurring-series-only patients, malformed references, atomic audit failure, preserved duration IDs, individual revocation audits, actual API routes and patient access remaining restricted.

Completed local checks on 20 October 2026:

- Service SQL/HTTP acceptance: 365 checks; room-assignment regression: 187 checks; room regression: 244 checks; location regression: 197 checks. Each fixture also passed its inherited 74 booking-contact and 28 access-audit checks.
- Two additional SQL compatibility checks passed for a clinic-only zero free-travel threshold without a radius and its no-op retry preserving a restricted approval. The maintained service fixture includes that legacy zero-threshold shape.
- All 53 root PHP test scripts passed; the access-audit policy suite includes 57 checks after the catalogue additions.
- Syntax checks passed for 95 PHP files and the PowerShell SQL runner.
- Full public, portal and application-admin frontend build passed, including TypeScript, four bilingual content entries and 2,114 English/French translation checks. Existing bundle-size warnings remain.
- Six selected browser cases passed: service list/create/assignment workflow, three service refusal/recovery cases and two assignment denial/reload cases. Two initially ambiguous button selectors were corrected and the affected cases rerun.

Review base: `feature/consent-authorized-room-assignments` (`b57ac05`). This task builds on that unmerged checkpoint. No migration is required. Before deployment, rehearse current staff-role resolution, advisory locking and authorized assignment/approval revocation on the hosted database. Review any malformed legacy associations through the clinic's controlled process. Local checks do not establish hosted acceptance or PHIPA certification; the [readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md) still governs real-data release gates.

Remaining conservative groups include tax creation, clinic profile, branding, themes and booking defaults. Client merges with any consent history still require reviewed reconciliation. Unknown staff routes retain the fallback.

Subsequent configuration work: [tax creation and clinic booking defaults](CONSENT_AUTHORIZED_TAX_BOOKING_SETTINGS.md) now have current clinic authority, bounded validation and atomic audits, without propagating configuration into existing patient records. Clinic profile, branding, theme and welcome routes remain separately blocked.
