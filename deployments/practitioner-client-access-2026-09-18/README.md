# Practitioner client booking access deployment

Built from source commit `9360f27` on `codex/practitioner-client-booking-access`.

This release lets a practitioner configured as **Practitioner managed** search any active
client in the same clinic while creating an appointment. The search is available only in
the booking workflow and returns the minimum scheduling projection: name, email, and phone.
It does not grant access to client administration or clinical records. Creating the
appointment establishes the care relationship used by later protected workflows.

The portal also replaces the generic HTTP 404 shown for an appointment tied to an inactive
legacy service assignment with an actionable English or French explanation.

## Deploy

Deploy these two coordinated archives:

| Archive | Destination |
| --- | --- |
| `wellness-portal.zip` | `/public_html/wellness-portal` |
| `wellness-api-private.zip` | Account-root `/wellness-api` outside `public_html` |

Preserve the private API's existing `.env` and `var` directory. Replace the private API
source and its complete matching `vendor` directory together. The public website and public
API pointer did not change and their archives are included only as a complete snapshot.

No database migration or SQL update is required. Do not rerun the historical files copied
into `sql-updates` if they were already applied.

## Verify

1. Add a new active client as Super Admin or reception without creating an appointment.
2. Sign in as a practitioner configured as **Practitioner managed**.
3. Open **Appointments → Book appointment** and search for the new client by at least two
   characters of their name, email, or phone.
4. Confirm the client appears and can be selected.
5. Complete a test booking and confirm it appears only on that practitioner's schedule.
6. Confirm the practitioner remains denied access to the Clients administration page.
7. Open an old appointment whose service assignment was deactivated and confirm the portal
   explains that it must be canceled/rebooked or restored by an administrator.

All 32 browser tests, six production-build tests, PHP syntax/authorization tests, and the
existing scheduling, mobile-delivery, client, onboarding, and duration-pricing tests passed.
Archive hashes and entry counts are recorded in `manifest.json`.
