# Staff booking release — 2026-09-15

Includes all previous merged work plus staff booking and appointment lists. Source commit: `33c3c98` on `codex/staff-booking`. This release is configured for `https://wellness.copihue.ca` and the existing development Entra registrations.

Download each ZIP using GitHub's **Download raw file** option. Archive hashes and entry counts are in `manifest.json`.

## Deploy

Back up the existing files and database first. Use a maintenance window when replacing the API.

1. Preserve `/wellness-api/.env` and runtime files. Rename the existing `/wellness-api/vendor` directory to a backup name, then extract **all** contents of `wellness-api-private.zip` into `/wellness-api/`. Do not mix individual old and new Composer files. Keep the backup until verification passes.
2. If needed, extract `wellness-api-public.zip` into `/public_html/wellness/api/`. It contains `index.php` and `.htaccess`.
3. Extract `wellness-frontend.zip` into `/public_html/wellness/`, preserving the `api/` directory. Show hidden files to verify `.htaccess` is present.
4. Refresh the website and sign in. Open **Appointments → Book appointment** as an administrator or reception user. Practitioners get their own appointment list.

The frontend and private API must be updated together. **No new SQL migration is required.** The SQL folder contains previously issued migrations 001–003 for completeness; run only missing migrations in order, never rerun already-applied scripts. No seed data is included.

The API queues confirmation email but delivery is not yet enabled. Contact the client directly after booking.

## Verify

Check `/api/v1/health` and `/api/v1/health/database`, then search an active client, choose configured care, find a time, and confirm a development appointment. Reopen the appointment list to verify it saved.

The frontend build, PHP checks, and 50 local checks passed. Signed-in browser, MySQL integration, and concurrency acceptance remain pending. Follow `documentation/STAFF_BOOKING.md` and `documentation/BOOKING_VALIDATION_TESTS.md` from this branch. Phase 4 is still in progress.
