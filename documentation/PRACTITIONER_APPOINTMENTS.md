# Practitioner appointment management checkpoint

## Delivered scope

Practitioners configured as **Practitioner managed** can use **Practitioner workspace →
Appointments** to:

- view only their own appointment schedule;
- search active clients with whom they already have an appointment relationship;
- create their own appointments using assigned services, durations, locations, delivery
  modes, rooms, pricing, travel buffers, and current availability;
- reschedule an upcoming requested/confirmed/rescheduled appointment into another valid
  opening without changing its client, service, location, mode, duration, or price; and
- cancel an upcoming appointment while retaining its history.

Clinic-managed practitioners remain read-only in the practitioner workflow. Administrators
and reception retain their existing clinic booking flow. Practitioner access does not grant
the general client directory or permit changing another practitioner's appointment.

## Safety and consistency

Creation continues through the existing transaction, clinic schedule lock, availability
revalidation, room/travel checks, price snapshot, and idempotency key. Rescheduling uses the
same schedule lock and availability engine while excluding only the appointment being moved.
The API rejects stale appointment versions, past or terminal appointments, unsuitable rooms,
and cross-practitioner changes. Exact reschedule/cancellation retries return the already
updated result instead of repeating history, audit, or notification records.

Every accepted change increments the appointment version, writes status history and an audit
event, and queues a client notification event. The current environment still does not have a
verified notification sender, so staff and practitioners must arrange confirmation directly
until the communications phase is deployed.

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
3. Confirm client search includes an existing related client but not an unrelated client.
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
