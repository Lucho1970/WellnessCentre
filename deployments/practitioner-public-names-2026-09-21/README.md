# Practitioner public names deployment

Source commit: `6b06edd`

This release includes the practitioner structured-name fields, separate internal and public names, friendly booking names such as **Book with Esther**, duplicate booking-name warnings, and the latest localization changes.

## Database update

Run `sql-updates/010_practitioner_public_names.sql` once against the existing wellness database before deploying the matching API. Do not rerun migrations `001` through `009` if they were already applied.

The migration preserves each existing published full name and derives an initial booking name from the structured given name or the first word of the existing display name. Review those values in **Administration → Public team** after deployment.

## Deploy in this order

1. Back up the database and the current private API directory.
2. Run `sql-updates/010_practitioner_public_names.sql` once.
3. Extract `wellness-api-private.zip` into the private `wellness-api` application directory. Preserve the deployed `.env` and runtime data. Replace the generated `vendor` directory as one complete set; do not mix Composer files from different releases.
4. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the two thin public entry files are not already identical.
5. Extract the contents of `wellness-portal.zip` directly into `/public_html/wellness-portal`. This package includes the portal's thin `api` entry directory.
6. Extract the contents of `wellness-public.zip` directly into `/public_html/wellness`.
7. Hard refresh the public site and portal.

## Acceptance checks

- Open **Administration → Practitioners**, edit a practitioner, and confirm first name, last name, and internal display name are available.
- Open **Administration → Public team** and confirm public full name and booking name. Save a friendly booking name such as `Esther`.
- Confirm the public Contact and Services pages display the public full name and show **Book with Esther**.
- If two published practitioners use the same booking name, confirm the administration screen warns you to add a surname initial or another familiar identifier.
- Confirm staff sign-in, client sign-in, `/api/v1/health`, `/api/v1/health/database`, and a normal portal refresh.

Archive hashes and entry counts are recorded in `manifest.json`. The packages contain no `.env` files.
