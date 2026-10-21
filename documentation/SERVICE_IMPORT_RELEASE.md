# Service import deployment and acceptance

This release includes the reviewed XLSX importer (PR #11) and priced CSV support (PR #12). The outer package is a local download bundle: extract it on your computer first. Upload only the contents of the two application ZIPs to their destinations. Keep this README, manifests, SQL and your catalogue CSV off the public website.

## Contents and destinations

| File | Action |
| --- | --- |
| `sql/052_service_imports.sql` | Apply once in phpMyAdmin to the existing target clinic database, before deploying code |
| `wellness-api-private.zip` | Extract contents directly into private `/wellness-api` |
| `wellness-common-portal.zip` | Extract contents directly into `/public_html/wellness-common-portal` |
| `manifest.json` | Source revision, package checksums and destinations; retain locally |
| `SERVICE_IMPORT.md` | Format, permissions, duplicate policy and implementation details |
| `DEPLOYMENT_LAYOUT.md` | Confirmed shared hosting layout |

Both `livinlively.copihue.ca` and `wellnexa.copihue.ca` use the common portal files. Upload that portal archive once. No marketing-site, general landing-page or mail-bridge upload is required for this feature. No private `.env` or catalogue file is included. No server PHP security/runtime upgrade is included.

## Upload order

1. Back up the target database and existing private API and portal files. Keep the backup and this release manifest locally. Preserve the current private `.env`, uploaded files, runtime data and permissions.
2. In phpMyAdmin, select the correct existing database. Check `SHOW TABLES LIKE 'service_import_batches';`. If absent, run **only** `sql/052_service_imports.sql`. If present, inspect `SHOW CREATE TABLE service_import_batches;` and compare to the migration; do not blindly rerun or drop it. The migration creates one table and does not change services, prices or roles. Apply to each distinct database used by the target clinics; if both share one database, apply once. Do not run the full schema or every historical migration.
3. Overlay `wellness-api-private.zip` contents into `/wellness-api`, retaining the private `.env`, uploads and runtime data. The archive includes production Composer dependencies and source. Do not put it under `public_html`, and do not create an extra enclosing folder.
4. Overlay `wellness-common-portal.zip` contents into `/public_html/wellness-common-portal`, including hidden `.htaccess` files and the thin `api/` entries. Upload new assets before replacing `index.html`; keep old hashed assets until rollout is complete. Avoid opening the importer during the upload.
5. Refresh both clinic portals. Verify clinic identity, staff sign-in, ordinary service lists and same-origin API JSON responses before importing. A database or deployment error must be resolved before continuing.

## Import Services-Library-Priced.csv

1. Sign into the **intended clinic portal** as a current clinic-wide **Super Admin**. An application/global administrator account alone, clinic administrator, practitioner, receptionist or location-scoped Super Admin does not grant import access. Use your existing authorized clinic staff account; this release does not grant roles.
2. Open **Services → Import services** and select your local `Services-Library-Priced.csv`. Do not upload that CSV into a public server directory. The reviewed file contains 64 services, six categories and 183 duration prices, in CAD before tax. Verify those counts and the displayed prices against your approved file.
3. Keep **Skip existing services** for the first import. Enable **Create missing categories** only if you intend to create the listed categories. Existing matches are not repriced: even the optional content-update policy preserves existing prices, duration IDs, assignments, public URLs and booking settings.
4. Select **Preview import**. Review the clinic, every action, bilingual content, duration prices and proposed categories. Resolve every invalid row. Zero means explicitly free; a blank price is invalid for a new service. A preview expires after 30 minutes or becomes stale when the catalogue changes.
5. Check **I reviewed the rows, prices and category changes for this clinic**, then select **Import reviewed services**. If a network failure occurs, retry the same confirmation; applied previews are idempotent. Download the completion report with service IDs and retain it locally.
6. New services are **inactive and unpublished**, without practitioner/location assignments. Review booking rules, room requirements, tax configuration and assignments before activating or publishing. `Type` and `Initial Consultation` are descriptive import metadata, not enforced intake prerequisites. The imported prices do not establish provider qualifications or billing eligibility.

## Hosted acceptance and rollback

First use a small synthetic service in staging to verify preview/apply, repeat confirmation, duplicate skip and a denied staff role. Confirm existing service prices and assignments remain unchanged. Local tests do not prove hosted database, identity-provider or clinic acceptance. Record the target clinic, source revision and migration result when accepting the release.

For a code rollback, restore the previous private API and matching portal index/assets/routing files together, preserving configuration and runtime data. The new import-history table can remain. Do not drop it to roll back code. Code rollback does not undo imported catalogue changes; use the downloaded result IDs and a reviewed correction/restore plan if imported records need correction. Do not delete records already referenced by bookings.

## Rebuild

From a clean committed revision on `main`, run:

```powershell
./scripts/build-service-import-deployment.ps1 -ReleaseName service-import-YYYY-MM-DD
```

The wrapper invokes the existing production builder, selects the two required upload archives, adds migration/instructions and writes an outer download ZIP plus SHA-256. Generated packages stay ignored in `deployments/`; the manifest records the actual source commit. No upload, hosted migration or live import is performed by the builder.
