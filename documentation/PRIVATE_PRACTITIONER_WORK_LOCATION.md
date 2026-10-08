# Private practitioner work location

Practitioners configure their own starting location under **Practitioner → Profile → Private work location**. Enter a work address, or enter a home address and select **Same as home address**. Home is optional when work is elsewhere. The checkbox is a persistent relationship: updating home also changes the effective work origin; no duplicated work address is saved while selected. Unchecking it permits a separate address. Only complete Canadian addresses can be saved. Google confirms the origin and destination when coverage is checked.

These addresses are stored in `practitioner_private_locations`, separate from public clinic locations and practitioner cards. GET/PUT `/api/v1/profile/work-location` is restricted to the signed-in active practitioner in the current clinic. No administrator editing or other-practitioner access endpoint is provided. Audit records include the change/version and checkbox setting, never the address. Saving uses optimistic version checks and an SQL transaction; stale edits return a conflict without overwriting newer settings.

On-Site coverage uses the saved effective work origin and existing practitioner/service distance limit. Practitioners with no saved private settings retain the existing public clinic base calculation, preserving their current bookings. Do not enter private home/work addresses into public clinic location records. The setting does not alter public clinic addresses, service assignments, availability or existing appointment destinations.

Clients receive a coverage result and validation token, without an origin address, coordinates or exact distance. Out-of-area errors also omit exact distance. Staff coverage responses retain driving distance for scheduling. The signed token is readable, so distance is omitted there too; private origins are represented by a keyed fingerprint rather than a guessable address hash. The version invalidates prior tokens and saved coverage approvals on each save, including changes back to an earlier address. Recheck and approve coverage as needed. Coverage results still reveal whether a proposed visit address is served; this does not guarantee that a determined observer cannot infer a service-area boundary.

Google receives the starting and destination addresses server-side for validation/routing. Practitioner address suggestions use the existing Google browser integration on their private settings screen. No origin is returned to a client's browser or added to client map links, confirmations or public profiles. This slice does not change actual travel estimates from current/previous appointment locations or implement consecutive-appointment route optimization.

## Deployment

1. Back up the database and current application files.
2. Apply **only migration 038**, `api/database/migrations/038_practitioner_private_locations.sql`, once through phpMyAdmin. Do not rerun the complete schema or older migrations.
3. Generate a matching new release, then deploy the private API ZIP into the existing private `/wellness-api`, preserving `.env` and `var`, and the portal ZIP into Willow's actual document root. The portal includes the public API entry files. Existing release ZIPs do not contain this feature. No new environment flag is required.
4. As a practitioner, save home plus Same as home, refresh and confirm it persists. Change home and recheck On-Site coverage. Uncheck it, save another starting address and confirm routing uses that address.
5. As a client, confirm eligible/out-of-area results omit the private origin and exact distance, including token contents. Confirm an old coverage approval no longer works after the practitioner saves their origin. Check another account cannot access the private settings.

Hosted Google routing and database acceptance are separate from local synthetic tests. Keep the new table when rolling back code; do not delete address, approval or audit data to bypass validation. Restoring old coverage code restores its clinic-base behavior and client distance output, so review that rollback deliberately.

## Local validation

The isolated MariaDB rehearsal is:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest practitioner-work-location.php
```

It creates synthetic accounts and addresses without loading application `.env` files or calling Google. It checks migration SQL, ownership/clinic/role isolation, linked home updates, separate work origins, save conflicts, audit privacy, client response/token privacy, approval invalidation and clinic fallback. Browser tests use authenticated network substitutes and verify checkbox behavior, persistence and failed-save edit retention.

Local checkpoint, 8 October 2026: migration and 24 real SQL checks passed on isolated MariaDB 11.4.8; all 48 top-level PHP test scripts passed (RSA tests required the installed Git OpenSSL configuration on Windows). Both frontend builds, 13 built-site browser regressions, checkbox/persistence/error-retention tests and client booking/address-validation regressions passed. Hosted Google requests and Netfirms deployment have not been performed.
