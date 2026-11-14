# Guest client session navigation — 14 November 2026

## Hosted finding and correction

After using the new guest **Log in → Client → Google** route on Wellnexa, the owner returned to the public catalogue. Its header still said **Log in** and its location search said **Sign in to use my saved address**. In the same browser session, **My appointments** opened the authenticated `/client` workspace, which showed the existing synthetic cancelled appointment. This established that the provider return and client workspace worked; it did not establish a fresh registration or booking. No token, clinical record or long provider reference is recorded here.

The general guest Client choice included `/client?return=browse`. Client bootstrap treats this as an explicit instruction to return to the catalogue after successful authentication. That intent is appropriate for booking-specific handoffs but misleading for a general **Log in** choice. The guest choice now points directly to `/client`. When a recent client session exists, the public header shows a single **My account** link to `/client` instead of another **Log in** button. The saved-address link says **Open my account to use my saved address** in English and French. The server remains responsible for validating the session and authorization; the header's client-side session check only chooses navigation text. Expired sessions show the ordinary sign-in chooser.

Changed source: `Frontend/src/guest/GuestPortal.tsx`, `Frontend/src/discovery/LocationSearch.tsx`, `Frontend/src/i18n/discovery.ts`, and the application/build browser smoke tests. No PHP, SQL, identity client, session architecture, permission, consent, endpoint, external data flow, runtime dependency, `.env`, mail or SMS change. This is `bugs/guest-client-session-navigation` based on the hosted unified-sign-in revision `c085f3d`; it is not a change from current `main` and must not be combined with another branch's build without review.

## Validation and release boundary

`Frontend/npm run lint`, `npm run validate:content` (four bilingual entries), `npm run validate:i18n` (2,579 keys) and `npx tsc --noEmit` passed. The pilot-flagged Vite 6.4.5 portal build completed in an isolated ignored output directory, retaining the four existing public External ID identifiers, practitioner application and social sign-in settings, with invitations/server sessions disabled. Existing large-chunk warning remains. The first 27-case application browser run passed 26 and failed one guest page-load case while the Vite production build was running concurrently; its error page said the application could not start. The three changed/adjacent cases passed when rerun alone after the build; then the full serial `npx playwright test --config playwright.applications.config.ts --workers=1 --reporter=line` passed **27/27** in 54.6 seconds. `npx playwright test --config playwright.config.ts --grep "customer route offers sign-in|portal Book handoff|client login from the catalogue|client verification stays stable" --workers=1 --reporter=line` passed **4/4** in 20.9 seconds. A pilot-flagged preview of the newly built bundle passed the built guest-portal chooser smoke **1/1** in 10.7 seconds. The first temporary preview config resolved its test directory incorrectly and reported “No tests found”; correcting the test directory yielded the passing result. The preview config was removed after the run. These initial test-harness failures are not silently treated as passes.

This source has not been uploaded. Before a hosted change, generate and inspect a narrow shared-portal artifact from the exact committed revision, record hashes and rollback shell, obtain a new REL-05 production decision, upload content-addressed assets additively, then switch the single shared `index.html`. Both Wellnexa and LivinLively use this shared folder. A hosted synthetic client sign-in must then verify landing in `/client`, profile navigation, optional catalogue return, French/English, and signed-out/expired recovery. The previous deployed shell remains the rollback source. Existing pilot restrictions, inactive practitioner test accounts and disabled mail/SMS must remain in force. This does not approve real PHI use.

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
| AUTH-01 | Partial | Existing `/client` identity path retained; hosted callback retest pending. |
| AUTH-02 | Partial | Client-side check changes labels only; server clinic/record negative tests remain. |
| AUTH-03 | Unverified | MFA, idle/absolute and departure review unchanged. |
| AUTH-04 | Unverified | Existing browser session storage exception remains unresolved for real data. |
| AUTH-05 | Unverified | No grant change; deployed permission inventory remains. |
| SEC-01 | Partial | No secret added to source; final package scan pending. |
| SEC-02 | Unverified | Hosted TLS, backup encryption and transport unchanged. |
| SEC-03 | Unverified | Existing hosted CSP/nosniff/referrer gaps remain. |
| SEC-04 | Partial | Fixed internal route and translated plain text only; no new HTML sink. |
| SEC-05 | Unverified | No request/worker changes; abuse review remains. |
| SEC-06 | Unverified | API audit unchanged; hosted sensitive-access evidence remains. |
| SEC-07 | Partial | No diagnostic or token displayed by new UI; hosted error review remains. |
| SEC-08 | Unverified | No new external service; endpoint register review remains. |
| SEC-09 | Unverified | Messaging unchanged and pilot channels remain disabled. |
| DEV-01 | Partial | Dedicated bugs branch and unchanged locks; commit/review/push pending. |
| DEV-02 | Partial | Focused diff and typed route/copy changes reviewed; human review pending. |
| DEV-03 | Partial | No dependency change; existing ten moderate dev advisories remain. |
| DEV-04 | Partial | Lint/content/i18n/typecheck, 27 application browser, four client browser and one built smoke passed; unrelated suites omitted. |
| DEV-05 | Partial | Semantic account link and bilingual browser assertions; manual screen-reader/zoom review remains. |
| DEV-06 | Partial | Existing bundle-size warning; representative load not rerun. |
| REL-01 | Partial | This dated scoped ledger; accountable decision pending. |
| REL-02 | Partial | Focused frontend checkpoint only; unrelated PHP/SQL suites not rerun. |
| REL-03 | Missing | No committed-source artifact or hosted acceptance yet. |
| REL-04 | Missing | Real-data release decision remains blocked by open gates. |
| REL-05 | Partial | No deployment; new explicit decision and rollback evidence required. |
| REL-06 | Partial | Changed files, tests, risk and rollback recorded; hosted result pending. |

RELEASE STATUS: NOT READY FOR RELEASE
