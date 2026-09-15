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

Concurrency acceptance remains pending until step 8 is run on MySQL. Phase 4 remains in progress; the next work is database integration coverage and the booking interface.
