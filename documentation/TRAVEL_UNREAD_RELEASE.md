# Travel inbox fixes and polling deployment

This release combines PR #13 (restored MySQL 5.7 travel-inbox compatibility) and PR #14 (authorized unread counts and visibility-aware polling). The outer download ZIP is a local bundle: extract it on your computer first. Upload only the contents of the two application ZIPs to the destinations below. Keep documentation, manifests and catalogue files off the public website.

| Archive | Destination | Order |
| --- | --- | --- |
| `wellness-api-private.zip` | `/wellness-api` | First |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal` | Second |

There is **no new SQL migration** for these fixes. Do not rerun migration 052 if it is already applied. The service importer still requires that earlier migration when used; this release does not create its table. No marketing-site, central landing-page, mail-bridge or Azure Function update is required. This release does not update the hosting PHP runtime.

## Upload

1. Back up the current private API and common portal files locally. Keep the current private `.env`, uploaded files, runtime data and permissions. The application ZIPs exclude private environment files and catalogue attachments.
2. Overlay the contents of `wellness-api-private.zip` into `/wellness-api`. Confirm `src/Api.php`, `src/Service/TravelEnquiryService.php`, `src/Service/ConsentAccess.php`, `src/Service/AccessAudit.php` and `vendor/autoload.php` are directly beneath that existing application root. Do not put the private application under `public_html` or add an extra enclosing directory.
3. After the API upload finishes, overlay `wellness-common-portal.zip` into `/public_html/wellness-common-portal`, including hidden `.htaccess` files and thin `api/` entries. When uploading individually, upload new assets first and `index.html` last. Keep old hashed assets through rollout and avoid using the app during the upload.
4. This portal directory serves both `livinlively.copihue.ca` and `wellnexa.copihue.ca`; upload it once. Remove uploaded ZIPs after extraction. Do not upload this outer bundle into the website root.
5. Refresh both portals with Ctrl+F5. Confirm clinic identity, sign-in and ordinary service/inbox screens. The complete new API must be present before the new frontend; the previous one-file MySQL patch does not add the unread-count route.

These archives include both fixes. Do not subsequently upload the historical `service-import-2026-10-21` private API archive or the earlier one-file travel patch: either could replace newer service code and remove the count endpoint. Build future releases from current main, retaining the source revision and checksums in the manifest.

## Verify on Netfirms

Use synthetic data where possible. Local regression checks do not establish hosted database or identity-provider acceptance.

1. Sign into the intended clinic as authorized staff. On `/admin/services`, open browser Network. Expect `GET /api/v1/travel-enquiries/unread-count` with HTTP 200 and data containing only a non-negative `unread_count`. Client sessions use `/api/v1/customer/travel-enquiries/unread-count`. Names, message text, visit areas and thread IDs must not appear in count responses.
2. Leave the tab visible: healthy count checks occur roughly once a minute after the prior request completes. Outside the inbox, badge polling must not download `/api/v1/travel-enquiries` lists. Opening the inbox still loads its authorized list/detail endpoints.
3. Switch to another tab or minimize the browser for over a minute. Periodic checks should stop. An already-sent request may finish. Return to the portal tab; a fresh count should be requested immediately, or after the older pending request completes.
4. Verify the inbox list and unread indicator with a permitted account, including a linked client session. Confirm a synthetic consent withdrawal removes affected staff counts and denied access discloses no patient information. Hidden/foreign enquiry records must not affect a user's count.
5. Failures clear the badge and retry after 2, 4, 8, then at most 15 minutes; a successful response restores the one-minute interval. An absent badge after an error does not prove the inbox is empty. Returning to a visible tab requests a fresh count without waiting for that backoff.

If the new route returns `not_found`, check the actual hosted private API path and updated `src/Api.php`. If code is current but a server error remains, use its correlation ID for privacy-safe error-log diagnosis. Do not run migrations or remove consent restrictions to work around route/query errors. If the host continues serving an older file after a verified replacement, ask Netfirms to invalidate/restart the relevant PHP cache rather than publishing a public diagnostic/reset script.

## Rollback and rebuild

Restore the previous compatible API and portal index/assets/routing files together, preserving private configuration and runtime data. Restoring an API revision before the MySQL correction can bring back the original inbox failure. No new database changes need rolling back. Uploads in this package do not import or delete services, enquiries, messages or patient records.

Rebuild from a clean committed main revision:

```powershell
./scripts/build-travel-polling-deployment.ps1 -ReleaseName travel-polling-YYYY-MM-DD
```

The builder produces two upload archives, instructions, a source-revision manifest and an outer download ZIP with SHA-256. Generated outputs remain ignored under `deployments/`. No hosted upload, SQL execution or live import is performed by the script.
