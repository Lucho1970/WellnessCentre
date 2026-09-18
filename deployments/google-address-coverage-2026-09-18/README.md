# Google address coverage deployment — 18 September 2026

Source commit: `20b02ee967b1b10e9fd9d9eb613ac3544566d4c7`

This release replaces the mobile-booking self-attestation checkbox with Google address
confirmation and driving-distance radius enforcement. It includes the current public site,
portal and private API.

## Before uploading

1. In a billing-enabled Google Cloud project, enable **Address Validation API** and
   **Routes API**.
2. Create a server-side API key restricted to those two APIs. Add an IP restriction only
   if Netfirms confirms a stable outbound IP.
3. Generate an application signing key locally:

   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`

4. Add the following to the existing private `/wellness-api/.env`. Do not paste the real
   values into Git, chat, a `VITE_` setting, or either web document root:

   ```dotenv
   GOOGLE_MAPS_API_KEY=your-restricted-server-key
   ADDRESS_VALIDATION_SIGNING_KEY=your-generated-random-key
   ADDRESS_VALIDATION_TOKEN_TTL_SECONDS=900
   ```

5. In the admin portal, ensure the base location has a complete street address and every
   mobile practitioner/service assignment has a driving coverage radius from 1–500 km.

No database migration is required. The `sql-updates` directory is included by the generic
packaging process for reference; do **not** rerun previously applied migrations.

## Upload order

1. Back up the current files and preserve the private API `.env`.
2. Extract `wellness-api-private.zip` into the private `/wellness-api` directory. Replace
   `src`, `vendor`, `bin`, `composer.json`, and `composer.lock` as one matched set; do not
   mix Composer-generated files from releases.
3. Extract `wellness-portal.zip` into `/public_html/wellness-portal`, including its `api`
   pointer files. Upload assets before replacing `index.html` if transferring manually.
4. Extract `wellness-public.zip` into `/public_html/wellness`, preserving the existing
   public `api` directory.
5. `wellness-api-public.zip` contains the two same-origin API pointer files for repair or
   verification; the portal package already includes its copy.

Archive sizes and SHA-256 hashes are recorded in `manifest.json`.

## Hosted acceptance

- Confirm `/api/v1/health` still returns `status: ok` through both site origins.
- Sign in as Super Admin and verify the base location postal address and a mobile radius.
- Start a mobile booking. Confirm “Find a time” stays disabled until address validation.
- Validate one known nearby address and confirm the displayed driving distance and limit.
- Edit the address and confirm validation is cleared.
- Try a known outside-radius and an incomplete address; neither may continue.
- Complete a mobile booking and verify it appears for the assigned practitioner.
- Confirm an in-clinic booking still follows its existing room rules.

If Google validation returns a temporary service error, first check the two enabled APIs,
key restrictions, billing status and private `.env`; the system intentionally fails closed
instead of restoring the trust checkbox. Full design and troubleshooting notes are in
`documentation/GOOGLE_ADDRESS_COVERAGE.md` in the repository.
