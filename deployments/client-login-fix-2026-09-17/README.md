# Client-first login and refresh fix

For an installation already running the September 17 customer sign-in checkpoint,
**upload only these two updated frontend archives**:

| Archive contents | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |

Back up both web folders first. Extract the contents directly into each destination,
including index.html, assets and .htaccess, not into an extra nested folder. The portal
archive includes the same thin API pointer as before. Preserve the public site's api
directory. Upload assets before index.html to minimize deployment interruptions.

No database script, private API replacement, environment setting or Entra registration
change is required for this fix. Additional API archives and sql-updates are included
by the full-package build process; they are not required for this incremental update.
Never rerun historical SQL migrations already applied. Preserve server .env and var.

## Changes

- Public Login goes directly to the client portal. Generic `/login` redirects to `/client`.
- Staff login has its own `/staff/login` page and secondary links.
- Customer verification no longer restarts with each render; a timeout offers recovery.
- Cached accounts are filtered by the correct tenant and environment for each persona.
- Public initials are a minimal remembered-account hint from a read-only portal bridge.
  No tokens or clinical data are shared. If storage/framing is blocked or the cached ID
  token expires, Login remains available and the portal verifies the session normally.
- Existing staff callbacks, permissions and API token validation remain unchanged.

## Test after upload

1. Open a fresh private window at https://wellness.copihue.ca/ (not copihues.ca).
2. Click Login: expect Client portal, not Staff portal.
3. Sign in as a customer. Expect Customer sign-in verified and the unlinked-record notice.
4. Refresh: expect verification to finish without flickering.
5. Browse availability, refresh the public page and use the round initials link to return
   to the client account. If browser policies prevent the display hint, Login must still
   take you to the client page and not attempt workforce authentication.
6. Test customer sign-out, then the separate Staff login using an authorized staff account.
7. Test staff and customer navigation in the same tab to exercise the shared account cache.

The client-record linking and online confirmation features are still not enabled.
Tests with synthetic identities passed locally: 27 browser checks, TypeScript and both
production builds. Production-host artifact checks passed for Login navigation, client
and staff reload, the generic login redirect and public bundle isolation. Actual provider
federation and Netfirms/browser framing policy still need hosted acceptance.

See [customer setup](../../documentation/CLIENT_SIGN_IN_SETUP.md) for details. Source
commit and ZIP hashes are recorded in manifest.json. Roll back using both previous
frontend archives if needed; no data rollback is required.
