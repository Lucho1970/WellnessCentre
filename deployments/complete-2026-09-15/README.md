# Complete Netfirms deployment — 2026-09-15

Built from main commit `3c269e4b8bc15c2d88fad3524bbebb6b101a4a3e`. Includes all merged work through client management and booking validation. The frontend is configured for **https://wellness.copihue.ca** and the existing development Entra tenant/app registrations.

## Download from GitHub

Open this directory on GitHub and download each ZIP using **Download raw file**. Alternatively download the repository ZIP and find this directory inside it. `manifest.json` contains archive sizes, entry counts, and SHA-256 checksums.

## Before updating

Back up the deployed website, private API, and MySQL database. Use a maintenance window while replacing API and frontend files. Preserve `/wellness-api/.env` and runtime files. The packages contain no database password or Entra client secret. Transfer your existing `.env` separately through your secure hosting access; it is not in GitHub.

## Extract each ZIP's contents into its destination

| ZIP | Destination | Expected contents |
| --- | --- | --- |
| `wellness-api-private.zip` | `/wellness-api/` | `src/`, `vendor/`, `bin/`, `composer.json`, `composer.lock` |
| `wellness-api-public.zip` | `/public_html/wellness/api/` | `index.php`, `.htaccess` |
| `wellness-frontend.zip` | `/public_html/wellness/` | `index.html`, `assets/`, `.htaccess` |

Upload and extract the private API first, then the public API, then the frontend. Extract the contents directly: do not leave an extra ZIP-name folder between the destination and these files. Keep the frontend's existing `api/` subfolder when uploading the frontend. Do not upload the private API into the website's public directory.

The ZIPs explicitly include `.htaccess`; enable **show hidden files** in your file manager if necessary. The public API ZIP is intentionally only two files. Permissions normally use `755` for directories and `644` for files. If Netfirms produces a generic server error after extracting a PHP file, consult its error log and the project's Netfirms deployment guide.

## SQL updates

`sql-updates/` includes the three existing incremental migrations:

1. `001_user_profile_images.sql`
2. `002_service_delivery_assignments.sql`
3. `003_catalogue_settings.sql`

**Run only migrations not already applied to your database, in order. These scripts are not guaranteed safe to rerun.** The recent scheduling, booking validation, and client management changes require no new migration. If your database already has all three updates, do not run SQL for this deployment. No seed data or full database-creation script is included in these packages. Never recreate your existing database to deploy an update.

## Verify after deployment

1. Open `https://wellness.copihue.ca/api/v1/health` and `/api/v1/health/database`; both should return JSON with `data.status` equal to `ok`.
2. Hard refresh the website and sign in using the existing staff account.
3. Open **Clients**, create a development client, reopen it, edit, and search for it.
4. Check the existing business, catalogue, profile, and availability screens.
5. Follow `documentation/CLIENT_MANAGEMENT.md` and `documentation/BOOKING_VALIDATION_TESTS.md` in the repository for further acceptance tests.

Local builds and validation tests passed before packaging. MySQL acceptance and concurrent booking tests remain pending; Phase 4 is still in progress. This release is the current development baseline.

## Rebuilding

From the repository root, run `./scripts/build-deployment.ps1 -ReleaseName <new-name>`. Requires Node/npm, PHP/Composer, and PowerShell. A fresh frontend build and locked production PHP dependency install are included. Each release name must be new; the script refuses to overwrite an existing release.
