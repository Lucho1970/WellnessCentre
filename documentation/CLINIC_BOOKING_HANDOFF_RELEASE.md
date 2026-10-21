# Keep booking on the current clinic portal

The public availability page used the build-time VITE_PORTAL_URL when constructing its Book this time link. A portal build copied between clinic domains could therefore send a Wellnexa slot to the other clinic domain. Portal links now use the current hostname and the application's configured base path. The selected practitioner, location, service, duration, start time, visit type and language remain in the handoff. The existing client sign-in flow retains that intent on the same clinic portal.

The separate public website build continues to use its configured portal destination. This correction does not change identity-provider settings or the private API.

## Upload

Back up the common portal files, then extract `wellness-common-portal.zip` into `/public_html/wellness-common-portal/` once for both clinic hosts. Include hidden `.htaccess`, `api/`, index.html and assets directly in the destination. Do not upload this archive into either marketing site or `/wellness-api`. See [Deployment layout](DEPLOYMENT_LAYOUT.md).

No new database migration or private API upload is required for this link correction. Keep existing private API updates, including the separate clinic-specific time-off patch. Preserve uploads and runtime files; retain old hashed assets during the initial rollout so already-open tabs can finish safely. Refresh the page to load the new index and assets. An old tab may retain the old link until reloaded.

## Verify

1. Open Wellnexa's `/availability` page in a fresh tab. Select a service, practitioner, duration and available time.
2. Inspect **Book this time**: its hostname must be `wellnexa.copihue.ca`, with `/book` and the selected slot parameters. An installation under a subdirectory retains that base path.
3. Click the link and sign in if needed. The login and booking review must stay on Wellnexa and retain the slot selection. Availability and price are rechecked before confirmation; selecting a time does not reserve it.
4. Repeat the check on Livin’ Lively, which serves the same portal build: it must use that clinic's hostname.

Automated checks simulate a build configured for `livinlively.copihue.ca` while browsing another portal host and verify the generated link stays on the current host with the selected slot. Existing public website links, customer handoff and sign-in tests cover the surrounding behavior. Hosted acceptance remains separate; local tests never confirm a live appointment.

To roll back, restore the previous portal index and assets together. The release manifest records the source commit and ZIP hash. No database rollback is involved.
