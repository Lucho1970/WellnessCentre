# Client sign-in configuration and language handoff fix

Built from source commit `da7ddb8` on `codex/client-signin-config-fix`.

This release restores the four public Microsoft Entra External ID identifiers to the
production portal build. It also carries the selected English or French language between
the public website and the portal, saves that choice on the destination, and removes the
temporary language parameter from the address bar.

The standard deployment builder now fails if any required customer identity setting is
missing or does not appear in the compiled portal. This prevents a future package from
silently displaying **Client sign-in is not configured yet** for the same reason.

## Deploy

Deploy both frontend archives because the language handoff changes both sites:

| Archive | Destination |
| --- | --- |
| `wellness-public.zip` | `/public_html/wellness` |
| `wellness-portal.zip` | `/public_html/wellness-portal` |

No API archive, database migration, or server `.env` change is required if the previous
release is already deployed and client sign-in worked before it. The API archives and
historical SQL files are included only as a complete coordinated snapshot.

## Verify

1. Open the public website in a private browser window and choose **FR**.
2. Click **Connexion** and confirm the portal remains in French.
3. Confirm the client portal offers the configured sign-in action instead of the
   unconfigured message.
4. Return to the public website and confirm it remains in French.
5. Change back to English, cross between the two sites again, and refresh both pages.
6. Confirm staff sign-in still opens the separate workforce Microsoft login.

Both production bundles built successfully and all six release tests passed, including
the cross-origin language handoff and persistence check. Archive hashes and entry counts
are recorded in `manifest.json`.
