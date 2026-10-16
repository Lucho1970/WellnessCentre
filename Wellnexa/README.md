# Wellnexa public website

React and TypeScript application for **Wellnexa — Virtual Wellness Collective**, intended for `wellnexa.ca`. This is the public website starter; booking, client accounts and staff operations remain in the existing clinic portal.

## Develop from the solution root

Use Node.js 22.12+ (this scaffold was verified on 22.22.1) and npm.

```powershell
npm --prefix Wellnexa ci
npm --prefix Wellnexa run dev
```

Open `http://localhost:5175`. The dedicated port allows this website and the existing `Frontend` app to run together. Vite fails clearly if that port is already in use.

```powershell
npm --prefix Wellnexa run typecheck
npm --prefix Wellnexa run build
npm --prefix Wellnexa run preview
```

The preview uses `http://localhost:5176`. The build runs TypeScript checks before generating static files in `Wellnexa/dist/`.

## Structure

- `src/App.tsx`: minimal working-brand welcome page.
- `src/config/site.ts`: brand text and public portal destination.
- `src/App.css` and `src/index.css`: responsive starter styles.
- `public/`: assets copied into the build.
- `vite.config.ts`: development and preview configuration.
- `package-lock.json`: reproducible dependency versions.

The app has its own package and lockfile within the shared solution repository. Existing portal/public frontend and PHP dependencies are managed separately. The website reads published service and practitioner information from the Wellnexa portal's public endpoints; it does not authenticate clients or read clinical records. English/French site controls and additional public pages remain follow-up slices.

## Public configuration

The starter links to `https://wellnexa.copihue.ca/`. To override it locally:

```powershell
Copy-Item Wellnexa/.env.example Wellnexa/.env.local
```

Edit `VITE_PORTAL_URL` and restart the dev server or rebuild. All `VITE_` settings are visible in browser bundles; never put API secrets or database credentials in them. Local environment files, dependencies and build output are ignored by Git.

## Hosting later

For Netfirms, upload the **contents** of `dist/` to the document root assigned to `wellnexa.ca`, including `api/` and its hidden `.htaccess`. Do not upload the source folder or `node_modules`. No database migration, private `.env`, clinic hostname alias or provider callback change is required for this public catalogue integration. PHP with cURL and Apache rewrite support must be enabled; these are already used by the existing Netfirms portal. If client-side page routing is added later, include the corresponding server fallback rules in that development slice.

## Public catalogue integration

The browser calls same-origin `/api/public/services` and `/api/public/practitioners`. On Netfirms, `api/.htaccess` routes them to a read-only PHP bridge that fetches only the corresponding `/api/v1/public/` endpoints at `wellnexa.copihue.ca`. The bridge forwards no incoming cookies, authorization or query parameters, follows no redirects, verifies HTTPS, bounds response size/time, and projects an explicit set of public presentation fields. It cannot proxy appointments, client details, forms or staff APIs. Vite proxies the same two paths during development; no CORS changes or browser API keys are needed.

Only active/published records selected by the existing portal API are shown. Changes appear on the next page load or retry; responses are not cached. The current clinic has empty lists until services and practitioner public profiles are published in its portal. Loading, verified-empty and unavailable states are distinct, and errors do not replace real data with fabricated profiles. Booking links keep the service slug and permitted practitioner identifier.

The bridge and Vite proxy deliberately use a fixed clinic host. Changing `VITE_PORTAL_URL` changes links only; a host cutover must update the reviewed bridge/proxy targets to the same clinic. There is no request-controlled upstream URL or client-selected clinic ID.

Published practitioner cards display the profile photo already uploaded in the portal. The bridge includes only the public `has_image` and `image_version` fields; the browser loads photos from the fixed clinic's public `/api/v1/team/{slug}/image` endpoint. The version refreshes cached photos after an upload. No private photo URLs or account details are exposed. Cards show initials when no photo is published or an image cannot load. Upload the full website build, including `api/catalogue-lib.php`, when deploying this change; no database or portal API update is required.

```powershell
php Wellnexa/tests/catalogue.php
npm --prefix Wellnexa run test:browser
```

Browser tests use synthetic published cards and failure/empty fixtures. On Windows they use installed Microsoft Edge; other systems need `npx playwright install chromium` from `Wellnexa/`. Production acceptance: after upload, verify both same-origin API URLs return JSON, publish a controlled public profile/service and confirm the website and booking links, then unpublish it and verify removal on refresh. The existing portal remains the source of truth; no hosted writes are performed by local tests.

## Hosting destination

Upload the contents of `Wellnexa/dist/` (including `api/` and hidden `.htaccess`) to `/public_html/wellnexa.ca/`. This marketing build is separate from the shared clinic portal in `/public_html/wellness-common-portal/`. See [Deployment layout](../documentation/DEPLOYMENT_LAYOUT.md).
