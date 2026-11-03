# Server-session core — local implementation and evidence

**Later local integration update - 3 November 2026:** [existing-route and browser integration](SESSION_SERVER_INTEGRATION_2026-11-03.md) records the default-off gateway, browser memory mode, request transactions, actual-route synthetic tests and remaining release gates. Production remains unchanged; earlier results below describe their own revisions.

3 November 2026. **Production unchanged. NOT READY FOR RELEASE.**
Baseline: `d82ac1a13daf593d48d41942049dbd062737e844`.
Implementation revision: `54e5d81bc267a625df9aee45e65263329816e2b2`, branch `feature/server-session-core`.
Reviewer: Codex; accountable human security/privacy/clinical/operations decisions remain
outstanding. Owner authorized local implementation, thorough synthetic testing and
documentation, explicitly keeping production unchanged. No hosted migration, deployment,
account change, cloud test, merge or broader permission was performed.

## Delivered boundary

This implements the backend core and an **unregistered endpoint adapter, disabled by
default**. It does not switch existing portals to cookie sessions. There is no new
environment flag that can accidentally activate a partial migration. `Api.php`, `Config.php`,
the identity-provider registrations and browser identity/storage behavior are unchanged.
The [implementation proposal](SESSION_SERVER_CORE_IMPLEMENTATION_PLAN.md) remains the
roadmap; existing route/business-service adoption and the browser work remain necessary.

| Plan slice | Current result |
| --- | --- |
| A: policy/proof | Four realm policies, exact signed API and SPA proof contracts, stable oid/tid proof binding, preserved customer API issuer/sub hash; generated-key unit evidence. A required injected key loader must use the approved realm transport in future composition; no new provider transport was activated. |
| B: store/schema | Migration 059, four new tables, nullable metadata on existing customer_sessions, customer adapter, transactional epochs/challenges/action proofs/audit, two database engines and real competing transactions. |
| C: service/transport | Host/realm/principal binding, 15/30-minute idle and 8-hour absolute policy, explicit activity, own logout, rotation, one-use recent proof for own revoke-all, strict cookies/origin/CSRF, body limits and real loopback HTTP tests. |
| D: existing application integration | Partial: explicit disabled endpoint composition, future-route body limits and legacy downgrade prevention. No live Api.php route registration, production authority callback, complete business-route guard adoption or membership/pilot/administrator mutation wiring. Existing transactions and response auditing must be adapted before this can be enabled. |
| E: browser work | Separate follow-up: memory/popup integration, cookie/CSRF handoff, private UI cleanup, warnings, reload/new-tab behavior, bilingual/accessibility acceptance and real browsers/providers. |

The core requires both a valid API bearer and the bound cookie for protected work.
It never turns a cookie, ID proof, email address or pilot approval into a membership.
The required server callback resolves **current** clinic, identity, membership, explicit
authority and restrictions using the supplied PDO transaction. The synthetic callback
used in tests is not a production membership adapter or clinical-access implementation.

## Implemented contract and transaction rules

- API tokens require exact issuer, tenant, v2 audience, approved SPA azp and delegated
  scope, signature/algorithm and lifetime. ID proofs require their exact SPA audience;
  omitted azp is allowed, but any present invalid/mismatched value is rejected.
  Stable verified oid/tid bind pairwise subjects; customer canonical identities remain
  SHA-256 of the verified API issuer/sub JSON tuple. No email-based account merging.
- A 10-minute, one-use challenge is bound to a separate host-only pre-auth cookie and
  nonce. Bootstrap consumes it and records the session and required audit atomically.
  A failed operation returns no grant; HTTP cookie headers are built only after commit.
  A lost response is uncertain: replay is denied and a new sign-in is required.
- Session, correlation and action secrets are 32 random bytes; SQL stores their digests.
  No JWT, refresh token, cookie secret, full claims, email, location/IP fingerprint,
  clinical body or role cache is added to the session tables. The grant/response objects
  deliberately exclude cookies from JSON and debug output.
- Policy version, host/realm/clinic/principal, epoch, idle and absolute deadlines must
  match. `me` and silent token renewal do not slide time. Only the explicit activity
  operation slides idle; the absolute limit derives from fresh authentication time.
  API expiry/deadlines are rechecked after authority lookup and before commit. Expiry
  during a long effect rolls back its writes.
  Policies use canonical DNS hosts with HTTPS origins and no explicit port. Local
  HTTP fixtures supply virtual host headers; actual same-origin development/hosting
  still needs a reviewed trusted-HTTPS configuration.
- The epoch row is locked first, then the relevant session/challenge/proof and current
  authority, through effect, audit and commit. Same-principal operations serialize at
  the epoch. No automatic deadlock, payment or clinical-effect retry is introduced.
  The business callback must use the supplied transaction and must not commit, start
  another transaction, send externally or emit a response. External side effects need
  their existing separately reviewed dispatch boundary.
- Required success audit uses `access_audit_events` in that same transaction. A denial
  after rejected work is audited in a separate transaction after rollback; failed
  required audit fails closed. The helper writes only scope/principal fingerprints,
  actor/realm, fixed action/reason, status and correlation. No IP/body/token is added.
  This is core-operation auditing, not full API disclosure/audit integration.
- Cookies use `__Host-`, Secure, HttpOnly, Path=/, SameSite=Lax and no Domain or persistent
  lifetime. Mutation transport requires the exact configured HTTPS Origin/Host and a
  session CSRF value. Duplicate/malformed/oversized cookies and body-selected clinic
  IDs are rejected. Forwarded-host headers supply no authority. New session-family
  request bodies are limited to 65,536 actual bytes by `Request::capture`.
- Same-browser sign-in rotates the previous same-principal cookie; another tab can
  fetch current CSRF using the current cookie and matching bearer. Other devices are
  not implicitly logged out by ordinary bootstrap. Own logout works after token/session
  expiry with the cookie plus exact origin/CSRF, without reading records.
- Own revoke-all requires a new, single-use proof bound to the current session,
  principal target and epoch. Its authentication freshness and lifetime are capped at
  five minutes. Concurrent consumers cannot both commit. No remote-user administrator
  revocation privilege or route was introduced.
  Revoke-all applies to the current host/realm/clinic scope. Other clinic/realm and
  identity-provider sessions retain their separate policies.
- Anonymous login challenges have a persisted 20-per-10-minute budget keyed by a
  server-derived source digest and scope. Production edge throttling, authenticated
  operation limits, cleanup/retention and connection lock-wait settings still need
  integration/operations evidence; the core is not a complete abuse-control program.

### Legacy downgrade finding and fix

Review found that the older customer validator would ignore the new policy/epoch
metadata. A red regression demonstrated that a still-timed session with policy_version=1
was accepted by that validator. `CustomerOnboarding::sessionIsActive` now accepts only
legacy rows with no policy version. Its legacy logout also rejects versioned rows.
Actual SQL tests exercise both legacy entry points against new client sessions.

Existing unversioned rows retain their prior behavior before and after migration 059.
The logout query now reads its single session row to inspect optional metadata without
requiring column 059 on an older database; this broader internal row contains session
metadata/digests and is never returned or logged. Client sessions remain in the existing
table, not copied into `browser_sessions`.

## Validation and retained failures

All SQL servers are freshly initialized loopback instances with randomly named synthetic
databases. Neither suite reads .env nor attaches to an occupied port. Parent/worker JWTs
travel in private process pipes, not command arguments/files/output. The HTTP fixture
is restricted to CLI-server, loopback, an explicit synthetic-only environment and a
per-run control secret. All processes are terminated in finally blocks; retained SQL
data/logs are private disposable evidence, not backups or deployment fixtures.

| Command / directory | Result |
| --- | --- |
| `php api/tests/browser-session-proof.php` | 197 signed positive/negative checks passed, exit 0; four realms, omitted/present azp, wrong audiences/callers/issuer/tenant/account/nonce/freshness, malformed numeric dates, missing fields, scopes, algorithm/key confusion, rotation and provider outage. |
| `php api/tests/browser-session-http.php` | 61 cookie/transport policy and legacy downgrade checks passed, exit 0. |
| `php api/tests/customer-auth.php` | 32 checks passed, exit 0. |
| `php api/tests/external-staff-auth.php` | 67 checks passed, exit 0. |
| `php api/tests/customer-onboarding.php` | 25 checks passed, exit 0. |
| `php api/tests/identity-membership.php` | 32 checks passed, exit 0. |
| `php api/tests/pilot-policy.php` | 80 checks passed, exit 0. |
| `php api/tests/request-body-limits.php` | 55 real-input HTTP checks passed, exit 0. |
| PHP policy total / syntax | 549 checks; all 19 changed PHP source/test files pass `php -l`. OPENSSL_CONF set process-only to `C:/Program Files/Git/usr/ssl/openssl.cnf`, then restored. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13401 -IntegrationTest browser-sessions.php` | 188 checks passed on MariaDB 11.4.8, exit 0; `.tmp/server-session-maria-final-reviewed.log`. Includes three repetitions of each race, all four endpoint realms, purpose binding and anonymous denial audit. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13402 -IntegrationTest browser-sessions.php` | 188 checks passed on MySQL 5.7.44, exit 0; `.tmp/server-session-mysql-final-reviewed.log`. |
| `composer install --no-interaction`; `composer validate --strict`; `composer --no-plugins --no-scripts audit --format=json` — api | Each exit 0. Lock install required no package changes; zero advisories/abandoned packages. This existing-workspace restore is not a clean release build. |
| `npm run build` — Frontend | Exit 0: lint, 4 bilingual content entries, 2,568 translations, strict TS, public/portal/central builds. Existing >500kB chunk warnings retained. |
| `npx playwright test --config playwright.build.config.ts` — Frontend | 15 passed, exit 0, 24.6s. |
| `npm test` — Frontend | 289 passed / 2 skipped / 2 failed, exit 1, 10.9m. Form-import button startup timeout and appointment client-search debounce emitted an intermediate query. Both surfaces and fixtures are unchanged; original failures/traces retained. Follow-up below does not turn this full run green. |
| `npx playwright test --config playwright.application-admin.config.ts` — Frontend | 46 passed, exit 0, 1.5m. |
| `npx playwright test --config playwright.config.ts --grep 'JSON file imports as a private draft\|appointment client finder filters' --workers=1 --repeat-each=3 --output test-results/session-core-focused` — Frontend | 6 passed, exit 0, 38.5s. Both failed cases passed three focused repetitions with unchanged code/assertions. This supports an intermittent timing classification, not a clean full-suite checkpoint. |
| `npx playwright test --config playwright.invitations.config.ts` — Frontend | 7 passed, exit 0, 26.7s. |
| `npx playwright test --config playwright.applications.config.ts` — Frontend | 22 passed, exit 0, 48.3s. |
| `npm run lint`; `npm run build`; `npm run test:browser` — Wellnexa | Each exit 0; 24 passed, 37.4s. Four existing lint warnings: App.tsx module mutations (2), catalogue Details.tsx fast-refresh exports (2). |
| `npm audit --json`; `npm audit --omit=dev --json` — Frontend | Full exit 1: 11 existing moderate dev lint/minimatch-chain findings; no high/critical. Runtime exit 0: zero advisories. |
| Same two audit commands — Wellnexa | Each exit 0, zero advisories. No forced fixes or dependency changes. |

The SQL suites include exact idle/absolute/challenge/action boundaries, current-authority
denial, same/different-principal cookie rotation, CSRF, policy version, cross-host/clinic
denial, action replay/cross-session use, audit outage with session/challenge/effect
rollback, expiry during authority/effect, budget exhaustion, default-off composition,
legacy rejection and migration rerun preservation. Real HTTP covers headers, framing,
byte limits, malformed JSON, cookie pollution, hostile origin/host, bearer-only bypass,
replay, membership loss and own logout. Its manual loopback HTTP cookie transport does
not verify real Secure-cookie delivery or trusted HTTPS in a browser.

The two default-suite skips are the optional private priced-CSV and Services-Library
workbook import acceptances (`SERVICE_IMPORT_CSV` / `SERVICE_IMPORT_WORKBOOK` are unset).
No private import file was inspected. Other synthetic import cases ran. QA still owns
the two intermittent broad-run failures; focused repetitions do not close that gate.

Each database runs the competing-transaction scenarios **three times**: effect before
departure; departure before effect; duplicate bootstrap; duplicate revoke-all proof.
Both ordering cases must observe a real InnoDB lock wait, and a waiting stale effect
must not run or commit after departure. These prove the core plus synthetic authority
callback, not the existing clinic mutation services, which do not yet take these locks.

Initial failures remain recorded:

1. The expanded HTTP fixture duplicated Cache-Control because its generic header loop
   appended the already emitted header. The strict header assertion failed. The fixture
   now replaces ordinary headers and appends only Set-Cookie; the assertion remains.
2. A 20ms lock-table poll sometimes pinned an empty metadata snapshot, producing failed
   lock-observation assertions and a classified worker timeout. Merely increasing the
   window did not resolve it. The [MySQL 5.7 documentation](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/innodb-information-schema-internal-data.html)
   explains that refresh requires more than 0.1s since the previous read. The observer
   now leaves 200ms between reads. The strict positive lock observation remains, with
   bounded 8s observation/15s test-worker lock waits, and repeated dual-engine acceptance.
   Initial logs: server-session-maria-races, maria-final, mysql-reviewed under `.tmp`.
3. The legacy downgrade check failed before its source fix (`.tmp/server-session-legacy-red.log`).
   It now passes in the policy and actual SQL suites. No original failure was skipped.

## Existing integration boundaries still to complete

| Existing entry / authority change | Required integration before activation |
| --- | --- |
| `Api::user`, `StaffMembershipResolver`, Entra/external staff verification | Server-resolve exact host/realm, verify current issuer/caller/scope and local membership, add the session guard to every protected read/write, preserve pilot and consent locks. |
| Early practitioner application branches before the dispatcher | Separate identity-only application authority from practitioner workspace authority; neither a signed-in identity nor a pilot approval grants clinical membership. |
| `Api::applicationRoute`, ApplicationAdminAuthenticator and central services | Current explicit administrator grant, central host policy, recent-action boundaries; no clinic-record authority implied. |
| `Api::customerMe` / `customerRoute`, customer identity/link/consent services | Adapt the existing `/customer/auth/*` family and remove its header credential path for activated hosts; never run a cookie clock alongside legacy admission for the same session. |
| Admin/global practitioner membership/departure, pilot approval, central grant and clinic-state mutations | Acquire the same epoch-before-authority locks as protected effects; advance epochs and required audit atomically. The new store primitive alone does not change these existing writers. |
| Business service transactions, ConsentLock, PractitionerPilotLock and AccessAudit response callbacks | Inventory full lock order and adapt transaction ownership. Existing services that start their own PDO transaction cannot simply be invoked inside `protect`. Coordinate response audit and pending cookie headers; retain existing record/consent/disclosure checks. |

The adapter currently describes staff/practitioner/application session families and the
client auth family; **none is newly registered in Api.php**. The client adapter retains
its existing `auth/session` bootstrap contract (the internal service method is `start`)
and rejects a competing `auth/start` URL; all four realm metadata paths are tested.
A complete endpoint-by-endpoint guard and permission inventory, real signing
key loader composition, edge/body/header/rate policy, connection timeouts, bounded cleanup,
runtime DB grants and concrete browser callers remain integration acceptance work.

No private setting, permission, consent or scope was broadened. There is no Graph call,
provider registration edit, patient record, mail/SMS/payment, or new external application
data flow. BrowserStack remains deferred. Actual providers, effective MFA, real Safari/
macOS/iOS, browser TLS dispatch, hosted migrations/backups/restore, clinical authority,
representative/minor flows, accessibility/volume/load and a clean exact-lock release
installation are unverified. The previous four strict Windows WebKit prototype SameSite
failures remain open; that unchanged isolated matrix was not rerun as hosted acceptance.

## Files, versions, provenance and rollback

| Changed file | Purpose |
| --- | --- |
| api/src/Service/BrowserSessionPolicy.php | Trusted realm/host/clinic/identity policy, exact canonical hashes and timing. |
| api/src/Auth/LoginProofVerifier.php | Existing Firebase JWT/JWK verification with exact API/proof contracts and fresh principal binding. |
| api/src/Service/BrowserSessionStore.php | Single-PDO transactions, epochs, indexed session/challenge operations, persisted budgets and redacted required audit. |
| api/src/Service/CustomerSessionStore.php | Existing customer table adapter and unchanged issuer/sub identity hash. |
| api/src/Service/BrowserSessionService.php | Challenge/bootstrap, guard/activity/logout/rotation, action proof and own revoke-all lifecycle. |
| api/src/Service/BrowserSessionGrant.php | Internal cookie grant with safe JSON/debug serialization. |
| api/src/Http/SessionCookie.php | Strict cookie parsing, attributes and exact origin/host checks. |
| api/src/Http/BrowserSessionEndpoint.php | Disabled-by-default, unregistered transport adapter; no body-selected realm/clinic/privilege. |
| api/src/Http/BrowserSessionResponse.php | Keep cookie headers outside response JSON/debug output. |
| api/src/Http/Request.php | Bound the future session route bodies to 65,536 actual bytes. |
| api/src/Service/CustomerOnboarding.php | Prevent versioned sessions from downgrading through legacy validation/logout. |
| api/database/migrations/059_browser_sessions.sql | Four additive core tables and nullable legacy-table metadata; no hosted execution. |
| api/tests/browser-session-proof.php | Four-realm signed positive/negative/rotation/outage checks. |
| api/tests/browser-session-http.php | Cookie/origin/JSON credential exclusion and legacy downgrade regression. |
| api/tests/helpers/browser-session.php | Generated in-memory keys, synthetic policies/claims and classified assertions. |
| api/tests/helpers/browser-session-races.php | Real worker barriers, correctly spaced lock observation and commit/denial assertions. |
| api/tests/integration/browser-session-worker.php | Disposable competing PDO workers, input credentials over pipes and redacted status output. |
| api/tests/helpers/browser-session-router.php | Hard-gated loopback PHP fixture using the actual adapter/service/PDO. |
| api/tests/helpers/browser-session-network.php | Actual HTTP requests, cookie/header/body/replay/departure tests and process cleanup. |
| api/tests/integration/browser-sessions.php | Fresh synthetic schema/migration, four-realm lifecycle, rollback, budget, legacy, repeated race and HTTP acceptance. |
| scripts/test-recurring-sql.ps1 | Explicit browser-sessions suite allowlist entry; existing disposable MariaDB protections retained. |
| scripts/test-mysql57-sql.ps1 | Same allowlist entry for MySQL 5.7. |
| documentation/SESSION_SERVER_CORE_2026-11-03.md | This source/control/result and remaining-integration record. |
| documentation/SESSION_SERVER_CORE_IMPLEMENTATION_PLAN.md | Link current implementation status without claiming existing-route completion. |
| documentation/README.md | Index this delivery/evidence. |

Unchanged versions: Node 22.22.1 / npm 11.19.0, PHP CLI 8.4.14 (supported ^8.2), Composer 2.9.5,
Firebase PHP-JWT 7.1.0. Frontend: MSAL browser 5.19.0 / React 5.6.0, React 19.2.8,
TypeScript 5.7.3, Playwright 1.63.0, Vite 6.4.5, ESLint 10.13.0. Wellnexa: React
19.3.0, TypeScript 6.0.3, Playwright 1.64.1, Vite 8.3.4. Installed Edge: 157.0.4341.30.
No runtime/test dependency,
manifest, lockfile, supported toolchain, API grant or external endpoint change.

Migration 059 SHA-256: `755beb66cadb9ef8e1bc234fb166738d34fc818a2a1f1cf3961beef580b241e5`.
It requires 005/039/049 and is **apply-once**. A rerun stops at the existing table;
tests verify history remains unchanged. MySQL DDL is not a single rollback transaction.
Rehearse a fresh backup/restore, inspect actual migration state and recheck numbering
against other branches before any separately approved hosted application.

Local rollback is a reviewed source revert; production has no new data/settings to undo.
For a later activated deployment, close affected entry points first, invalidate sessions,
retain additive columns/tables and audit/history, and keep the legacy downgrade guard.
Do not drop policy metadata or restore a pre-core legacy validator while versioned rows
could be admitted through old transport. Do not restore an older database over newer
clinical data or fall back to bearer-only admission. After a restore, invalidate restored
sessions/epochs before reopening. Retention and record disposal need separate decisions.

## All 38 controls — dated local-core scope

Evidence refers to the source revision and commands above. Roles below require named
human appointment and sign-off; no organizational or legal compliance is inferred.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custody/operator/contracts unchanged; custodian identify named clinic/platform owners. |
| GOV-02 | Unverified | No legal commencement reassessment; privacy/legal maintain actual applicability decisions. |
| GOV-03 | Unverified | No new commercial flow; privacy/legal assess actual PIPEDA scope. |
| GOV-04 | Partial | Administrative identity/session core only; clinical/product retain intended-use assessment. |
| GOV-05 | Partial | Synthetic disposable databases/keys and minimized metadata; security/privacy update actual PIA/TRA before integration. |
| PRIV-01 | Unverified | Clinic notices/contact unchanged; custodian verify actual bilingual practices. |
| PRIV-02 | Partial | No patient/consent collection; metadata limited to session authority/digests. Privacy approve actual retention/purpose/integration. |
| PRIV-03 | Partial | Current synthetic authority, epoch and effect transaction tests; existing reads/workers/writers not wired to this core. Security/clinical validate each actual boundary. |
| PRIV-04 | Unverified | No new representative/minor authority; clinical/custodian retain scoped assisted process. |
| PRIV-05 | Unverified | Patient rights workflows unchanged; privacy operations retain actual evidence. |
| PRIV-06 | Partial | No record deletion/purge; migration preserves legacy history. Records/privacy approve class-specific session/audit/backup retention and holds. |
| PRIV-07 | Partial | Own logout/revoke-all/departure primitives tested; incident/privacy operations rehearse actual lost-device/breach procedures. |
| AUTH-01 | Partial | Signed four-realm proof/API contract and safe required-loader interface; actual realm composition/provider/MFA still unverified. |
| AUTH-02 | Partial | Scope/principal/host/epoch plus required authority callback; core negative and SQL races pass, existing clinical/role integration remains. |
| AUTH-03 | Partial | Core idle/absolute/activity/rotation/recent-action/revocation tested; production policies, privileged MFA, UI clearing and device flows incomplete. |
| AUTH-04 | Partial | Digest-only SQL, HttpOnly cookie contract and no cookie in JSON; existing browser caches unchanged, real Safari/CSRF/logout integration pending. |
| AUTH-05 | Partial | No grants broadened; actual provider, DB grants and endpoint/role inventory need security/operations evidence. |
| SEC-01 | Partial | Generated keys in memory, private pipes, ignored outputs and scoped scans below; full history/artifact exposure review remains security-owned. |
| SEC-02 | Partial | Existing transport not weakened, no TLS bypass; real HTTPS/DB encryption/backup/key custody unverified. Operations own. |
| SEC-03 | Partial | Cookie/origin/CSRF/no-store tested locally; actual host headers, browser dispatch, existing WebKit failures and server policy remain gates. |
| SEC-04 | Partial | Fixed prepared identifiers, strict signed claims/body/cookie input, no HTML; actual endpoint composition and hostile integration review pending. |
| SEC-05 | Partial | Bounded JWT/body/cookie, indexed lookups, persisted challenge budget and repeated races; full edge/rate/header/volume/timeouts/purge controls pending. |
| SEC-06 | Partial | Atomic core audit/effect rollback and separate denial persistence; existing AccessAudit/business transaction/privilege integration and immutable retention not complete. |
| SEC-07 | Partial | Classified errors/worker statuses, no credential diagnostics; operations verify real proxy/provider/error/support logs. |
| SEC-08 | Partial | No new provider call/host/service/application flow; privacy/vendor owners retain actual subprocessor register decisions. |
| SEC-09 | Not applicable | No new mail/SMS/calendar dispatch or payment transport. |
| DEV-01 | Implemented (local scope) | Dedicated feature branch, inspected configs/migrations/controls, preserved unrelated work; commit/push for review, no merge/deploy. |
| DEV-02 | Partial | Reviewed core, red/green downgrade regression, strict tests, retained observer/HTTP failures; human security review and production composition remain. |
| DEV-03 | Partial | Exact existing locks, Composer install/audits, no dependency addition; 11 dev advisories and clean-release/license/maintenance evidence remain. |
| DEV-04 | Partial | PHP/dual SQL/HTTP/build/browser checks recorded; original broad browser failures, skips and omitted hosted scenarios remain explicit. |
| DEV-05 | Partial | No new UI; English API errors need mapped bilingual UI in future callers. Accessibility, actual themes/Safari/assistive technology not certified. |
| DEV-06 | Partial | Indexed bounded core queries and transaction cleanup; four signature checks per guarded operation, real-volume DB/latency/polling analysis still required before rollout. |
| REL-01 | Partial | Source-bound ledger and remaining integration map; release owner obtain named decisions/evidence. |
| REL-02 | Partial | Broad local checkpoint and explicit failures; no hosted DB, real identities or clean release restore. |
| REL-03 | Unverified | No release package/deployment/hosted migration/restore acceptance. Exact future artifact review and rollback required. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE: integration, storage, identity, browser, operational and broad-check gaps remain. |
| REL-05 | Implemented (authorized local scope) | Local implementation/disposable migrations authorized; production, settings, accounts, permissions and cloud services unchanged. Future release approval separate. |
| REL-06 | Implemented (report scope) | Files/versions/source/commands/failures/control/rollback and scoped final checks recorded. Existing-route/browser/hosted integration is explicitly incomplete. |

Final static checks: `python .tmp/validate-server-session-core.py` validates the 25
documented files, 106 relative links, all 38 distinct controls, unchanged protected source/
configuration/manifests/locks, migration hash and stopped disposable database ports.
It scans 309 tracked application source/scripts plus changed files and all 102 current
Frontend/Wellnexa build files for private-key/literal-JWT signatures, and checks build
files for prototype/core-test fixture markers, with zero signature/fixture matches.
Both disposable ports (13401/13402) are closed. The migration's committed Git blob matches
the recorded SHA-256; all three lock hashes are unchanged. These bounded scans report classifications
only; they are not a full credential-history or deployable-package certification.
`git diff --check` and staged whitespace checks pass.

The source and this documentation are committed/pushed on the dedicated branch for
review. Main is unchanged; no merge, package, deployment or hosted SQL was performed.
Unrelated untracked documentation/blog-topics.pdf and documentation/REQUIRED_FOR_SITE.md
were preserved without inspection or staging.

RELEASE STATUS: NOT READY FOR RELEASE
