# Client duplicate prevention and merge deployment — 18 September 2026

Source commit: `6d4377a0fae518a38961fdd7a3ef4d8860752480`

This release adds staff duplicate warnings, searchable email aliases and a Super Admin-only client merge workflow. It includes all prior functionality in the source commit.

## Required SQL update

Back up the database and run `sql-updates/006_client_merge.sql` once **before** uploading the matching API and portal. Migrations 001–005 are included for reference only and must not be rerun if they were already applied.

Migration 006 creates `client_email_addresses` and `client_merge_records`, then copies existing client primary emails into the alias table. It does not merge, deactivate or delete clients.

## Upload order

1. Back up the database and deployed files. Preserve the private `/wellness-api/.env`.
2. Apply `sql-updates/006_client_merge.sql` and confirm both new tables exist.
3. Extract `wellness-api-private.zip` into private `/wellness-api`. Replace `src`, `vendor`, `bin`, `composer.json`, and `composer.lock` as one matched set.
4. Extract `wellness-portal.zip` into `/public_html/wellness-portal`, including its `api` pointer. Upload assets before replacing `index.html` if transferring manually.
5. Extract `wellness-public.zip` into `/public_html/wellness`, preserving its existing public `api` folder.
6. `wellness-api-public.zip` is supplied only for pointer repair or verification.

## Hosted acceptance

- As reception or an administrator, create a client with the same first and last name as an existing client. Confirm the portal shows possible matches and requires an explicit **Create anyway** choice.
- Search the client directory using an earlier/alternate email and confirm the client appears.
- As Super Admin, use two synthetic duplicate records to open **Merge duplicate**. Confirm the preview shows both records and relationship counts.
- Choose the primary email, profile and service address to retain. Enter a reason and the exact confirmation phrase.
- Confirm appointments and other listed relationships now belong to the survivor, both email addresses remain searchable, and the duplicate is inactive.
- Confirm clinic administrators and reception do not see merge controls and receive HTTP 403 if they call a merge endpoint directly.
- Do not test a live-client merge until the synthetic acceptance passes. A completed merge is deliberately not undone by deploying older code.

See `documentation/CLIENT_MERGE.md` in the repository for design, safeguards and recovery guidance. Archive hashes and entry counts are in `manifest.json`.
