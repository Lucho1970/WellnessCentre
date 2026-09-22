# Appointment email delivery (Microsoft 365 development tenant)

The PHP API queues booking confirmation, change, and cancellation events in MySQL. A private CLI worker sends minimal bilingual email through Microsoft Graph. No public URL invokes the worker, and no Graph secret belongs in the frontend or Git.

## Sender and tenant

The proposed development sender is `wellness@lucho1970.onmicrosoft.com`. Confirm that this is an actual Exchange Online mailbox with the ability to send mail. A directory user without an Exchange mailbox is not sufficient. This is the workforce Microsoft 365 tenant, **not** the separate customer External ID tenant. Change `MAIL_FROM_ADDRESS` when the production domain and mailbox are ready.

Create a dedicated confidential app registration in the sender mailbox's tenant. Record its Directory (tenant) ID and Application (client) ID. Create a client secret with an expiry reminder and copy its value immediately to the private API `.env`. Do not add it to Git or the frontend build.

Grant only the sender mailbox the ability to send. Prefer Exchange Online [application RBAC](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac): register the app's service principal in Exchange, create a recipient scope containing only the sender mailbox, assign `Application Mail.Send` within that scope, and test authorization for this mailbox and an unrelated mailbox. Do **not** also leave an unscoped Entra `Mail.Send` application permission on this app, because that would defeat the mailbox scope. Have a tenant administrator perform and verify the Exchange configuration. If application RBAC is unavailable in this tenant, the broader Graph `Mail.Send` application permission with admin consent is a development-only fallback that must be documented and revisited before production.

Graph uses the [client-credentials flow](https://learn.microsoft.com/en-us/graph/auth-v2-service) and [`POST /users/{sender}/sendMail`](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0). A `202 Accepted` means Graph accepted the request, **not** that the recipient received it.

## Deployment order

1. Back up the database and apply `api/database/migrations/016_notification_delivery.sql` once in phpMyAdmin. This intentionally cancels all pre-existing `queued` or `sending` notifications to prevent old test bookings suddenly emailing clients. Do not rerun the migration.
2. Deploy the matching private API package. No frontend rebuild is required for the worker itself.
3. Set the following in the private `/wellness-api/.env` (values shown are examples; use the actual IDs and secret):

   ```ini
   MAIL_ENABLED=false
   MAIL_TENANT_ID=<sender-tenant-guid>
   MAIL_CLIENT_ID=<mail-app-client-guid>
   MAIL_CLIENT_SECRET=<secret-value>
   MAIL_FROM_ADDRESS=wellness@lucho1970.onmicrosoft.com
   CLIENT_PORTAL_URL=https://wellness.copihue.ca/client
   ```

4. Verify the mailbox and permission scope, then set `MAIL_ENABLED=true`. Run a private command-line smoke test with a safe test client address. Only then schedule the worker.
5. On Netfirms, schedule PHP CLI (the same PHP 8.4 family as the site) to run every minute or every few minutes: `php /absolute/private/path/wellness-api/bin/send-notifications.php --limit=20`. Set the absolute path for the hosting account. If scheduled jobs are unavailable, leave `MAIL_ENABLED=false` and run the CLI manually; do not expose a web-triggered endpoint or claim delivery is live.

The command prints only counts: `sent` (Graph accepted), `retry`, `review`, and `canceled`. It exits nonzero if disabled or if setup/database access fails. The queue is not processed merely because the API package is deployed.

## Monitoring and recovery

Query `notification_events` by `status`, `scheduled_at`, `next_attempt_at`, `attempt_count`, and `last_error`. The worker retries definite transient failures with backoff, including Graph throttling ([429 guidance](https://learn.microsoft.com/en-us/graph/throttling)). It does not automatically resend a request with an unknown outcome (e.g. a network break after submission or a worker crash mid-send); those rows become `needs_review` to avoid duplicate mail. Review provider traces and the recipient mailbox before deciding whether to resend. Do not change those rows to `queued` casually. `sent` means Graph accepted the mail, not inbox delivery; delivery receipts and admin failure management remain future work.

The message contains the appointment date/time and a sign-in link, but no clinical notes, location address, or access instructions. It is bilingual until client language preference is integrated with notification delivery.
