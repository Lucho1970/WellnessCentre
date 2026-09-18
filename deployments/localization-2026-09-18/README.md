# English and French localization deployment

Built from source commit `79fc375388b062c2288124767c426f6e5a7d9a2c` on
`codex/frontend-localization`.

This release moves the application interface into shared English and French resource
catalogs and adds a persistent language selector to the public website, client portal,
and staff portal. Dates, times, currency, appointment statuses, validation messages,
API errors, navigation, administration pages, and account flows now follow the selected
language. The portal headers also use a responsive two-row layout on small screens so
the French labels remain readable.

No database migration or API configuration change is required for this release.

## Deploy

If the current coordinated application release is already deployed, replace these two
frontend packages:

| Archive | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |

The directory also contains a complete coordinated snapshot. If the server is not on
the current release, back up the existing installation and deploy all four archives:

| Archive | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |
| `wellness-api-private.zip` | Account-root `/wellness-api` outside `public_html` |
| `wellness-api-public.zip` | `/public_html/wellness/api` |

Preserve the existing private `.env` and `var` directories. If deploying the private
API, replace its source and complete matching `vendor` directory together. Upload hidden
`.htaccess` files as well.

## Verify

1. Open the public website and switch from **EN** to **FR**.
2. Refresh the page and confirm French remains selected.
3. Browse availability and verify dates, times, prices, and messages are in French.
4. Sign in to the client portal and confirm its navigation and account menu are French.
5. Sign in as staff and review appointments and each administration page in French.
6. Switch back to English and confirm the choice persists after another refresh.

Both production frontend bundles built successfully. Six automated release checks passed
for resource parity, public bundle isolation, public and portal deep links, the client
callback, language persistence, and localized API errors. English desktop and French
mobile layouts were also visually checked.

Archive hashes and entry counts are recorded in `manifest.json`. The SQL files under
`sql-updates` are historical migrations only; do not rerun them for this release.
