# Consent-authorized clinic locations

## Scope and authority

Location creation and updates previously inherited the conservative patient-consent block. Address updates could also delete all saved On-Site area approvals for a location without checking the affected patients or practitioners, and without an encompassing transaction. Location administration now has an explicit current-authority and patient-effects boundary.

The administrative list, create and update methods require an active staff account in an active clinic, a Super Admin role in the principal, and a persisted clinic-wide Super Admin grant (`user_roles.location_id IS NULL`). A Super Admin grant confined to one location is insufficient to administer the clinic's location catalogue. Clinic Admin, practitioner, reception, accountant, privacy-review permission and application/global administrator labels alone do not confer this authority. Central clinic administration retains its separate existing routes and permissions; no automatic local staff grant is created.

Only the existing `createLocation` and `updateLocation` route names are newly exempted from the blanket consent fallback. The already classified administrative list now rechecks current authority too. Reads return only locations in the acting clinic, with the API's no-store and fail-closed disclosure audit. Anonymous published catalogues retain their existing behavior. Unknown authenticated staff routes and unclassified room/service mutations remain conservatively blocked.

## Complete patient effects

Ordinary location metadata and future bookability maintenance do not read patient records or change existing appointments. Unrelated patient restrictions no longer prevent that configuration work or new location creation. Submitted clinic, client and location IDs do not redirect a routed operation or create patient relationships.

An address change checks every saved coverage approval at the location before deleting any of them. Both address and timezone changes also check every linked appointment because existing/historical booking views use that location context. The appointment check includes completed and canceled history; it is not limited to future appointments or a preview page. Address changes also preserve the existing all-approvals invalidation behavior, including approvals that used a practitioner's private origin.

For every affected approval or appointment, the clinic, patient, assigned practitioner/staff account and service must resolve within the acting clinic. Inactive patients/practitioners are not silently dropped from historical effects. The acting administrator and the associated practitioner must both be permitted by the patient's current staff-access directives. Global or targeted restrictions deny the entire operation with 403. Malformed or cross-clinic dependencies require privacy review with a generic 409. No patient identities, contact details, proof contents, record counts or partial effect inventories are returned on refusal. Rows are checked without a preview limit or inner joins that could hide an invalid record. Missing directive storage fails closed before patient-related effects.

These checks can legitimately prevent a move or timezone correction at a location with a restricted linked record; that change needs authorized privacy review. This task adds no consent override, partial revocation or silent skip of restricted records. Public-name/phone-only edits and future bookability settings remain separate from address/timezone effects.

## Transactions and auditing

Location methods use the shared current-clinic administration transaction helper, renamed from its practitioner-only name. Existing practitioner operations retain their prior policy; the clinic-wide grant requirement is explicit for the three location methods. Each operation owns its transaction, acquires the clinic consent lock, locks the active clinic/current account, and checks the stored role. Updates lock the location and complete dependency sets. The clinic coordination row also serializes app booking/coverage operations with configuration changes.

An allowed address change commits the new location, all approval deletions, one client-scoped revocation audit per deleted approval, and the location audit together. If any business audit fails, all those changes roll back. Creation and its business audit are atomic as well. Lock contention returns 503; nested caller transactions are refused without committing or rolling back the caller's work.

Successful changes do not rewrite UTC appointment times, appointment statuses, historical prices, availability rules, patient addresses, consent directives, practitioner assignments or notification rows. Approvals at unrelated/foreign locations remain untouched. No external address/map provider is called. The dependency check selects IDs and relationship fields rather than clinical notes or destination snapshots. Revocation audits contain the patient reference, location/practitioner/service references and a fixed reason; they do not copy addresses or validation hashes. Central response audits identify disclosed location IDs; supplied numeric patient IDs remain separate requested references under the existing audit policy.

Optional fields omitted from an update retain their stored values, so a metadata-only PATCH does not accidentally clear an address or re-enable a disabled location. Name and timezone remain required by the existing update contract. Explicit null/empty optional address fields still clear those fields and trigger the same authorization checks. Creation now honors an explicit false bookability choice. String/boolean validation rejects coerced arrays, control characters and false-like strings. The location name limit is aligned with the existing 120-character database column and portal input, including Unicode names.

## Portal and rollout

Location refresh/load failures clear cached configuration and editing drafts. A 401/403/404 save refusal closes the drawer, removes the selection and disables creation until an authorized reload succeeds. Validation errors keep their field details and draft in the drawer. A successful save followed by a denied reload does not show a false success message. Request generation checks prevent older loads or saves from repopulating the current view. Retry restores controls only after the server permits a fresh list.

No production migration or environment variable is introduced. Existing clinic role/identity, booking/coverage, consent and audit foundations remain prerequisites. Reconcile administrator clinic-wide role grants and review malformed legacy location dependencies before rollout. Deploy the matching API and portal after review and drain older API instances so all address changes use the same transaction and authorization boundary. Hosted data acceptance and the broader [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain outstanding.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-locations.php` for disposable local SQL/HTTP acceptance. Local validation on 20 October 2026:

- 197 new SQL/HTTP authorization checks passed on MariaDB 11.4.8: current account/clinic/role and clinic-wide scope, all three administrative operations, complete 31-record approval effects, actor/global/recipient restrictions, historical canceled appointments, malformed cross-clinic references, missing directive storage, audit rollback, preserved omitted fields, body-ID isolation, denial auditing, patient-boundary preservation, lock contention and nested caller transactions. The same run passed 74 booking-contact and 28 access-audit checks.
- The shared administrator-helper regression passed 147 practitioner-management SQL/HTTP checks, with its inherited 74 booking-contact and 28 access-audit checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. New integration PHP and the maintained PowerShell runner passed syntax checks.
- Five selected browser scenarios passed: existing location/room creation/editing panels, drawer field validation, 403 and 404 save-denial cleanup/recovery, and a committed save followed by a denied reload without a false success message.
- Full public, portal and application-admin production build passed, including TypeScript, content checks and 2,111 English/French translations. Existing bundle-size warnings remain.

All fixtures are synthetic; no real patient data, private environment, hosted database or external provider is used. The complete browser suite is outside this checkpoint.

Remaining conservative blocks include room/service configuration and assignments, clinic profile/branding/themes/booking settings, and reviewed reconciliation for client merges with any consent history.
