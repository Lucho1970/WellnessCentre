# Consolidated deployment package

Built from `main` commit `a4e32d7` after the Azure mail scheduler work was merged. This is a full reference package for a future clean deployment, **not an instruction to redeploy the working site now**. The authenticated `cron/send-notifications.php` pointer is included in `wellness-api-public.zip` and under `api/cron/` in `wellness-portal.zip`.

Current production already has the cron pointer, and the Azure Logic App has delivered a test email successfully. No file or database update is required for this release. Do not rerun SQL migrations that were already applied, especially `016_notification_delivery.sql`, which intentionally changes old queue rows.

The `tuff-tar-mail-bridge.zip` archive is retained only for completeness; the old Netfirms scheduled job should remain disabled. Keep `MAIL_TRIGGER_SECRET` and Graph credentials only in the private `/wellness-api/.env`. The archives exclude `.env` files.

See `manifest.json` for archive checksums and `documentation/EMAIL_DELIVERY.md` for the scheduler setup and monitoring guidance.
