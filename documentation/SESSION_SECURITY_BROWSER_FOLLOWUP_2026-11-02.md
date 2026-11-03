# Session prototype browser follow-up — 2 November 2026

**Production unchanged. Local feasibility gate remains incomplete.**

Source: `9931efb85083bb7d20efa92aaf30d1ab28354c44`, based on
`825edb01572a7d9040f9ba696d5b5d5f4bfe7184`, branch `bugs/session-prototype-browsers`.
Reviewer/date: Codex, 2 November 2026 Eastern. The full run began
`2026-11-03T03:57:42.573Z` (2 November locally). Owner authorized continued isolated
browser investigation; no production integration, deployment, merge, schema, tenant,
grant or real-account change. Accountable security/privacy/identity/release decisions
remain unsigned. The untracked `documentation/blog-topics.pdf` is preserved.

## Outcome

The new full matrix is **128 passed / 4 failed / 0 skipped**, exit 1, 181.9 seconds:

| Browser | Full prototype | Independent cookie probe |
| --- | --- | --- |
| Edge 157.0.4341.30 | **44/44 passed** | Lax metadata retained; explicit Lax cookie withheld on cross-site POST. |
| Firefox 155.0, locked revision 1543 | **44/44 passed** using an isolated test cache | Same passing cookie outcomes. |
| Windows WebKit 26.6, locked revision 2359 | **40/44 passed** | Reports None for explicit Lax and **sends** the dummy Lax cookie on cross-site POST. |

All four remaining full-matrix failures are the existing strict SameSite=Lax checks,
one per realm. The assertions, PHP cookie policy, CSRF/origin requirements and token
requirements were retained. WebKit's reload assertions following the failed cookie
check remain unreached. Do not interpret the 40 passing cases as WebKit acceptance.

Popup cancellation passed **10/10 focused repetitions** (exit 0, 1.2m), and then
passed in every browser's full run. The fixture now waits for the actual initialized
sign-in status and models returning focus to the parent after closing the popup.
The HTML login button starts disabled; a bilingual pending status confirms an active
interaction. The bridge timeout remains four seconds and the failure assertion
timeout remains five seconds. This corrects local readiness/focus assumptions without
overriding MSAL, polling another window's URL, persisting tokens or weakening checks.
It does not prove cancellation timing in background tabs, iOS or real MFA enrollment.

MSAL's supported bridge deliberately waits for its configured timeout after the user
closes a popup; immediate close detection is not its contract. This matches both the
installed 5.19.0 `dist/utils/BrowserUtils.mjs` and Microsoft's
[popup cancellation guidance](https://github.com/AzureAD/microsoft-authentication-library-for-js/blob/dev/lib/msal-browser/docs/login-user.md).
The short timeout is for synthetic testing, not a proposed production MFA limit.

## Why the WebKit result is an environment capability finding

The independent `Frontend/scripts/check-session-browser-capabilities.mjs` does not
load the application, MSAL, PHP, a provider or any real account. It starts two temporary
servers on **127.0.0.1 and 127.0.0.2**, random ports, and tests a fixed dummy cookie:

1. A real loopback HTTP response sets an explicit HttpOnly, SameSite=Lax dummy cookie.
2. A form on the other loopback IP submits a top-level cross-site POST. The target
   reports only whether that dummy cookie arrived, never its value.
3. A routed synthetic HTTPS response sets a Secure/HttpOnly/host-only Lax cookie.
4. Playwright explicitly adds another Lax cookie through its cookie API.

Edge and Firefox retain Lax and withhold the HTTP cookie on the cross-site POST.
Windows WebKit reports None in all three creation paths and sends the HTTP cookie.
The issue therefore extends beyond our PHP response, duplicate-header formatting,
interception or metadata reporting alone. No production code change can make this
particular test build demonstrate the missing behavior. Safari/macOS/iOS behavior
must be assessed on the appropriate platform; these observations do not establish
a Safari vulnerability. Both full-matrix and capability commands intentionally stay red.

The probe uses HTTP only for fixed synthetic loopback cookies, and intercepted HTTPS
for the Secure-cookie cases. **No real TLS/certificate verification is claimed or
disabled.** The ordinary HTTP test cookie is not a proposed session-cookie setting.
The actual prototype still uses __Host-/Secure/HttpOnly/Lax and its existing bearer,
CSRF and exact-Origin guards. The separate diagnostic never transmits PHI or reusable
credentials. Both local servers and every fresh browser are closed on normal completion.

## Firefox environment resolution

The original user-level cached Firefox fails with Windows SideBySide event 33:
the mozglue assembly cannot activate. A fresh download of the **same locked build**
into ignored `.tmp/session-browsers` launches successfully. SHA-256 comparison of all
61 files found no missing or different files between those caches. The precise reason
for the path-specific Windows activation failure remains unverified; this is not
evidence of corrupted package bytes or an application defect.

Use the official process-scoped PLAYWRIGHT_BROWSERS_PATH setting for this experiment.
No global cache was deleted/replaced, system library installed, security setting
disabled, test framework upgraded or production dependency added. An inventory found
Ubuntu WSL without Linux Node/PHP and an unavailable Docker daemon; no new distro,
container, runtime or service was provisioned or started to conceal the Windows failures.

## Reproduce

From `Frontend` in PowerShell, after the existing exact-lockfile dependencies are installed:

```powershell
$previousBrowserCache = $env:PLAYWRIGHT_BROWSERS_PATH
try {
    $env:PLAYWRIGHT_BROWSERS_PATH = [IO.Path]::GetFullPath((Join-Path (Get-Location) '../.tmp/session-browsers'))
    npx playwright install firefox webkit
    npm run typecheck:session-prototype
    npm run lint
    npm run test:session-prototype
    node scripts/check-session-browser-capabilities.mjs
} finally {
    $env:PLAYWRIGHT_BROWSERS_PATH = $previousBrowserCache
}
```

The last two commands currently exit 1 for Windows WebKit; inspect each exit code.
Browsers come from the existing Playwright lock, using verified download transport.
Do not change the expected cookie policy to make this platform pass.

On a separately prepared compatible macOS/Linux test machine with the existing Node,
PHP extensions, Composer vendor tree and exact npm lock restored, the same config can
select only `--project=firefox --project=webkit`; the capability probe accepts
`firefox webkit` as arguments. No execution on those operating systems is claimed here.
Actual Safari/iOS and trusted HTTPS acceptance are separate from Playwright engine tests.

## Validation, warnings and omitted checks

| Command / directory | This task's result |
| --- | --- |
| `npm run typecheck:session-prototype` — Frontend | Exit 0; dedicated strict TypeScript unchanged. |
| `npm run lint` — Frontend | Exit 0, zero warnings; includes the new diagnostic script. |
| `npx playwright test --config playwright.session-prototype.config.ts --project=webkit --grep 'cancelled popup' --repeat-each=10` — Frontend | 10 passed, exit 0, 1.2m; `.tmp/session-browsers-cancel-focus.log`. |
| `npm run test:session-prototype` — Frontend, process-scoped isolated cache | 128 passed, 4 failed, no skips, exit 1, 181.9s; `.tmp/session-browser-full-matrix.log`; preserved JSON `.tmp/session-browser-final-report.json`. |
| `node scripts/check-session-browser-capabilities.mjs` — Frontend, same cache | Exit 1; Edge/Firefox requirements pass, WebKit fails cookie metadata and real cross-site POST; `.tmp/session-browser-capabilities-final.json`. |
| `npx playwright install firefox`; `npx playwright install webkit` — Frontend, same cache | Both exit 0; locked builds only, no app dependency change. Original cache failure and byte-identical comparison retained above. |
| `npm run build` — Frontend | Exit 0: lint, 4 bilingual content entries, 2,568 translations, strict TS, public/portal/central builds; `.tmp/session-browser-frontend-build.log`. Existing >500kB chunks warned; limits unchanged. |
| `npx playwright test --config playwright.build.config.ts` — Frontend | 15 passed, exit 0, 21.4s; `.tmp/session-browser-build-tests.log`. |
| `npm run lint`; `npm run build`; `npm run test:browser` — Wellnexa | Each exit 0; 24 browser tests passed, 35.3s. Same four lint warnings: two module mutation warnings in App.tsx, two component-export warnings in catalogue/Details.tsx. `.tmp/session-browser-wellnexa-tests.log`. |
| `php api/tests/customer-auth.php` | Exit 0: 25 auth + 1 staff boundary + 6 proof checks. |
| `php api/tests/external-staff-auth.php` | Exit 0: 67 checks. |
| `php api/tests/customer-onboarding.php` | Exit 0: 25 checks. |
| `php api/tests/identity-membership.php` | Exit 0: 29 membership + 3 permission checks. |
| PHP total / syntax | 156 existing synthetic checks; process OPENSSL_CONF set temporarily to `C:/Program Files/Git/usr/ssl/openssl.cnf` and restored. Both prototype PHP files pass `php -l`; neither was changed. |
| `composer validate --strict`; `composer --no-plugins --no-scripts audit --format=json` — api | Both exit 0; no advisories or abandoned packages; ignored audit JSON retained. |
| `npm audit --json`; `npm audit --omit=dev --json` — Frontend | Full exit 1: same 11 moderate development lint/minimatch-chain findings, no high/critical. Runtime exit 0: zero. No forced fixes; [existing remediation review](FRONTEND_QUALITY_GATES.md) remains open. |
| Production exclusion | 102 built files scanned: no prototype marker, fake identity host, prototype route/cookie prefix or diagnostic cookie name. Production source and all three app lockfiles are unchanged from 825edb0. |

The former [prototype report](SESSION_SECURITY_PROTOTYPE_2026-11-02.md) remains historical
evidence, including its failed full run, isolated passes and initial harness errors.
This task's first diagnostic falsely counted an unrelated Firefox HTTP cookie in the
fake-origin inventory when a favicon request raced cookie clearing. The probe now sets
its HTTP cookie only on `/set` and filters inventory to the specific target origin;
the strict Lax/HttpOnly/Secure/domain/path and cross-site POST requirements remain.

The broad default application suite and dedicated application-admin/invitation/application
suites were not repeated: their production code/configuration is unchanged. The previous
broad run's two startup failures and two skips remain recorded and unresolved; isolated
passes did not turn that run green. No new skips or weakened assertions. No clean release
restore, SQL suite/migration, hosted DB/account inspection, real Google/Microsoft flow,
MFA, provider logout, Safari/iOS, trusted HTTPS, full accessibility, load/restore or
operational sign-off was performed. Source builds are not a release package.

## Changed files, versions and operations

| File | Purpose |
| --- | --- |
| Frontend/playwright.session-prototype.config.ts | Add the installed/locked Firefox project without removing Edge/WebKit. |
| Frontend/prototypes/session-security/index.html | Disable login before JavaScript/SDK initialization. |
| Frontend/prototypes/session-security/main.ts | Announce pending sign-in in both languages and clear stale failure metadata. |
| Frontend/tests/helpers/sessionPrototype.ts | Wait for initialized status as well as an enabled button. |
| Frontend/tests/session-security-prototype.spec.ts | Assert pending interaction; model returning to parent focus after cancellation. |
| Frontend/scripts/check-session-browser-capabilities.mjs | Independent fixed-data HTTP, cross-site POST, routed HTTPS and cookie-API diagnostic; redacted metadata, browser/server cleanup and failing exit status. |
| prototypes/session-security/README.md | Current matrix, isolated cache reproduction and limits. |
| documentation/SESSION_SECURITY_BROWSER_FOLLOWUP_2026-11-02.md | Source-bound findings, commands, remaining gates and all-control ledger. |
| documentation/SESSION_SECURITY_PROTOTYPE_2026-11-02.md | Link this later follow-up while retaining initial results. |
| documentation/README.md | Index the latest browser evidence. |

Unchanged toolchain/dependencies: Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14 (declared ^8.2),
MSAL browser 5.19.0 / React adapter 5.6.0, React 19.2.8, TypeScript 5.7.3,
Playwright 1.63.0, Vite 6.4.5, ESLint 10.13.0, Firebase PHP-JWT 7.1.0.
Wellnexa uses its existing Vite 8.3.4. No manifest/lock/dependency/runtime support change.
The prior report retains the unchanged lock hashes.

No production permissions, consent, identity registration, endpoints, tenant settings,
private env, memberships, payments, records or external application data flows changed.
Only synthetic loopback diagnostic listeners were added; existing harness fake HTTPS
origins and loopback Vite/PHP remain isolated. No messages or clinical data. Browser
downloads used the existing toolchain's distribution endpoints, with no TLS bypass.
Normal harness teardown removed both generated private files; cache binaries and reports
stay ignored. No release ZIP, migration, package upload, merge or deployment.

Rollback: review/revert these task commits and, if desired, stop using the private test
cache; no production restoration or global browser deletion. Do not revert session
security requirements or change PHP's SameSite/CSRF policy to accommodate this test build.

## Remaining decisions and next action

Security/QA should obtain macOS WebKit and actual Safari/iOS cookie/popup evidence over
trusted HTTPS, retaining the same strict tests. Identity/security must still verify real
Google/personal Microsoft/workforce claim freshness and MFA independently. Product and
security must accept memory-loss/reload UX and approve session/recent-auth timing before
production integration. Engineering/operations still need production SQL authority/race/
audit integration and a concrete reviewed deployment/rollback artifact. Named custodian,
privacy, clinical, security and release sign-offs remain outstanding. This investigation
does not authorize those later authentication architecture or hosted changes.

## All 38 controls — this task's scope

Date/source/reviewer above; owner roles need named assignment and approval. Implemented
means only the verified local scope; historical findings are not closed by inheritance.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | No custody/contracts change; custodian/operator identify accountable clinic/platform owners. |
| GOV-02 | Unverified | No legal/commencement reassessment; privacy/legal maintain applicability register. |
| GOV-03 | Unverified | No PIPEDA determination changed; privacy/legal assess actual commercial/cross-border flows. |
| GOV-04 | Partial | Administrative synthetic test only; product/clinical confirm real intended use. |
| GOV-05 | Partial | Fixed synthetic data, independent probe flow described; security/privacy update real PIA/TRA separately. |
| PRIV-01 | Unverified | Notices/contact unchanged; custodian verify actual bilingual practices. |
| PRIV-02 | Partial | No real collection/consent; privacy approve production session/audit metadata and authority. |
| PRIV-03 | Partial | Local expiry/revocation/denial regression retained; security/custodian need production transaction/worker authority evidence. |
| PRIV-04 | Unverified | Representatives/minors unchanged; clinical/custodian retain scoped authority/assisted-process evidence. |
| PRIV-05 | Unverified | Rights workflows unchanged; privacy operations retain access/correction evidence. |
| PRIV-06 | Unverified | Disposable test secrets removed only; records/privacy approve actual session/audit/backup retention and holds. |
| PRIV-07 | Partial | Local logout/denial exercised; incident/privacy lead rehearse real lost-device/breach response. |
| AUTH-01 | Partial | Existing real SDK and PHP validators pass local Edge/Firefox matrix; identity/security verify real claims/MFA/providers. |
| AUTH-02 | Partial | Account/host/realm negative cases retained; production memberships/record relationships unchanged, not newly accepted. |
| AUTH-03 | Partial | Local timer/logout/cancellation cases pass; production limits, recent auth and MFA remain missing/unverified. Security/operations own. |
| AUTH-04 | Partial | Memory-only fixture maintained; cookie gate fails WebKit. Existing production storage unchanged; security approve actual design/handoffs. |
| AUTH-05 | Partial | No grants broadened; identity/operations still need exact deployed permission/principal inventory. |
| SEC-01 | Partial | Fixed dummy cookies, metadata-only diagnostic, keys/cache/reports ignored; security retain broader artifact/history exposure review. |
| SEC-02 | Partial | No TLS bypass; native HTTP uses fixed loopback data only. Actual HTTPS/DB/backup encryption and key custody unverified. Operations own. |
| SEC-03 | Partial | Cookie capability failure reproduced beyond interception; strict assertion retained. Security/QA require target-platform trusted HTTPS acceptance. |
| SEC-04 | Partial | Dynamic probe HTML uses fixed literals/numeric ports/boolean outcomes, no untrusted input/unsafe DOM sink; production parser/SQL review remains. |
| SEC-05 | Partial | Existing request/store caps/replay/timeout tests retained; local server/browser cleanup explicit. Engineering need production abuse/load/SQL race tests. |
| SEC-06 | Partial | No new production audit implementation; security/DB need actor/time/correlation, denial and fail-closed transaction integrity evidence. |
| SEC-07 | Partial | No token/cookie values logged by new probe; classified browser failures and current fixture diagnostics. Operations verify real proxy/provider logs. |
| SEC-08 | Partial | Loopback-only new probe, no external app flow; privacy/vendor owners approve actual identity/session/subprocessor changes later. |
| SEC-09 | Not applicable | No new email/SMS/calendar flow or dispatch; existing communication controls remain separate. |
| DEV-01 | Implemented (local scope) | Dedicated bugs branch, instruction/config/lock/deploy/migration inspection, preserved PDF, commit/push without merge/deploy. |
| DEV-02 | Partial | Small isolated changes and strict checks; failed browser requirements retained, no persistent-token/CSRF fallback. Human security review remains. |
| DEV-03 | Partial | Same locks and browser builds, audits recorded; 11 dev advisories and clean release restore/licence/maintenance review remain. |
| DEV-04 | Partial | 132-case full matrix, capability probe, builds, 15 artifact/24 site and 156 PHP checks; WebKit failures and omitted suites explicit. |
| DEV-05 | Partial | Bilingual pending status/native disabled control and existing keyboard/mobile checks; real assistive tech/contrast/themes/AODA assessment unverified. |
| DEV-06 | Partial | No new production bundle code/network polling; finite local servers/browsers close, bounded original fixture. Real load/background/SQL performance unverified. |
| REL-01 | Partial | This dated source-bound ledger; release owner obtain named decisions and missing evidence. |
| REL-02 | Partial | Local commands/results/failures recorded; SQL/hosted and reproducible release restore omitted as above. |
| REL-03 | Unverified | Production artifacts exclude harness; no new deployable artifact, hosted session acceptance or restore. Operations/release own. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; no waiver of cookie or missing operational/privacy gates. |
| REL-05 | Implemented (authorized local scope) | Owner authorized continuation; production architecture/grants/schema/endpoints unchanged. Later material work needs concrete approval. |
| REL-06 | Implemented (report scope) | Findings/files/versions/commands/flows, failures/omissions, owners and rollback recorded with final checks below. |

Final repository checks: `node .tmp/validate-session-browsers.mjs` passed for four
maintained documents, 107 relative links, 38 distinct control rows and all ten changed
files. A bounded private-key/JWT signature scan found zero matches in those files;
no private env/dump/ZIP, test cache or generated output is committed. Production
source and lock comparison against 825edb0 is empty. `git diff --cached --check`
passed; normal LF-to-CRLF notices are not failures. Both generated private files
were confirmed absent after teardown.

Ignored evidence SHA-256:

- `.tmp/session-browser-final-report.json`: `7df0b7e0146f37c80dd2e455a4a0368f45b122adf2afbaa6afe0bec69fc8776f`.
- `.tmp/session-browser-capabilities-final.json`: `4e53016d3cf9d298b74aa732c64174cbe49022a0a98e15d3d053bf74740a7bac`.

The dedicated branch is committed and pushed for review; no merge or deployment.

RELEASE STATUS: NOT READY FOR RELEASE
