# Refreshed deployment package — 18 September 2026

Source commit: `3767bd0527ddc1e9deb4e8317cbc8f2e5ea5dbe9`

This package contains the owner's manual frontend changes and the complete client-merge release. The source used to produce the archives is committed at the revision above.

No `.env` file is included. The locally modified `api/.env.example` is not copied into any ZIP.

## Database

If migration 006 has not been applied, back up the database and run `sql-updates/006_client_merge.sql` once before deploying. Do not rerun migrations 001–005 if they are already present.

If migration 006 was already applied for the earlier client-merge package, no additional SQL change is required.

## Upload order

1. Preserve the private `/wellness-api/.env` and back up deployed files.
2. Apply migration 006 only if it has not already been applied.
3. Extract `wellness-api-private.zip` into private `/wellness-api`, replacing `src`, `vendor`, `bin`, `composer.json`, and `composer.lock` as one matched set.
4. Extract `wellness-portal.zip` into `/public_html/wellness-portal`, including its `api` pointer.
5. Extract `wellness-public.zip` into `/public_html/wellness`, preserving its existing public `api` directory.
6. Use `wellness-api-public.zip` only to repair or verify the public API pointer.

The frontend production build passed. The browser suite's 34 scenarios passed after correcting five test expectations to use the translated visible labels and one selector to use an exact match. Archive entry counts and SHA-256 hashes are recorded in `manifest.json`.
