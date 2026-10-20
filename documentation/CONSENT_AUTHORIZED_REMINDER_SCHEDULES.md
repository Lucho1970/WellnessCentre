# Consent-authorized reminder schedules

Reminder settings now have their own authorization in `ReminderScheduleService`. The list, create and active-toggle routes no longer use the unrelated-patient blanket consent block. Existing Clinic Admin/Super Admin roles remain necessary; privacy-review permission, practitioner, reception, accountant and global/application-admin status do not grant reminder administration.

## Patient-free settings and affected queue scope

Listing settings returns only the current clinic's email reminder schedule IDs, lead times and active flags. The service checks the active local staff account, current administrator role and active clinic, including direct service calls with an old principal. Submitted clinic IDs cannot select another clinic. Unsupported-channel, foreign and missing schedule IDs are concealed. The existing offered times, duplicate protection, boolean validation and three-active-times limit remain.

Creating or enabling a timing normally affects future booking/appointment-change queue generation. It can also change eligibility of legacy work already in the queue. Every create/toggle therefore checks all matching `queued`, `failed` and `sending` email appointment reminders in this clinic, including repeated toggles and already-disabled settings. There is no preview/page-size cap. A worker may claim a message before acquiring its consent lock, so `sending` belongs in the authorization set.

Each covered event must resolve to an appointment, its client recipient, practitioner staff account, service and location in the same clinic, with no merged-duplicate client reference. A global or acting-administrator `staff_access` restriction, or an unresolvable/mismatched reference, refuses the whole operation with generic `reminder_schedule_access_restricted`. No patient identity, destination, notification IDs, affected count or payload is returned. Empty timings and unrelated patient restrictions do not prevent otherwise authorized settings changes.

The operation is not a filtered partial cancellation. When authorized, disabling cancels **all** matching queued/failed reminders and clears their retry time; claimed messages remain owned by their worker. Different clinics, timing values, channels, event codes and terminal/manual-review states are unchanged. Enabling never recreates canceled messages, and adding a time does not backfill existing appointments. Workers still independently enforce current patient communication instructions, recipient validity, appointment state/version, staleness and active schedule before composing or sending. A completed/provider-accepted send cannot be recalled by changing a setting.

## Exact timing parsing

The settings authorization/cancellation SQL and worker use equivalent strict timing rules: an offered integer or its exact decimal string (`60`, `180`, `1440`, `2880`, `4320`). Normal queue generation may serialize bound integers as strings, so both canonical forms are accepted. Floats, booleans, arrays, exponential notation, leading zeroes, signs, whitespace, suffixes and unsupported timings are not eligible. This closes PHP-versus-MySQL numeric-coercion differences that could otherwise make a worker eligible for work missed by a timing authorization predicate.

Malformed legacy timing payloads are excluded from settings effects because the worker now cancels them before message preparation/provider access. They are retained as canceled rows, not rewritten or automatically regenerated. Review legacy/custom queue values and rehearse this behavior before rollout; normal API-generated offered timings remain supported.

## Transactions, audit and portal behavior

Mutations acquire the shared clinic consent/worker lock, start their own transaction, lock the active clinic coordination row used by bookings, and recheck the account/role. Policy storage must be available even for empty mutations. Booking writes cannot add an unreviewed matching reminder during that transaction; workers serialize preparation/dispatch through the shared consent lock. A worker that completed before the configuration acquired its lock is outside the new setting's effects. Direct callers cannot nest these operations inside a caller-controlled transaction; rejection preserves that transaction.

Settings, queue cancellations and business audits commit together. Cancellation audits contain every changed notification ID in chunks of 200, without patient contacts or message payloads. The API's central trail records list/response schedule IDs under `reminder_schedule` and records denied attempts. Audit failure rolls back the whole mutation, including earlier cancellation audit chunks. Missing policy storage or lock contention cannot partially change settings or queue rows. The settings list is patient-free and remains readable under unavailable consent storage if ordinary account/clinic authorization succeeds.

The portal shows the translated generic review message. Authoritative 401/403/404 failures discard cached reminder controls and selected notification details, refresh the consent-filtered notification list, and require a successful settings reload before another change. This cleanup does not erase previously viewed information or replace server authorization.

## Rollout and validation

No new schema migration or environment variable is introduced. The existing reminder/queue tables and preceding access-audit/consent changes are prerequisites; retain migrations through the current source baseline (051). Deploy matching API, notification worker and portal together and drain old worker/API versions before acceptance. Review current queue representations; old workers must not continue using permissive numeric casts. Keep private environment files and records intact. The shared-lock namespace, worker/booking concurrency, database JSON behavior and representative queue-size performance need hosted verification.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-reminder-schedules.php` for disposable local SQL/HTTP checks. It retains synthetic booking/access-audit regressions. Worker tests use an uninitialized mailer sentinel that cannot initialize a provider transport; no real patient data, private environment, production authentication bypass or provider send is used.

Local validation on 19 October 2026:

- 329 reminder SQL/HTTP checks passed on MariaDB 11.4.8, including 206 individual pending-row cancellation checks, complete chunked audit references, permission restoration, global/named restrictions, claimed work, invalid references, timing/channel/clinic boundaries, worker suppression, active-account/role checks, audit rollback, lock contention and missing policy storage. The same final run passed 74 booking-contact and 28 central access-audit checks.
- All 53 root PHP scripts passed, including 26 strict timing-payload checks. Syntax checks passed for 94 source/public files and the new integration source; the SQL runner's PowerShell syntax passed.
- Three selected browser scenarios passed: denied reminder creation, denied disabling, and the existing privacy-history filtering/pagination/revocation flow. The denial cases verify controls cannot be reused until a successful reload. Privacy history's resource filter now includes `reminder_schedule`.
- Full public, portal and application-admin production build passed, including TypeScript, content and 2,110 English/French translations. Existing bundle-size warnings remain; the full browser suite was not rerun.
- Initial fixtures were corrected to preserve JSON floating-point values and to click a controlled switch whose rejected update intentionally leaves its state unchanged. Another run stopped in the inherited audit-storage failure assertion after a database exception; the expected fail-closed behavior passed in the final full run.

Hosted acceptance, infrastructure/audit retention controls and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open. This change does not approve real-data use or certify compliance. Unclassified configuration/widget routes and consent-history merge handling remain separate follow-ups.
