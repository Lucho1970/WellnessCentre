# Configurable staff dashboard deployment

Source commit: `a152dc6`

This release replaces the placeholder staff dashboard with live, role-scoped Operations and Practitioner appointment widgets. Users can show or hide cards, move them with keyboard/touch-friendly controls, select supported card sizes, save a separate layout for each workspace, and reset to the recommended layout. English and French resources are included.

## Database update

Back up the database, then run `sql-updates/012_dashboard_preferences.sql` once before deploying the matching API and portal. Do not rerun migrations `001` through `011` if they were already applied. Migration 012 is additive and stores layout preferences only; it does not change appointment or client data.

## Deploy in this order

1. Back up the existing database and private API directory.
2. Run `sql-updates/012_dashboard_preferences.sql` once in the existing wellness database.
3. Extract `wellness-api-private.zip` into the private `wellness-api` directory. Preserve the deployed `.env` and runtime data, and replace `vendor` as one complete set.
4. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the thin public API files need updating.
5. Extract `wellness-portal.zip` directly into `/public_html/wellness-portal`.
6. Extract `wellness-public.zip` directly into `/public_html/wellness`.
7. Hard-refresh the portal.

## Acceptance checks

- Sign in as Super Admin and confirm the Operations dashboard shows real counts for today’s appointments, requests awaiting confirmation, and On-Site visits.
- Select **Customize dashboard**, hide a card, change another card size/order, save, refresh, and confirm the layout remains.
- Reset the layout and confirm the recommended cards return.
- If the account also has the Practitioner role, switch workspaces and confirm the Practitioner dashboard shows only that practitioner’s appointments and has an independent layout.
- Click each visible card and confirm it opens the authorized appointment page.
- Switch to French and confirm dashboard controls and card text are translated.
- Confirm an Accountant-only account does not receive appointment widgets.
- Recheck staff sign-in, client sign-in, `/api/v1/health`, and `/api/v1/health/database`.

Checksums and archive entry counts are recorded in `manifest.json`. No `.env` files are included.
