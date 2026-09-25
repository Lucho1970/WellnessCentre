# Staff-assisted mobile appointments — 17 September 2026

Branch: `codex/mobile-appointment-booking` (based on the deployed portal-separation branch).
This implements a bounded R4/MOB slice, not completion of scheduling or customer onboarding.

## Model

One service retains its treatment price and durations. Practitioner/service assignments
enable Clinic visits and/or Mobile visits. Existing assignments retain clinic eligibility;
new UI assignments default to mobile-only. No existing appointment or configuration is
silently converted. Disable Clinic visits explicitly for the initial mobile-only practice.

Keep a bookable base location/service area linked to the service and practitioner, with
recurring working hours. It provides timezone and scheduling scope, not a physical visit
destination. No physical clinic address or room is required for mobile booking. Leave a
service's Requires room setting enabled if it will need one for future clinic visits.

Mobile appointments require a structured destination and optional access instructions.
Google Address Validation confirms the destination and Google Routes calculates driving
distance from the selected base location. The API enforces the practitioner/service radius
using a short-lived signed proof; the browser cannot self-approve coverage. Set a realistic
travel buffer; the same fixed minutes are reserved before
and after the treatment, in addition to service buffers. This is conservative and does
not optimize travel between nearby consecutive visits.

Pricing precedence: practitioner override, then duration price, then service price.
The configured mobile surcharge is added only for mobile visits. Server-side confirmation
checks a supplied quote against current pricing. Appointment snapshots preserve base
price, surcharge, CAD currency, travel minutes and destination. Legacy appointments have
unknown/null historical treatment prices; no prices are invented for them. Amounts are
explicitly **before applicable taxes**: tax calculation, invoicing and payment remain future
work. Confirmation email is now attempted after booking, with the scheduled worker as backup.

## Security and current scope

Creation is staff-assisted. Public browsing can select delivery type but cannot confirm
a booking. Clients cannot self-attest coverage through the mobile creation endpoint.
Existing role and clinic checks, assigned-practitioner schedule restrictions, transaction
locking and idempotency are preserved. Replays with changed destination or mode fail.
Destinations appear only in authorized appointment lists and are not added to public
availability or notification payloads. Viewing mobile appointment lists is audited without
copying addresses into audit metadata.

Addresses are immutable booking snapshots, not yet a reusable client address book.
Address corrections, dynamic travel-time scheduling, check-in/escalation safety workflows
and automated taxes are not delivered by this slice.
Do not treat a destination snapshot as a real-time practitioner location tracker.

## Deployment order

1. Back up the database and deployed files; use a quiet test/maintenance window.
2. In phpMyAdmin select `wellness_centre` and run
   `api/database/migrations/004_mobile_bookings.sql` **once**. It contains two ALTER
   statements; execute complete statements, not arbitrary line fragments. Prerequisites
   are the existing deployed schema and migrations 001–003. Do not rerun old migrations
   or schema.sql. MySQL ALTER statements auto-commit: if interrupted, inspect columns
   before resuming rather than blindly rerunning the entire file.
3. Upload the private API package to the existing private `/wellness-api` folder,
   preserving `.env` and runtime files. Always deploy vendor as a matching complete set
   if replacing it; never mix generated Composer autoload files from separate packages.
4. Upload public and portal frontend packages to their respective document roots,
   assets before index.html. Preserve the existing public `api/` directory. The portal
   ZIP includes its two same-origin API pointers. No Entra changes are needed.
5. Hard-refresh, configure assignments, then perform the acceptance checks below.

Rollback code/frontend from backups together; keep the additive columns (older code
ignores them). Do not drop columns after mobile appointments exist. Old booking code
does not enforce mobile-only eligibility: disable bookings during rollback/recovery.

## Initial mobile-only setup

Services → Assignments → select the massage service and base location → tick
the practitioner → enable On-Site visits → disable Clinic visits → set fee, travel minutes
each way and a driving coverage radius → Save assignments. Repeat for each offered service.
The practitioner's hours must belong to that base location. Room creation is unnecessary.

## Hosted acceptance (required)

- Use labelled test records. Book a mobile visit with no rooms configured, including a
  service with Requires room enabled. Confirm address, practitioner, timezone and subtotal.
- Missing, unconfirmed, expired or outside-radius destinations must prevent confirmation.
  The API must reject mobile delivery for a practitioner/service that does not offer it.
- Verify first/last available times include travel and service buffers inside working hours.
  Attempt a conflicting treatment/travel slot; confirmation must reject it.
- Sign in as the assigned practitioner: verify address and travel details on the schedule.
  A different practitioner and accountant must not gain access to that destination.
- Change the configured price/fee after booking: the saved appointment price must remain
  unchanged. Check a stale quote is rejected before creation.
- Retry a lost confirmation with the same idempotency key: only one appointment. Changed
  destination under the same key must fail. Check clinic-mode room validation still applies.
- Verify both site roots, portal health, public mobile browsing, and staff login.

Local checks use synthetic browser APIs and a PDO test double for the real availability
engine. They do not validate MySQL SQL execution, migrations, locking races or hosted
sign-in. A local MySQL runtime was unavailable; hosted checks remain necessary.
