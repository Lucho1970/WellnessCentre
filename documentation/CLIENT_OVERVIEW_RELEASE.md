# Client overview deployment — 6 October 2026

This release adds client appointment history, saved operational appointment changes and a practitioner access report. Source and validation details are in [Client overview](CLIENT_OVERVIEW.md). The release manifest records the exact source revision. A prepared package is not confirmation that Netfirms has been updated.

## Upload only these two archives for this feature

1. Back up the existing private application and Willow portal files before replacing them. Keep a recoverable copy of the previous release. Preserve the live private `.env`, runtime files and uploads.
2. Extract **wellness-api-private.zip** into the existing private **`/wellness-api`** directory. Its top-level `src`, `bin`, `vendor` and Composer files belong directly in that directory. Do not create `/wellness-api/private` or a second `/wellness-api/wellness-api`. This includes the new `src/Service/ClientOverviewService.php` and matching `src/Api.php`. PHP service classes must stay outside `public_html`.
3. Extract **wellness-portal.zip** into the existing document root assigned to **willowwellness.copihue.ca**. Check that domain's directory in Netfirms before uploading; do not infer the directory from its domain name. Put `index.html`, `assets`, `.htaccess` and `api` directly in that document root. This is the same directory containing the currently working Willow application, not the generic `portal.copihue.ca` landing page.
4. Do not upload the other archives for this feature. In particular, leave the public clinic website, neutral portal landing and mail bridge alone. The full builder emits those artifacts for other release workflows.
5. Hard-refresh the Willow portal after upload, sign in as administrator and open **Clients → Details → View appointments** or **Practitioner access**.

No new SQL migration or environment setting is needed for the client overview itself. Existing prerequisites are client merge tables (006), appointment cancellation snapshot fields (015), reassignment history (029), and identity/membership foundation (032). Do not import the entire `sql-updates` directory or rerun migrations already applied.

The source also contains the previously developed practitioner invitation pilot. Keep the private API setting **`STAFF_INVITATIONS_ENABLED=false`** (or absent, its disabled default) and frontend build setting **`VITE_STAFF_INVITATIONS_ENABLED=false`** until that pilot's separate identity-provider, migration 033 and acceptance prerequisites are complete. Preserve the existing staff membership pilot settings that were already tested with Esther; deploying this release does not migrate her identity or enable invitations.

## Acceptance after upload

- Confirm administrator and Esther can still sign in using their existing staff accounts.
- Open a known test client. Verify contact/details remain correct, appointment status/date/time/practitioner/location/fees are shown, and upcoming/past/cancelled filters work.
- Expand recorded changes for an appointment with status/reassignment/fee history. Empty history should display its empty state. Old times that were never recorded cannot be reconstructed.
- Open an appointment and use **Back to client history**; it must return to the same client. Check a second client, mobile width and French labels.
- Review practitioner access: relationship counts, creator relationship, account status and separate booking/directory/appointment/logistics-note scopes. This is configured access, not a record of who has viewed the client.
- Verify denied roles, clinic isolation, incorrect appointment/client pairs and identifier-only audit entries with synthetic data before treating hosted acceptance as complete. Local PDO-double and browser tests passed; real MySQL and hosted acceptance remain pending until performed.

If an API request fails, capture its correlation ID and corresponding private PHP log entry without exposing credentials or client contact information. A missing-table/column error needs a prerequisite check, not a blind import of all migrations.

## Rollback

Restore the previous matching private API and portal files together from the backup, preserving `.env`, runtime files and uploads. No database rollback is introduced by this feature. Keep the deployment manifest with the backup for traceability.
