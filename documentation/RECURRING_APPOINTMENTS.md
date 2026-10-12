# Recurring appointments

Implemented on `feature/recurring-appointments`. Not merged or deployed. This implements SCH-09 and the next Phase 5 scheduling slice. Database and hosted acceptance are distinct from the local PHP and browser checks below.

## Booking a series

Administrators, reception, authorized practitioners, and linked clients use the existing booking workflow. Choose the client/care, visit address where applicable, an available first time, and a room where required. On **Review and confirm**, eligible services show **Repeat appointment**. Ineligible services keep single booking.

Choose weekly, every two weeks, or monthly and either a count (including the first appointment) or an inclusive end date. There must be 2–26 appointments within one year, and every occurrence must also fit the service's current booking horizon and lead time. Default: six appointments. Monthly recurrence is anchored to the original day: January 31 becomes February's last day, then March 31. The location's wall-clock time is preserved across daylight saving changes. A later missing or ambiguous local time is a conflict; it is never silently shifted. The first time retains the explicit offset selected from availability.

**Preview all dates** validates each occurrence through the ordinary booking writer: active clinic/client, actor/practitioner permissions, service/duration/location assignments, delivery/coverage, current quoted prices, hours/exceptions/time off, buffers/travel, room suitability/turnover and conflicts. The preview shows local dates, before-tax subtotals, and conflicts. It runs in a transaction that is always rolled back; queued notifications and audit entries are rolled back too. It allocates no usable appointment IDs and dispatches no messages. Auto-increment gaps after previews are normal.

**Confirm entire series** validates the entire request again under the shared clinic booking lock and checks that the reviewed dates/prices/results still match. All appointments, their series association, history, audit and queued reminders/notifications commit together. Any conflict saves none of the series. There is no “book available dates” partial-save option. The result lists every saved appointment number before **Done** returns to the schedule. Notification delivery itself remains asynchronous; it is not guaranteed by a booking commit. Confirmation/reminder emails are per appointment, not one aggregate email.

An uncertain confirmation locks edits and retains the same idempotency key for **Retry series confirmation**. The server records the completed request's actor, hash and result, so a replay does not create duplicate bookings or notifications. Authorization is checked again before returning its saved result. New keys represent new requests; after leaving/reloading an uncertain request, inspect the appointment list before starting another series.

## Changing one visit or the series

Schedule lists identify the recurring series. Ordinary **Reschedule**, **Cancel appointment**, and administrator **Change practitioner** continue to affect one appointment only. They preserve its series association. Existing email links also identify the series, but opening a link changes nothing.

**Manage future series** is a separate staff/client action. It lists all future active appointments in that clinic-local series and authorizes every appointment, including individually reassigned visits. Past, ongoing, completed and canceled appointments are excluded. More than 26 future appointments in a legacy series requires clinic review.

- **Cancel all listed appointments:** clients review and acknowledge the policy fee for each appointment. Staff series cancellation is clinic-initiated and charges no client cancellation fee; individual administrator fee controls remain available through single-appointment management.
- **Reschedule all listed appointments:** select a new available time for each listed visit. The service, duration, practitioner, location, delivery/address, room and price snapshots are preserved. Times offered must match the existing duration and room. The series pattern remains the original booking pattern; the saved appointments are authoritative after explicit changes.

Both actions require **Preview series changes** followed by **Confirm entire series**. The exact ordered appointment IDs, versions, fees and proposed times are reviewed. A changed set/version/fee requires reloading and reviewing; a new slot conflict rolls the whole operation back. Rescheduling is conservative: it does not free other existing occurrences to swap their times. There are no implicit replacements, regeneration of visits, or series-wide practitioner reassignment. Individual price/room/practitioner changes still use the existing authorized appointment controls.

## API and storage

Staff routes:

| Method | Route | Purpose |
|---|---|---|
| POST | `/api/v1/recurring-series/preview` | Preview new series |
| POST | `/api/v1/recurring-series` | Confirm reviewed series |
| GET | `/api/v1/recurring-series/{id}` | Review future active occurrences |
| POST | `/api/v1/recurring-series/{id}/preview` | Preview explicit cancel/reschedule |
| POST | `/api/v1/recurring-series/{id}` | Confirm reviewed changes |

Linked clients use equivalent routes under `/api/v1/customer/`. Their `client_id` is derived from the signed-in account; submitting it is rejected. Existing single-booking routes reject a `recurrence` field instead of ignoring it. Single-update routes reject a series `scope` instead of silently applying it to one visit.

New-series bodies contain the ordinary booking payload, a request `idempotency_key`, and `recurrence: {frequency, count}` or `{frequency, until}`. An apply body additionally includes the returned `preview_token`. Change bodies contain `action`, optional `reason`, `idempotency_key`, and every returned occurrence's `appointment_id`/`version`, with `expected_cancellation_fee_cents` for cancel or an ISO start including timezone for reschedule. Results expose `ready`, `applied`, `timezone`, `items`, and a review token; `ok` in a preview means validation succeeded, not that an appointment was saved.

Migration **035_recurring_booking_requests.sql** adds clinic/timezone metadata to the existing `recurring_series` table, backfills legacy clinic ownership from its client, and creates `recurring_booking_requests` for completed request replay. Existing `appointments.recurring_series_id` is reused. The request ledger stores a hash and minimal result, not raw coverage proofs, addresses, credentials or full request bodies. Single operations preserve their existing transaction behavior; series writers participate in an outer transaction with per-occurrence savepoints and defer immediate notification dispatch until the whole series commits.

## Deployment and acceptance

No new `.env` setting is required. Keep the existing practitioner invitation and membership rollout flags unchanged.

1. Back up the database. Confirm migrations through 034 are present. Rehearse 035 on a disposable copy, then apply 035 once before deploying this feature's API/portal code.
2. Deploy the versioned private API and Willow portal builds from the reviewed source revision. The public website and neutral `portal.copihue.ca` landing need no changes for this feature. The follow-up [release guide](RECURRING_APPOINTMENTS_RELEASE.md) describes the prepared package and upload order; package creation is separate from deployment.
3. In **Services**, enable **Recurring bookings** only for the services being tested. This setting already existed; it now enables the recurring booking control.
4. Use synthetic clients to create a weekly series across a daylight saving boundary; inspect all dates and their local times. Check monthly month-end and end-date/count limits.
5. Occupy one of the future dates. Confirm the preview identifies it and saves nothing. Introduce a conflict after a successful preview; confirmation must still save none. Test rooms, time off, mobile coverage, horizons and changed prices.
6. Retry a completed confirmation with the same key. Confirm the same IDs return without extra appointments, histories or notification events. Test concurrent single/series confirmations on independent connections, including empty schedules and cross-location practitioner/room conflicts.
7. As a client, change one occurrence and verify the others are untouched. Review/cancel the future series with individual fees. Change a version or fee between review and apply; require renewed review. Reschedule the future series; verify one conflict leaves every visit unchanged.
8. Verify other clients/clinics and unauthorized staff cannot view/change/replay a series. Test practitioner-managed versus clinic-managed access and an individually reassigned occurrence. Inspect reminders, history and ordinary email-link invalidation after committed version changes.
9. Confirm mobile English/French screens and recovery states. For rollback, turn off recurrence eligibility before reverting the portal/API. Retain migration 035 and existing records; do not drop the request ledger or series/appointment data.

Local validation on 6 October 2026:

- `npm run build`: both production builds, TypeScript, four content entries and 1,559 bilingual keys passed (existing bundle-size warnings remain).
- All 46 top-level PHP test files passed, including 35 recurrence/calendar/transaction coordination assertions using a PDO double. Changed PHP files and the integration script passed syntax checks.
- 123 mocked browser scenarios covered: 122 passed in the final full run; its time-off fixture had expired that day, so its clock was fixed and the repaired scenario passed a targeted rerun. The nine recurrence scenarios passed, including per-visit rescheduling, fee changes and an incomplete confirmation response.
- All 13 production-build browser checks passed against the final build. `git diff --check` passed.

 `api/tests/integration/recurring-bookings.php` rehearses migration 035 with legacy data and exercises actual booking writers, preview/failed-apply rollback (including notification/audit rows), replay, eligibility/isolation, single versus series operations, a slot taken after preview and changed cancellation fees. It uses an explicitly authorized random localhost database, never the application `.env` or hosted client data. Run with `RECURRING_TEST_ALLOW_CREATE=true`; optional `RECURRING_TEST_PORT` defaults to 13317, `RECURRING_TEST_USER` to root, and `RECURRING_TEST_PASSWORD` to empty. The synthetic database is retained for inspection.

### Repeatable local SQL rehearsal

The follow-up branch `feature/recurrence-sql-validation` adds a Windows portable MariaDB runner and independent PHP workers for simultaneous confirmations. It creates a fresh local data directory for every run, binds only to `127.0.0.1`, refuses an occupied port, does not install a Windows service, and stops its server in `finally`. The synthetic database and server logs remain in ignored `.tmp/recurring-sql-<random>/` for inspection. It never loads the application `.env` or connects to Netfirms.

1. Extract a Windows x64 portable MariaDB ZIP to an ignored local directory. The validated runtime was [MariaDB 11.4.8 from the official archive](https://archive.mariadb.org/mariadb-11.4.8/winx64-packages/); its ZIP SHA-256 was `ed86e93157af46317bb49161451c2ec258498a6fa8e68ca821ef1d780d855e6b`, matching the archive checksum. Runtime binaries are not committed.
2. With PHP CLI and the existing `api/vendor` dependencies available, run from the repository root:

   ```powershell
   ./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64
   ```

   `-Port` selects an unused localhost port (default 13317); `-PhpExecutable` selects another PHP CLI. The script restores its temporary test environment variables after success or failure.
3. Retain the final check count, database version, source revision and local log directory with the review evidence. The simultaneous-request checks require PHP `proc_open`: both workers open separate connections, signal readiness, and receive a common release before confirming. Identical requests must return the same series and create one set of appointments/notifications; competing keys for the same dates must produce one winner, a complete conflict report for the loser, and no partial extra appointments or ledger rows.

Local follow-up evidence on 6 October 2026: **34 real SQL checks passed on MariaDB 11.4.8**, including migration 035, rollback/replay/fee checks and both simultaneous-confirmation scenarios. Synthetic database `wellness_recurring_test_20d3d1118490` remains in `.tmp/recurring-sql-9f81c2ca9b3d44baad139a0c09af677d/data`; the temporary server was stopped. All 46 top-level PHP tests and both integration-file syntax checks passed; the runner also refused an occupied port. The first concurrent harness attempt blocked on Windows pipe reads; the successful rerun used readiness/output files with bounded process polling. Frontend source was unchanged, so its previously recorded build/browser evidence was not rerun for this test-tooling change.

**Outstanding:** local MariaDB rehearsal does not establish compatibility with the exact hosted MySQL version or its operational configuration. Hosted migration, broader booking concurrency/isolation acceptance, notification delivery and owner acceptance remain required before rollout is considered complete. No feature code or migration has been deployed during this follow-up.
