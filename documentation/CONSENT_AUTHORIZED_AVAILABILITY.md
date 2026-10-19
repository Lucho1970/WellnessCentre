# Consent-authorized recurring availability and one-time overrides

Recurring availability creation, update and archive, and one-time available/blocked override creation, update and deletion now authorize their affected appointment windows in `AdminService`, including direct service calls. These six routes no longer use the unrelated-patient blanket consent block.

## Authorization policy

Existing staff role, clinic, active-account, practitioner ownership, booking-mode and assigned-location checks remain. Ordinary practitioners manage only their own practitioner-managed profile at active assigned locations. Booking delegation does not widen this scope. Updates and removals also check the existing item's location assignment. Administrators retain their existing schedule scope but cannot override a patient's negative permission.

Before changing anything, the service checks every noncanceled appointment whose buffered interval overlaps the rule occurrence or override at the same clinic, practitioner and location. Both existing and proposed windows are checked on updates, including moves between practitioners or locations. Completed appointments still count; canceled appointments do not. A global or acting-staff restriction, or an unresolved/mismatched patient/service/location or merged duplicate reference, denies the entire operation with generic `schedule_access_restricted`. The response exposes no hidden identity, appointment ID, count or period. No partial change, appointment change or notification change occurs.

Unrelated restrictions, off-week occurrences, different locations and appointments outside the effective dates do not block an otherwise permitted change. Intervals are half-open and include booking buffers. Recurrence uses the location timezone, inclusive effective dates and the slot engine's Monday-of-effective-start-week phase. PHP local-time construction preserves the engine's daylight-saving behavior without requiring database timezone tables. Invalid calendar dates are rejected before normalization.

Open-ended rules have no artificial review horizon. The query selects only denied/unresolvable appointments' numeric IDs and buffer timestamps in batches of 256; every batch is considered. The overlap calculation selects the first possible recurrence arithmetically, rather than expanding an unbounded calendar. It does not load patient contact data or clinical contents.

This intentionally checks whole old/new windows, including notes-only or redundant overlapping-rule changes. It does not calculate the effective difference of the union of all schedule rules. Such cases can require an authorized privacy review. There is no bypass, emergency exception or review-approval endpoint in this change.

## Atomicity, audit and portal

Mutations hold the shared clinic consent lock and the existing booking-coordination clinic row lock, recheck the active staff account, and commit the schedule write and business audit together. Failed authorization, missing policy storage, lock timeout or failed audit leaves the mutation uncommitted. Nested direct-service transactions are rejected without rolling back the caller's transaction. Consent withdrawal waits for a protected operation to finish; already disclosed information cannot be recalled.

Denied attempts use the existing central access audit without disclosed patient resources. The portal shows the translated privacy-review message and clears selected details after authoritative removal failures. Public slot availability and booking conflict checks continue treating every occupied appointment as busy.

No schema migration is introduced. Existing migrations through 050 and the preceding access/consent changes are prerequisites. Deploy API and portal together and drain older API/worker versions before hosted acceptance. The single-primary advisory-lock namespace, transaction isolation, connection lifetime and performance with large schedules require verification on the intended host.

## Validation

Run `api/tests/integration/consent-availability.php` using `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-availability.php`. It uses a disposable local database, loopback HTTP, synthetic identities and synthetic appointments. No real patient data or external maps provider is used.

Local results on 19 October 2026:

- 138 new SQL/HTTP checks passed on MariaDB 11.4.8, including all six operations, old/new windows, global/named restrictions, restoration, positive ownership/location boundaries, buffers, recurrence phases, distant occurrences, more than 256 denied candidates, invalid references, audit rollback, lock contention and unavailable policy storage.
- The same completed run passed 74 booking-contact, 28 access-audit, 70 consent-directive, 59 dashboard/schedule, 61 notification, 94 travel, 107 time-off, 85 registration and 223 address-coverage checks. An earlier run stopped at the inherited schedule/delegation assertion after a database exception; that assertion and the full chain passed on rerun.
- All 52 root PHP scripts passed, including 20 recurrence-window checks covering timezone, daylight-saving, inclusive dates, week phase and long buffers. Syntax checks passed for 93 source/public files and the new integration source.
- Eight selected browser scenarios passed: rule-save and override-removal denials, existing availability editing, both time-off denials, time-off preview/save, own practitioner management and clinic-managed read-only behavior.
- Full public, portal and application-admin production build passed, including content, TypeScript and 2,078 English/French translations. Existing bundle-size warnings remain. The full browser suite was not rerun.

Hosted identity/database acceptance and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain outstanding. This change does not approve real-data use or certify compliance. Unclassified administrative routes and the reviewed migration requirement for client merges with consent history remain conservative.
