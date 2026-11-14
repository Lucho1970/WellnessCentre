# Client account display and label correction — 14 November 2026

## Finding and scope

The owner-controlled Google test confirmed that Wellnexa's new client sign-in route reaches a verified client workspace. Its account menu showed the provider's placeholder `unknown` and a `U` avatar. The browser Issues panel also flagged a **Show** filter label in the appointments view: its native `for` attribute pointed to MUI's styled `div` combobox. The matching ID existed, but a `div` is not a labelable native control. The owner supplied only the relevant element markup for diagnosis. A second Issues message said the callback history item was marked skippable; it follows replacement of the temporary OAuth callback URL and is informational. The screenshot included a one-time sign-in code in a URL; its value is intentionally absent from Git, test output and this record.

On `bugs/client-account-display`, based on the deployed guest-navigation branch `022ad7b`, `Frontend/src/customer/accountDisplay.ts` centralizes a display-only fallback. The client menu and public-site account bridge now show **Client**/`C` when the identity provider supplies `unknown`, `undefined`, `null` or no display name. A normal supplied name remains visible. This does not use an email address as a fallback and does not fetch a clinical profile. `Frontend/src/customer/CustomerWorkspace.tsx` uses MUI `InputLabel` plus `Select` for the **Show** appointment filter, connecting the label with `labelId`/ARIA instead of an invalid native `for`. The filter values and booking/record behavior are unchanged. `Frontend/tests/customer.spec.ts` covers the placeholder, public-site initial, label target validity, accessible combobox name, Past selection, and existing client/session behavior. No PHP, SQL, identity registration, session design, privilege, consent, endpoint, external data flow, dependency, mail/SMS or private setting changed.

## Validation and limitations

`Frontend/npm run lint` passed with ESLint 10.13.0 and zero warnings. `npm run validate:content` passed four bilingual entries; `npm run validate:i18n` passed 2,579 keys; `npx tsc --noEmit` passed TypeScript 5.7.2. `npx playwright test --config playwright.config.ts --grep "placeholder provider name|linked client sees only their appointments|client verification stays stable" --workers=1 --reporter=line` passed **3/3**. After the final keyboard/filter assertion, the changed test alone passed **1/1** in 14.0 seconds. The first extension of that test timed out because it attempted to click the public-site link while the MUI account menu was still open and had made the background inaccessible; pressing Escape before navigation fixed the test. This was a test-sequence error, not a product failure. The test explicitly checks that every `label[for]` in the initial client appointment view targets a labelable control; the earlier, weaker check only verified that the target ID existed and did not detect the warning.

A pilot-flagged `npm run build:portal -- --outDir D:\Dev\WelnessCentre\.tmp\client-account-display-build` passed with Vite 6.4.5 and the existing four public External ID identifiers. It retained practitioner applications/social sign-in and kept invitations/server sessions disabled. The existing 553 kB large-chunk warning remains. No dependencies or lockfiles changed. A local preview of the built guest chooser passed **1/1**. The full default browser suite, dedicated application/admin suites, PHP/SQL tests, hosted provider return after this new fix, manual screen-reader/200% zoom checks, operational security sign-offs and rollback rehearsal were not repeated for this narrow UI slice.

The ignored local package `deployments/client-account-display-2026-11-14/01-shared-portal-assets-only.zip` is from source `0c30d850135c479b5ce640b97e6b1d41b7fa2f9b` on this branch. Its SHA-256 is `dc57390505b71b730612e8dd882e4fa1a8d54447650f4c1ad457516691d5355b` (816,050 bytes). It contains exactly `index.html` and 69 `assets/` files; all 70 ZIP entry hashes were checked against the build. The entry page hash is `2bc51f6107a50c5b452bf2f50a52381f6ca52594d012eb58cd340689cc6efafc`. The ignored manifest records the lockfile hash, tool versions, date, flags and file hashes. The four expected public identity identifiers are present. The output has no source maps, private environment files, PHP, SQL or private keys. No API or `.htaccess` file is in the ZIP.

The controlled transfer required the previous shared `index.html` hash `0b6e79eb3fc55b7a3c01a2c8bf9d12f74cf6022567822609aaefb6b859be6ee2`, an off-web-root backup and a new REL-05 production decision for this ZIP. Assets were to be copied additively and verified on both clinic hosts before replacing the shared entry page. For rollback, restore the saved old `index.html`; leave added hashed assets in place pending separate cleanup. Owner smoke should repeat Google client entry, account-menu display, **Show** selector and sign-out. Real-data release remains separately blocked.

### Hosted pilot transfer and asset retention — 14 November 2026

The owner uploaded the ZIP's 69 assets additively. Independent HTTPS reads matched each SHA-256 on both shared clinic hosts (**138/138**). Both hosts still served the expected previous `index.html` before the switch. An exact copy of that shell was saved outside the web root at `D:\Dev\WellnessCentre-backup\client-account-display-2026-11-14\live-index-before.html` and matched the previous hash. The owner then replaced only the shared `index.html`. Both hosts returned 200 with the exact new hash `2bc51f6107a50c5b452bf2f50a52381f6ca52594d012eb58cd340689cc6efafc`, `no-store` and five-minute HSTS. Their two shell-linked assets and `/client`, `/staff/external`, `/staff/login` returned 200. Fresh anonymous Edge checks showed three correct chooser destinations, Escape closure and zero page errors on both hosts. Authenticated account-menu and label behavior on this deployed bundle remains pending an owner refresh; no booking was made for this check.

The owner observed about ten hashed versions of some remote assets. **Do not mirror-delete `assets/`.** The current package has 69 assets and its immediate rollback package has 68; six filenames overlap, so the minimum current-plus-rollback keep set is **131 distinct paths**. The ignored local `deployments/client-account-display-2026-11-14/MINIMUM_ASSETS_TO_KEEP.txt` lists those paths, but it is not a deletion list: older open browser tabs may still request earlier lazy-loaded chunks, and other retained entry pages or rollback packages may reference them. A cleanup needs a remote inventory, a full off-web-root backup, reference checks against every retained shell/rollback, an agreed old-tab grace period, and a separately reviewed list of files to remove. No hosted asset was deleted in this release.

## Scoped 38-control ledger

Review date: 14 November 2026. Release owner: Luis Duran; privacy/security/clinical decisions remain with their accountable reviewers. Statuses are for this UI slice only. The earlier audit and the guest-navigation hosted record remain separate evidence. For every unverified item, the next action is the responsible owner's release review, not an inferred exception.

| Control | Status | Evidence or next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/agent allocation unchanged; owner/legal review. |
| GOV-02 | Unverified | Applicability and current legal sources unchanged; release review. |
| GOV-03 | Unverified | PIPEDA assessment unchanged; owner/legal review. |
| GOV-04 | Unverified | Administrative intended use unchanged; function review remains. |
| GOV-05 | Unverified | Data inventory, PIA and threat/risk decisions remain. |
| PRIV-01 | Unverified | Notice/contact unchanged; verify at release. |
| PRIV-02 | Unverified | No collection or consent change; authority review remains. |
| PRIV-03 | Partial | Display helper never grants access; server restriction checks remain. |
| PRIV-04 | Unverified | Representative/minor process unchanged. |
| PRIV-05 | Unverified | Full access/correction process remains open. |
| PRIV-06 | Unverified | Record/profession retention approvals remain. |
| PRIV-07 | Unverified | Breach rehearsal remains. |
| AUTH-01 | Partial | Existing signed-in client path preserved; hosted retest after this bundle pending. |
| AUTH-02 | Partial | No client/clinic identifier or role derived from provider name; server negative tests remain. |
| AUTH-03 | Unverified | MFA, limits, revocation/departure unchanged. |
| AUTH-04 | Unverified | Existing browser session storage exception remains for real-data gate. |
| AUTH-05 | Unverified | No grant change; deployed inventory remains. |
| SEC-01 | Partial | No secret or one-time code in source; eventual artifact scan pending. |
| SEC-02 | Unverified | HTTPS, database transport and backup encryption unchanged. |
| SEC-03 | Partial | Hosted shell has no-store and five-minute HSTS; other header/cache gaps remain. |
| SEC-04 | Partial | Display text is React encoded; no HTML sink or dynamic URL added. |
| SEC-05 | Unverified | No request/worker change; abuse review remains. |
| SEC-06 | Unverified | Audit routes unchanged; hosted review remains. |
| SEC-07 | Partial | Placeholder suppressed in UI; browser callback diagnostic reviewed without retaining code. |
| SEC-08 | Unverified | No new service; endpoint register review remains. |
| SEC-09 | Unverified | Mail/SMS unchanged and pilot channels stay disabled. |
| DEV-01 | Partial | Dedicated bugs branch, source commit `0c30d85` and unchanged lockfile; review/push pending. |
| DEV-02 | Partial | Focused typed UI changes reviewed; human review pending. |
| DEV-03 | Partial | No dependency change; existing development advisories remain. |
| DEV-04 | Partial | Lint/content/i18n/typecheck, targeted browser and portal build passed; omitted suites above. |
| DEV-05 | Partial | Label/role/keyboard/browser assertions passed; manual assistive technology/zoom audit remains. |
| DEV-06 | Partial | Existing large-chunk warning; representative-load review not repeated. |
| REL-01 | Partial | Dated scoped ledger; accountable reviewer decision pending. |
| REL-02 | Partial | Narrow frontend checkpoint passed; broad PHP/SQL suites omitted. |
| REL-03 | Partial | ZIP plus 138 hosted asset hashes and two entry hashes verified; authenticated acceptance pending. |
| REL-04 | Missing | Real-data release decision remains blocked by open gates. |
| REL-05 | Partial | Owner performed controlled portal-only transfer with prior-shell backup; operational sign-offs remain. |
| REL-06 | Partial | Files, tests, hashes, rollback and asset-retention boundary recorded; signed-in result pending. |

RELEASE STATUS: NOT READY FOR RELEASE
