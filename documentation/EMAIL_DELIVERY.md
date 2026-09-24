# Appointment email delivery (Microsoft 365 development tenant)

The PHP API queues booking confirmation, change, and cancellation events in MySQL. A private worker sends minimal bilingual email through Microsoft Graph. The optional HTTPS trigger is a small public pointer protected by a separate high-entropy key; no Graph secret belongs in the frontend or Git.

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
5. If Netfirms supports a real PHP CLI scheduled job, run `php /absolute/private/path/wellness-api/bin/send-notifications.php --limit=20`. Otherwise use the authenticated Azure Logic App trigger below. Leave `MAIL_ENABLED=false` until the trigger and its authentication have been tested; deployment alone does not enable delivery.

### Azure Logic App Consumption scheduler (recommended for this hosting account)

The Azure subscription can be in a different tenant from the Microsoft 365 mail sender. It only schedules an HTTPS request; the Graph app credentials stay in Netfirms' private `/wellness-api/.env`. Deploy `api/deploy/netfirms/public/cron/send-notifications.php` to `/public_html/wellness/api/cron/send-notifications.php`. The portal API package also contains the same pointer, but configure Azure to call **one** URL: `https://wellness.copihue.ca/api/cron/send-notifications.php`.

1. Generate a random key of at least 32 characters. Keep it out of Git, chat, URL query strings, and logs. Put `MAIL_TRIGGER_SECRET=<key>` in the private `/wellness-api/.env`, with `MAIL_ENABLED=false`. The existing Graph settings remain there as before.
2. Create a **Logic App (Consumption)** in the subscription (which may be in a different tenant). Deploy `deployments/azure-mail-trigger-2026-09-22/logic-app-consumption.json` as an **incremental** custom template to its resource group. Enter the existing Logic App's name and location, and put the private key only into the template's secure `mailTriggerSecret` field. This replaces its workflow with a built-in 15-minute Recurrence trigger and one built-in HTTP POST action, with a tiny `{}` JSON body (Netfirms requires a request length), secure inputs/outputs, and no automatic HTTP retries. The template leaves the Logic App **Disabled**. Do not put the real key in the Designer's Default Value field; it is stored in plaintext and the Actual Value field can be read-only.
3. A normal browser GET to the trigger URL should receive 405; a POST without the key receives 404. With `MAIL_ENABLED=false`, enable the Logic App and trigger one run: the action should receive HTTP **503** and **no mail should be sent**. Confirm the URL reaches the new pointer before enabling mail.
4. Disable/delete the old Netfirms scheduled job so only Azure drives the worker. Confirm the queue contains only safe test mail addressed to a mailbox you control. Then set `MAIL_ENABLED=true` and trigger one run. A successful call returns 204. Check `notification_events`, the recipient inbox, and the generic count in the Netfirms PHP error log. `sent` means Graph accepted the request, not that it arrived in the inbox. If any check fails, set `MAIL_ENABLED=false` again.

The pointer processes at most three events per run, uses a private lock and five-minute cooldown, and returns no patient or queue details. A 503 means disabled or failed; a 404 means the key was not accepted; a 204 means the worker completed or the cooldown applied. Rotate the key in both places if exposed. See [Microsoft's secure parameter](https://learn.microsoft.com/en-us/azure/logic-apps/create-parameters-workflows) and [secure run history](https://learn.microsoft.com/en-us/azure/logic-apps/logic-apps-securing-a-logic-app) guidance.

### Netfirms URL-only scheduler (development bridge)

The Netfirms Scheduled Jobs screen on this account accepts only an `http://tuff-tar.com` URL under `/public_html/tuff-tar.com`; it does **not** execute the private CLI worker. The release includes `tuff-tar-mail-bridge.zip` as a limited development workaround. Extract its `api/wellness-notification-trigger.php` entry under `/public_html/tuff-tar.com`, so the private `/wellness-api` remains outside `public_html`. The bridge has no credentials or recipient data in its URL, sends at most one due notification per invocation, uses an exact source-IP allowlist, a private lock and a five-minute cooldown, and returns no queue details. It is not a production-grade scheduler or a substitute for HTTPS.

The bridge derives the private directory as three levels above its own `api` folder, followed by `/wellness-api`. Verify the hosting layout before use. Do not copy the private worker, `.env`, or `vendor` into the web root.

1. Apply `api/database/migrations/017_notification_scheduler_probe.sql` once. It creates a dedicated single-row diagnostic table, separate from clinical and notification records. Leave `MAIL_ENABLED=false` and **do not** set `MAIL_CRON_ALLOWED_IPS` yet. Schedule the bridge URL for one hourly run (no key or other query string). In the PHP error log, find `Wellness notification bridge probe method=GET source IP: ...` at the scheduled run time. Alternatively, use `SELECT * FROM notification_scheduler_probe WHERE id=1;` in phpMyAdmin; `last_seen_at` is UTC, and `hit_count` increases when the bridge is reached (subject to a five-second probe-write throttle). Requests during this probe do not send mail. If the scheduler's IP is not visible, its method is not `GET`, or its IP cannot be distinguished from ordinary web visitors, stop and use an HTTPS-capable scheduler instead.
2. Set `MAIL_CRON_ALLOWED_IPS` in the private `.env` to only that observed address. Multiple literal IPs may be comma-separated; wildcards, ranges and proxy headers are not accepted. Test from a normal browser: the bridge must return 404 and must not consume the test event. If the browser is also allowed, stop; the hosting proxy makes the IP guard ineffective.
3. Check the queue contains only a test event addressed to a mailbox you control. Set `MAIL_ENABLED=true` shortly before the next scheduled run. The bridge will process only one event. Inspect the PHP error log for the generic `sent`, `retry`, `review`, and `canceled` counts, the `notification_events` status, and the recipient mailbox. `sent` means Graph accepted the request, not that the recipient received it. Return `MAIL_ENABLED=false` if any check fails.
4. Keep the interval hourly during development. For timely, production-grade delivery, replace this bridge with a private CLI scheduler or a strongly authenticated HTTPS trigger. Remove the public bridge once it is no longer needed. Never put a client secret or bearer token in an HTTP URL.

The bridge relies on the source IP that PHP sees as `REMOTE_ADDR`; it does not trust `X-Forwarded-For`. A shared proxy or changing scheduler IP may make this option unusable. A 503 response means it is disabled, not configured, or failed; a 404 means the caller was not allowed; a 204 means the bridge completed or there was no due work. The bridge records only counts and error classes in the server error log, never message contents or addresses.

The command prints only counts: `sent` (Graph accepted), `retry`, `review`, and `canceled`. It exits nonzero if disabled or if setup/database access fails. The queue is not processed merely because the API package is deployed.

## Monitoring and recovery

Super Admin and Clinic Admin can inspect the read-only **Email status** page at `/admin/notifications`. It lists clinic-scoped appointment email events, status counts, retry timing, attempts, and the last error. The corresponding API is `GET /api/v1/admin/notifications?status=needs_review&page=1`; it does not expose message payloads or provide a resend action. The page is not a delivery receipt: `sent` means Graph accepted the mail, not that it reached an inbox.

The worker retries definite transient failures with backoff, including Graph throttling ([429 guidance](https://learn.microsoft.com/en-us/graph/throttling)). It does not automatically resend a request with an unknown outcome (e.g. a network break after submission or a worker crash mid-send); those rows become `needs_review` to avoid duplicate mail. Review provider traces and the recipient mailbox before deciding whether to resend. Do not change those rows to `queued` casually. Provider delivery receipts and a controlled, audited recovery action remain future work.

The message contains the appointment date/time, a sign-in link, and a privacy-minimal `.ics` calendar attachment. Confirmation and change messages carry an event request with two display reminders: 24 hours and one hour before the start. Cancellation messages carry a cancellation with the same event ID and an increased sequence, without alarms. The attachment contains no client name, service, clinical notes, or visit address. The linked client can also download an `.ics` file from **My appointments** in the client portal. Mail delivery, automatic appearance in a recipient's calendar, and adoption of the supplied reminders are not guaranteed; the recipient's email/calendar provider and settings may require opening or accepting the event and may override alarms. This uses the existing mail permission and requires no database migration. It is bilingual until client language preference is integrated with notification delivery.
