# Complete public / portal deployment — 16 September 2026

Built from source commit `a078deb` on `codex/public-portal-separation`. Includes the current public website, separate staff portal and complete PHP API. This is a development deployment, not a declaration that all planned production features are ready.

Download the ZIPs from this branch using GitHub's **Download raw file** option. Extract their contents directly into the destinations below, not into extra ZIP-named wrapper folders. `manifest.json` records source, SHA-256 hashes, sizes and entry counts.

| Package | Netfirms destination | Expected contents |
| --- | --- | --- |
| `wellness-public.zip` | `/public_html/wellness/` | `index.html`, `assets/`, `.htaccess` |
| `wellness-portal.zip` | `/public_html/wellness-portal/` | `index.html`, `assets/`, `.htaccess` |
| `wellness-api-public.zip` | `/public_html/wellness/api/` | `index.php`, `.htaccess` |
| `wellness-api-private.zip` | `/wellness-api/` outside `public_html` | `src/`, `bin/`, complete `vendor/`, Composer manifests |

Paths are relative to the existing hosting account layout. Keep the API at its existing location; do not copy it into the portal directory. Both frontend builds call `https://wellness.copihue.ca/api/v1`.

## Upload sequence

1. Back up the current files, private configuration/runtime data and database. Use a maintenance window while replacing API files. Do not delete the website root or private application directory.
2. Preserve `/wellness-api/.env` and any runtime/upload directories. Rename the existing `/wellness-api/vendor` to a backup name, then upload/extract **all** contents of `wellness-api-private.zip` into `/wellness-api/`. Do not mix individual old/new Composer files; a previous partial vendor update caused autoloader failures. Keep the vendor backup until verification passes.
3. Upload/extract `wellness-api-public.zip` into `/public_html/wellness/api/`.
4. Upload/extract `wellness-portal.zip` into `/public_html/wellness-portal/`.
5. Upload/extract `wellness-public.zip` into `/public_html/wellness/`, **preserving its existing `api/` directory**. Do not upload the parent `dist` folder. Show hidden files and verify `.htaccess` on both sites; preserve any host-required directives when updating routing files.
6. Configure the items below and test both sites. An uploaded portal is not ready for sign-in until its callback and API origin are configured.

No secrets, `.env` files, client data or sample seed data are included. Existing server settings are intentionally not overwritten.

## Settings to configure after upload

- Confirm Netfirms maps `https://portal.copihue.ca/` to `/public_html/wellness-portal` with a valid HTTPS certificate. Public site remains `https://wellness.copihue.ca/`.
- Add `https://portal.copihue.ca/` as an exact **SPA redirect URI** in the existing staff Entra app registration. Keep the existing API scope, tenant and roles. No frontend client secret is required. Keep the prior callback during the rollback window.
- In the private API `.env`, ensure the comma-separated origin allowlist includes both origins (no trailing slashes):

  ```dotenv
  CORS_ALLOWED_ORIGINS=https://wellness.copihue.ca,https://portal.copihue.ca
  ```

  Preserve any other deliberately allowed development origins. Do not use `*`, and do not replace database credentials or change `APP_URL` merely to configure CORS.
- Frontend URLs are already compiled for these hosts. Staff authentication returns to the portal root. A future domain change requires updating the frontend URL configuration and rebuilding, plus updating the corresponding callback/origin settings.

## Database

**No new SQL migration is required for the portal split.** The `sql-updates/` folder contains the previously issued 001–003 migrations for completeness. Run only missing migrations, in order, after checking the actual schema and taking a backup. Do not rerun already-applied scripts or run a fresh-install schema/seed over the existing database.

## Verification and limitations

1. Open both HTTPS roots. The public site has discovery/contact/booking browsing; the portal has staff sign-in, not marketing content.
2. Confirm `/api/v1/health` on the wellness host responds. Do not leave temporary diagnostics publicly accessible.
3. After callback/CORS configuration, sign in as existing staff and verify the authorized dashboard, clients, appointments, catalogue and profile screens. Refresh a direct portal URL such as `/admin/clients` or `/practitioner/schedule`.
4. Test profile upload, an approved development client edit and a staff booking; verify data persisted. Sign out and back in. Check denied routes with restricted roles.
5. Check browser network/console for CORS, token, missing-asset or routing failures. If an obsolete service worker serves old content, clear only this application's obsolete cached assets/worker deliberately.

Customer Google/Microsoft login and online confirmation are not implemented yet; the public handoff says so and makes no reservation. Staff booking remains available. Notification events are queued, but email delivery is not enabled; contact clients directly. Broader launch/security/database acceptance remains separate from frontend automated checks.

Source testing before packaging passed both builds, 13 browser/policy tests and 2 production-artifact/deep-link smoke tests. The role scenarios use synthetic API/auth fixtures; they do not prove live Entra or MySQL behavior. See [the full checkpoint](../../documentation/PORTAL_SEPARATION.md) for details and remaining acceptance checks.

Release verification: all four archive hashes and required files match the manifest; no environment files, Git metadata or temporary diagnostics are included. The extracted private package successfully loads the API and JWT classes using its own Composer autoloader. The two production-artifact smoke tests passed again on this build. Archive entry counts are 5 public-site files, 30 portal files, 2 public API files and 117 private API files; the small two-file public API archive is intentional.

For rollback, restore the prior frontend/API files and routing configuration from backup without overwriting retained `.env` or runtime data. Keep matching vendor files together. This release requires no database rollback because it introduces no schema change.
