# Azure mail trigger patch

Extract `wellness-api-cron.zip` into `/public_html/wellness/api/`. It contains only `cron/send-notifications.php`. The private API, Graph credentials, frontend, and database are unchanged.

Set `MAIL_TRIGGER_SECRET` to a newly generated random key (at least 32 characters) in private `/wellness-api/.env`. Leave `MAIL_ENABLED=false` until the Azure Logic App is configured and tested. See `documentation/EMAIL_DELIVERY.md` for the complete rollout and verification steps.

This patch uses the existing `notification_events` table; no SQL migration is required.
