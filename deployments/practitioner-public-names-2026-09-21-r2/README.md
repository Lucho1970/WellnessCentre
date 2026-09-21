# Practitioner public names deployment — R2

Source commit: `dba031b`

This verified release includes structured practitioner names, separate internal/public names, friendly booking names such as **Book with Esther**, duplicate booking-name warnings, and the corrected language-selector tests.

## Database update

Run `sql-updates/010_practitioner_public_names.sql` once against the existing wellness database before deploying the matching API. Do not rerun migrations `001` through `009` if they were already applied.

The migration preserves each existing published full name and derives an initial booking name from the structured given name or the first word of the existing display name. Review these values in **Administration → Public team** after deployment.

## Deploy in this order

1. Back up the database and current private API directory.
2. Run `sql-updates/010_practitioner_public_names.sql` once.
3. Extract `wellness-api-private.zip` into the private `wellness-api` directory. Preserve the deployed `.env` and runtime data. Replace `vendor` as one complete set.
4. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the thin public API files need updating.
5. Extract `wellness-portal.zip` directly into `/public_html/wellness-portal`.
6. Extract `wellness-public.zip` directly into `/public_html/wellness`.
7. Hard refresh both sites.

## Acceptance checks

- Confirm **Administration → Practitioners** provides first name, last name, and internal display name.
- Confirm **Administration → Public team** provides public full name and booking name.
- Confirm public Contact and Services pages show the public full name and a friendly **Book with _name_** action.
- Confirm the language globe opens in English and French.
- Confirm staff sign-in, client sign-in, `/api/v1/health`, `/api/v1/health/database`, and portal refresh.

Checksums and entry counts are in `manifest.json`. No `.env` files are included.
