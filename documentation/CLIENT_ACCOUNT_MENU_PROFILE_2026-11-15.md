# Client account menu profile action — 15 November 2026

## Finding and change

On the signed-in Wellnexa synthetic client page, the avatar menu displayed a gray, disabled **Client** row above **Sign out**. The row was a role label (`MenuItem disabled`), not a profile link, although its styling suggested a broken action. The provider returns the placeholder `unknown` for this test identity, so the existing display helper intentionally uses **Client** and **C**; a contact email or clinical profile is not inferred from the identity provider. The actual profile was available through **My profile** in the workspace button row, but the account menu did not expose it. The previous [client display review](CLIENT_ACCOUNT_DISPLAY_2026-11-14.md) recorded this discovery issue.

On `bugs/client-account-menu-profile` (source commit `3449f78`), `Frontend/src/customer/ClientApp.tsx` now renders the client label as ordinary menu text and adds an actionable **My profile** item only after server verification and clinic-record linkage. That item closes the menu and asks the existing `CustomerWorkspace` to show its profile mode. `Frontend/src/customer/CustomerWorkspace.tsx` disables that menu action during the same locked/saving states as its workspace navigation, then focuses a visible profile heading when the menu action opens the profile. It also gives the **Preferred contact** selector an ARIA label relationship through MUI `InputLabel` and `Select`; the prior `TextField select` generated a native label pointing to a nonlabelable styled `div` when the profile was opened. The profile API, client linkage, name fallback, sign-out, authentication and session behavior are unchanged.

`Frontend/tests/customer.spec.ts` verifies that a placeholder provider name stays out of the UI, Enter on the account menu's **My profile** item opens the saved synthetic profile, focus moves to its heading, and all displayed native labels target labelable controls. `Frontend/tests/client-forms.spec.ts` verifies the menu item is disabled while an uncertain form submission locks navigation. This uses existing synthetic browser fixtures; no hosted client record was read for implementation testing.

## Validation and remaining evidence

Local runtime: Node 22.22.1, npm 11.19.0, Vite 6.4.5, TypeScript 5.7.2, ESLint 10.13.0, Playwright 1.63.0, Windows Edge. `Frontend/npm ci` passed from the unchanged lockfile. `Frontend/npm run build` passed: lint zero warnings, four bilingual content entries, 2,579 English/French translations, strict TypeScript, public, shared-portal and application-admin builds. Existing large-chunk warnings (including portal `FormValidation` about 553 kB and central bootstrap about 1.56 MB) and an existing mixed static/dynamic `AuthProvider` import warning remain; this change does not hide or adjust those limits.

`npx playwright test --config playwright.config.ts tests/customer.spec.ts --reporter=line` initially passed **31/33**. The first two pilot-denial cases displayed the generic boot error after local module requests failed with `net::ERR_NETWORK_CHANGED` in their saved traces; the other 31, including the new profile path, passed. Re-running only those two after the build with `--grep "pilot denial explains restricted access"` passed **2/2**. After the final focus and keyboard change, `npx playwright test --config playwright.config.ts tests/customer.spec.ts tests/client-forms.spec.ts --grep "placeholder provider name|uncertain submission locks answers" --reporter=line` passed **2/2**. The first focused profile test had exposed the Preferred contact label warning; the selector fix made the focused test pass. The completed browser evidence is local and mocked, not hosted acceptance for this new bundle.

`npm audit --json` reported **nine moderate, zero high/critical** advisories in development tooling, including `eslint@10.13.0`, `typescript-eslint@8.72.1` and transitive `minimatch@10.2.6` (globparts amplification). `npm audit --omit=dev --json` reported **zero runtime advisories**. These tools are used by the build/lint process and are not shipped as portal runtime packages. No dependency or lockfile was changed; the advisory needs a separate reviewed tooling update and fresh audit. `npm ci` also repeated the existing esbuild 0.25.12 install-script policy warning. The portal-only artifact described below was packaged and inspected; a full release artifact review remains open.

No PHP, SQL, private settings, identity provider, API grant, consent, role, external endpoint, mail/SMS, runtime dependency or toolchain changed. The prepared portal-only package below was transferred by the owner for synthetic pilot UI testing; authenticated acceptance is still being checked. The synthetic client should verify account menu, keyboard/focus, profile, appointment/form lock, sign-out, French, mobile and console; both clinic hosts share the frontend. Older portal assets retained under the [asset cleanup record](PORTAL_ASSET_CLEANUP_REVIEW_2026-11-14.md) must not be deleted solely because a new shell is built. Release/operations owner: Luis Duran. Real-PHI release remains blocked by the broader repository gates.

### Prepared portal-only pilot package — 15 November 2026

The owner asked whether this fix can be deployed. A controlled pilot artifact is prepared at `D:\Dev\WelnessCentre\deployments\client-account-menu-profile-2026-11-15\01-shared-portal-assets-only.zip` from source commit `eeb4acb4fa401fa0ca9761933aba85c54ee6a7dd`, branch `bugs/client-account-menu-profile`. Its SHA-256 is `9124e0707042ba27d6acce422c38417815b08ec49d6fce91df3db56d2c809eaa` (816,396 bytes); it contains exactly `index.html` and 69 JS/CSS assets. The new entry page SHA-256 is `28b4e4b6b63c43cba62b1dce290cbc13362771f19dba92c27d373d561645944a`. `manifest.json` alongside the ignored ZIP records all 70 file hashes, lockfile hash, runtime versions, build flags, source and target. All ZIP entry hashes match the manifest. The staging build contains additional `.htaccess`, favicon and notices, but **none** is in the ZIP. No source maps, PHP, SQL, private settings, private keys or path traversal entries were found in the ZIP. The built browser bundle contains the expected public Maps key and public External ID identifiers; library literals for `http://localhost` and Azure's instance-metadata address are not app API targets. This is a scoped artifact check, not a complete historical secret scan.

The portal build explicitly enabled external practitioner auth, applications and social sign-in, and kept invitations and server sessions disabled; public External ID identifiers came from the prior reviewed pilot release manifest. The configured API/public/portal base URLs are HTTPS. The flagged build was byte-identical across the release staging folder and `Frontend/dist/portal` (73 build files, including the three excluded staging extras). With `BUILD_PRACTITIONER_SIGN_IN=1`, `npx playwright test --config playwright.build.config.ts --reporter=line` passed **17/17** built public/portal smoke tests, including practitioner versus workforce entry. Current live `index.html` on **both** clinic hosts still matched `2bc51f6107a50c5b452bf2f50a52381f6ca52594d012eb58cd340689cc6efafc` before transfer. A fresh copy is saved outside the web root at `D:\Dev\WellnessCentre-backup\client-account-menu-profile-2026-11-15\live-index-before.html` and hash verified.

The owner transfer sequence is: upload the ZIP's 69 `assets/` files **additively** into `/public_html/wellness-common-portal/assets/`, with no mirror/deletion; verify every new asset on both hosts; then replace **only** `/public_html/wellness-common-portal/index.html` with the ZIP's `index.html`; verify the new shell hash and client/staff/practitioner routes. Do not copy the ZIP's staging folder, alter `.htaccess`, `api/` or private settings, or touch the separate marketing website. This shared folder affects Wellnexa and LivinLively. Rollback restores only the saved previous `index.html`; keep the added hashed assets so open tabs and rollback can load. If new assets or either host do not verify, do not switch the entry page. This pilot package does not authorize real-PHI release.

### Hosted pilot transfer and static acceptance — 15 November 2026

The owner reported uploading the `assets/` contents with SFTP, without mirroring. Before the entry switch, HTTPS downloads of **69/69 assets on each clinic host** matched the ZIP manifest SHA-256 values (**138/138**) and both hosts still served the previous entry hash `2bc51f6107a50c5b452bf2f50a52381f6ca52594d012eb58cd340689cc6efafc`. The owner then replaced only the shared `index.html`. Both hosts returned HTTP 200 with the new exact entry hash `28b4e4b6b63c43cba62b1dce290cbc13362771f19dba92c27d373d561645944a`; a second check after the owner test matched again. `/client`, `/staff/login`, `/staff/external` and `/staff/apply` returned HTTP 200 with `text/html` on both hosts (eight route checks). This confirms routing to the shared shell, not authorization or an open practitioner application intake. Entry responses retained `Cache-Control: no-store, must-revalidate, no-cache, max-age=0` and five-minute HSTS. The checked entry responses did not include `X-Content-Type-Options`; the pre-existing broader header gap remains for SEC-03 review and is not treated as fixed by this upload. No API, SQL, identity or private setting was uploaded.

After a hard refresh, the owner reported that the signed-in synthetic Wellnexa client could choose the new account-menu **My profile** item and the saved profile opened, with no new portal error. The supplied Console stack traces point to `chrome-extension://bbcinlkgjjkejfdpemiealijmmooekmp/background-redux-new.js` (`OperationError` and `Invalid frameId`), so they are classified as browser-extension errors, not evidence of a portal bundle exception. Focus placement was covered in local keyboard regression but not independently confirmed in the hosted report. Sign-out, French, mobile, form-lock and a LivinLively authenticated session remain untested for this deployed revision.

### Keyboard Tab finding and correction — 15 November 2026

During hosted acceptance, the owner pressed Enter on the focused **C** avatar, then Tab toward **My profile**. The deployed MUI `Menu` closed on Tab. The original local test had called `.focus()` directly on the menu item, so it missed that exact path. The follow-up changes the account actions to a `Popover` with an accessible named group and ordinary buttons. Enter on the avatar opens the panel; Tab reaches **My profile** and then **Sign out**; Escape closes it and returns focus to the avatar. The client label is still plain text. The actions keep their existing verification, linkage, busy and uncertain-save restrictions. No authentication or API behavior changed.

The updated Playwright test uses Enter, Tab, Enter and asserts profile-heading focus; it also checks second Tab and Escape focus restoration. The form-lock and sign-out selectors were updated to match the button group. `npm run build` passed lint, content/i18n, strict TypeScript and all three frontend builds. Focused tests passed **3/3**, followed by the profile keyboard test **1/1**. A broader customer/client-form run passed **73/74**; one unrelated draft-save test reached the generic application-boot error while local modules loaded and timed out waiting for **My forms**. Its isolated rerun passed **1/1**. This intermittent test-environment failure is retained as initial evidence; it is not counted as a clean 74/74 run. Hosted acceptance of the corrected keyboard path remains pending.

## Scoped 38-control ledger

Review date: 15 November 2026. Statuses apply to this UI slice, not the whole application. Unverified items require the accountable privacy, security, clinical or release owner to follow the existing release process; this record grants no exception.

| Control | Status | Evidence or next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/agent allocation unchanged; owner/legal review. |
| GOV-02 | Unverified | Applicability register and current legal sources unchanged. |
| GOV-03 | Unverified | PIPEDA assessment unchanged. |
| GOV-04 | Unverified | Administrative intended use unchanged; clinical review remains. |
| GOV-05 | Unverified | Data inventory, PIA and threat/risk decisions remain. |
| PRIV-01 | Unverified | Published notice/contact unchanged. |
| PRIV-02 | Unverified | No collection, disclosure or consent change. |
| PRIV-03 | Partial | Menu requires verified linked status; server checks remain authoritative and hosted revocation retest remains. |
| PRIV-04 | Unverified | Representative/minor process unchanged. |
| PRIV-05 | Unverified | Full access/correction process unchanged. |
| PRIV-06 | Unverified | Record/profession retention approvals remain. |
| PRIV-07 | Unverified | Breach rehearsal remains. |
| AUTH-01 | Partial | Existing client verification path retained; hosted test of new bundle pending. |
| AUTH-02 | Partial | Menu opens only the existing linked client's workspace; server boundary tests remain. |
| AUTH-03 | Unverified | MFA, idle/absolute limits and departure controls unchanged. |
| AUTH-04 | Unverified | Existing browser storage exception remains for real-data gate. |
| AUTH-05 | Unverified | No API grant change; deployed inventory remains. |
| SEC-01 | Partial | No secret or private fixture added; final artifact/history scan pending. |
| SEC-02 | Unverified | TLS, private hosting and backup controls unchanged. |
| SEC-03 | Partial | New shell on both hosts has no-store and five-minute HSTS; `X-Content-Type-Options` absent from checked entry responses and wider header/cache review remains. |
| SEC-04 | Partial | Account text remains React encoded; no HTML or URL sink added. |
| SEC-05 | Unverified | No upload/request/worker change; abuse review remains. |
| SEC-06 | Unverified | Audit routes unchanged; hosted review remains. |
| SEC-07 | Partial | Placeholder remains suppressed; initial local network failure recorded, not hidden. |
| SEC-08 | Unverified | No new service or data endpoint; register review remains. |
| SEC-09 | Unverified | Mail/SMS unchanged and pilot channels remain disabled. |
| DEV-01 | Partial | Dedicated bugs branch, source commit `3449f78` pushed and lockfile preserved; human review pending. |
| DEV-02 | Partial | Typed, focused UI change; human code review pending. |
| DEV-03 | Partial | Lockfile restore passed; nine moderate build-tool advisories, zero runtime advisories; separate remediation needed. |
| DEV-04 | Partial | Build/content/i18n/typecheck and scoped browser tests passed after recorded local network retry; full suites omitted. |
| DEV-05 | Partial | Semantic menu action, label, keyboard/focus and lock assertions passed; manual screen reader, zoom and hosted bilingual/mobile checks remain. |
| DEV-06 | Partial | Existing bundle and import warnings recorded; representative performance review remains. |
| REL-01 | Partial | Dated scoped ledger; accountable reviewer decision pending. |
| REL-02 | Partial | Frontend build and relevant browser checks passed; broad PHP/SQL and dedicated admin suites omitted because backend is unchanged. |
| REL-03 | Partial | ZIP, manifest, 17 built-smoke checks, 138 asset hashes, two entry hashes, eight hosted route responses and the owner's signed-in Wellnexa profile opening passed; other authenticated scenarios remain. |
| REL-04 | Missing | Real-data release remains separately blocked. |
| REL-05 | Partial | Owner transferred exact ZIP in reviewed order with current-shell backup; broader operational sign-offs remain. |
| REL-06 | Partial | Files, versions, results, advisories, artifact, rollback, hosted static checks, signed-in profile result and browser-extension Console classification recorded; wider acceptance remains. |

RELEASE STATUS: NOT READY FOR RELEASE
