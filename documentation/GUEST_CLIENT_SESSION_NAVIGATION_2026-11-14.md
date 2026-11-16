# Guest client session navigation — 14 November 2026

## Hosted finding and correction

After using the new guest **Log in → Client → Google** route on Wellnexa, the owner returned to the public catalogue. Its header still said **Log in** and its location search said **Sign in to use my saved address**. In the same browser session, **My appointments** opened the authenticated `/client` workspace, which showed the existing synthetic cancelled appointment. This established that the provider return and client workspace worked; it did not establish a fresh registration or booking. No token, clinical record or long provider reference is recorded here.

The general guest Client choice included `/client?return=browse`. Client bootstrap treats this as an explicit instruction to return to the catalogue after successful authentication. That intent is appropriate for booking-specific handoffs but misleading for a general **Log in** choice. The guest choice now points directly to `/client`. When a recent client session exists, the public header shows a single **My account** link to `/client` instead of another **Log in** button. The saved-address link says **Open my account to use my saved address** in English and French. The server remains responsible for validating the session and authorization; the header's client-side session check only chooses navigation text. Expired sessions show the ordinary sign-in chooser.

Changed source: `Frontend/src/guest/GuestPortal.tsx`, `Frontend/src/discovery/LocationSearch.tsx`, `Frontend/src/i18n/discovery.ts`, and the application/build browser smoke tests. No PHP, SQL, identity client, session architecture, permission, consent, endpoint, external data flow, runtime dependency, `.env`, mail or SMS change. This is `bugs/guest-client-session-navigation` based on the hosted unified-sign-in revision `c085f3d`; it is not a change from current `main` and must not be combined with another branch's build without review.

## Validation and release boundary

`Frontend/npm run lint`, `npm run validate:content` (four bilingual entries), `npm run validate:i18n` (2,579 keys) and `npx tsc --noEmit` passed. The pilot-flagged Vite 6.4.5 portal build completed in an isolated ignored output directory, retaining the four existing public External ID identifiers, practitioner application and social sign-in settings, with invitations/server sessions disabled. Existing large-chunk warning remains. The first 27-case application browser run passed 26 and failed one guest page-load case while the Vite production build was running concurrently; its error page said the application could not start. The three changed/adjacent cases passed when rerun alone after the build; then the full serial `npx playwright test --config playwright.applications.config.ts --workers=1 --reporter=line` passed **27/27** in 54.6 seconds. `npx playwright test --config playwright.config.ts --grep "customer route offers sign-in|portal Book handoff|client login from the catalogue|client verification stays stable" --workers=1 --reporter=line` passed **4/4** in 20.9 seconds. A pilot-flagged preview of the newly built bundle passed the built guest-portal chooser smoke **1/1** in 10.7 seconds. The first temporary preview config resolved its test directory incorrectly and reported “No tests found”; correcting the test directory yielded the passing result. The preview config was removed after the run. These initial test-harness failures are not silently treated as passes.

The ignored local package `deployments/guest-client-session-2026-11-14/01-shared-portal-assets-only.zip` is from source `78de4b1528a8e71a1ad51d8ea836255b37d6c02b` on this branch. Its SHA-256 is `dbf1df23c8a94518c71e1e2ca18ca6dad79f50dea01f3e8c9069719c83f1e3d6` (815,744 bytes). It contains exactly `index.html` and 68 content-addressed `assets/` files; all 69 ZIP entry hashes were checked against the build. The new `index.html` hash is `0b6e79eb3fc55b7a3c01a2c8bf9d12f74cf6022567822609aaefb6b859be6ee2`. Its ignored manifest records the lockfile hash, Node/npm/Vite versions, build time, flags and file hashes. All four expected public External ID identifiers are present in the bundle. A scan found no source maps, private keys or `127.0.0.1`; one third-party bundled library contains a dormant `http://localhost` fallback for a null browser origin, and one auth-library chunk contains a `.env` text match. No build configuration points to localhost or includes a private environment file. No PHP, SQL, `.htaccess` or private setting is in the ZIP.

The controlled upload required the current shared shell hash `1cb8dd99db62526f20dd7b96640b84279d8594291a7707cdcc7f4164adb4fa3f`, an off-site backup and a new REL-05 production decision. The assets were to be copied additively and checked on both hosts before switching the single shared `index.html`. Both Wellnexa and LivinLively use this folder. For rollback, restore the saved shared `index.html`; leave added hashed assets pending separate cleanup. Existing pilot restrictions, inactive practitioner test accounts and disabled mail/SMS remain in force. Release/operations owner: Luis Duran; clinic privacy/security/clinical decisions and incident contact still require separate confirmation. This does not approve real PHI use.

### Controlled hosted pilot update — 14 November 2026

The owner approved this exact ZIP and performed the Beyond Compare transfer. Before the entry-page switch, the ZIP was rehashed and confirmed to contain one root `index.html` and 68 assets. Both live hosts still served the expected previous shell. The owner copied the assets additively and kept an off-site `index.html` backup. A first bulk verifier using a direct .NET HTTP client received proxy status 465 for every request; a direct PowerShell `Invoke-WebRequest` returned 200 and matched the asset bytes. Re-running all entries through the working request path verified **68/68 SHA-256 matches on each host (136/136)**. The old shell still matched on both hosts immediately before the switch.

After the owner replaced only the shared `index.html`, both hosts returned 200 with the exact new shell hash `0b6e79eb3fc55b7a3c01a2c8bf9d12f74cf6022567822609aaefb6b859be6ee2`, `no-store` and the existing five-minute HSTS header. Both shell-linked assets returned 200 on each host. `/client`, `/staff/external` and `/staff/login` returned 200 on each host. Fresh headless Edge guest checks showed one login chooser with the exact `/client`, `/staff/external` and `/staff/login` links, Escape closure and zero page errors on each host. This establishes deployed shell/asset integrity and unauthenticated navigation only. The owner-controlled Google client return, signed-in profile/account display and sign-out/expiry remain to be verified; no booking was made for this check.

The owner then used a fresh/private Wellnexa tab with the existing pilot-approved Google test identity. The provider return landed in the **Client portal**, showing **Customer sign-in verified**, the client workspace/profile navigation and the existing cancelled synthetic test appointment. This closes the specific guest-login destination defect. The account menu displayed the identity provider's placeholder `unknown` and a `U` avatar; that separate presentation defect is tracked for follow-up. No new booking or client record was created in this check. Sign-out, real session expiry, other identities and real-data release remain unverified.

## Scoped 38-control ledger

Source owner: Luis Duran for release decision; implementation evidence is this branch at the commit recorded in Git. Review date: 14 November 2026. These statuses cover this navigation slice; earlier audit findings remain open. **Unverified** means this edit does not prove the control. Next action for every unverified control is the accountable owner's existing release review, not an inferred waiver.

| Control | Status | Evidence or next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/agent allocation unchanged; owner/legal review. |
| GOV-02 | Unverified | Applicability register and current legal sources need release review. |
| GOV-03 | Unverified | PIPEDA applicability unchanged; owner/legal review. |
| GOV-04 | Unverified | Intended administrative use unchanged; clinical feature review remains. |
| GOV-05 | Unverified | Data inventory, PIA and threat/risk sign-off remain open. |
| PRIV-01 | Unverified | Published notice/contact unchanged; verify at release. |
| PRIV-02 | Unverified | No new collection/consent; authority review remains. |
| PRIV-03 | Partial | UI shortcut does not grant access; live server restriction checks remain. |
| PRIV-04 | Unverified | Representative/minor process unchanged. |
| PRIV-05 | Unverified | Full access/correction process remains open. |
| PRIV-06 | Unverified | Profession/record retention approval remains open. |
| PRIV-07 | Unverified | Breach rehearsal remains open. |
| AUTH-01 | Partial | Owner-controlled Google return reached verified `/client`; other identity/session cases remain. |
| AUTH-02 | Partial | Client-side check changes labels only; server clinic/record negative tests remain. |
| AUTH-03 | Unverified | MFA, idle/absolute and departure review unchanged. |
| AUTH-04 | Unverified | Existing browser session storage exception remains unresolved for real data. |
| AUTH-05 | Unverified | No grant change; deployed permission inventory remains. |
| SEC-01 | Partial | No secret added to source; final package scan pending. |
| SEC-02 | Unverified | Hosted TLS, backup encryption and transport unchanged. |
| SEC-03 | Partial | Hosted shell has no-store and five-minute HSTS; existing CSP/nosniff/referrer gaps remain. |
| SEC-04 | Partial | Fixed internal route and translated plain text only; no new HTML sink. |
| SEC-05 | Unverified | No request/worker changes; abuse review remains. |
| SEC-06 | Unverified | API audit unchanged; hosted sensitive-access evidence remains. |
| SEC-07 | Partial | No diagnostic or token displayed by new UI; hosted error review remains. |
| SEC-08 | Unverified | No new external service; endpoint register review remains. |
| SEC-09 | Unverified | Messaging unchanged and pilot channels remain disabled. |
| DEV-01 | Partial | Dedicated bugs branch, source commit `78de4b1` and unchanged locks; review/push pending. |
| DEV-02 | Partial | Focused diff and typed route/copy changes reviewed; human review pending. |
| DEV-03 | Partial | No dependency change; existing ten moderate dev advisories remain. |
| DEV-04 | Partial | Lint/content/i18n/typecheck, 27 application browser, four client browser and one built smoke passed; unrelated suites omitted. |
| DEV-05 | Partial | Semantic account link and bilingual browser assertions; manual screen-reader/zoom review remains. |
| DEV-06 | Partial | Existing bundle-size warning; representative load not rerun. |
| REL-01 | Partial | This dated scoped ledger; accountable decision pending. |
| REL-02 | Partial | Focused frontend checkpoint only; unrelated PHP/SQL suites not rerun. |
| REL-03 | Partial | ZIP and hosted shell/assets verified on two hosts; Google client return succeeded, other role/expiry acceptance pending. |
| REL-04 | Missing | Real-data release decision remains blocked by open gates. |
| REL-05 | Partial | Owner approved and copied narrow portal artifact after backup; operational sign-offs remain. |
| REL-06 | Partial | Changed files, tests, hashes, rollback and owner-observed Google return recorded; other gates remain. |

RELEASE STATUS: NOT READY FOR RELEASE
