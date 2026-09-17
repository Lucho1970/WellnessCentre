# Service duration pricing deployment

Built from source commit `f2a5ef2` on `codex/service-duration-pricing`.

This release lets one service contain explicit duration-and-price options, such as:

- 60 minutes — $110
- 90 minutes — $155
- 120 minutes — $200

Existing services remain compatible. A duration whose database price is currently NULL
continues to inherit the service base price until the service is edited and saved. Saving
the revised service writes an explicit price for every active duration. Removed duration
options are made inactive so existing appointment references remain valid.

No database migration or SQL update is required—the existing
`service_duration_options.price_cents` column is used.

## Deploy

Back up the private API and website folders first. Extract each archive directly into:

| Archive | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |
| `wellness-api-private.zip` | Account-root `/wellness-api` outside `public_html` |
| `wellness-api-public.zip` | `/public_html/wellness/api` (unchanged pointer, optional for this release) |

Preserve the existing private `.env` and `var` directories. Replace the private source
and complete matching `vendor` directory together; do not upload selected vendor files.
The portal archive includes its small `/api` pointer. Upload `.htaccess` files as well.

After deployment, sign in as Super Admin and open **Services**:

1. Edit the massage service.
2. Add 60, 90 and 120-minute rows with their explicit prices.
3. Save the service.
4. Confirm the Services list shows each duration and price.
5. Confirm public availability buttons and staff booking duration choices show the
   corresponding price.
6. Book a test appointment and confirm its treatment price uses the selected duration.
   Mobile fees and travel buffers remain separate.

## Verification

- TypeScript and both production builds passed.
- 31 browser tests passed, including multi-duration service creation, public pricing,
  mobile booking and existing customer/staff regression coverage.
- 145 PHP policy/unit checks passed, including 12 duration-pricing validation checks.
- Five persistence checks passed against a disposable MariaDB 10.11.14 database:
  create, list, update, base-price derivation and inactive duration history.
- Archive hashes and entry counts are recorded in `manifest.json`.

The SQL files copied into `sql-updates` are the repository's historical migrations. Do
not rerun them for this feature if they were already applied.
