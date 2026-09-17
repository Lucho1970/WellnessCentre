# Mobile booking release — 17 September 2026

Source: `3a3dd7d`, branch `codex/mobile-appointment-booking`.
Includes the previously deployed portal same-origin and service-list refresh fixes.

**Database update required before API deployment.** Back up database and files.
Run only `sql-updates/004_mobile_bookings.sql` once against the existing database.
Files 001–003 are historical prerequisites, not instructions to rerun them.

| Archive | Upload contents to |
| --- | --- |
| wellness-api-private.zip | `/wellness-api/` outside public_html; preserve .env and runtime files |
| wellness-public.zip | `/public_html/wellness/`; preserve existing api/ |
| wellness-portal.zip | `/public_html/wellness-portal/`; includes api/ pointers |
| wellness-api-public.zip | `/public_html/wellness/api/`; unchanged pointer files, optional for this upgrade |

Use a quiet deployment window: SQL first, private API second, frontends last.
Do not mix Composer autoload files: replace vendor only as a complete matching
directory from the private package (keep its backup outside public_html).
Upload frontend assets before index.html; include .htaccess, preserving hosting directives.
No Entra or .env changes. Never upload the private ZIP into a public document root.

After deployment, hard-refresh. In Services → Service assignments, select the base
location and practitioner; enable Mobile visits, disable Clinic visits, set the mobile
fee and travel minutes each way, and save. Existing assignments are not automatically
changed. The base location supplies timezone/hours; a physical clinic/room is unnecessary.

See [the full setup and acceptance guide](../../documentation/MOBILE_BOOKING.md).
Coverage is staff-verified, not map-calculated. Prices are before applicable taxes.
No customer self-booking, automated email delivery, tax engine or safety tracking is
claimed in this release. Hosted database/booking acceptance remains required.

Checks: 86 PHP checks, 15 browser tests, TypeScript and both production builds passed.
Production artifact smoke checks are run on the packaged outputs. Tests use synthetic
data/PDO doubles; actual MySQL migration/concurrency tests were not possible locally.
SHA-256 archive hashes and source commit are recorded in manifest.json.
