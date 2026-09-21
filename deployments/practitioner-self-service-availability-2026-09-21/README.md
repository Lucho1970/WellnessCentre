# Practitioner self-service availability deployment

Source commit: `e221107`

This release adds the **Availability** page to the practitioner workspace. A
practitioner whose booking management mode is `practitioner_managed` can maintain
their own regular hours, one-time schedule changes, and time off. They can use
only locations actively assigned to their practitioner profile. A
`clinic_managed` practitioner receives the same schedule as a read-only view.
Clinic administrators retain the existing multi-practitioner availability page.

The API enforces practitioner ownership, booking-management mode, clinic scope,
and active location assignments for create, edit, archive, and delete operations.

## Database update

There is no new database migration for this release. The `sql-updates` directory
contains the complete migration history for reference and fresh deployments.
Do not rerun migrations already applied to the current database.

## Deploy in this order

1. Back up the current private API directory and preserve its `.env` and runtime
   uploads.
2. Extract `wellness-api-private.zip` into the private `wellness-api` directory.
   Replace `vendor` as one complete set; do not mix old and new Composer files.
3. Extract `wellness-api-public.zip` into each public API pointer directory if
   those thin public files need refreshing.
4. Extract `wellness-portal.zip` directly into `/public_html/wellness-portal`.
5. Extract `wellness-public.zip` directly into `/public_html/wellness` for the
   complete matching public release.
6. Hard-refresh the browser after deployment.

No `.env` files are included in these archives.

## Acceptance test

1. Sign in as a practitioner whose booking mode is **Practitioner managed**.
2. Open **Availability** and confirm the signed-in practitioner's schedule opens
   automatically without a practitioner selector.
3. Add a short regular-hours rule at an assigned location, edit it, and archive it.
4. Add and remove a one-time schedule change and a short time-off entry.
5. Confirm the practitioner cannot choose a location that is not assigned to them.
6. Change the practitioner to **Clinic managed**, sign in again, and confirm the
   schedule is visible but Add, Edit, Archive, and Remove actions are unavailable.
7. Sign in as Super Admin and confirm the multi-practitioner Availability page
   still supports the same schedule operations.
8. Confirm client and staff availability searches reflect the saved schedule.

Archive checksums and entry counts are recorded in `manifest.json`.
