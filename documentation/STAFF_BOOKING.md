# Staff booking checkpoint

Superseding note (18 September 2026): practitioner-managed providers can now book,
reschedule, and cancel their own upcoming appointments using scoped client relationships.
See [Practitioner appointment management](PRACTITIONER_APPOINTMENTS.md). The administrator
and reception workflow below remains applicable.

## What is available

Open **Staff portal → Appointments**. Super Admin, Clinic Admin, and reception can select **Book appointment**. Accounting and unrelated roles cannot list appointments through this endpoint.

The form supports in-clinic bookings using active client records:

1. Search active clients by name, email, or phone. Results are limited to 25; refine the search if there are more matches. Create missing clients through Clients first.
2. Select location, service, practitioner, and duration from the clinic's configured active assignments.
3. Choose a date and search current availability. Times use the location's timezone, including the review and appointment list.
4. Select an offered time and one eligible room when the service requires a room.
5. Review and confirm. The API revalidates the booking before inserting it. If the time or room is no longer available, the form returns to time selection.

After confirmation the appointment list refreshes. The list has upcoming, past, and all views, with 50 records per page. Client names, practitioner, service, location, room, times, status, and reference number are shown. Lists remain clinic-scoped; practitioner and client access is further restricted to their own appointments.

## Retry handling

The confirm button is guarded against duplicate clicks. A failed or unreadable response keeps the original payload and idempotency key for **Retry confirmation**. Back/cancel are disabled while that result is uncertain. A confirmed HTTP client error clears the pending request and requires a fresh time selection. Browser reloads warn while a confirmation is unresolved; if leaving the page anyway, check the appointment list before beginning another booking. Keys are held in memory and do not survive reloading the page.

## API additions

- `GET /api/v1/booking-options`: authenticated administrators/reception; returns active clinic-specific combinations plus bookable room names. The API still decides actual availability and eligibility.
- `GET /api/v1/clients?status=active&q=...`: filters client search for the booking selector.
- `GET /api/v1/appointments?view=upcoming|past|all&page=1`: role-restricted list; returns up to 50 records, with location timezone and readable labels.

## Deployment

No database migration is needed. Deploy the new frontend and private API together. Preserve the private `.env` and public `api/` folder. If installing a full private-API package, replace its complete `vendor` folder together: do not mix generated Composer files from different packages. The public API entry files are included for completeness.

Email confirmation is queued by the API, but email delivery remains disabled. The interface tells staff to arrange confirmation directly. Public client confirmation and payments are not part of this checkpoint; later practitioner appointment capabilities are documented separately.

## Verification and development acceptance

Local checks: frontend TypeScript/build; PHP syntax; Composer validation; `tests/clients.php`, `tests/booking-request.php`, `tests/schedule-intervals.php`, and `tests/appointment-access.php`.

Signed-in browser and MySQL tests remain to be run on the development deployment:

1. Sign in as an administrator and reception user. Verify booking options include only active assignments in their clinic, and inactive clients cannot be selected.
2. Search a known client and book a free slot. Verify the review details and saved appointment match, including timezone and room.
3. Refresh the list; verify one appointment, one status history entry, and one queued notification.
4. Book the same offered slot from another session before confirming in the first. The first session must show a conflict and allow a new search.
5. Interrupt the response after confirmation, then retry; verify the original appointment is returned without duplicates. See `BOOKING_VALIDATION_TESTS.md` for the concurrent MySQL acceptance scenario.
6. Sign in as a practitioner. Only that practitioner's appointments should appear; the client directory and Book appointment button should be absent. Accounting should receive 403 from the list endpoint.
7. Test empty client/availability results, unavailable API responses, and a narrow mobile viewport.

These changes do not mark Phase 4 complete. MySQL concurrency acceptance, administrative schedule-write coordination, and the remaining booking scope are still tracked in the solution plan.
