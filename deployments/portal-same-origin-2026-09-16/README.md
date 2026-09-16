# Portal same-origin API update

Upload the contents of `wellness-portal.zip` to `/public_html/wellness-portal/`.
Back up the existing portal files first. Upload assets and the `api/` directory
before replacing `index.html`; preserve any Netfirms-required .htaccess directives.

The ZIP contains the portal frontend plus `api/index.php` and `api/.htaccess`.
Include hidden files. Do not create an extra enclosing directory.
The entry point loads the existing `/wellness-api` at the hosting account root.
It does not copy private application code, vendor, configuration or database.

No SQL, Entra, private `.env`, vendor or public website update is needed.
Authentication, role checks and token audience remain unchanged.

After uploading:

1. Open https://portal.copihue.ca/api/v1/health and confirm JSON status ok.
2. Open https://portal.copihue.ca/api/v1/auth/me without a token: expect JSON 401,
   not an HTML page. This confirms the route remains protected.
3. Hard-refresh https://portal.copihue.ca/login and sign in. Network requests for
   auth/me, profile/avatar and other API routes must use portal.copihue.ca/api/v1.
4. Verify profile, existing clients and appointments with an authorized account.
5. Remove the temporary preflight-probe.php from the public website after testing.

Rollback: restore the backed-up portal frontend/routing files. The original public
website API and private backend are untouched. Keep the small portal entry point
until rollback verification completes. The old cross-origin build may still fail
on this host; rollback is not a remedy for the existing hosting OPTIONS failure.

Local tests mock API responses; real hosted sign-in acceptance is still required.
