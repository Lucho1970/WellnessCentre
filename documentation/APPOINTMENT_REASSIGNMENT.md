# Appointment reassignment — first increment

Clinic administrators can change the practitioner on an existing future **in-clinic** appointment from the Appointments ribbon or details panel. A reason is required. The client, service, location, room, start/end time, and stored price are unchanged. The server offers only active practitioners assigned to that service and location who are available at that exact time, can use the same room, and have the same current base price. It rechecks those conditions inside the appointment-change transaction. The appointment version increments, reminder events are refreshed, and an `appointment_reassignments` record and audit event preserve who changed the practitioner and why.

The client receives an updated email/calendar invitation identifying the new practitioner. The incoming practitioner receives a booking notice; the outgoing practitioner receives a distinct “moved off your schedule” notice, not a cancellation. Staff email/SMS preferences and the existing immediate dispatch plus scheduled fallback apply. Provider acceptance is not proof of recipient delivery. Both practitioners' portal calendars reflect the current assignment; the outgoing practitioner no longer has appointment-detail access.

This is intentionally not a transfer workflow for On-Site appointments: those require a separate travel, coverage, and fee review. Qualification records are not yet mapped to service requirements, so the eligibility check uses active practitioner-service assignment, not credential expiry. Administrators must manually verify any special qualifications before reassignment.

## Deployment

Apply `api/database/migrations/029_appointment_reassignment.sql` **once** to the clinic database before deploying the matching private API and portal frontend. Fresh databases receive the table from `api/database/schema.sql`. The new endpoint is `GET /api/v1/appointments/{id}/reassignment-options`; `PATCH /api/v1/appointments/{id}` accepts `{ "action": "reassign", "version": 1, "practitioner_id": 2, "reason": "..." }` for clinic administrators only.

## Pilot checks

1. Set up a second practitioner offering the same service/duration at the same location and price, with availability covering a future in-clinic appointment. Reassign it as clinic admin and confirm the time, room, price, and client are unchanged.
2. Confirm the appointment appears on the new practitioner's calendar and disappears from the old practitioner's calendar. Check the client email/calendar update and the two distinct staff notices.
3. Verify a practitioner, receptionist, and client cannot call either reassignment endpoint directly.
4. Try a practitioner with a conflicting booking, time off, unavailable room, or different price; they must not be offered. Try an On-Site or past appointment; reassignment must be refused.
5. Open the same appointment in two admin sessions, reassign in one, then submit the stale version in the other; the second change must be rejected and refreshed before another action.
