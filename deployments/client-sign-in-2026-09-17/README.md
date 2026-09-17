# Customer sign-in checkpoint — September 17, 2026

This release adds isolated customer identity verification at `/client`. It does not
create or link clinic records, confirm bookings, or grant staff permissions.
Google, personal Microsoft and email-code federation require hosted acceptance tests.

## Deploy

Back up the current deployed files first. Extract archive contents to the destinations
below (not an extra nested directory). Keep the server's existing private `.env` and
`var` directory, including uploads and runtime data.

| Archive | Destination |
| --- | --- |
| wellness-api-private.zip | Private account-root `wellness-api` folder, outside public_html |
| wellness-portal.zip | `/public_html/wellness-portal` (includes its API pointer) |
| wellness-public.zip | `/public_html/wellness` |
| wellness-api-public.zip | `/public_html/wellness/api` |

Deploy the complete matching vendor folder; do not mix individual Composer files from
different releases. Back up/rename the old vendor folder outside the webroot before
replacing it. Do not upload the entire repository or private API inside public_html.

Add these settings to the existing private `wellness-api/.env`:

```dotenv
CUSTOMER_ENTRA_TENANT_ID=0a3841c6-b244-410d-821f-bbd9ccd1b5e2
CUSTOMER_ENTRA_SUBDOMAIN=copihuewellnessclientsdev
CUSTOMER_ENTRA_API_CLIENT_ID=08542bbb-09cc-4737-979b-ca61c7eec70d
CUSTOMER_ENTRA_SPA_CLIENT_ID=7a522317-d74f-4ccb-9805-8e4b912c02ab
```

These are public identifiers, not secrets. Keep all existing staff, database and APP_KEY
settings. The frontend archives already contain the matching development identifiers.
Ensure PHP can write `wellness-api/var/cache`.

**No SQL update is required.** `sql-updates` contains historical migrations for complete
installations; do not rerun migrations already applied.

## Before testing

In the customer SPA registration, verify these SPA redirect URIs:

- `https://portal.copihue.ca/client/auth/callback`
- `https://portal.copihue.ca/client` (logout return)

The personal-Microsoft federation registration uses Web callbacks, not these SPA
callbacks. See the complete [setup and acceptance runbook](../../documentation/CLIENT_SIGN_IN_SETUP.md)
for its additional domain-form callback and Google test-user configuration.

Open `https://portal.copihue.ca/client` in a private window. Test each provider,
reload and sign-out; expect **Customer sign-in verified** and an unlinked-record notice.
Also retest existing superadmin/practitioner access. Do not share tokens or callback codes.
Real provider sign-ins and Netfirms deployment are not verified by the local tests.

Local verification: 112 PHP checks, 22 browser checks, TypeScript and both frontend
builds passed. `manifest.json` records the source commit, archive hashes and entry counts.
Packaged Composer autoload/classes were checked after extraction. Production-host checks
passed for the staff deep link, reload and customer page. The generic localhost staff
smoke check rejects this production build as expected because its configured staff
redirect belongs to portal.copihue.ca; the public-isolation and customer-callback checks passed.

Rollback: restore the previous portal/private API packages from your backup while
preserving `.env` and `var`. No database rollback is needed.
