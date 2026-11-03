# Main integration checkpoint — 3 November 2026

Integration branch `feature/integrate-validated-main` starts at `759cfeb58c406072d5004505a100bf1cb0568258`, the reviewed development chain based on `main` commit `38ac97a1e41ee3acf6212ea2ba30f4c6cd4f2f7d`. The source tree through `759cfeb` contains the practitioner pilot/sign-in, browser-session work, commit-safe email and separate account-email scheduler preparation. It does not include the two owner-committed site documents on the later `feature/account-email-worker-scheduling` tip (`286ae20`): `documentation/REQUIRED_FOR_SITE.md` and `documentation/blog-topics.pdf`. The PDF is a local attachment excluded from this integration under repository policy. The independent blog-topic branches also remain outside this code integration; one is checked out in another worktree. No production deployment, schema operation, private configuration change, account change or message send occurred.

This checkpoint verifies a merge into the source `main` branch only. It does not make any default-off session, mail, pilot or scheduler setting active. The existing reports remain the per-file implementation and purpose records: [practitioner sign-in](WELLNEXA_PRACTITIONER_SIGN_IN_VALIDATION_2026-11-01.md), [pilot administration](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md), [session integration](SESSION_SERVER_INTEGRATION_2026-11-03.md), [commit-safe email](COMMIT_SAFE_EMAIL_2026-11-03.md), and [account-email scheduler](ACCOUNT_EMAIL_SCHEDULER_2026-11-03.md). The exact cumulative path list is reproducible with `git diff --name-status 38ac97a..759cfeb`. This integration task changes only this dated record; it does not rework product files or dependency locks.

## Exact local validation of the candidate

Runtimes: Node 22.22.1, npm 11.19.0, PHP 8.4.14, Composer 2.9.5; disposable MySQL 5.7.44. Browser runs used matched Playwright Chromium, installed Edge and isolated Firefox. The complete result belongs to the candidate tree; a later commit containing only this record does not change executable files.

| Command / scope | Result |
| --- | --- |
| `Frontend/npm run build` | Exit 0: ESLint, 4 bilingual content entries, 2,572 paired translations, strict TypeScript and public/portal/central production builds. Existing >500 kB chunk and mixed `AuthProvider` import warnings remain. |
| `Frontend/npm test -- --workers=2 --reporter=line`, with locked matching Chromium path | Exit 0: **298 passed, 2 existing optional private-fixture skips, 0 failed** across 300 cases. No assertion, timeout or skip was changed. The prior 297/2/1 run remains historical evidence of a guest-availability flake. |
| `Frontend/npx playwright test --config playwright.build.config.ts --reporter=line` | Exit 0, **15 passed**. |
| `Frontend/npx playwright test --config playwright.application-admin.config.ts --reporter=line` | Exit 0, **46 passed**. |
| `Frontend/npx playwright test --config playwright.invitations.config.ts --reporter=line` | Exit 0, **7 passed**. |
| `Frontend/npx playwright test --config playwright.applications.config.ts --reporter=line` | Exit 0, **22 passed**. |
| `Wellnexa/npm run lint`; `npm run build`; `npm run test:browser -- --reporter=line` | All exit 0; **24 browser cases passed**. Oxlint still reports 4 existing nonfatal warnings. |
| Each `api/tests/*.php` with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | **65 policy suites, 0 failed**. |
| `php -l` for all PHP in `api/src`, `api/bin`, `api/tests`, `api/deploy/netfirms/public/cron` | **286 files, 0 syntax failures**. |
| `./scripts/test-mysql57-sql.ps1 -IntegrationTest commit-safe-email.php -Port 13431` against an explicit portable MySQL 5.7 runtime | Exit 0; **184** disposable SQL/HTTP/account-email checks. |
| Same runner `-IntegrationTest server-session-integration.php -Port 13432` | Exit 0; **129** disposable SQL/HTTP checks without browsers. |
| Same runner `-IntegrationTest server-session-integration.php -Port 13433` with `RUN_SERVER_SESSION_BROWSER=true` and isolated Firefox path | Exit 0; **28/28 Edge/Firefox real-route browser cases and 130 session integration checks**. Both historically failing cases passed in this unchanged rerun; the earlier failures remain recorded in the session/email reports and are not erased. |
| `Frontend/npm audit --json`; `npm audit --omit=dev --json`; `Wellnexa/npm audit --json`; `api/composer validate --strict`; `composer audit --format=json` | Frontend full audit exit 1 with **11 moderate development-tree advisories**; frontend runtime, Wellnexa and Composer audits exit 0 with no critical/high or runtime findings. No forced fixes, dependency updates or exception approvals. |
| `git diff --check main..759cfeb`; changed-path inspection | Passed. The integration candidate adds placeholder `.env.example` files and versioned SQL migrations, but no new private `.env`, PDF, release ZIP, vendor directory or generated build output. |

The previously validated MariaDB 11.4.8 suites are recorded in the linked feature reports; they were not rerun for this merge. BrowserStack Safari remains unavailable under the owner's existing access decision. No approved nonproduction hosted artifact or live provider/MFA, Graph, database migration, restore/rollback, per-host headers, accessibility review, volume test or actual runtime-grant check was performed here. Passing local suites does not certify privacy/security or authorize production. The user authorized merging the tested source branches into `main` and removing redundant merged refs; that is not production release approval.

## 38-control integration ledger

Reviewer: Codex, 3 November 2026, candidate SHA above. “Implemented” applies only to the local integration procedure. Source evidence and owners are detailed in the linked revision-specific ledgers. Luis Duran coordinates repository integration; accountable custodian/privacy, security/operations, clinical and release owners still need their own decisions. Unverified repository evidence is not proof of organizational noncompliance.

| Control | State | Evidence / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/operator/clinic responsibility sign-off remains with custodian/privacy owner. |
| GOV-02 | Unverified | Privacy/legal owner must refresh legal and professional applicability at release. |
| GOV-03 | Unverified | Privacy/legal owner decides PIPEDA and cross-border applicability. |
| GOV-04 | Partial | Source changes remain administrative; clinical owner confirms intended use. |
| GOV-05 | Partial | Feature flows/threats are documented; privacy/security owners approve PIA/TRA and residual risks. |
| PRIV-01 | Unverified | Custodian verifies actual bilingual notice and contact. |
| PRIV-02 | Partial | No new consent wording in this merge; privacy owner reviews existing purpose/wording and restrictions. |
| PRIV-03 | Partial | Local revocation/worker tests passed; hosted and wider boundaries remain unverified. |
| PRIV-04 | Unverified | Custodian documents assisted minors/representative process and product limits. |
| PRIV-05 | Unverified | Custodian evidences complete access/correction process. |
| PRIV-06 | Partial | No record deletion; custodian approves class-specific retention and backup handling. |
| PRIV-07 | Unverified | Incident lead provides breach runbook/rehearsal and current notice decisions. |
| AUTH-01 | Partial | Local signed proof/JWT/session tests passed; actual identity-provider policy remains a hosted gate. |
| AUTH-02 | Partial | Local clinic/role/revocation tests passed; representative hosted identities and data remain to test. |
| AUTH-03 | Partial | 28/28 current Edge/Firefox session cases passed; MFA, idle/departure policy and Safari/hosted acceptance remain. |
| AUTH-04 | Partial | Memory/server session design remains default off; legacy storage exception remains a release decision. |
| AUTH-05 | Partial | No grants broadened by integration; operations verifies actual API/Graph/DB permissions. |
| SEC-01 | Partial | Candidate excludes private files/PDF; secret-history, deployed settings and key custody need operations review. |
| SEC-02 | Partial | No transport/config change; hosted TLS, database, backups and key controls remain unverified. |
| SEC-03 | Partial | Local cookie/no-store tests passed; per-host deployed headers and caches remain unverified. |
| SEC-04 | Partial | Scoped input/encoding tests in linked reports; human security review and hosted tests remain. |
| SEC-05 | Partial | Local bounds/concurrency passed; production abuse, backlog and load unverified. |
| SEC-06 | Partial | Local required audit tests passed; operational logs, monitoring and retention remain. |
| SEC-07 | Partial | Local redacted errors passed; proxy/APM/provider logs need hosted review. |
| SEC-08 | Partial | Existing/new scheduler flow documented; privacy/security approve actual subprocessors and retention. |
| SEC-09 | Partial | Local minimal email/revocation tests passed; actual Graph acceptance and reconciliation remain. |
| DEV-01 | Implemented | Dedicated integration branch, ancestry/worktree review, clean candidate and source reports. |
| DEV-02 | Partial | No product edit or test weakening in merge; independent review remains. |
| DEV-03 | Partial | Lockfiles unchanged in this integration; 11 moderate frontend build-tree advisories unresolved. |
| DEV-04 | Partial | Exact local suites above; historical flakes, optional skips and hosted omissions retained. |
| DEV-05 | Partial | Bilingual browser coverage passed; full AT/keyboard/contrast/zoom/AODA review outstanding. |
| DEV-06 | Partial | Existing bundle warnings and provider-lock/backlog performance need review. |
| REL-01 | Implemented | This dated scoped ledger and linked per-feature evidence. |
| REL-02 | Partial | Broad local checkpoint completed; optional skips and omitted MariaDB/Safari/hosted checks explicit. |
| REL-03 | Unverified | No candidate artifact or hosted acceptance/rollback/actual grants. |
| REL-04 | Missing | Required real-data/release sign-offs, hosted acceptance and advisory decisions outstanding. |
| REL-05 | Partial | Owner approved Git merge/branch cleanup; no production migration/deployment/activation. |
| REL-06 | Implemented | Integration purpose, source revision, tests, warnings, omissions, owners and rollback limits recorded. |

Source merge rollback is a reviewed Git revert of the integration commit(s), not deletion of database history or recall of provider effects. No database state or external delivery changed in this task. Production rollback remains defined in each feature report and must be tested before deployment.

RELEASE STATUS: NOT READY FOR RELEASE
