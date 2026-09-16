# API preflight response correction — 16 September 2026

Follow-up to `portal-split-2026-09-16`. Source commit: `ee31e59`.

The response helper previously serialized JSON even for a 204 No Content response. It now exits without writing a body or JSON/content-length headers for 204, preserving the status, correlation ID and existing CORS headers. Normal JSON responses and API authentication/authorization are unchanged.

## Upload this one-file patch

1. Download `wellness-api-preflight-fix.zip` using GitHub's **Download raw file** button.
2. Back up the server's `/wellness-api/src/Http/Response.php` outside the public web root.
3. Extract the ZIP locally. Upload its `src/Http/Response.php` to `/wellness-api/src/Http/Response.php`, replacing only that file. Alternatively, extract its contents directly into the existing private `/wellness-api/` directory, preserving the `src/Http/` path.
4. Do not replace vendor, `.env`, frontend files or the database. No SQL, Composer install or frontend rebuild is required on the server.
5. Retest the cross-origin preflight and then reload `https://portal.copihue.ca/login` and retry sign-in.

The archive intentionally contains only one file (587 bytes compressed). Its checksum is in `manifest.json`; the archived source was compared with the tested source byte-for-byte.

## Verification

All 63 local PHP checks passed, including 13 new preflight/response checks. The new test verifies zero body bytes at the PHP response helper (before an HTTP server could suppress them), 204 on the wire, the allowed-origin/header/method policy, correlation IDs, rejection of untrusted origins by omission of allow-origin, 401 on protected requests without authentication and unchanged ordinary JSON responses. The test uses only synthetic configuration and a loopback server; no live database or account is accessed.

Hosted resolution is **not yet confirmed**. The malformed response is a verified source defect, but Netfirms may have a separate PHP-handler/proxy issue. If OPTIONS still returns 500 after this patch, keep the next investigation focused on hosting/request handling rather than weakening authentication or broadening CORS.

Read-only verification from a terminal:

```powershell
curl.exe -i -X OPTIONS https://wellness.copihue.ca/api/v1/auth/me -H "Origin: https://portal.copihue.ca" -H "Access-Control-Request-Method: GET" -H "Access-Control-Request-Headers: authorization"
```

Expected: HTTP 204, `Access-Control-Allow-Origin: https://portal.copihue.ca`, allowed headers/methods and no response body. No token is required or should be pasted into this check.

Rollback, if required: restore only the backed-up `Response.php`. The complete September 16 portal-split ZIP remains a historical base package and does not contain this follow-up correction; apply this patch after deploying that package.
