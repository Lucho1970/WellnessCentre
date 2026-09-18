# Appointment client finder deployment

Built from source commit `9a9451c` on `codex/appointment-client-finder`.

This release replaces the appointment form's manual client search and dropdown with an
automatic, accessible client finder. After two characters, the portal waits 300 ms and
then searches active clients by name, email, or phone. Matching clients appear as detail
cards showing their name, email, and phone, and staff must explicitly select one before
continuing. Starting a different search clears the previous selection to reduce the risk
of booking the wrong client.

Birthdate filtering is intentionally deferred. No database migration or SQL update is
required for this release; it uses the existing client-search API.

## Deploy

If the immediately preceding service-duration pricing release is already deployed, only
the following archive is required:

| Archive | Destination |
| --- | --- |
| `wellness-portal.zip` | `/public_html/wellness-portal` |

The package is also a complete coordinated snapshot. If the server is not already on the
preceding release, back up the current folders and deploy all archives:

| Archive | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |
| `wellness-api-private.zip` | Account-root `/wellness-api` outside `public_html` |
| `wellness-api-public.zip` | `/public_html/wellness/api` |

Preserve the existing private `.env` and `var` directories. When deploying the private
API, replace its source and complete matching `vendor` directory together. Upload hidden
`.htaccess` files as well.

## Verify

Sign in as Super Admin and open **Appointments**, then start a new appointment:

1. Enter one character and confirm no search is performed.
2. Enter at least two characters of a client's name, email, or phone.
3. Confirm matching detail cards appear after the short delay.
4. Select a client and confirm the selected-client summary appears.
5. Choose **Change client**, select another client, and finish a test booking.

Both production frontend builds passed, and all 32 browser tests passed. Archive hashes
and entry counts are recorded in `manifest.json`.

The SQL files copied into `sql-updates` are historical migrations. Do not rerun them for
this feature if they were already applied.
