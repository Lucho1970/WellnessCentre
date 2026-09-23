# Booking confirmation checkpoint — 2026-09-15

No database migration is required. Deploy the private API while preserving its `.env`.

Confirmation now validates the requested time through the same availability search used to offer slots. This covers recurring hours, extra openings, time off, blocked periods, buffers, booking windows, existing appointments, room capabilities, restrictions, and turnover. The API verifies active clients and bookable locations belong to the signed-in clinic. Practitioners without administration/reception roles can book only their own schedules. The server assigns the booking source.

Every confirmation locks the existing clinic row before any snapshot reads. Confirmations within a clinic execute sequentially through validation and insertion; this deliberately favors correctness over throughput for the initial single-clinic deployment. Future appointment writers must follow this lock convention. Schedule and catalogue administration do not yet take this lock, so simultaneous administrative rule changes require additional coordination in a later checkpoint.

An exact retry from the same actor with the same idempotency key returns the original appointment. A changed client, practitioner, service, duration, room, location, start time, or actor returns HTTP 409 without exposing the original appointment.

## Local verification

Run from `api`:

```powershell
composer run lint
php tests/booking-request.php
php tests/schedule-intervals.php
php tests/dst-availability.php
```

These tests cover request validation, timezone conversion, replay isolation, and interval calculations. They do not exercise MySQL locking. A local MySQL server was unavailable and the Docker engine was not running during this checkpoint.

## Development database acceptance

Use development clients and a practitioner with configured working hours. These tests create real appointment records and queue notification events; do not run against a live clinic.

1. Search availability and submit a returned slot, its duration option, and one returned room ID (omit room for a service that does not require one). Expect one confirmed appointment and one notification event.
2. Repeat the identical request and key. Expect the same appointment ID and no additional history or notification rows.
3. Change the start time or client but keep the key. Expect 409. Repeat using a different actor: expect 409.
4. Submit a time outside working hours, during time off, or at a non-slot boundary. Expect rejection with no appointment inserted.
5. Submit an unsuitable room or a room occupied during turnover. Expect rejection.
6. Submit a client or location from another clinic. Expect rejection.
7. As a practitioner, attempt to book another practitioner's schedule. Expect 403.
8. From two independent authenticated sessions, submit the same free slot concurrently using distinct keys. Expect one success and one 409, with exactly one appointment in MySQL. Repeat with different practitioners competing for the same room and with the same practitioner across two locations.

An automated version of step 8 is in `api/tests/integration/booking-race.php`. It does not load the application `.env` or use any existing clinic record. Configure `BOOKING_TEST_DB_HOST`, `BOOKING_TEST_DB_PORT` (optional, default 3306), `BOOKING_TEST_DB_NAME`, `BOOKING_TEST_DB_USER`, `BOOKING_TEST_DB_PASSWORD`, and `BOOKING_TEST_CONFIRM` (the exact database name) as environment variables; keep credentials out of chat, source control, and shell history. Run `php api/tests/integration/booking-race.php` from the repository root with a PHP CLI that supports `proc_open`.

- **Preferred:** use an empty disposable database whose name contains `booking_test`. The default `BOOKING_TEST_MODE=empty` refuses any nonempty database and installs the fresh schema there.
- **Current development database:** set `BOOKING_TEST_MODE=isolated-clinic` and `BOOKING_TEST_EXISTING_ACK=synthetic-clinic-only`. This explicitly permits a populated database, but the harness creates a uniquely named synthetic clinic, users, practitioners, service, rooms, and appointments. Test email addresses are deliberately invalid, so the mail client cannot submit them to Graph. On completion or normal PHP shutdown, the harness cancels remaining synthetic notification events and marks the synthetic clinic inactive. It does **not** delete any records, and it never selects or modifies an existing clinic. Note the reported synthetic clinic ID for later inspection or carefully scoped cleanup.

The harness races independent connections for the same practitioner, the same room, an identical idempotent retry, and one practitioner across two locations, then checks appointment/history/notification counts. It creates test data, so back up the development database first and run during a quiet testing window. If the process is forcibly terminated or reports that deactivation failed, the synthetic clinic may remain active; identify it by the `Synthetic booking race` name, cancel its notifications and deactivate only that clinic before continuing.

Concurrency acceptance remains pending until this harness passes on real MySQL. Local PHP syntax checks alone do not close the gate. If the hosting CLI disables `proc_open` or remote MySQL access is unavailable, run the original two-session manual step 8 with synthetic records in a dedicated test clinic and record the results.

### Run the automated test through Netfirms PHP

If the development database is reachable only from Netfirms, a temporary [web entry point](../api/tests/integration/booking-race-web.php) can run the same four-scenario harness there. It starts **two independent PHP booking workers** for each race; it does not use a browser token or call the public API. It therefore validates the booking service and MySQL locking, while the normal portal journeys still need separate testing. The private API deployment archive does not include tests, and the public archive intentionally does not expose this runner.

1. Back up the development database. Upload `api/tests/integration/booking-race.php` and `booking-race-worker.php` to `/wellness-api/tests/integration/` (create those private directories if needed). Upload `booking-race-web.php` to `/public_html/wellness/api/booking-race-web.php` **only for this test**.
2. Generate a unique random secret of at least 32 characters and add `BOOKING_RACE_WEB_ENABLED=true`, `BOOKING_RACE_WEB_SECRET=<secret>`, and `BOOKING_RACE_WEB_DATABASE_ACK=<exact DB_NAME>` to the private `/wellness-api/.env`. Confirm that `DB_NAME` is the **development** database before acknowledging it. Do not put the secret in Git, chat, a URL, or a screenshot. If Netfirms' web PHP binary is not a CLI executable, set `BOOKING_TEST_PHP_CLI` in the same file to the host's PHP CLI binary path.
3. Open `https://wellness.copihue.ca/api/booking-race-web.php`. Enter the secret in the form and run once. A pass reports four scenarios, four appointments, one history and notification each, and the new synthetic clinic ID. The test clinic is then marked inactive and its queued notifications canceled; test records remain for audit.
4. Record the result and inspect the synthetic clinic in phpMyAdmin. **Immediately** set `BOOKING_RACE_WEB_ENABLED=false`, remove `BOOKING_RACE_WEB_SECRET` and `BOOKING_RACE_WEB_DATABASE_ACK` from `.env`, and delete `/public_html/wellness/api/booking-race-web.php`. The private test files may also be removed. The endpoint is not part of normal deployment.

If the page reports that `proc_open` is disabled, or the host cannot start two CLI workers, the automated test cannot run on that hosting plan; use the manual two-browser procedure below. A generic failure returns a short reference; the PHP error log has details. Do not paste secrets or database credentials into a support report.

### When access is phpMyAdmin only

phpMyAdmin can inspect rows but cannot itself issue two simultaneous authenticated API booking requests. Use two independent browser sessions signed in to the development portal (for example, two browser profiles), both with test-only clients and the same practitioner/room/start time visible. Keep both booking forms open, then confirm them as closely together as possible. One should succeed and the other should receive a conflict; record both outcomes and the successful appointment ID. Repeat for two practitioners competing for one room if that setup is available. Do not share copied bearer tokens or request payloads.

In phpMyAdmin, run the read-only [overlap audit](../api/database/maintenance/audit_booking_overlaps.sql) after setting the actual clinic ID and the winning appointment ID at the top of the script. Both overlap queries should return zero rows, and the winning appointment should have one confirmation history row and one notification event. The audit also checks pre-existing appointments, so investigate any rows it returns rather than assuming they came from this test. Cancel the test appointment through the portal when finished, preserving its history. A manual near-simultaneous attempt is useful pilot evidence, but it is not the same as a deterministic concurrent integration test; retain that automated gate for later.
