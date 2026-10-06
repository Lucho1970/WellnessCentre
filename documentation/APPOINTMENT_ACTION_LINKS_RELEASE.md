# Appointment action links release — 6 October 2026

This release adds secure appointment links to newly sent client confirmation/change/reminder emails, staff link revocation and client cancellation-fee acknowledgement. The release manifest records its exact source revision. Prepared files do not mean Netfirms has been updated. Behavior, security and validation limits are documented in [Appointment action links](APPOINTMENT_ACTION_LINKS.md).

## Netfirms upload order

1. Back up the current database, private `/wellness-api` application and Willow portal files. Preserve the live private `.env`, runtime files and uploads.
2. In the existing **private `/wellness-api/.env`**, keep `APPOINTMENT_ACTION_LINKS_ENABLED=false` during upload. Absence also defaults to false. Leave the working staff membership settings and disabled practitioner invitation pilot alone.
3. In phpMyAdmin, select the same database used by that private API. Check `SHOW TABLES LIKE 'appointment_action_links';`. If absent, import **034_appointment_action_links.sql** once. If present, do not rerun it; use `SHOW CREATE TABLE appointment_action_links;` to verify the existing definition against the migration. Do not import the entire migration directory or full schema. Migration 033 is not required for this release.
4. Extract **wellness-api-private.zip** directly into the existing private **`/wellness-api`** directory, including `src`, `bin`, `vendor` and Composer files. Avoid a nested `private` or second `wellness-api` directory. Do not place PHP classes under `public_html`. This updates both immediate notification delivery and the scheduled worker.
5. Extract **wellness-portal.zip** directly into the current document root assigned to **willowwellness.copihue.ca**, including `index.html`, `assets`, `.htaccess` and `api`. Verify that domain's directory in Netfirms; its domain name does not establish its physical path.
6. Hard-refresh the client and staff tabs. The API and portal must be deployed as a pair: client cancellation now requires the displayed-fee acknowledgement even while link generation is disabled. Staff cancellation requests are unchanged.
7. Verify administrator and Esther's existing staff sign-in and the linked test client's account/appointment list. Check that the new table exists and `CLIENT_PORTAL_URL` in the private `.env` is `https://willowwellness.copihue.ca/client` (or the correct deployed clinic client URL), with no query or fragment.
8. Set **`APPOINTMENT_ACTION_LINKS_ENABLED=true`** in the same private `.env` to start the hosted development acceptance below. No frontend setting/rebuild or identity-provider callback change is needed for activation. Disable it again if a check fails.

Only these two ZIPs and migration 034 are needed. Leave the public clinic website, neutral `portal.copihue.ca` landing and mail bridge unchanged. Do not upload the other release ZIPs simply because the builder generated them. Keep `.env` outside the public document roots.

## Test with new synthetic bookings

- Book a fresh test appointment and inspect its newly sent confirmation email. Open **Review, reschedule or cancel**: the token disappears from the address bar and client sign-in is required. It should show the exact booking without changing it.
- Cancel only after reviewing the displayed fee and pressing **Confirm cancellation**. Verify the appointment remains in history. If the fee changes while the page is open, it must require another confirmation.
- On a second test appointment, choose **Reschedule**, find a new available time and explicitly confirm it. The old email link must then fail because its appointment version is stale; the newly sent change email should open the new version.
- In administrator appointment details, select **Appointment email links → Revoke existing email links → Confirm link revocation**. An already issued link should fail, while the client can still use My appointments. Future messages may create new links.
- Test a copied link with a different client account and, where available, a different clinic host. Neither may reveal the booking. Test an unlinked account, malformed token, mobile width and French. Do not use real client data for isolation tests.
- Verify one scheduled reminder email contains a working link and that staff/cancellation messages retain their existing behavior. Older emails keep their old general portal destination; do not resend historical notifications.
- Confirm private audits record `appointment.action_link.open` / `appointment.action_links.revoke` with appointment IDs, not tokens or contact details. A failed API request's correlation ID can be matched to the private PHP log; do not share credentials or complete email links.

Local builds, 114 browser regressions, 13 built-artifact checks and 45 PHP scripts passed. Real MySQL rehearsal remains pending: Docker is installed but its engine is not running, and the local test database connection was refused. Hosted checks above are separate acceptance; a table created successfully is not proof of every permission/concurrency rule.

## Rollback

Set `APPOINTMENT_ACTION_LINKS_ENABLED=false` to disable new links and resolution. Restore the previous matching API/portal files if necessary, preserving `.env`, runtime files and uploads. The additional table can remain for inspection; do not delete token or audit records as a UI rollback. Retain the manifest with the release backup.
