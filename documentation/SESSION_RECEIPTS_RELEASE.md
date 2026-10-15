# Receipt workflow deployment for Netfirms

This release includes the receipt workflow and the previously deployed Wellnexa theme. It updates the shared private API and the Wellnexa clinic portal. The standalone website at **wellnexa.ca** does not need an update.

## Where the ZIP contents go

Extract the outer `wellnexa-receipts-package.zip` on your computer. It contains two upload ZIPs, SQL files, this guide and a source/checksum manifest. Do not upload the outer package into a website folder.

| Upload ZIP | Extract its contents into the existing hosted folder |
| --- | --- |
| `wellness-api-private.zip` | `/wellness-api/`, outside `public_html`. This is the same shared private API folder used by the current portals. |
| `wellnexa-portal.zip` | `/public_html/wellnexa-portal/`, the document root serving `https://wellnexa.copihue.ca/`. If Netfirms displays a different document-root path for this domain, use that existing root. |

Upload the **contents**, without nesting another `wellness-api` or `wellnexa-portal` folder. Include the portal's hidden `.htaccess` and its `api` directory. The private package includes the PDF library and fonts under `vendor`, so Composer is not required on Netfirms. If your current layout differs, retain the existing public API bootstrap's path to the existing private API instead of moving the application.

Preserve `/wellness-api/.env`, existing uploads and other private runtime files. The ZIP contains no `.env`. Keep old hashed portal assets for the initial pilot so cached tabs and rollback remain usable. The shared API update also serves Livin Lively, but its portal files do not need to be replaced to try this workflow in Wellnexa. Do not upload this portal ZIP to the wellnexa.ca marketing website or replace the neutral central-admin site.

## Before uploading

1. Download backups of the current private API and Wellnexa portal folders and export the existing application database through phpMyAdmin. Use a short maintenance window while replacing the API and its `vendor` directory.
2. Confirm Netfirms is using PHP **8.2 or later**, with **DOM** and **mbstring** enabled alongside the API's existing extensions. Enable **GD** for PNG logos; JPEG logos work without GD. The PHP temporary directory must be writable. Do not change existing identity, hostname, SSL or database settings.
3. In the existing private `/wellness-api/.env`, leave `SESSION_RECEIPTS_ENABLED=false` while installing. Add that line if it is absent. This flag controls the shared API, not just one hostname. Clinics without a saved receipt template and practitioner service details cannot issue receipts.

## Database update in phpMyAdmin

Use the **same existing database named by the hosted API's private `.env`**. Do not create a new database or import `schema.sql`, a database dump, seed data, or all historical migrations.

1. Select the application database in phpMyAdmin's left pane.
2. Open **Import**, choose `sql/session-receipts-preflight.sql` from the outer package, and run it. This is read-only. Confirm the selected database name, all prerequisite rows are **OK**, and `receipt_tables_present` is **0**. This assumes your previously deployed database is already current through migration 042. The checks screen for obvious missing prerequisites; they do not certify every historical migration.
3. If the count is **3**, migration 043 may already be applied: do not import it again. Run the postflight checks instead. If the count is **1 or 2**, or any prerequisite is missing, stop: the database needs review. MySQL/MariaDB DDL can commit table creation even if a later statement fails; do not repeatedly import the file or drop tables to retry.
4. If the count is **0** and prerequisites pass, import **`sql/043_session_receipts.sql` once**. It creates only `receipt_templates`, `practitioner_receipt_profiles` and `session_receipts`. It does not create receipts, record payments, or change existing clients, appointments or balances.
5. Import `sql/session-receipts-postflight.sql`. Expect three table rows using InnoDB, the `active_appointment_id` stored generated column, and three listed indexes with `non_unique=0`.

Keep SQL files on your computer; they do not belong in a public website directory. If phpMyAdmin reports an error, preserve its exact message and leave the feature disabled.

## Upload and enable

1. Extract `wellness-api-private.zip` into the existing private API folder, including **both `src` and `vendor`**. Preserve the production `.env`. Expect `composer.json`, `composer.lock`, `src`, `bin` and `vendor` directly under `/wellness-api/`.
2. Extract `wellnexa-portal.zip` into the existing Wellnexa portal document root. Expect `index.html`, `assets`, `.htaccess` and `api` directly in that root. Keep existing permissions consistent with the working deployment, normally directories `755` and files `644`.
3. Check [API health](https://wellnexa.copihue.ca/api/v1/health) and [database health](https://wellnexa.copihue.ca/api/v1/health/database). Both should return JSON with `data.status` equal to `ok`. These checks confirm basic access, not receipt configuration or PDF readiness.
4. After the SQL and files are in place, change the existing private `.env` line to:

```dotenv
SESSION_RECEIPTS_ENABLED=true
```

5. Refresh the Wellnexa portal with **Ctrl+F5**, then sign in as its clinic administrator. This test uses the existing sign-in and client account links; no new accounts or hostname configuration are required by the release.

## First receipt test

Use a clearly identified test client/session and test payment, because issuing a receipt creates persistent financial records. Use an existing clinic-linked client login to verify client delivery. Select **All appointments** or **Past** when necessary to find the session.

1. Open the session in **Appointments**. In **Session receipt**, a clinic administrator opens **Clinic receipt template** and saves the clinic issuer name, business address, contact details and optional logo. This is reusable throughout that clinic.
2. Open **Practitioner receipt details** and save the treating practitioner's professional name, applicable designation, registration number if applicable, and optional logo. These fields are reusable for that practitioner/service in that clinic. A practitioner may maintain their own details; only a clinic administrator can maintain the clinic template.
3. The assigned practitioner opens the session from their practitioner **Appointments** view and selects **Mark completed**. The session must already have started. If it is already completed, skip this step. Refresh/open its details again.
4. Enter the applicable **Tax amount CAD**, the exact full **Amount received CAD**, payment method and payment date. The date must be from the service date through today. Confirm the details and select **Record payment and issue receipt**. This records payment received outside the application; it does not charge a card or send money.
5. Download the PDF from the session panel. Verify the clinic/practitioner names, applicable credentials, logos, date, service/duration, historical price, On-Site fee if relevant, tax and payment. The first workflow supports full CAD payments only. A session missing its historical price cannot be billed by this workflow.
6. Sign into the linked client's Wellnexa portal and open **My receipts**. Download the same PDF. Save it or print it from the device's PDF viewer. Client delivery is through the portal; receipt email notifications are not included in this release.
7. Edit the template or practitioner details, then download the existing receipt again: the issued PDF should remain unchanged. If correcting a test entry, use **Void receipt** with **Payment recording error** and an explanation. Select **Full refund already completed** only if a real full refund has already occurred outside the system. Voiding does not transfer money or delete history.

Validate the chosen receipt fields and tax handling for the clinic's professions before using real receipts for benefits reimbursement. The application does not guarantee insurer acceptance.

## If something fails

- No Session receipt section: check the private flag and that the new API and portal files are installed; hard-refresh. Confirm the administrator is in this clinic or the practitioner is assigned to that session.
- Table not found: verify the selected database and migration 043. Do not import the full schema to fix this.
- Session not eligible: use a completed session with a historical price. Refunded/voided receipts retain their history; use Refresh before a replacement.
- PDF or logo error: confirm the new `vendor` directory and PHP DOM/mbstring; check GD for PNG or try JPEG. Keep the correlation/reference ID from the error.
- Client cannot see a receipt: use the client login linked to that exact clinic client record, then open My receipts again. Matching email alone does not establish an account link.

Rollback: set `SESSION_RECEIPTS_ENABLED=false` and restore the backed-up application files if needed. Keep the new tables and any receipt/payment history. Do not delete financial records or restore an old database backup over new payments merely to undo application files.

## Rebuilding this package

From a clean committed checkout with the existing private production frontend settings available, run:

```powershell
./scripts/build-session-receipts-deployment.ps1 -ReleaseName receipt-workflow-2026-10-15
```

The builder creates fresh production builds, installs locked production API dependencies into an isolated staging folder, checks required PDF library/font and portal bootstrap entries, excludes private environment/Git files, and records source revision and SHA-256 checksums. Generated ZIPs stay under ignored `deployments`; source migrations and these instructions remain versioned. The exact preflight/migration/postflight SQL can be rehearsed with `scripts/test-recurring-sql.ps1 -IntegrationTest session-receipt-deployment.php` and its required portable MariaDB directory parameter. Hosted acceptance remains pending after local packaging checks.
