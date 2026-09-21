# Public service catalogue deployment

Source commit: `d4a2f1760e107f117dcb163117c2b33d750f11f7`

The owner confirmed that `009_public_service_catalogue.sql` has already been applied. Do not run it again.

## Deploy in this order

1. Extract `wellness-api-private.zip` into the private `wellness-api` application directory. Preserve the deployed `.env` and runtime data. Replace the generated `vendor` directory as a complete set; do not mix Composer files from different releases.
2. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the thin public API entry files are not already identical.
3. Extract the contents of `wellness-portal.zip` directly into `/public_html/wellness-portal`. It includes the portal's thin `api` entry directory.
4. Extract the contents of `wellness-public.zip` directly into `/public_html/wellness`.
5. Hard refresh both sites. In the portal, open Administration → Services and confirm the Category field appears above Service name.

## Acceptance

- Edit each existing service, assign its category, review its generated `service-{id}` public URL, fill approved English/French public content, and save.
- Confirm published services appear at `https://wellness.copihue.ca/services` and unpublished services do not.
- Open one service detail page and verify duration, price, appointment mode, locations, and published practitioners.
- Click a service or practitioner booking action and confirm the booking page retains the selection.
- Verify staff and client sign-in, `/api/v1/health`, and a normal portal refresh.

Archive hashes and entry counts are recorded in `manifest.json`. The packages contain no `.env` files.
