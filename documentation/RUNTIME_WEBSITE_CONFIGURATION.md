# Runtime public website configuration

Both deployed frontends fetch `/api/runtime-config.php` on their own host before loading application modules. The endpoint reads the shared private PHP application's `.env` and returns only `publicWebsiteUrl`. No database connection, authentication or secrets are needed for this public setting. Responses and browser requests disable caching. A missing, invalid or unavailable setting stops startup with the existing reload/contact-clinic message, rather than silently linking to an outdated host.

Set this in the **live private** `/wellness-api/.env` before deploying:

```env
PUBLIC_WEBSITE_URL=https://livinlively.com/
```

Later, change this value and refresh either site; no frontend rebuild is needed. Existing tabs retain their setting until refreshed. Local Vite development continues using `VITE_PUBLIC_URL`, because Vite does not serve PHP. `VITE_PORTAL_URL`, authentication callbacks and asset base paths remain build-time settings. The independent practitioner website/social-page feature remains planned separately.

## Initial deployment

1. Add the setting to the live private `.env`; local files do not update Netfirms automatically.
2. Deploy `api/src/Service/PublicRuntimeConfig.php` to the matching private application's `src/Service/` directory. Its namespace follows the existing Composer PSR-4 mapping.
3. Upload `api/deploy/netfirms/public/runtime-config.php` as `api/runtime-config.php` under **both** the public website and Willow portal document roots. Check both HTTPS endpoints return the configured URL and `Cache-Control: no-store` before replacing the frontend bundles.
4. Deploy both rebuilt frontend outputs. Public and portal API requests now use same-origin `/api/v1`; the `.com` public root must expose the existing thin PHP API entry point and share the private application. If `.com` uses a separate document root, upload the API public entry-point directory there too.
5. Upload the public `.htaccess` from the build. It temporarily redirects `.ca` and `www` variants to `https://livinlively.com/` with status 302, preserving paths and query strings. Remove any conflicting Netfirms host-level redirects from `.com` to `.ca`.
6. Test the public homepage, catalogue, images, portal navigation, client session display, booking, and the portal's Public website button. Change the setting to a test destination and refresh to confirm the link changes, then restore `.com`.

The PHP setting controls application navigation. Apache's canonical-host redirect is separate hosting configuration and does not read the PHP `.env`. To return the canonical site to `.ca`, update both the live setting and the hosting redirect after certificate repair.

HTTPS certificate validation happens before an HTTP redirect can be received. Consequently a redirect from `https://livinlively.ca/` cannot bypass its broken certificate. Give testers the direct `.com` URL until `.ca` HTTPS works. See [MDN's TLS explanation](https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Transport_Layer_Security).

## Checks

Run `php api/tests/public-runtime-config.php`, frontend `npm run build`, and the Playwright build smoke suite. The smoke checks include changing the destination without rebuilding and failure behavior when configuration cannot load. Hosted configuration and Netfirms redirects still require deployment verification.
