# Wellnexa location-search diagnostics

29 October 2026. Reported error: `maps_unavailable`, correlation `a33afae7-f10f-40ae-a926-378c5fe6f262`. The exact hosted provider failure is **not yet established**. This patch adds safe diagnosis; it does not claim that changing a URL or the Google account is the proven fix.

## Confirmed observations

- `GET https://wellnexa.ca/api/public/discovery/options` and `GET https://wellnexa.copihue.ca/api/v1/public/discovery/options` returned HTTP 200 JSON with the expected settings. The website bridge and configured upstream route respond. The reported `maps_unavailable` comes from provider handling after the search route has been reached, not a missing route.
- The optional `map_browser_key` was absent in both option responses. This affects the optional browser map; typed address search uses the separate private server key. No private key was printed or copied into a browser bundle.
- The “See if we service your area” widget on `https://www.tuff-tar.com/` loads `/views/contact.html` and `scripts/app/googleApi.js`, using browser `google.maps.DirectionsService` and Places Autocomplete. Wellnexa uses PHP server-side Geocoding and Routes matrix calls. One widget working does not establish the other integration's key/API/billing/IP permissions. The locally configured server key differs from Tuff-Tar's browser key; the effective hosted server key was not accessed or verified.
- The original provider payload is coordinate-based: discovery geocodes practitioner origins before computing routes. An initial investigation incorrectly assumed raw address origins; the SQL test caught that assumption. It was corrected before packaging. The patch preserves numeric origins/destination and does not add private address strings to Routes requests.

Google documents the [coordinate waypoint contract](https://developers.google.com/maps/documentation/routes/specify_location-rm) and [separate application/API key restrictions](https://developers.google.com/maps/api-security-best-practices). Server web-service calls and browser SDK calls have different restriction requirements. No provider grants, restrictions or credentials were changed during this task. No live address search or Google provider test was sent by the coding agent; only public website/options reads and synthetic/local fake-provider tests were performed.

## Install on the existing full build

This patch is for the cumulative `2026-10-29-full` application (`f738e22`), whose API runtime also matches the later build-evidence commit. Back up these private files and coordinate API/worker process reload before replacing them. Extract **`wellness-api-maps-diagnostics.zip` directly into `/wellness-api`**, retaining the ZIP's directories:

| Patch entry | Hosted file |
| --- | --- |
| `src/Api.php` | `/wellness-api/src/Api.php` |
| `src/Service/LocationDiscoveryService.php` | `/wellness-api/src/Service/LocationDiscoveryService.php` |
| `src/Service/DiscoveryMapsClient.php` | `/wellness-api/src/Service/DiscoveryMapsClient.php` (new) |
| `bin/discovery-preflight.php` | `/wellness-api/bin/discovery-preflight.php` (new, CLI-only) |

Upload all four as a coordinated patch; the API references the new client. Preserve `.env`, vendor, uploaded files and runtime data. The existing optimized Composer PSR-4 loader supports the new class without a dependency update. A custom authoritative-classmap installation must regenerate its existing autoloader through the approved deployment process. No database migration or website ZIP is required. Do not put these private files in `/public_html/wellnexa.ca` or change public API rewrites to tuff-tar.com.

## Identify the host-specific failure

If CLI is available, run privately on the intended host:

```text
php /wellness-api/bin/discovery-preflight.php --env /wellness-api/.env
```

Default mode checks only the presence of effective server/browser key settings. It prints booleans, not values, and makes no database/provider connection. Exit 0 means a server key is present, not that Google accepts it; exit 2 means the server key is missing.

For an authorized operator to test the actual hosted server key explicitly:

```text
php /wellness-api/bin/discovery-preflight.php --env /wellness-api/.env --check-google
```

This makes **at most two billable Google requests**: geocoding Toronto City Hall, then a driving matrix between public Toronto landmarks. It reads no clinic/patient database or private practitioner address. It does not change key permissions, clinic data or settings. Exit 0 means both calls passed; exit 1 reports a failure using the redacted diagnostics. Process environment values take precedence over the private file, as in the app. Never expose this script or `.env` as a public diagnostic endpoint.

If CLI is unavailable, retry the ordinary search after patch/reload and obtain only the matching **`discovery_maps_failure`** JSON line from the private PHP error log. A new failure has a new reference; diagnostics cannot retroactively explain the earlier UUID. Events contain operation, fixed reason category, HTTP status, numeric cURL error, allowlisted provider status/restriction reason and correlation ID. They exclude request/response bodies, keys, full URLs, addresses, coordinates, arbitrary Google messages and provider metadata. Public error codes/messages stay generic.

| Redacted result | Operator follow-up |
| --- | --- |
| `operation: geocode`, `provider_status: REQUEST_DENIED` or HTTP 403 / `PERMISSION_DENIED` | Verify the effective private `GOOGLE_MAPS_API_KEY`, its project, Geocoding API enablement, billing and restrictions. A working browser Directions key is not this check. |
| `provider_reason: API_KEY_IP_ADDRESS_BLOCKED` | Have hosting/security verify the actual server egress IP against the approved key's server restrictions; do not assume the website DNS IP is its outbound IP. |
| `API_KEY_HTTP_REFERRER_BLOCKED` / `API_KEY_SERVICE_BLOCKED` / `SERVICE_DISABLED` | Review the correctly separated server key and the specific existing Geocoding/Routes API allowlist with the credential owner. Do not remove restrictions or substitute a public browser key to bypass a denial. |
| `operation: routes`, HTTP 400 / `INVALID_ARGUMENT` | Review deployed matching files and coordinate contract against the safe diagnostics. Do not infer that the user's address is invalid from a provider request failure. |
| HTTP 429 / `RESOURCE_EXHAUSTED` / `OVER_QUERY_LIMIT` | Check project quotas/billing and existing usage; no automatic retry or quota increase is added. |
| `curl_errno` nonzero, HTTP 0 | Hosting operator checks DNS, connectivity, timeouts and trusted CA configuration. Keep TLS certificate/host verification enabled. |

Browser map configuration uses `GOOGLE_MAPS_BROWSER_KEY` with approved website restrictions; it is separate from `GOOGLE_MAPS_API_KEY`. Changing permissions, billing or restrictions requires the credential owner's authorized review. This task makes no such changes.

## Implementation and validation

`DiscoveryMapsClient` isolates the existing two fixed HTTPS endpoints and their bounded cURL response parsing so raw provider requests can be tested. Discovery's quotas, time/call limits, geocode/distance caches, clinic filtering, coverage thresholds, encrypted proofs and consent authority remain in their existing services. Successful requests do not add diagnostics. No runtime dependency, identity/permission grant or external endpoint/data flow is added.

- `php api/tests/discovery-maps-client.php`: **206 checks passed**, all requests fake; numeric waypoint contract, Canadian geocoding, field masks, errors, malformed/oversized responses, timeouts and diagnostic redaction.
- `php api/tests/discovery-preflight.php`: **8 checks passed**, no Google/database calls; default configuration-only behavior, missing keys, invalid arguments and no key disclosure.
- Every root `api/tests/*.php` invoked individually with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`: **61 scripts passed** on PHP CLI 8.4.14.
- Recursive `php -l` in api/src, api/public, api/bin, api/tests and Wellnexa/public/api: **256 files passed**.
- `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13356 -IntegrationTest location-discovery.php`: **34 recurrence + 65 discovery/travel checks passed** on disposable MariaDB 11.4.8; server stopped.
- `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13357 -IntegrationTest location-discovery.php`: **the same 34 + 65 checks passed** on disposable MySQL 5.7.44; server stopped.
- PowerShell parsing for both changed/added runners and `git diff --check`: passed.
- Initial disposable SQL attempts rejected the investigation's incorrect raw-address origin assumption. The client/test fixtures were corrected to preserve the service's original geocoded-point contract. A subsequent cold-search assertion also needed to expect both search-point and practitioner-origin geocoding before Routes. Both engines were rerun successfully. These were development diagnostic/test errors, not evidence of an existing payload bug.
- No frontend source/configuration changed; the full frontend builds/browser suites were not repeated for this private PHP diagnostic patch. The [full-build checkpoint](DEPLOYMENT_BUILD_2026-10-29.md) records their prior results. No hosted configuration/provider acceptance was performed.

Versioned files: `api/src/Api.php` forwards validated request correlation IDs; `api/src/Service/LocationDiscoveryService.php` retains policy and delegates provider handling; `api/src/Service/DiscoveryMapsClient.php` adds testable transport/redacted failures; `api/bin/discovery-preflight.php` adds private operator checks; `api/tests/discovery-maps-client.php` and `api/tests/discovery-preflight.php` add provider/CLI regressions; `api/tests/integration/location-discovery.php` exercises cold search through real request serialization; `scripts/test-mysql57-sql.ps1` permits that existing suite; `scripts/build-wellnexa-maps-patch.ps1` packages/verifies the four private files; API/documentation READMEs index this guide; this file records scope, evidence and operator actions.

Rollback: restore the previous `Api.php` and `LocationDiscoveryService.php` together and reload processes; the added client/CLI can remain unused. No database restore is needed for this patch. Do not replay notifications or weaken restrictions while diagnosing maps. The hosting/credential owner must establish the actual hosted failure and confirm successful search; broader privacy/security/real-PHI release gates remain open.

RELEASE STATUS: NOT READY FOR RELEASE
