# Client account menu profile action — 15 November 2026

## Finding and change

On the signed-in Wellnexa synthetic client page, the avatar menu displayed a gray, disabled **Client** row above **Sign out**. The row was a role label (`MenuItem disabled`), not a profile link, although its styling suggested a broken action. The provider returns the placeholder `unknown` for this test identity, so the existing display helper intentionally uses **Client** and **C**; a contact email or clinical profile is not inferred from the identity provider. The actual profile was available through **My profile** in the workspace button row, but the account menu did not expose it. The previous [client display review](CLIENT_ACCOUNT_DISPLAY_2026-11-14.md) recorded this discovery issue.

On `bugs/client-account-menu-profile` (source commit `3449f78`), `Frontend/src/customer/ClientApp.tsx` now renders the client label as ordinary menu text and adds an actionable **My profile** item only after server verification and clinic-record linkage. That item closes the menu and asks the existing `CustomerWorkspace` to show its profile mode. `Frontend/src/customer/CustomerWorkspace.tsx` disables that menu action during the same locked/saving states as its workspace navigation, then focuses a visible profile heading when the menu action opens the profile. It also gives the **Preferred contact** selector an ARIA label relationship through MUI `InputLabel` and `Select`; the prior `TextField select` generated a native label pointing to a nonlabelable styled `div` when the profile was opened. The profile API, client linkage, name fallback, sign-out, authentication and session behavior are unchanged.

`Frontend/tests/customer.spec.ts` verifies that a placeholder provider name stays out of the UI, Enter on the account menu's **My profile** item opens the saved synthetic profile, focus moves to its heading, and all displayed native labels target labelable controls. `Frontend/tests/client-forms.spec.ts` verifies the menu item is disabled while an uncertain form submission locks navigation. This uses existing synthetic browser fixtures; no hosted client record was read for implementation testing.

## Validation and remaining evidence

Local runtime: Node 22.22.1, npm 11.19.0, Vite 6.4.5, TypeScript 5.7.2, ESLint 10.13.0, Playwright 1.63.0, Windows Edge. `Frontend/npm ci` passed from the unchanged lockfile. `Frontend/npm run build` passed: lint zero warnings, four bilingual content entries, 2,579 English/French translations, strict TypeScript, public, shared-portal and application-admin builds. Existing large-chunk warnings (including portal `FormValidation` about 553 kB and central bootstrap about 1.56 MB) and an existing mixed static/dynamic `AuthProvider` import warning remain; this change does not hide or adjust those limits.

`npx playwright test --config playwright.config.ts tests/customer.spec.ts --reporter=line` initially passed **31/33**. The first two pilot-denial cases displayed the generic boot error after local module requests failed with `net::ERR_NETWORK_CHANGED` in their saved traces; the other 31, including the new profile path, passed. Re-running only those two after the build with `--grep "pilot denial explains restricted access"` passed **2/2**. After the final focus and keyboard change, `npx playwright test --config playwright.config.ts tests/customer.spec.ts tests/client-forms.spec.ts --grep "placeholder provider name|uncertain submission locks answers" --reporter=line` passed **2/2**. The first focused profile test had exposed the Preferred contact label warning; the selector fix made the focused test pass. The completed browser evidence is local and mocked, not hosted acceptance for this new bundle.

`npm audit --json` reported **nine moderate, zero high/critical** advisories in development tooling, including `eslint@10.13.0`, `typescript-eslint@8.72.1` and transitive `minimatch@10.2.6` (globparts amplification). `npm audit --omit=dev --json` reported **zero runtime advisories**. These tools are used by the build/lint process and are not shipped as portal runtime packages. No dependency or lockfile was changed; the advisory needs a separate reviewed tooling update and fresh audit. `npm ci` also repeated the existing esbuild 0.25.12 install-script policy warning. The final artifact has not been packaged or scanned for this branch.

No PHP, SQL, private settings, identity provider, API grant, consent, role, external endpoint, mail/SMS, runtime dependency or toolchain changed. No hosted files were deployed. A future portal-only release needs a new package and source manifest, current live `index.html` backup, additive asset upload, both-host hash checks, rollback and REL-05 owner approval. The authenticated synthetic client should then verify account menu, keyboard/focus, profile, appointment/form lock, sign-out, French, mobile and console; both clinic hosts share the frontend. Older portal assets retained under the [asset cleanup record](PORTAL_ASSET_CLEANUP_REVIEW_2026-11-14.md) must not be deleted solely because a new shell is built. Release/operations owner: Luis Duran. Real-PHI release remains blocked by the broader repository gates.

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
| SEC-03 | Unverified | Hosted headers/cache unchanged; new bundle not deployed. |
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
| REL-03 | Missing | No new release artifact or hosted acceptance for this UI fix. |
| REL-04 | Missing | Real-data release remains separately blocked. |
| REL-05 | Unverified | No production change; future deployment needs explicit decision and rollback record. |
| REL-06 | Partial | Files, versions, results, advisories, omitted checks and release boundary recorded; final review pending. |

RELEASE STATUS: NOT READY FOR RELEASE
