# Session security feasibility prototype — 2 November 2026

**Local synthetic WP0 only. Production authentication is unchanged. NOT READY FOR RELEASE.**

Baseline: `2858e2dcfa291c3775c685736ebe4c33a9ab79ca`.
Branch: `feature/session-security-prototype`. Implementation source:
`1dfc098ceafd309124384a171b25571b51caeddc`; the following documentation commit records these results.
Reviewer: Codex, local engineering evidence, 2 November 2026. Product owner authorized
this experiment, keeping production unchanged. Named security, privacy, identity and
release reviewers and their decisions remain outstanding. No merging or deployment
is authorized by this report. The unrelated `documentation/blog-topics.pdf` is preserved.

## Decision and useful findings

The existing MSAL 5.19.0 client can complete a popup/PKCE exchange with durable tokens
in memory, and the existing PHP JWT library can independently require both a verified
bearer and a host/realm/principal-bound HttpOnly session cookie. Local tests demonstrate
idle and absolute expiry, one-use fresh sign-in proof, CSRF/origin enforcement, server
revocation and removal of mounted private UI. Silent provider-token renewal does not
restore an expired/revoked application session.

**The full feasibility exit gate is incomplete.** WebKit cookie assertions remain
strict and failing, and one popup-cancellation recovery case also failed; Firefox cannot launch here; no real provider, MFA, Safari/mobile,
hosting or production SQL transaction has been accepted. These findings support a
design review, not production integration. Do not select a different authentication
architecture solely from a synthetic transport failure. The earlier
[implementation plan](SESSION_SECURITY_REVIEW_2026-11-02.md) still defines WP1 onward.

Important product behavior: reloading or opening a new tab loses the in-memory MSAL
credentials. A cookie alone cannot recover access; another interactive popup is
required. Security and product must explicitly accept this behavior or review a BFF
design. There is no persistent-token fallback in this prototype.

## What runs, and what it proves

The harness has independent frontend and PHP entry points. It does not import into
production entry points, load private environment files, access a database or connect
to a real identity provider. Existing workforce, practitioner, client and central
application sources, their authorities/scopes, locks and grants are unchanged.

| Component | Actual behavior | Evidence limit |
| --- | --- | --- |
| MSAL | Actual installed `PublicClientApplication`, memory cache, `loginPopup`, supported `redirect-bridge`, SDK-generated PKCE, `acquireTokenSilent` and `clearCache`. Existing fresh-customer/practitioner login parameter helpers are reused. | Fake signed IdP responses model Google/Microsoft accounts; this does not test either real federation chain or provider logout. |
| Fresh proof | PHP verifies RS256 signature, issuer, tenant, audience, authorized SPA, scope, expiry and identity. A host/realm pre-auth cookie binds a one-use nonce challenge to matching oid/tid and recent auth_time. | Synthetic ID tokens include azp/auth_time. Real realm-specific claim availability/semantics and identity linking must be verified; this is not a production validation contract. |
| App session | Random 32-byte secret in `__Host-` cookie; only digests/principal hashes stored; 15-minute staff/practitioner/central idle, 30-minute client idle, eight-hour absolute deadline. Explicit activity slides idle only. | Timings are proposed project defaults, not approved policy or statutory durations. No clinical role/membership authority is granted by the fixture. |
| Cookie and CSRF | Secure, HttpOnly, host-only, Path=/, SameSite=Lax emitted by PHP. Protected calls require bearer and cookie; POST checks exact Origin; activity/logout require session-bound CSRF. | Cookie parsing/dispatch is partially simulated; actual HTTPS and cross-site behavior remain required. |
| Revocation/UI | Next protected request denies a revoked/expired session; timers hide private UI; generation checks prevent late responses/login completions from restoring it; same-origin realm channel carries only an ended marker. | No instant remote-device push, production membership commit-race or independent audit authority is implemented. |
| Logout | Private state clears before network work. A current bearer retrieves the current cookie's CSRF proof, addressing another tab's cookie rotation. Failure remains locally locked and explicitly reports unconfirmed server logout. | Local app logout does not establish upstream IdP/global logout. Different-account proof cannot revoke the newly selected account. |
| Store | Locked, bounded, disposable JSON file under ignored `.tmp`; challenge/session ceilings 512 each; 100 event metadata entries. | File locking and PHP's single-process development server are not SQL concurrency, audit integrity, retention, HA or production scalability evidence. |

All browser requests are intercepted. Allowed synthetic HTTPS origins are
`clinic-a.session.test`, `clinic-b.session.test`, `admin.session.test` and
`login.session.test`. Unknown origins abort. Static modules come from Vite at
`http://127.0.0.1:5196`; PHP calls go to `http://127.0.0.1:5197`. Browser auth callback
queries are not forwarded to Vite/PHP. No TLS verification is disabled; there is no
real TLS connection to these fake hosts, so this is not certificate/hosting evidence.

Edge forwards its intercepted Cookie header directly. WebKit stores the secure
cookies in its native jar but omits Cookie headers from intercepted requests; the
fixture explicitly bridges eligible cookies from that jar to loopback. WebKit also
reports SameSite=None after the synthetic Set-Cookie response. Four realm tests
continue to require Lax and therefore fail. PHP's actual Set-Cookie attributes are
independently asserted; no fallback to SameSite=None or weakening of CSRF was made.
WebKit reload assertions after the failed cookie check are not reached. Real Safari
HTTPS cookie dispatch, SameSite and popup/ITP behavior remain **unverified**.

The router requires PHP CLI development-server mode, an explicit synthetic-only flag,
a loopback peer and the exact disposable runtime directory. Its control endpoint
requires a generated local secret. A plain `php prototypes/session-security/router.php`
returns `prototype_disabled`. These are experiment safeguards, not a deployable endpoint.

## Storage inventory

Both measured engines expose only **`msal.interaction.status`** in sessionStorage
while the popup is held open. Installed SDK source stores client/interaction metadata
there. No persisted nonce, PKCE verifier or reusable bearer/ID/refresh token was
observed in these exercised popup paths. After success, sessionStorage key inventory
is empty for all four synthetic realms; cancellation/error/timeout/logout checks
verify transaction cleanup. A harmless local theme preference survives cleanup.

This narrows the original review's temporary-cache concern; it does not establish that
every SDK feature/redirect/native-broker path has the same inventory. Redirect-based
production clients still persist credentials and have not been converted. D3 needs a
reviewer classification of the observed flag and approved flow/storage boundaries,
not a blanket exception for tokens or PHI. The suggested 14-day pilot exception in the
original proposal was not automatically approved or started by this experiment.

[Microsoft caching guidance](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/caching)
distinguishes durable memory storage from temporary sessionStorage and does not
support redirect authentication with a memory-only durable cache. The harness uses
the [supported popup flow](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/login-user)
and the installed SDK bridge, without a deprecated temporary-cache override.

The four-second bridge timeout deliberately bounds synthetic tests. It is unsuitable
as an assumed production MFA enrollment limit. Closing/blocked popups, denied consent,
bad state/nonce and token failure are explicitly exercised. Interrupted-browser
crashes cannot guarantee SDK cleanup; next initialization and the one-use, ten-minute
server challenge bound must be part of the eventual real flow's lifecycle review.

## Validation and initial failure context

The final full run contains **88 cases: 83 passed, 5 failed, no skips**, exit 1,
117.8 seconds. **Edge: 44/44 passed. WebKit: 39/44 passed.** Four WebKit failures are
the strict SameSite mismatch described above. The fifth is popup cancellation: the
page remained locked with “Sign in required” instead of reaching the expected failure/
retry status within five seconds. No private dashboard was exposed. This is unresolved
popup recovery behavior, not a passed compatibility gate. The prior local run's
server-revocation/cleanup and route-disposal races were fixed and passed this run.
The cancellation case subsequently passed three isolated repetitions (32.3s, exit 0)
with unchanged code/assertions. Its inconsistent full-run result is retained; the
targeted pass does not establish reliable Safari recovery or replace the failed matrix.

| Exact command / directory | Result |
| --- | --- |
| `npm run typecheck:session-prototype` — Frontend | Exit 0; dedicated strict TS configuration. |
| `npm run lint` — Frontend | Exit 0, zero warnings; prototype browser globals added without weakening rules. |
| `npm run test:session-prototype` — Frontend | **83 passed, 5 failed**, exit 1, 117.8s, as above. Dedicated config, one worker, no traces; preserved full report `.tmp/session-prototype-final-report.json`, log `.tmp/session-prototype-verified.log`. Later focused runs use the configured report filename. |
| `npx playwright test --config playwright.session-prototype.config.ts --project=webkit --grep 'cancelled popup' --repeat-each=3 --output=test-results/session-prototype-cancel-retest` — Frontend | 3 passed, exit 0, 32.3s; log `.tmp/session-prototype-cancel-retest.log`. No test/source change or extended timeout; full-run failure remains unresolved. |
| `npm run build` — Frontend | Exit 0: lint, 4 content entries, 2,568 bilingual UI entries, TypeScript and public/portal/central production builds. Log `.tmp/session-prototype-frontend-build.log`. Existing >500 kB chunk warnings retained; no limit changed. |
| `npx playwright test --config playwright.build.config.ts` — Frontend | 15 passed, exit 0, 20.5s; log `.tmp/session-prototype-build-browser.log`. |
| `npm test` — Frontend | **289 passed, 2 failed, 2 skipped**, exit 1, 8.9m. Both failures displayed the existing safe startup-error page before their assertions: customer invitation-fragment cleanup and practitioner-enquiry status denial. Log `.tmp/session-prototype-default-browser.log`; initial failure traces retained locally. |
| `npx playwright test tests/customer.spec.ts tests/portal.spec.ts --grep 'invitation fragment is removed\|practitioner enquiry status denial' --workers=1 --output=test-results/session-baseline-retest` — Frontend | Both passed, exit 0, 13.6s. Cause of initial startup failures remains unresolved; an isolated pass does not make the broad run green. Log `.tmp/session-prototype-baseline-retest.log`. |
| `npx playwright test --config playwright.application-admin.config.ts` — Frontend | 46 passed, exit 0, 1.5m; log `.tmp/session-prototype-admin-browser.log`. |
| `npm run lint`; `npm run build`; `npm run test:browser` — Wellnexa | Each exit 0; 24 browser cases passed, 38.2s. Four existing lint warnings: two module-level mutation warnings in App.tsx and two component-export warnings in catalogue/Details.tsx. No suppressions or website changes. |
| `php api/tests/customer-auth.php` | 25 auth + 1 customer/staff + 6 proof checks passed, exit 0. |
| `php api/tests/external-staff-auth.php` | 67 checks passed, exit 0. |
| `php api/tests/customer-onboarding.php` | 25 checks passed, exit 0. |
| `php api/tests/identity-membership.php` | 29 identity/membership + 3 external-permission checks passed, exit 0. |
| PHP existing total | 156 synthetic checks; process OPENSSL_CONF temporarily set to `C:/Program Files/Git/usr/ssl/openssl.cnf` and restored. No hosted DB/provider request. |
| `php -l prototypes/session-security/SessionFixture.php`; `php -l prototypes/session-security/router.php` | Both exit 0. Plain CLI router guard also returned only `prototype_disabled`. |
| `composer validate --strict`; `composer --no-plugins --no-scripts audit --format=json` — api | Both exit 0; no advisories/abandoned packages; saved ignored JSON. |
| `npm audit --json`; `npm audit --omit=dev --json` — Frontend | Full exit 1: 11 moderate development lint/minimatch-chain findings, no high/critical. Runtime exit 0: zero advisories. Existing [quality-gate](FRONTEND_QUALITY_GATES.md) follow-up remains open; no forced fix/update. |
| `npx playwright install firefox webkit` — Frontend | Exit 0, existing locked Playwright browser binaries only. Firefox 155.0/revision 1543 then failed to launch with `spawn UNKNOWN`, independently reproduced. No browser security override or toolchain upgrade attempted. Firefox is not in the retained runnable matrix and is explicitly unverified. |
| Artifact/source separation | 102 files scanned across Frontend/dist and Wellnexa/dist: no prototype marker, fake identity hostname, prototype route or cookie prefix. `git diff 2858e2d -- Frontend/src api/src Wellnexa/src Frontend/package-lock.json api/composer.lock Wellnexa/package-lock.json` is empty. These local builds are not deployment packages. |

The matrix covers all four abstract realms, simulated Google/Microsoft identities,
real SDK PKCE and refresh, memory persistence/reload, cancellation/blocked/denied/bad
state/bad nonce/token failure/timeout, wrong bearer claims/signature/account/host/realm,
cookie-only/bearer-only denial, origin/CSRF rejection, one-use proof replay/freshness,
idle/absolute bounds, explicit activity, revocation, interrupted logout, stale successful
responses, logout during sign-in, cross-tab logout/account switch, malformed/unavailable/
throttled responses, corrupt store, six competing proof submissions, challenge limits,
French keyboard/focus and a 320px viewport. Concurrent proof requests test this fixture,
not a multi-process database or in-flight clinical transaction.

Initial failures were retained while corrected: unsupported obsolete MSAL timeout
options and missing Vite ambient types; a PHP input-read offset used instead of a
length; an outdated first-tab CSRF after second-tab session rotation; async cleanup
overlapping retry; and route teardown racing in-flight cleanup. Guards now lock the
login button during cleanup, check operation generations, refresh current logout proof
without restoring UI and await cleanup before fixture teardown. WebKit cannot fulfill
302 through this interception API, so the fake IdP uses an HTML navigation; the real
SDK callback bridge and PKCE remain exercised. The initial Firefox/WebKit expansion
was interrupted after deterministic transport failures. No failed assertions were
removed, timeouts extended to hide a product failure, production auth changed, or
SameSite checks weakened. Final failures/omissions remain explicit above.

An initial route-disposal diagnostic included generated synthetic request-header
values in ignored logs. Transport failures now produce classified messages and fixture
teardown awaits application cleanup. Those development logs remain private synthetic
evidence; do not share them as sanitized operational logs. No real credentials or
private browser session were involved. Memory storage/HttpOnly cookies also do not
prevent active-page XSS from making authorized requests; CSP/injection review and
production authorization/transaction checks remain necessary.

No clean `npm ci`/Composer restore, database integration/migration/restore, real provider
sign-in/MFA/logout, lost-device exercise, real HTTP TLS test, private browser inspection,
full assistive-technology/theme audit or mobile-device test was performed. Dependencies
were already installed; this is not a reproducible release environment. Dedicated
invitation and practitioner-application configurations were not rerun; their production
sources were unchanged. Default Playwright excludes those suites and this prototype.
Two baseline skips require optional local fixtures/features; no skips were added.
Color-environment warnings are incidental; bundle/lint/audit findings remain recorded.

## Versions, files, data flows and rollback

Node 22.22.1; npm 11.19.0; PHP CLI 8.4.14 (declared PHP ^8.2);
MSAL browser 5.19.0 / React adapter 5.6.0; frontend React 19.2.8, TypeScript 5.7.3,
Playwright 1.63.0, Vite 6.4.5, ESLint 10.13.0; Firebase PHP-JWT 7.1.0.
Measured Edge engine 157.0.4341.30 and Playwright WebKit 26.6. Wellnexa build used
Vite 8.3.4. No dependency/lock, permission, consent, runtime or supported-toolchain change.
Downloading the existing Playwright test browsers does not add application dependencies.

Unchanged SHA-256 lock hashes:

- Frontend/package-lock.json: `cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c`.
- api/composer.lock: `16fab4fc79b83afd34d4200474204bfc55cd7fe40d650a0c39d848dc4f93265f`.
- Wellnexa/package-lock.json: `17b1d75bce7d40a857dfd16c319f32a116b905e799f339c4443446da3a571f1e`.

Every changed file and purpose (paths relative to repository):

| File | Purpose |
| --- | --- |
| Frontend/eslint.config.mjs | Declare browser globals for isolated prototype; keep strict rules. |
| Frontend/package.json | Two explicit prototype test/typecheck commands only. |
| Frontend/playwright.session-prototype.config.ts | Dedicated local servers, synthetic setup, browser matrix and redacted reporting. |
| Frontend/tsconfig.session-prototype.json | Strict prototype/fixture typecheck independent of production compilation. |
| Frontend/vite.session-prototype.config.ts | Loopback-only synthetic development server, separate cache, no private env loading. |
| Frontend/prototypes/session-security/index.html | Accessible native controls and hidden synthetic dashboard, no real data. |
| Frontend/prototypes/session-security/main.ts | Actual MSAL memory/popup/session lifecycle experiment; bilingual statuses/cleanup. |
| Frontend/prototypes/session-security/popup.html | Dedicated synthetic popup return entry. |
| Frontend/prototypes/session-security/popup.ts | Supported SDK redirect bridge with fake-host guard. |
| Frontend/prototypes/session-security/protocol.ts | Synthetic realm/host constants, lease validation, key-name-only inventory. |
| Frontend/tests/helpers/sessionPrototype.ts | Fake signed IdP/PKCE verification and bounded loopback transport; explicit WebKit cookie limitation. |
| Frontend/tests/helpers/sessionPrototypeSetup.ts | Generate private disposable signing/control keys and initial state outside public roots. |
| Frontend/tests/helpers/sessionPrototypeTeardown.ts | Remove only the two named disposable private files. |
| Frontend/tests/session-security-prototype.spec.ts | Real SDK + PHP security/denial/lifecycle and negative regression matrix. |
| prototypes/session-security/SessionFixture.php | Disposable signed-proof/session/CSRF/expiry/store experiment. |
| prototypes/session-security/router.php | Local-only bounded, redacted HTTP fixture; no application API wiring. |
| prototypes/session-security/README.md | Safe reproduction and limits; explicitly no deployment. |
| documentation/SESSION_SECURITY_PROTOTYPE_2026-11-02.md | This source-bound results/decision/control report. |
| documentation/SESSION_SECURITY_REVIEW_2026-11-02.md | Preserve original review and link later local-only WP0 authorization/results. |
| documentation/README.md | Index the follow-up accurately. |

No real application endpoint or subprocessor/data-flow change. Only fake origins and
loopback fixture routes were added. No Graph permission, actual grant, private .env,
provider setting, hosted account, payment, clinical record, SQL schema, outbound message
or published artifact changed. Last hosted test-account cleanup is historical evidence
in [the reliability report](PRACTITIONER_UI_RELIABILITY_2026-11-02.md); this work does not
re-query or reactivate those accounts. No real-PHI gate is signed by these tests.

Private generated keys and test output remain ignored. Windows mode 0600 alone is not
an ACL guarantee; use a private synthetic workspace. Normal teardown deletes private.pem
and control-secret; a crash needs named-file cleanup per the harness README. Safe
preparation/diagnostics do not authorize deploying this harness. Rollback is a reviewed
revert of its commits and removal of its named disposable files; no production database
restore, legacy-auth fallback, branch reset or hosted change is involved.

## Outstanding decisions and owners

1. Security + identity + product: approve D1 architecture, memory-loss/reload UX, popup
   lifecycle and D2 timing. Resolve actual-browser/provider feasibility before WP1.
2. Identity administrator + security: verify real workforce/External ID claim shapes,
   auth_time freshness, MFA/privileged assurance, Google and personal Microsoft flows,
   iframe/popup constraints and provider logout. This harness deliberately cannot prove them.
3. Security + privacy: classify the exact observed temporary SDK flag; assess all real
   handoffs (invitations, actions, booking/location/PHI) and D3 compensations. Current
   production Web Storage and historical audit gaps remain open.
4. Engineering + operations: real same-origin PHP routes, production session/challenge
   tables and atomic membership/revocation/audit integration, five-minute sensitive-action
   reauthentication, limits/cleanup/indexes, shared-device/sleep/offline handling and
   fail-closed rollback. None is implemented in the application by this experiment.
5. QA + operations: trusted HTTPS Edge/Chrome/Firefox/Safari/mobile acceptance, restore/
   race/load/cache/header tests and diagnosis of baseline startup failures. Keep strict
   SameSite checks until the actual HTTPS behavior is demonstrated.
6. Custodian/privacy, security, clinical and release owners: sign the applicable 38-control
   evidence, endpoints/grants, exact artifact/configuration/schema and release decision.
   Named sign-offs remain unverified; production rollout needs its own concrete approval.

## All 38 controls — prototype scope ledger

Date/revision/reviewer as above. Implemented means only the local verified scope.
Owner roles are outstanding assignments, not invented organizational approvals.
The historical [audit](COMPLIANCE_AUDIT_2026-10-29.md) and source review remain intact.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/operator roles and contracts unchanged; custodian name accountable tenant/data owners. |
| GOV-02 | Unverified | No legal/commencement reassessment; privacy/legal maintain dated applicability register before release. |
| GOV-03 | Unverified | No PIPEDA determination changed; privacy/legal assess actual commercial/cross-border flows. |
| GOV-04 | Partial | Administrative synthetic identity/session experiment only; product/clinical confirm real intended use. |
| GOV-05 | Partial | Synthetic identities, no real database/PHI; security/privacy incorporate actual session flows into PIA/TRA. |
| PRIV-01 | Unverified | Clinic notices/contacts unchanged; custodian verify actual bilingual practices. |
| PRIV-02 | Partial | No real identity/consent collected; privacy assess necessary production session/audit metadata and authority. |
| PRIV-03 | Partial | Local expiry/revocation/UI and replay tests; security/custodian still need production transaction/worker/current-authority evidence. |
| PRIV-04 | Unverified | Representative/minor authority unchanged; custodian/clinical retain assisted-process boundaries. |
| PRIV-05 | Unverified | Rights process unchanged; privacy operations retain access/correction evidence. |
| PRIV-06 | Unverified | Only disposable synthetic secrets removed; records/privacy approve actual record/session/audit/backup schedules and holds. |
| PRIV-07 | Partial | Local revocation behavior modeled; incident/privacy lead rehearse real breach/lost-device response. |
| AUTH-01 | Partial | Actual SDK and independent PHP JWT signature/claim/proof tests; identity/security verify deployed realm claims/policies. |
| AUTH-02 | Partial | Negative host/realm/account/session checks pass in modeled scope; no production role or clinical relationship integration. Security own implementation. |
| AUTH-03 | Partial | Local idle/absolute/logout/revocation implemented; production limits, recent auth, MFA and lost-device operations remain missing/unverified. |
| AUTH-04 | Partial | No durable tokens in measured popup storage; temporary flag inventoried. Existing production persistence unchanged; security classify flows and approve design. |
| AUTH-05 | Partial | No grants broadened; identity/operations still need exact deployed permission/runtime-principal inventory. |
| SEC-01 | Partial | Disposable keys/output ignored, diagnostics classified, source/artifact scan scoped below; security retain full history/artifact exposure assessment. |
| SEC-02 | Partial | Loopback-only modeling, no TLS bypass; real transport, DB/backups/encryption/key custody unverified. Operations own acceptance. |
| SEC-03 | Partial | Origin/CSRF/no-store and Edge cookie checks; WebKit SameSite strict failures, no real TLS/CSP/HSTS acceptance. Operations/security resolve. |
| SEC-04 | Partial | Typed guards, bounded JSON, independent signed proof and safe UI text; security review production parser/SQL integration separately. |
| SEC-05 | Partial | Body/token/store caps, timeout, malformed/unavailable/throttled/replay tests; no representative-volume/multi-process SQL abuse proof. Engineering own limits. |
| SEC-06 | Partial | Bounded redacted fixture events only; production actor/time/correlation/denial audit, transaction atomicity and independent retention not implemented. Security/DB own. |
| SEC-07 | Partial | Safe UI classifications, no traces, bounded synthetic diagnostics and transport-error redaction; operations still need actual proxy/provider/log redaction evidence. |
| SEC-08 | Partial | No new actual external data flow; fake/loopback inventory above. Privacy/vendor owner approve actual session/MFA/provider flows before release. |
| SEC-09 | Not applicable | No new mail/SMS/calendar dispatch; synthetic session experiment cannot close existing communications controls. |
| DEV-01 | Implemented (local scope) | Dedicated branch, instructions/configs inspected, unfinished PDF preserved; completed work committed/pushed, no merge/deploy inferred. |
| DEV-02 | Partial | Focused isolated code, strict typing/lint, safe error handling; real review/sign-off and known browser failures remain. No assertions weakened. |
| DEV-03 | Partial | Locks/dependencies unchanged, runtime/Composer audits clean; 11 development advisories and clean-release restore/licence/maintenance evidence remain. |
| DEV-04 | Partial | Strict checks/builds and extensive dedicated/regression tests; failing WebKit/broad-run evidence and omitted live/SQL/config suites explicit. QA own acceptance. |
| DEV-05 | Partial | Bilingual native controls, keyboard/status focus and narrow width tested; full screen-reader/contrast/theme/zoom/mobile/AODA assessment unverified. Accessibility/product own. |
| DEV-06 | Partial | Explicit activity, timer invalidation, bounded stores/routes and async cleanup modeled; production SQL indexes/load/background/browser throttling still unverified. |
| REL-01 | Partial | Dated source-bound 38-row ledger; release owner obtain named decisions/signatures and complete missing evidence. |
| REL-02 | Partial | Builds, browser, PHP and HTTP evidence recorded including failures; no SQL change/suite, no hosted identity or reproducible release restore. |
| REL-03 | Unverified | Production artifacts exclude harness; no deployment package/hosted session acceptance or restore. Release/operations own next gate. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; successful local cases do not waive missing controls/failing acceptance. |
| REL-05 | Implemented (authorized experiment only) | User authorized local WP0; production, auth architecture integration, grants/schema/flows unchanged. Exact later changes require concrete approval. |
| REL-06 | Implemented (report scope) | Scope/files/versions/flows, commands/counts, failures, omissions, ownership and rollback recorded; source/hash/scan completion below. |

Final repository verification: `node .tmp/validate-session-prototype.mjs` checked
4 maintained documents, 105 relative links, 38 distinct control rows and all 20
changed files. A bounded private-key/JWT signature scan found zero matches in the
changed files; no new private env/dump/ZIP/dependency/build files are committed.
Production source/lock comparison is empty. `git diff --cached --check` passes.
Normal LF-to-CRLF notices are recorded, not whitespace failures. The preserved
full report SHA-256 is
`a0313587071f7dc30198070fc462d956b6a2512384772f4b19978583fdd11b62`.
Both disposable private files were confirmed absent after normal teardown.
The branch is delivered for review; no merge, production package or deployment.

RELEASE STATUS: NOT READY FOR RELEASE
