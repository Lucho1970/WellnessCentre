# Ontario health application and engineering audit — 29 October 2026

**Decision: NOT READY FOR RELEASE with real patient data.** Material project-policy gaps and missing operational acceptance evidence remain. This is a source/local evidence audit, not a compliance certification, legal opinion, penetration test or verification of the deployed service. No finding establishes that a privacy breach occurred.

## Scope, baseline and method

Application source reviewed: **`68808bbb7743765b664d7c6cddab50ef3f553273`**, the active `feature/provider-reconciliation` baseline, five commits ahead of local `main`. This task branches from that baseline as `feature/ontario-compliance-standard`; it does not reset it or assert that it is deployed. Existing untracked service spreadsheets, sales material and branding were preserved and excluded. No private environment values, patient databases, live vendor/identity settings or patient attachments were inspected. Tests used synthetic fixtures and disposable localhost databases. Source inspection covered representative high-risk paths, not a formal exhaustive route-by-route proof.

The [mandatory standard](ONTARIO_HEALTH_APPLICATION_STANDARD.md) supplies control IDs. Root [AGENTS.md](../AGENTS.md) instructs future contributors/agents to follow it. Enforcement is through repository instructions and human release gates; this documentation task does not add CI enforcement or remediate application findings. Missing evidence is a release gate, not proof that the organization lacks a control.

Method: read the supplied development standard; inspect current source/configuration/tests and prior privacy documentation; check primary Ontario, IPC, professional regulator, federal privacy, CRTC and Health Canada sources; run local builds, dependency audits, browser/PHP checks and selected SQL/HTTP acceptance. Direct retrieval of current Ontario statute/regulation URLs was blocked by the research service; indexed official extracts, including versioned consolidations, and regulator guidance were available. Confirm the current consolidation and commencement at release. This is not an exhaustive assessment of every law or threat.

Status: **Implemented** = evidence for the stated local/source scope; **Partial** = incomplete coverage or approval; **Missing** = no complete implementation/process found in reviewed material; **Unverified** = external or unexecuted evidence needed. The table maps every standard control; grouped entries have qualified scope.

## Control assessment

| Controls | Status | Evidence and remaining acceptance |
| --- | --- | --- |
| GOV-01–03: accountability/applicability | Unverified | Prior PHIPA documentation identifies questions, not named custodian/agent appointments, signed processing agreements or a complete applicability register. Determine custody/control, HINP and federal scope; confirm current law/commencement. |
| GOV-04: intended use/device scope | Partial | Reviewed scheduling, forms, receipts and communications suggest administrative/record-access use. This is an inference, not a device determination. Approve intended use; reassess diagnostic/clinical recommendation functions or claims. |
| GOV-05: inventory/PIA/TRA | Partial | Architecture/feature documentation and synthetic tests exist; no signed complete PIA/TRA with actual infrastructure/vendor flows was established. |
| PRIV-01: notice/contact | Missing / Unverified | No complete clinic-specific PHIPA information-practices notice/request/complaint contact found in reviewed patient-facing source. `Wellnexa/src` search for privacy/personal-information wording found none. Validate all actual hosted surfaces and publish approved notices. |
| PRIV-02–03: authority/restrictions | Partial | `ClientConsentService.php`, `ConsentAccess.php`, `ConsentLock.php` and related services/workers implement named/global staff and communication restrictions, version conflicts and instruction references. Local SQL/HTTP checks pass. Coarse negative permissions do not establish every purpose/recipient authority, finer information restrictions or approved exceptions. |
| PRIV-04: representatives/treatment consent | Missing / Unverified | No complete supported capacity/minor/representative workflow established. Consent documentation explicitly leaves representative/offline global restoration work open. Adopt a verified assisted process and explicit boundaries before those uses. Form acceptance alone is not treatment consent. |
| PRIV-05: access/correction | Partial | Clients can see forms/appointments/receipts; reasoned form amendments exist. `data_export_requests` appears in record-merge handling, but no complete general request intake, verification, due-date, severance/refusal/complaint and protected-delivery process was established. A tested controlled manual process can close this gap. |
| PRIV-06: retention/holds/succession | Missing / Unverified | Histories preserve integrity, but no complete approved profession/record-class schedule, enforceable hold/disposal/backup-expiry process or tested closure/transfer procedure established. Do not infer one retention period for every profession. |
| PRIV-07: breach process | Unverified | No signed/rehearsed incident plan, named reporting owner or annual statistics process inspected. Use the standard's separate individual/IPC/college/statistics rules. |
| AUTH-01: identity | Implemented locally / hosted Unverified | `EntraAuthenticator.php:44` validates JWT signature, scope, issuer, audience and tenant; separate customer/external staff/central admin authenticators exist. PHP identity tests pass with local OpenSSL configured. Actual registrations, grants, redirects and identity controls remain unverified. |
| AUTH-02: access/clinic boundaries | Implemented foundation / Partial overall | `BookingClientAccess.php:11` applies active actor, consent, merge exclusion and relationship/delegation predicates. Answers remain limited to client/assigned practitioner (`ClientFormsService.php:104`); central and privacy reviewer roles are distinct. Selected SQL/HTTP checks pass. Historical appointments remain a relationship criterion; approve continuing necessity and delegation. Not every route was exercised. |
| AUTH-03: sessions | Partial | `CustomerOnboarding.php` has server-tracked hashed sessions, idle/absolute limits and revocation. Equivalent workforce idle policy, MFA/device/offboarding effectiveness needs evidence; silent renewal alone is insufficient. |
| AUTH-04: browser storage | **Fails project default; High** | `Frontend/src/auth/AuthProvider.tsx:25`, `Frontend/src/customer/auth.ts:22` use MSAL sessionStorage; `Frontend/src/customer/session.ts:52` persists a customer session token. Invitation/action tokens are also temporarily stored. No approved time-bounded exception established. This raises XSS exposure; it is not evidence of theft or a claim sessionStorage is intrinsically unlawful (F-01). |
| AUTH-05: permissions | Partial / Unverified | Explicit server roles/scheduling/privacy permissions exist. `EMAIL_DELIVERY.md:13` requires mailbox-scoped mail access and warns against unscoped grants. Actual deployed identity grants and runtime DB privileges not inspected. |
| SEC-01: secrets/configuration | Partial | Targeted private-key/secret-assignment scan found no candidates in 294 selected tracked source files and 85 local built text files. Not a full entropy/history/whole-repository or release-ZIP scan. `Frontend/.env.production` is tracked; its presence is not proof of a secret and private values were not opened. Final configuration/artifact review remains required. |
| SEC-02: transport/storage | Partial / Unverified | Reviewed external service URLs use HTTPS. `api/src/Database.php:16–25` has native prepares but no explicit verified-TLS/CA PDO configuration. This does not establish an unsafe local connection or unencrypted disks/backups. Obtain topology, connection security, encryption/key, privilege, patching and restore evidence (F-04). |
| SEC-03: host/browser protections | Partial / Unverified | `Api.php:765–766` sets allowlisted CORS, no-store, nosniff and no-referrer. Public frontend has redirects; neutral landing page has CSP. These do not establish CSP/frame/HSTS/HTTPS controls for every actual portal/API host (F-05). |
| SEC-04: injection/rendering | Implemented foundation / Partial review | Native prepares, React rendering and escaped receipt fields found; `ReceiptPdf.php` disables remote resources/PHP/JavaScript. Targeted unsafe HTML/eval/new Function scans found no matches in the two first-party React source trees. No exhaustive SQL/XSS/SSRF review or penetration test. |
| SEC-05: bounds/abuse | Partial | `Http/Request.php:27–29` bounds selected routes to 64 KiB and service imports to 1 MiB; other routes read an unbounded body. Enquiry rate limits exist. Global host/request/rate limits, all-route bounds, upload/export safety and representative load unverified (F-09). |
| SEC-06: audit/integrity | Implemented API foundation / Partial overall | `AccessAudit.php`, response auditing and `PrivacyAuditService.php:16` provide resource references, denials, explicit reviewer privileges and current-authority rechecks. Selected SQL/HTTP checks pass. DB/backup audit immutability, independent monitoring, routine reviews and non-API access need operational evidence. |
| SEC-07: logs/errors | Implemented foundation / Partial | Safe generic API failure/audit payload exclusions have PHP tests. `VoipMsSmsClient.php:request` sends API credentials/destination/message in a server-side HTTPS query URL; verify proxy/APM/provider URL redaction and restricted retention. This is not browser address-bar exposure. |
| SEC-08–09: vendors/messages | Partial / Unverified | Generic notices, consent checks, uncertain-send safeguards, reviewed provider evidence and shared-sender STOP suppression exist. Contracts, fields, support geography, retention, live STOP operations and CASL authority are not proven by delivery or operator “reviewed” flags (F-06). |
| DEV-01–02: workflow/quality | Partial | Dedicated branch, preserved work, strict TypeScript and versioned docs/migrations/tests. No runtime changes here. Full per-module typing/error/maintainability review incomplete; lint cannot provide evidence until repaired. |
| DEV-03: dependencies | **Partial; High build findings** | Frontend full npm audit: two high package findings, both build/development paths; omit-dev: zero. Wellnexa/Composer: zero advisories. Lockfiles exist; Composer validates. Clean restore, licence review and final dependency inclusion proof not performed (F-03). |
| DEV-04: lint/build/tests | **Fails required gate** | Frontend lint exits 1: ESLint missing/undeclared. Builds and listed PHP/SQL checks pass. Default browser suite had one startup timeout; report and targeted repeat below. Other suites and hosted checks remain separate gates (F-02/F-11). |
| DEV-05: accessibility/i18n | Partial / Unverified | 2,520 bilingual translations validate; browser cases cover some keyboard/mobile/French behavior. Full keyboard/screen-reader/contrast/zoom/assistive technology assessment not performed. Actual AODA organization/surface applicability unresolved (F-10). |
| DEV-06: performance | Partial | Frontend warns about >500 kB chunks: public bootstrap about 1,051 kB and central admin about 1,532 kB uncompressed. Wellnexa JS about 259 kB. Volume/query plans and timer/render/network review incomplete. |
| REL-01–02: evidence/checkpoint | Partial | This ledger records revision-bound local results; named owners, signatures and complete checks remain required. Fixtures/disposable SQL are explicitly distinct from hosted acceptance. |
| REL-03–05: artifact/host/rollback/approval | Unverified | No ZIP, clean release restore, hosted acceptance, backup recovery, signed real-PHI gate or release-specific rollback evidence. Local dist also has old root-level assets outside current build surfaces; package clean output with an exact manifest. |
| REL-06: completion report | Implemented for documentation scope | Files, no runtime changes, commands, findings and limitations recorded here. Readiness withheld. |

## Findings and closure plan

Priorities are project release/risk priorities, not legal findings. Owners are roles awaiting named assignment. All applicable mandatory controls need evidence before real-PHI acceptance; operational gaps are not necessarily software bugs.

| ID / priority | Required action and closure evidence | Owner |
| --- | --- | --- |
| F-01 / High | Replace persistent credential storage with an approved design or obtain a narrow, expiring security exception. Test XSS defenses, expiry, revocation, logout, account switching and cleanup. Do not disable auth as a workaround. | Security + identity lead |
| F-02 / High | Declare/configure compatible lint tooling and get Frontend lint passing without weakening controls; document baseline debt/exceptions. | Engineering lead |
| F-03 / High build environment | Resolve/review npm findings by path and exposure; keep dev servers private, apply compatible reviewed patches, rebuild/re-audit and inspect artifacts. No forced audit fixes. Zero runtime advisories do not clear build exposure. | Engineering + security |
| F-04 / High release evidence | Verify DB transport/topology, least-privileged accounts, encryption/keys and backups; exercise restore with RPO/RTO and record-integrity evidence. Configure verified network TLS where needed. | Hosting/DB operations + security |
| F-05 / High release evidence | Capture redirects/headers/cache behavior from every public/clinic/central/API host. Apply/test CSP/frame controls and safe HSTS appropriate to the host layout. Landing-page CSP alone is insufficient. | Hosting + frontend/security |
| F-06 / High release evidence | Complete signed endpoint/vendor inventory; verify scoped mail grants, SMS URL redaction, maps minimization, geography/support, breach/deletion terms, preferences and CASL decisions. Disable flows that cannot be approved. | Privacy + operations |
| F-07 / High rights gate | Publish actual custodian practices/contact; rehearse access/correction, verified representatives/capacity and offline restriction restoration. Controlled manual workflows can close gaps with evidence. | Custodian/privacy + clinical |
| F-08 / High operational gate | Approve record/profession retention, holds, disposal/backup expiry and closure transfer; rehearse incidents/notifications/statistics; prove training/support/access review. Complete PIA/TRA and applicability. | Custodian/privacy + operations |
| F-09 / Medium; escalate if exposed | Bound all API bodies before read/decode or verify effective host limits; test rate/oversize/stalled requests and bounded resource use safely. | Backend + hosting |
| F-10 / Medium review; core barriers block | Complete accessibility/AODA scope and keyboard/screen-reader/zoom/contrast evidence; assess large bundles and representative-volume performance. | UX/accessibility + engineering |
| F-11 / High release evidence | Resolve browser startup instability; produce a clean traceable artifact, test actual non-production identities/providers/database and rollback, obtain accountable sign-offs. Repository instructions alone do not supply runtime/CI enforcement. | Release owner + custodian/security |

The [18 October PHIPA review](PHIPA_READINESS_REVIEW_2026-10-18.md) is historical. Its broad booking-contact lookup, missing response audit and missing restriction enforcement findings now have substantial source/local remediation. Remaining rights, retention, finer consent and operational concerns persist. See [consent boundaries](CONSENT_RESTRICTIONS_WITHDRAWAL.md), [sensitive audit](SENSITIVE_ACCESS_AUDIT.md), [privacy review](PRIVACY_AUDIT_REVIEW.md) and [provider evidence](PROVIDER_EVIDENCE.md).

## Observed endpoint/permission register

Initial source inventory, not an approved or exhaustive live register. Literal URLs can be navigation/library defaults rather than transmissions; configured identity/clinic/relay hosts need environment evidence. HTTPS schemes are not certificate tests.

| Service / host | Purpose and data | Auth/permission and remaining evidence |
| --- | --- | --- |
| Microsoft identity: `login.microsoftonline.com`, configured `*.ciamlogin.com` | Workforce/customer/external claims and sign-in/tokens; HTTPS | MSAL/OIDC and API delegated scopes. Verify registrations, redirects, grants, MFA/session controls, support and geography. |
| Graph: `graph.microsoft.com` | Recipient email, operational subject/body and optional calendar; HTTPS | Client credentials/`.default`; verify mailbox-scoped `Application Mail.Send` without a bypassing unscoped grant. Mail worker handles transient/uncertain failures; live grant/delivery unverified. |
| VoIP.ms: `voip.ms/api/v1/rest.php` | Sender/destination, minimized SMS and provider identity; HTTPS GET | Server account credentials in query. Verify account/IP restrictions and full URL redaction at intermediaries/provider; no automatic retry of timeout. Reviewed STOP import is not proof of instant live ingestion. |
| Google: `maps.googleapis.com`, `addressvalidation.googleapis.com`, `routes.googleapis.com` | Places/maps, address components, coordinates/routes; HTTPS | Restricted browser and server API keys as configured. Verify exact fields, terms, location/retention and key restrictions. Attribution is not approval. |
| Clinic/API/public/portal domains; mail/scheduler bridges | Records, enquiries and server-side dispatch; deployment-specific hosts | Bearer/customer sessions and trigger/bridge authority. Verify exact hosts, TLS, allowlists, secret/log handling, bounds and failures. No live endpoint testing. |
| Practitioner/social/Google policy links | User navigation to published external destinations | Validate schemes, deliberate disclosure and referrer/URL minimization; distinguish navigation from background processing. Exhaustive published-link review not performed. |

## Dependency findings

| Path | Classification / audit | Relevance |
| --- | --- | --- |
| `Frontend → vite@6.4.3`; also used by `@vitejs/plugin-react@4.7.0` | Direct development dependency; high overall severity. Audit includes launch-editor command/argument and dev-server middleware/filesystem issues; e.g. [GHSA-587h-7wmj-h5pm](https://github.com/advisories/GHSA-587h-7wmj-h5pm), [GHSA-9jrq-w75r-8gcw](https://github.com/advisories/GHSA-9jrq-w75r-8gcw). | Build/dev-server exposure, not a confirmed vulnerable static browser runtime. Review any preview/dev server hosting and compatible fixes. |
| `Frontend → vite@6.4.3 → postcss@8.5.26 → source-map-js@1.2.1` | Transitive development/build dependency; high source-map event-loop DoS, [GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q). | Review untrusted map/build inputs. Omit-dev audit is clean; final deployed inclusion proof incomplete. |
| Wellnexa / Composer | Zero npm/Composer advisories; no Composer abandoned packages reported. | Point-in-time advisory results are not licence approval or proof of safety. |

## Validation executed

Windows; Node `v22.22.1`, npm `11.19.0`, PHP CLI `8.4.14`, Composer `2.9.5`, disposable MariaDB `11.4.8`. Local PHP/MariaDB checks do not establish hosted PHP/MySQL 5.7 compatibility. Commands ran from the directory indicated; every result is limited to the reviewed baseline and local environment.

| Command/check | Result |
| --- | --- |
| `Frontend: npm run build` | Passed content, 2,520 translations, TypeScript and public/portal/central builds; existing >500 kB warnings. |
| `Wellnexa: npm run build` | Passed TypeScript/production build. |
| `Frontend: npm run lint` | Failed exit 1: ESLint not recognized; undeclared/missing tool. |
| `Wellnexa: npm run lint` | Exit 0, four warnings: React immutability (`src/App.tsx:14–15`), component-only exports (`src/catalogue/Details.tsx:4,9`). |
| `Frontend: npm audit --json` | Exit 1: two high package findings above. |
| `Frontend: npm ls source-map-js vite eslint --all` | Confirmed dependency paths; no ESLint in returned tree. |
| `Frontend: npm audit --omit=dev --json` | Exit 0: zero runtime advisories. |
| `Wellnexa: npm audit --json` | Exit 0: zero advisories. |
| `api: composer audit --format=json` | Passed: no advisories/abandoned packages. |
| `api: composer validate --strict` | Passed manifest/lock validation. |
| Root PHP scripts: `Get-ChildItem api/tests -File -Filter '*.php'`, each invoked with `php` | First run had three RSA-key failures (`customer-auth`, `external-staff-auth`, `identity-membership`). Reran all with process `OPENSSL_CONF='C:/Program Files/Git/usr/ssl/openssl.cnf'`: **56/56 passed**. No production config changed. |
| `php -l` on recursive PHP files in `api/src`, `api/public`, `api/tests`, `Wellnexa/public/api` | **242/242 passed**. Initial combined shell still exited 1 from earlier RSA failures, not syntax. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13347 -IntegrationTest consent-directives.php` with the above OpenSSL environment | Passed: 74 booking authorization + 28 API audit + 70 consent SQL/HTTP checks. Disposable server stopped; synthetic data/logs under ignored `.tmp`. |
| Same SQL command with `-Port 13348 -IntegrationTest privacy-audit-review.php` | Passed: 74 booking + 28 API audit + 69 privacy reviewer checks. Disposable server stopped. |
| Same SQL command with `-Port 13349 -IntegrationTest provider-evidence.php` | Passed: 36 subscription/SMS ledger + 69 practitioner application + 58 automated communication + 49 provider evidence SQL/worker/HTTP checks. Disposable server stopped. |
| `Frontend: npm test -- --reporter=line` | **282 passed, 2 skipped, 1 failed** of 285. Failure: CSV 409 service-import scenario (`portal.spec.ts:209`) timed out during startup, before reaching import; snapshot showed “Unable to start the application.” Root cause unproven. Skips are existing optional private CSV/workbook tests, with synthetic alternatives. No test was weakened. |
| `Frontend: npx playwright test tests/portal.spec.ts --grep 'service import csv 409 after preview clears unsafe confirmation' --reporter=line` | **1 passed** on isolated repeat. Root cause of original startup failure remains unproven; this does not erase the full-run failure. |
| `Wellnexa: npm run test:browser -- --reporter=line` | **24 passed**, local Edge/API fixtures. |
| `Frontend: npx playwright test --config playwright.application-admin.config.ts --reporter=line` | **36 passed**. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | **14 passed**, local production-output smoke checks. |
| Targeted in-memory Node pattern scan and host/bundle inspection | 294 tracked source + 85 local built text files; no private-key/selected hardcoded secret-assignment candidates; no secret values printed. First-party React unsafe-sink scan found no matches. |

Bundle localhost/debug matches were inspected in context: sampled strings belong to router fallback, MSAL logger branches and markdown/file-URL handling. They do not establish an active production HTTP endpoint or sensitive logging. Dist also includes pre-existing root files outside current surface outputs. Rebuild cleanly and trace reachable modules/strings in the actual final package; this scan does not clear all artifact risks or certify absence of secrets.

**Not performed:** clean `npm ci`/`composer install` release restore; ZIP creation; exhaustive routes/endpoints/permissions; real provider sends; hosted DB/identity acceptance; all SQL suites; dedicated invitation/application browser configs; full accessibility/load/penetration/licence/Git-history secret and recovery tests. No generated output, dependencies, private data, synthetic DBs or ZIPs are included in this commit.

## Documentation changes and handoff

- `AGENTS.md`: mandatory policy and audit links; existing branch/Git workflow retained.
- `documentation/ONTARIO_HEALTH_APPLICATION_STANDARD.md`: normative health/privacy/security/engineering controls, gates and review checklist adapted to this stack.
- `documentation/COMPLIANCE_AUDIT_2026-10-29.md`: this evidence ledger and remediation plan.
- `documentation/README.md`: discovery links.

No dependency changes. No permission changes. No endpoint changes. No application/database behavior changes, production deployment or merge. Browser credential storage is recorded for separately reviewed remediation. Documentation rollback is a reviewed Git revert; runtime migration rollback does not apply to these edits. The reviewed source SHA above anchors all results; the subsequent documentation commit is available in Git history.

Named custodian/privacy, clinical, security/operations and release owners must resolve applicability and close/accept eligible project-policy findings with evidence. Legal duties cannot be waived. Until then this solution is not demonstrated to follow every mandatory rule; this audit does not approve real PHI use.

RELEASE STATUS: NOT READY FOR RELEASE
