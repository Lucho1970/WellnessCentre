# Practitioner appointment management checkpoint

Current extension (25 September 2026): a Super Admin can grant `add_clients` to an
individual practitioner after applying migration `019_practitioner_client_creation.sql`.
That practitioner can add an active client with minimal booking details from their own
appointment form, then select the new client and explicitly send a private portal
invitation email. They cannot browse or edit the general Clients directory, override
duplicate warnings, invite clients created by others, or approve identity links.
The client can accept the invitation, but an authorized clinic reviewer must verify
the person's identity and approve the link before the account sees client records.

The practitioner workspace also has **My clients**, a read-only, searchable contact
list limited to clients with an appointment assigned to the signed-in practitioner
or clients that practitioner created for booking. It shows minimal contact details,
appointment count, and a link to the latest assigned appointment. It never exposes
the clinic-wide Clients directory, administrative notes, clinical records, or
another practitioner's clients. The client detail panel has **Book appointment**,
which opens the existing booking form with that client selected. The Operations
client and service detail panels, the practitioner calendar, availability page,
and dashboard also provide direct booking actions. A service-initiated booking
preselects the service when it is offered in the staff booking options; all normal
booking validation and permissions still apply.

## Delivered scope

Practitioners configured as **Practitioner managed** can use **Practitioner workspace →
Appointments** to:

- view only their own appointment schedule;
- search all active clinic clients inside the booking workflow using only minimal scheduling
  details (name, email, and phone);
- create their own appointments using assigned services, durations, locations, delivery
  modes, rooms, pricing, travel buffers, and current availability;
- reschedule an upcoming requested/confirmed/rescheduled appointment into another valid
  opening without changing its client, service, location, mode, duration, or price; and
- cancel an upcoming appointment while retaining its history.

Clinic-managed practitioners remain read-only in the practitioner workflow. Administrators
and reception retain their existing clinic booking flow. Selecting a client and creating an
appointment establishes the care relationship for later authorized workflows. Practitioner
access does not grant the general client directory, clinical records before an authorized
care relationship, or permission to change another practitioner's appointment.

## Safety and consistency

Creation continues through the existing transaction, clinic schedule lock, availability
revalidation, room/travel checks, price snapshot, and idempotency key. Rescheduling uses the
same schedule lock and availability engine while excluding only the appointment being moved.
The API rejects stale appointment versions, past or terminal appointments, unsuitable rooms,
cross-practitioner changes, and a reschedule that keeps the appointment's existing start time.
The existing start time is not returned as a replacement choice. Exact reschedule/cancellation retries return the already
updated result instead of repeating history, audit, or notification records.

New bookings default the base location to the signed-in practitioner's active base location.
The location remains editable when another eligible location is available. Administrative
booking defaults to the only eligible location, or the first configured eligible location
when the clinic has several; staff can change it before continuing.

Every accepted change increments the appointment version, writes status history and an audit
event, and queues a client notification event. Appointment email and opted-in Canadian staff
SMS now have tested senders, immediate attempts, and a scheduled fallback. A provider's
acceptance is not proof that the recipient received the message.

## API

The contract is recorded in
[`practitioner-appointments.openapi.yaml`](../api/practitioner-appointments.openapi.yaml).
New or expanded operations are:

- `GET /api/v1/appointments?scope=practitioner`
- `GET /api/v1/booking-options?scope=practitioner`
- `GET /api/v1/booking-clients?q=...&scope=practitioner`
- `GET /api/v1/appointments/{id}/availability?date_from=...&date_to=...`
- `PATCH /api/v1/appointments/{id}` with `reschedule` or `cancel`

## Deployment and verification

No database migration is required. Deploy the portal frontend and private API together,
preserving the private `.env` and runtime directory. Replace the complete matching Composer
`vendor` directory if deploying the full private package.

Before production use, verify against deployed MySQL and signed-in Entra users:

1. Set one practitioner to **Practitioner managed** and another to **Clinic managed**.
2. Confirm the managed practitioner sees only their schedule and assigned booking options.
3. Confirm booking search includes a newly added active clinic client while the general
   client-management page and clinical records remain unavailable.
4. Create a clinic and a mobile appointment, including room/address behavior as applicable.
5. Reschedule into an available time and confirm the prior appointment no longer blocks itself.
6. Attempt the same time from another session and confirm the conflict is rejected.
7. Retry the same reschedule after simulating a lost response and confirm no duplicate history,
   audit, or notification row is added.
8. Cancel an upcoming appointment and confirm its status/history and released availability.
9. Confirm a practitioner cannot change another practitioner's appointment, a past appointment,
   or a terminal appointment.
10. Confirm clinic-managed practitioners cannot create or change appointments.

Local acceptance includes PHP syntax and policy tests, production frontend builds, resource
catalog parity, and the Playwright practitioner booking/rescheduling workflow. Hosted MySQL
race and notification-delivery acceptance remain required.

## Appointment logistics notes

The appointment details panel has an append-only logistics history for directions such as
“use the side entrance,” “call on arrival,” and “bring a portable table.” Notes are attached
to one appointment, not to the client's general profile or treatment record. Each entry
shows its author and creation time. This is **not** a place for symptoms, treatment plans,
or other health information. Notes are not inserted into email, SMS, or calendar invitations.

Clinic administrators and reception can read and add notes for appointments in their clinic.
A practitioner can read and add notes only for appointments assigned to them, including
past or canceled appointments. Clients and accountants cannot access this API. The server
enforces clinic and assignment scope on both reads and writes and audits each operation
without recording note contents in the audit log.

For an existing database, apply `api/database/migrations/025_appointment_logistics_notes.sql`
once **before** deploying the matching private API and portal frontend. Fresh installations
get the table from `api/database/schema.sql`. The API contract is in
`api/practitioner-appointments.openapi.yaml`. Test with two practitioner accounts to
verify that one cannot access the other's notes by changing the appointment ID, then
check that an authorized note appears with author/time and nowhere in client messages.
