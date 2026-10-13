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

The app has its own package and lockfile within the shared solution repository. Existing portal/public frontend and PHP dependencies are managed separately. No API calls, authentication, fabricated practitioner/service records or routing framework have been added to this initial scaffold. English/French content, real catalogue integration and the public site pages can be added as the next development slices.

## Public configuration

The starter links to `https://wellnexa.copihue.ca/`. To override it locally:

```powershell
Copy-Item Wellnexa/.env.example Wellnexa/.env.local
```

Edit `VITE_PORTAL_URL` and restart the dev server or rebuild. All `VITE_` settings are visible in browser bundles; never put API secrets or database credentials in them. Local environment files, dependencies and build output are ignored by Git.

## Hosting later

For Netfirms, upload the **contents** of `dist/` to the document root assigned to `wellnexa.ca`. Do not upload the source folder or `node_modules`. Adding this scaffold does not change DNS, SSL, clinic host registration, provider callbacks or current deployment scripts. Configure hosting/SSL and verify the portal destination before publishing. If client-side page routing is added later, include the corresponding server fallback rules in that development slice.
