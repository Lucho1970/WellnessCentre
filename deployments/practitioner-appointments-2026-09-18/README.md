# Practitioner appointment management deployment

Built from source commit `ddbcb8f` on `codex/practitioner-appointment-management`.

This release lets a practitioner configured as **Practitioner managed** book,
reschedule, and cancel their own upcoming appointments. The practitioner workspace is
limited to the signed-in practitioner's schedule, assigned booking options, and active
clients with an existing appointment relationship. It does not grant access to the
general client directory or another practitioner's appointments.

Rescheduling reuses the server availability, room, travel-buffer, clinic-lock, and
optimistic-version controls. Successful changes retain appointment history, create audit
records, and queue a client notification event. Notification delivery is still not
enabled, so confirm changes with clients through the clinic's current process.

## Deploy

Deploy these two coordinated archives:

| Archive | Destination |
| --- | --- |
| `wellness-portal.zip` | `/public_html/wellness-portal` |
| `wellness-api-private.zip` | Account-root `/wellness-api` outside `public_html` |

Preserve the private API's existing `.env` and `var` directory. Replace the private
API source and its complete matching `vendor` directory together; do not merge generated
Composer files from different releases. The public website and public API pointer did not
change for this feature, so their archives are included only as a complete snapshot.

No database migration or SQL update is required. The files in `sql-updates` are historical
migrations and must not be rerun if they were already applied.

## Configure and verify

1. As Super Admin, set the test practitioner's booking mode to **Practitioner managed**.
2. Sign in as that practitioner and open **Appointments**.
3. Confirm only that practitioner's appointments appear.
4. Select **Book appointment**, search for a client who already has an appointment with
   that practitioner, and complete a test booking.
5. Open **Change appointment**, reschedule to another available time, and verify the new
   time appears after the list refreshes.
6. Change the test appointment again, cancel it, and verify it leaves upcoming availability
   while remaining in appointment history.
7. Confirm an unrelated client is not returned and another practitioner's appointment
   cannot be changed.
8. Set the practitioner to **Clinic managed** and confirm practitioner creation and changes
   are denied.

Local acceptance passed all 32 browser tests, all six production-build tests, PHP syntax
and authorization tests, and the existing scheduling, mobile-delivery, client, and duration
pricing test suites. Hosted MySQL concurrency and queued-notification acceptance should be
completed during deployment verification. Archive hashes and entry counts are recorded in
`manifest.json`.
