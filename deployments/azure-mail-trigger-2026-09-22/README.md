# Azure mail trigger patch

Extract `wellness-api-cron.zip` into `/public_html/wellness/api/`. It contains only `cron/send-notifications.php`. The private API, Graph credentials, frontend, and database are unchanged.

Set `MAIL_TRIGGER_SECRET` to a newly generated random key (at least 32 characters) in private `/wellness-api/.env`. Leave `MAIL_ENABLED=false` until the Azure Logic App is configured and tested. See `documentation/EMAIL_DELIVERY.md` for the complete rollout and verification steps.

This patch uses the existing `notification_events` table; no SQL migration is required.

For Azure, deploy `logic-app-consumption.json` to the existing `rg-wellness-clients-dev` resource group as an **incremental** custom template deployment. Set `logicAppName` and `logicAppLocation` to the already-created Consumption Logic App. Enter the Netfirms key only in the secure `mailTriggerSecret` deployment field. The template replaces that Logic App's current recurrence-only workflow and leaves it **Disabled** so it cannot send mail until the endpoint has been verified. Do not put the key in the Logic App Designer's Default Value field: it is stored in plaintext, and Actual Value may be read-only.
