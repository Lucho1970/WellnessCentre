# Google-assisted address entry deployment — 18 September 2026

Source commit: `07892e76da8e19e092f7638bc404854eebf9b562`

This release includes the Google server-side address/radius validation checkpoint plus a
reusable Canadian address-autocomplete control for mobile booking, staff client records,
customer profile/onboarding and clinic locations. Manual entry remains available.

## Configuration prerequisites

The Google Cloud project must have Address Validation API, Routes API, Places API (New)
and Maps JavaScript API enabled. Use separate keys:

- private `/wellness-api/.env`: `GOOGLE_MAPS_API_KEY`, restricted to Address Validation
  and Routes; plus `ADDRESS_VALIDATION_SIGNING_KEY` and the optional 900-second TTL;
- frontend build: `VITE_GOOGLE_MAPS_BROWSER_API_KEY`, restricted to Maps JavaScript,
  Places API (New), and approved HTTP referrers.

The portal/public ZIPs were built with the locally configured browser key. The key is
expected to be visible in browser JavaScript; its API and HTTP-referrer restrictions are
the security boundary. The private server key is not included in any archive.

No new SQL migration is required. This release uses `client_contact_addresses`, which was
created by the already-applied migration 005. Do not rerun migrations in `sql-updates`.

## Upload order

1. Back up deployed files and preserve the private `.env`.
2. Extract `wellness-api-private.zip` into private `/wellness-api`. Replace `src`, `vendor`,
   `bin`, `composer.json`, and `composer.lock` as one matched set.
3. Extract `wellness-portal.zip` into `/public_html/wellness-portal`, including its `api`
   pointer. Upload assets before replacing `index.html` if transferring manually.
4. Extract `wellness-public.zip` into `/public_html/wellness`, preserving its existing
   public `api` folder.
5. `wellness-api-public.zip` is supplied only for pointer repair/verification.

## Hosted acceptance

- Open client create/edit, clinic location edit, customer profile and mobile booking.
  Confirm Canadian suggestions appear and selecting one fills street, city, province,
  postal code and country. Unit and access instructions remain editable.
- Confirm manual entry still works if suggestions are unavailable.
- Save a staff-managed client address, reopen the client, and verify it was retained.
- In mobile booking, select an address, run coverage validation, and confirm a known nearby
  address succeeds while an outside-radius address fails.
- Edit any destination field after validation and confirm “Find a time” is disabled until
  validation runs again.
- Confirm the Google Maps attribution and privacy/terms links remain visible.
- Verify both English and French labels and check browser console/network failures.

Before a production launch, publish clinic-reviewed public privacy and terms pages that
disclose Google Maps processing and link to Google's applicable policies. The in-control
links and attribution in this development release do not replace the clinic's own policy.

Archive sizes and SHA-256 hashes are recorded in `manifest.json`.
