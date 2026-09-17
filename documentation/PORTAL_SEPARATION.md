# Public website / portal separation checkpoint

16 September 2026 · Feature branch: `codex/public-portal-separation`

This is an implementation/deployment runbook for R1 in [Master Requirements](MASTER_REQUIREMENTS.md), not a replacement master plan. No database migration is required for this update. No tenant, DNS or live hosting changes have been made by this code change.

## What changed

- Two independently deployable builds: `Frontend/dist/public` and `Frontend/dist/portal`. Both share branding/configuration, MUI theme and the existing PHP API.
- Public home, `/book` availability browsing and `/contact`. The public build no longer initializes or includes workforce MSAL authentication. Unbuilt marketing/content sections are not presented as working modules.
- A separate staff login and operational layout, without the public hero, booking section or marketing footer. The round application avatar/initials menu retains profile upload and sign-out access.
- Real guarded routes, browser history/refresh support, desktop navigation and a mobile drawer. Existing feature components are retained and loaded on demand.
- Current API-derived permissions are preserved, not broadened. SuperAdmin still owns the existing restricted configuration screens; reception retains clients/bookings; practitioners retain appointment viewing; accountants get dashboard/profile until finance is built.
- Multi-role staff can switch between their eligible operations/practitioner workspaces. A remembered workspace is a preference, never an authorization grant. Switching does not replace the API's resource/role rules; it does not create a narrower server-side impersonation session.
- Legacy public `#portal` and `?portal=...` bookmarks redirect to the new portal and still pass authorization. `#booking` continues to open public browsing.
- Removed unsupported public claims that a time was held or an appointment was requested. The client route explains that customer sign-in/confirmation is not built. Handoff retains only non-sensitive booking preferences, with no tokens or client data in the URL.
- Shared public/auth response handling gives a readable error for empty/HTML API responses, with retry where applicable. Public requests are cancelled when selection changes or the page unmounts.

## Routes

Paths below are relative to the portal base: at a subdomain they start at `/`; for a same-host fallback they start at `/portal/`.

| Portal route | Released behavior |
| --- | --- |
| `/`, `/staff/login` | Staff sign-in, then eligible default workspace; root retained for workforce callback compatibility |
| `/login` | Redirect to client sign-in at `/client` |
| `/profile` | Redirect to eligible workspace's profile |
| `/admin` | Operations dashboard with truthful guidance, not invented metrics |
| `/admin/appointments`, `/admin/clients` | Existing authorized appointment and client workflows |
| `/admin/availability` | Existing availability/exceptions administration |
| `/admin/locations`, `/admin/rooms`, `/admin/services` | Existing catalogue setup and assignments |
| `/admin/practitioners`, `/admin/users`, `/admin/settings` | Existing SuperAdmin practitioner/staff/business settings |
| `/admin/profile` | Application-owned avatar/profile |
| `/practitioner`, `/practitioner/schedule`, `/practitioner/profile` | Current practitioner workspace, appointment list and profile |
| `/client`, `/client/book` | Isolated customer sign-in verification; record linking and booking not yet enabled |
| `/client/session` | Read-only, origin-checked initials display bridge for the public website; no tokens or account access shared |

Unknown paths and unauthorized module paths show safe errors without mounting the feature. API authorization remains mandatory and unchanged.

## Local development

Use two terminals in `Frontend`:

```powershell
npm run dev
```

Public site: `http://localhost:5173/`.

```powershell
npm run dev:portal
```

Portal: `http://localhost:5174/`. Keep the existing tenant/client/API settings in `.env.local`. Register the portal's local URL as an SPA redirect URI. Allow both exact local origins in the API if using a local backend. A hosted API must separately allow whichever local origins you intentionally use for development; no local MySQL is required when calling that hosted API.

Vite's normal environment precedence is preserved: builds read `.env.production`/`.env.production.local`, development reads its normal local environment. Two explicit TypeScript Vite configs select the surface, so old generated `vite.config.js` files cannot silently choose the wrong build. Production frontend environment values are public build-time configuration, not secrets.

## Deploy to a portal subdomain

**16 September hosting correction:** production portal builds now always use
`/api/v1` on the portal origin. Upload the two files from
`api/deploy/netfirms/public` into `/public_html/wellness-portal/api/`, including
`.htaccess`. Both entry points load the same private `/wellness-api`; no vendor,
environment, database or application-source copy belongs in the portal root.
The release builder includes these two files inside `wellness-portal.zip`.
`VITE_API_BASE_URL` still configures the public build and local development;
production portal builds deliberately override it. Direct `dist/portal` uploads
must also include the entry-point files. Vite preview itself does not execute PHP.
This avoids cross-origin preflight for deployed portal requests; it does not fix
Netfirms OPTIONS handling for cross-origin local development.

The owner confirmed `https://portal.copihue.ca/` mapped to `/public_html/wellness-portal`, and `https://wellness.copihue.ca/` mapped to `/public_html/wellness`. The hostnames are configured in `Frontend/.env.production`. These are owner-provided mappings; live DNS/routing and HTTPS readiness still require verification. Do not upload a `/portal/`-based fallback build unchanged to a subdomain root.

1. Verify that `portal.copihue.ca` serves `/public_html/wellness-portal` and has a valid HTTPS certificate. This is a sibling of `/public_html/wellness`, not a directory inside it. Do not point the subdomain at the private PHP application directory.
2. The current **frontend build** values are:

   ```dotenv
   VITE_PUBLIC_URL=https://wellness.copihue.ca/
   VITE_PORTAL_URL=https://portal.copihue.ca/
   VITE_API_BASE_URL=https://wellness.copihue.ca/api/v1
   ```

   Retain the existing three Entra IDs. By default the auth callback/logout destination is the portal origin plus its build base, so a subdomain-root build returns to `https://portal.copihue.ca/`. `VITE_ENTRA_REDIRECT_URI` is an optional exact override on that same portal origin/base; it must not point back to the public site. No client secret belongs in the frontend.
3. In the **existing staff SPA app registration**, add the exact HTTPS portal callback under the **Single-page application** platform. Keep authorization-code/PKCE via MSAL; do not enable implicit grant or add a client secret. Keep API audience, scope, role assignments and database identity links unchanged. Retain old callbacks during a controlled rollback window, then remove obsolete ones after acceptance. Existing public-site browser sessions do not transfer to the new origin: expect staff to sign in again.
4. Preserve the private API `.env`, including its explicit CORS allowlist. No configuration change is required for the same-origin portal entry point. Do not use `*` or change `APP_URL` to the portal. Cross-origin development still requires working hosting preflight handling; deployed portal requests no longer depend on that.
5. Run `npm run build` in `Frontend`. Upload **contents** of `dist/public` to `/public_html/wellness` and **contents** of `dist/portal` to `/public_html/wellness-portal`. Each destination should contain its own `index.html`, `assets/` and `.htaccess` directly, not an extra wrapper directory. Also install the portal API entry files described above, or use the deployment ZIP which includes them. Preserve hosting-required directives when updating `.htaccess`. Never overwrite/delete the existing website API, private `.env`, vendor or runtime uploads during this frontend deployment.
6. Test the checks below before considering the split deployed. No SQL script or data seed is needed.

The PHP API itself is unchanged by this feature; only the example CORS configuration includes the new local development origin. Actual server configuration must be updated by the operator.

### Same-host fallback

Without URL overrides, production builds use public `/` and portal `/portal/`. Upload portal assets beneath the public document root's `portal` directory and register `https://wellness.copihue.ca/portal/` as the staff SPA callback. This fallback is useful for staging, not an assumption that the owner has chosen it instead of a subdomain.

## Build and package

```powershell
cd Frontend
npm ci
npm run build
npm test
npm run test:build
```

The deployment script now produces `wellness-public.zip` and `wellness-portal.zip` instead of a combined `wellness-frontend.zip`, plus the existing API archives and SQL-update folder when generating a complete release. The frontend split needs no new SQL; that folder holds historical upgrades only. Do not run them again without inspecting the database's existing state.

```powershell
./scripts/build-deployment.ps1 -ReleaseName YOUR-UNIQUE-RELEASE-NAME
```

Run packaging from the repository root after configuring the actual deployment URLs and committing the source to be packaged. Packages include hidden routing files and exclude environment files. The complete [portal-split-2026-09-16 release](../deployments/portal-split-2026-09-16/README.md) is now generated for the confirmed hosts, with all four ZIPs and checksums tracked in Git.

## Verification

Local checkpoint results: both production builds and TypeScript checks pass; 13 browser/policy scenarios and 2 production-artifact/deep-link smoke tests pass. Desktop/mobile screenshots were inspected. Vite still reports a non-blocking size warning for the shared portal bootstrap bundle; individual operational screens are lazy-loaded. The complete ZIP packaging script has run successfully; archive hashes, required files, excluded private files and extracted PHP autoloading were verified. Production-artifact smoke tests passed again on the packaged build.

Automated browser/policy tests use synthetic API data and test-only network replacement of the auth module for role scenarios. There is no production authentication bypass. These tests verify frontend behavior, **not** real Entra authentication or database integration. The anonymous login test loads the real MSAL bootstrap. Tests use separate ports 5183/5184 and do not stop an existing development server. `npm run test:build` rebuilds both production outputs and runs artifact/deep-link smoke checks on ports 5193/5194, including the configured base paths and public bundle isolation. Its local login smoke check uses the default derived callback; a deliberately hardcoded hosted `VITE_ENTRA_REDIRECT_URI` must instead be checked on that actual host.

On Windows tests default to installed Microsoft Edge. Other systems use Playwright Chromium (`npx playwright install chromium`). `PLAYWRIGHT_CHANNEL` can select an installed supported channel. Screenshots go to ignored `.tmp/`; reports/traces stay out of source control. Keep automated checks for public isolation, browsing/handoff, API errors, unauthorized deep links, all role policies, profile menu, mobile navigation, history/refresh and legacy bookmarks.

Hosted acceptance remains required:

- Public and portal roots have valid TLS and load their own bundles. Public browsing does not contact Entra or `/auth/me`.
- Directly open and refresh `/admin/clients`, `/admin/appointments` and `/practitioner/schedule` on the portal host. No marketing page should replace them.
- Sign in with existing SuperAdmin, reception, practitioner and accountant test accounts; check allowed screens and denied URLs. Verify `/auth/me` and subsequent API calls from the portal origin, logout, fresh login and expired access.
- Open/edit an existing practitioner; create/edit a test client; complete a staff booking in the approved test dataset; change business configuration; upload/remove an application avatar. Check backend history/data, not just a success message.
- Test mobile menu and profile access with long real business names; keyboard navigation and focus; browser back/forward. Full WCAG acceptance remains separate.
- Verify old links, contact data, public API failures and client-unavailable messaging. No public selection should create or promise a reservation.
- Check an existing installed browser/service-worker state. If a previous obsolete application worker still serves the old site, unregister that worker and clear that site's cached assets deliberately; do not erase unrelated applications' browser data.

For rollback, restore the prior public frontend artifacts and prior routing configuration; keep the API/database intact. Retain old identity callbacks during validation so rollback login is possible. The new portal can be taken out of navigation without altering users or appointments.

## Still pending

DNS/TLS/document-root verification, actual Entra callback/CORS changes, hosted sign-in/database acceptance, customer identity and account claiming, client confirmation, unreleased care/finance/messaging modules and the broader launch gates. See R2 onward in Master Requirements. This checkpoint completes the separation in source, not a production launch.

## Future domain change

Keep the public and portal URLs explicit in environment configuration. When the new domain is chosen, set `VITE_PUBLIC_URL` to its public website URL and `VITE_PORTAL_URL` to `https://portal.NEW-DOMAIN/`, update `VITE_API_BASE_URL` if the API moves, then rebuild both surfaces. Update the corresponding API origin allowlist and staff SPA callbacks during the cutover. No business records or database identity links need to change merely because the domain changes. Do not derive the public host by removing `portal.`: the current public site uses a different subdomain.
