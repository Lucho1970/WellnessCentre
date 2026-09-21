# Practitioner availability administration deployment

Source commit: `3a07366`

This release changes **Availability** to a practitioner-first administration screen. Selecting a practitioner expands their regular hours, one-time changes, and time off. The contextual ribbon supports adding hours/changes/time off and viewing, editing, archiving, or removing the selected schedule item.

## Database update

Migration `sql-updates/014_time_off_location.sql` must be applied once before the matching API is deployed. It preserves the location/timezone used for time-off records and backfills existing records from an active practitioner location when one is available.

The migration has already been reported as applied to the current development database. Do not rerun it there.

## Deploy in this order

1. Back up the current private API directory and preserve its `.env` and runtime uploads.
2. Extract `wellness-api-private.zip` into the private `wellness-api` directory. Replace `vendor` as one complete set; do not mix old and new Composer files.
3. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the thin public API files need refreshing.
4. Extract `wellness-portal.zip` directly into `/public_html/wellness-portal`.
5. Extract `wellness-public.zip` directly into `/public_html/wellness` only if deploying the complete release.
6. Hard-refresh the portal.

## Acceptance test

1. Sign in as Super Admin and open **Availability**.
2. Confirm only the practitioner list is initially shown and **Add hours** is not visible until a practitioner is expanded.
3. Expand a practitioner and confirm **Regular hours**, **Changes**, and **Time off** are shown separately.
4. Select a regular-hours row, open **Details**, then **Edit** it and save a harmless change.
5. Add and edit a one-time schedule change.
6. Add and edit a short test time-off entry, confirm the displayed time is correct for the selected location, then remove it.
7. Confirm an archived hours rule disappears and no longer contributes bookable times.
8. Confirm appointment availability still respects regular hours, changes, and time off.

Checksums and archive entry counts are recorded in `manifest.json`. No `.env` files are included.
