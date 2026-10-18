# Location discovery and travel enquiries

This release adds location filtering to Wellnexa's website and the shared clinic portal, private client–practitioner travel enquiries, practitioner opt-outs, and global distance controls. It builds on the deployed release through migration 047, including private work locations (038) and travel fees (044). Keep the existing clinic entry points pointing at the common portal.

## Database update: do this first

1. Back up the application database and the deployed API and website files.
2. In phpMyAdmin, **select the actual application database** in the sidebar. Run `location-discovery-preflight.sql`. The first result must show that database; every prerequisite must say `OK`. Stop if any table or column is missing. The script uses `DATABASE()` rather than assuming a database name.
3. Import `048_location_discovery.sql` using phpMyAdmin's Import tab. This creates eight new tables and seeds the global settings to **25 km extra allowance** and **100 km absolute cap**. It does not change existing appointments, travel maxima, fees, addresses or account permissions. Re-importing is supported and preserves configured settings and conversations.
4. Run `location-discovery-postflight.sql`. Expect eight table names and one settings row. Initially `extra_km=25`, `cap_km=100`, `revision=1`; later revisions reflect administrator changes.

Do not run the disposable integration fixtures against the hosted database. The release SQL is the migration above, not `schema.sql`.

## Upload destinations

Extract each ZIP's **contents**, replacing matching files:

| Package | Destination |
| --- | --- |
| `wellness-api-discovery-patch.zip` | `/wellness-api/` (private API; keep the `src/` paths) |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal/` |
| `copihue-portal-landing.zip` | `/public_html/wellness-portal/` (includes `/admin/`) |
| `wellnexa-website.zip` | `/public_html/wellnexa.ca/` |

Upload the API after the database migration, then the common portal, central administration and website. Include hidden `.htaccess` files, including the website's `api/.htaccess`. Keep the existing private `.env` and server configuration. This patch assumes the preceding API release is installed; it is not a replacement for the full API dependencies or previous migrations. Neither individual clinic entry folder needs another copy of the portal bundle. Refresh browsers after upload.

## Google Maps configuration

The search uses the existing private API `GOOGLE_MAPS_API_KEY` for **Geocoding API** and **Routes API** route matrices. Enable both for that key/project; retain Address Validation API for the existing final booking check. Keep this server key private with server/API restrictions appropriate to the host. It is never returned to the browser.

For map point selection, add this separate, public browser key to `/wellness-api/.env`:

```dotenv
GOOGLE_MAPS_BROWSER_KEY=YOUR_HTTP_REFERRER_RESTRICTED_BROWSER_KEY
```

Enable **Maps JavaScript API** on that browser key. Restrict allowed website referrers to the deployed origins, including `https://wellnexa.ca/*`, `https://wellnexa.copihue.ca/*` and any other participating clinic portal hosts. Use the same browser key as the portal's existing `VITE_GOOGLE_MAPS_BROWSER_API_KEY` to avoid conflicting Google loader configurations; retain its Places API access if the existing address entry uses it. Never substitute the private server key here. A browser key is intentionally public and is returned only as `map_browser_key`.

Without the browser key, the UI explicitly offers address and current-position search while explaining that the map is not configured. Without the server key/APIs, location search reports an error and ordinary unfiltered browsing remains available. Google location/routing requests use that project's configured billing and quotas. No live provider calls or key changes are made by the local test suite.

API references: [Geocoding requests](https://developers.google.com/maps/documentation/geocoding/guides-v3/requests-geocoding), [route matrices](https://developers.google.com/maps/documentation/routes/compute_route_matrix), [Maps JavaScript loading](https://developers.google.com/maps/documentation/javascript/load-maps-js-api).

## Where to configure and try it

- **Platform administrator:** open `https://portal.copihue.ca/admin/` → **Location discovery settings**. Edit the global allowance/cap and save. Updates use revisions to detect concurrent edits. These settings affect all clinics.
- **Practitioner:** clinic portal → **My profile** → **My travel settings** → **Extra-distance travel enquiries**. Each assigned On-Site service has its own opt-out in that clinic, initially enabled. Changing assignments does not erase an opt-out.
- **Website visitor or portal guest:** enter a Canadian city, postal code or address; choose **Use my current location**; or choose a map point and **Search selected point**. The browser asks for GPS permission only on deliberate click. The normal-coverage checkbox excludes enquiry-only matches. Clear the location filter to return to the full catalogue.
- **Signed-in client:** **Find nearby care** and the On-Site booking search default to the saved profile address unless a temporary tab-local search point exists. **Use my saved address** restores that address. The public website's **Sign in to use my saved address** opens this private portal workflow; the marketing website cannot read a private portal session across domains.
- **Extra-distance result:** **Ask about travel** opens the client portal. Sign in and link/register the client account if needed, enter a proposed visit area and message, then send. No personal practitioner phone number or email is supplied by the enquiry system. Avoid health details in this travel-specific conversation.
- **Practitioner / clinic operations:** **Travel enquiries** lists authorised conversations. Open one, reply, and optionally close it. The assigned practitioner and clinic super/clinic administrators or reception can access it. Other practitioners, clients and clinics cannot. The client has the same inbox in their account. Unread notices refresh every minute while the relevant workspace is open; inbox refresh is also available. Clinic staff reviewing a conversation share the practitioner's read state.

## Rules and limits

Discovery visibility ends at `min(practitioner maximum + extra allowance, global cap)`. With 50 + 25 and a 100 cap, visibility ends at 75 km. If extra enquiries are disabled, visibility ends at `min(practitioner maximum, global cap)`. Distances are one-way driving distances from the private work location, or the assigned clinic base when no private location is configured. Straight-line distance only excludes obviously distant candidates before calculating routes.

Within the normal maximum, results say **Within service area**. Beyond it they say **Travel approval required** and offer an enquiry. A service appears in filtered discovery only when an eligible, active, published, bookable practitioner offers it at an active assigned location. The filtered website's starting prices come from matching assignments. Normal published detail URLs remain readable; this is a discovery filter, not a restriction on viewing public profiles. In-clinic-only services are excluded from an active On-Site location search; clearing the filter restores the full catalogue.

Searches are approximate until the final complete visit address is validated. A map pin or city centre is not an appointment destination or a coverage approval. Changing the search point changes neither the saved client address nor any booked appointment. No practitioner starting coordinates, exact travel distance or private address are returned by discovery.

**This version provides enquiries and replies, not automatic travel exceptions.** Replies do not change fees, approve payment, reserve a slot, or bypass booking coverage. Staff must coordinate any resulting extra-distance appointment outside this enquiry workflow. Existing online booking still rejects addresses beyond the normal maximum. Session-specific negotiated fees and distance approvals remain future work. Messages stay in the portal; this release does not send SMS or email message notifications.

Search point preferences expire after 30 minutes and are stored in sessionStorage for the current tab, scoped to that website/clinic origin. Search proofs are encrypted, clinic-bound and expire after 30 minutes; the server rechecks current eligibility before creating an enquiry. Retry references prevent duplicate enquiries/replies after unknown network outcomes. Message bodies and visit-area text are not put in audit metadata.

Derived geocodes are cached server-side for one day; driving distances for ten minutes. Cache keys are keyed hashes, with expired cache cleanup during subsequent searches. Public searches are limited to 30/minute per clinic/IP and 1,500/day per clinic; the fixed website bridge shares its observed server IP, without trusting caller-forwarded IP headers. Provider calls have an additional 5,000/day global ceiling. Cold searches stop after 40 provider calls or an 18-second preparation window; retrying continues from cached progress, without displaying a partial result as complete. More than 2,000 active service/location combinations returns an explicit capacity error. Missing origins and absent driving routes cannot be treated as covered.

Enquiries are limited to ten new attempts/hour per client and replies to sixty/hour per sender. Private inboxes and messages are paginated. Location filters are also available directly in the guest availability and client booking forms; enquiry-only matches stay out of their bookable practitioner choices. Closing an enquiry keeps its history; it is not a deletion. Apply the clinic's established retention process to the new conversation tables. If rolling back the UI/API, retain these tables and messages; do not drop client communications to undo a deployment.

## Acceptance and build

Local validation covers TypeScript and all frontend production surfaces, bilingual resources, website/portal browser flows, map selection with a stub provider, and disposable MariaDB tests for distance boundaries, opt-outs, clinic/participant isolation, unread/read state and duplicate-safe messages. Map services and hosted database acceptance must still be checked after upload with the actual hosting configuration.

For hosted acceptance, use synthetic client details: verify a normal match, an enquiry-only match, and an out-of-range result; try the normal-only checkbox and temporary map area; send an enquiry, reply as the assigned practitioner, read it as the client, and check staff visibility and closure. Confirm another practitioner/clinic cannot open it. Recheck an ordinary booking and confirm its address still goes through normal coverage validation.

Build the packages from a committed tree using:

```powershell
powershell -NoProfile -File scripts/build-location-discovery-deployment.ps1 -ReleaseName location-discovery-2026-10-18
```

Install dependencies in both `Frontend` and `Wellnexa` before building. The website reuses the location-search component in `Frontend/src/discovery`; Vite deduplicates React. The release builder validates production authentication settings, includes source revision and ZIP hashes in `manifest.json`, and excludes private environment files and dependencies. Generated ZIPs stay outside Git.
