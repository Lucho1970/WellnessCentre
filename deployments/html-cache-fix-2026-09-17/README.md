# HTML cache policy patch

This is a configuration-only update. The ZIP contains one hidden file: `.htaccess`.
The tracked source is [Frontend/public/.htaccess](../../Frontend/public/.htaccess), so
future website and portal builds include this policy automatically.

## Upload

1. Back up each web folder's current `.htaccess` outside the publicly served folders.
2. Extract `wellness-html-cache-patch.zip` (enable Show hidden files if needed).
3. Upload its `.htaccess`, replacing the file in **both**:
   - `/public_html/wellness/.htaccess`
   - `/public_html/wellness-portal/.htaccess`

Do NOT place this file inside either `api` folder or the private `wellness-api` folder.
No other application files, database scripts, API settings or Entra changes are needed.
If you customized the web-root .htaccess yourself, merge the index.html Files block
instead of overwriting unrelated hosting directives.

## Verify after upload

Run from the repository in PowerShell:

```powershell
./scripts/test-html-cache.ps1
```

Expected HTML Cache-Control: `no-store, no-cache, must-revalidate, max-age=0`.
The script checks the website root and /book plus the portal root, /client and /staff/login.
Existing HTML cached before this deployment can remain cached until its previous lifetime
expires. Use a fresh private window or clear cached site files for the first retest; if
Netfirms has an edge-cache control, purge those HTML URLs there as well.

Only index.html (including SPA routes rewritten to it) receives this override. Hashed
JavaScript/CSS and PHP API responses retain their existing cache behavior. Module guards
avoid unknown-module directives, but headers will not change if mod_headers is unavailable
or the host/CDN overrides them. A failed check needs hosting configuration review.
If the update causes an HTTP 500, restore the backed-up .htaccess and inspect the Apache log.

Two local configuration tests passed (`node --test Frontend/tests/cache-config.test.mjs`).
These are structural tests, not Apache integration tests. Local Apache/Docker runtime was
unavailable; Netfirms response-header verification is pending deployment.

References: [Apache mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
and [Apache mod_expires](https://httpd.apache.org/docs/2.4/mod/mod_expires.html).
