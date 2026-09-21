# Dashboard widget management deployment

Source commit: `d95ed25`

This release adds the Super Admin **Dashboard widgets** screen, runtime JSON widget definitions, duplicate-ID confirmation, immutable version history, enable/disable, rollback, packaged-default restoration, strict server allowlists, audit entries, and runtime rendering without a frontend rebuild.

## Database updates

Back up the database first.

- If the configurable dashboard release has not been deployed, run `sql-updates/012_dashboard_preferences.sql` once.
- Run `sql-updates/013_dashboard_widget_catalogue.sql` once before deploying this API and portal.
- Do not rerun migrations already applied to the database.

Migration 013 is additive. It stores catalogue records and immutable JSON versions; it does not modify appointment or client records.

## Deploy in this order

1. Back up the database and current private API directory.
2. Apply the required migrations described above.
3. Extract `wellness-api-private.zip` into the private `wellness-api` directory. Preserve `.env` and runtime data, and replace `vendor` as one complete set.
4. Extract `wellness-api-public.zip` into `/public_html/wellness/api` if the thin public API files need updating.
5. Extract `wellness-portal.zip` directly into `/public_html/wellness-portal`.
6. Extract `wellness-public.zip` directly into `/public_html/wellness`.
7. Hard-refresh the portal.

## Acceptance test

1. Sign in as Super Admin and open **Dashboard widgets** from the Operations menu.
2. Download `documentation/examples/confirmed-appointments-today.widget.json` from the repository and upload it.
3. Review and publish it. Open the Operations dashboard and confirm **Confirmed appointments today** appears without rebuilding.
4. Upload the same file again. Confirm that the duplicate-ID warning appears and no replacement happens until **Publish new version** is selected.
5. Open **Versions**, restore the earlier version, and verify the dashboard still loads.
6. Disable the test widget and verify it disappears. Re-enable it and verify it returns.
7. Verify a non-Super-Admin cannot open `/admin/dashboard-widgets` or call its API routes.
8. Verify the Practitioner dashboard still shows only that practitioner’s data.

Uploaded JSON may select only approved renderers, appointment projections, capabilities, filters, destinations, sizes, and icons. SQL, executable code, HTML, and arbitrary URLs are not accepted. Checksums and entry counts are recorded in `manifest.json`; no `.env` files are included.
