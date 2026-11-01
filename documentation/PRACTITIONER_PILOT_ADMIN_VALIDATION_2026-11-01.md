# Practitioner pilot administration — 1 November 2026

Reviewer: Codex, source/local testing only. Branch `feature/practitioner-pilot-admin`,
based on `6845e30` from the ongoing practitioner sign-in work. Exact implementation
revision and final artifact checks will be recorded after the source checkpoint.
No merge, migration 058, managed approval, account creation or hosted configuration
change has been performed for this addition. The existing unrelated untracked PDF remains
untouched and excluded. Implementation authorization does not establish organizational approval.

## Result and boundaries

Central administrators can record a verified practitioner's own sign-in reference for one
clinic, review a pending entry, approve/revoke it and inspect the last 50 decision events.
Every decision requires a reason, current version and idempotency key. Approval additionally
requires independent account-owner verification. It grants only the extra private-pilot gate;
it grants no clinic membership, clinical role, patient relationship, application approval,
trial, SMS budget, publication or billing entitlement. Ordinary departure still uses Staff
access → Inactive and the existing offboarding review. No patient records are test fixtures.

The default-off `PRACTITIONER_PILOT_ACCESS_ENABLED` flag selects database authority only for
external practitioners while `PILOT_MODE=true`. Workforce/client environment approval lists
and all delivery guards remain in place. Mixed static external-practitioner approvals are
rejected. Admin endpoints are unavailable outside pilot mode; normal non-pilot authentication
continues using the existing identity/membership checks. The UI clears private state on denied,
missing or conflicting authority and on principal changes. Language switching retains drafts.

Full existing JWT validation precedes the own-account `WPI1` support reference. This encoding
contains adapter/issuer/subject only, no token/email/role/name. It is neither signed ownership
proof nor a credential. A copied reference alone cannot approve an account. Operators must
independently verify the intended owner; do not trust unsolicited references or email matches.
No anonymous/pre-allowlist write endpoint is added. Raw upstream provider tokens remain invalid.

Migration **058**, requiring verified 032/040/049 prerequisites, adds exactly three tables:
`practitioner_pilot_access`, `practitioner_pilot_events`, `practitioner_pilot_requests`.
It does not require the disabled communications updates 056/057. It seeds no grants/flags,
changes no clinical records and charges nobody. **Its hosted application is not approved.**

New first-party API routes on the existing central host, using the existing application-admin
workforce token and active explicit database grant:

| Route under `/api/v1/application/clinics/{clinic}/pilot-practitioners` | Purpose |
| --- | --- |
| GET root, optional `status` / `before_id` | Up to 50 entries; detailed identity tuple omitted. |
| GET `/{id}` | Exact scoped identity and last 50 reason/actor/time events. |
| POST root | Record reference/label/reason as pending. |
| POST `/{id}/approve` | Versioned, independently verified approval. |
| POST `/{id}/revoke` | Versioned revocation; no deletion. |

These responses use `no-store`. Request capture limits these paths to 65,536 bytes; field
limits are tighter. SQL uses prepared values and fixed/allowlisted identifiers; pagination
is by indexed clinic/id. A clinic/identity/database-scoped advisory lock has a five-second
acquisition bound and serializes decisions with protected API execution. Completed revocation
waits for prior authorized execution to finish; later requests recheck current authority.
Unavailable tables/locks fail closed. Required decision events and retry records commit
atomically with the change. Old approval replay cannot restore revoked access.

No new external hostname, telemetry, provider, sender, API permission, consent grant, OAuth
flow, runtime dependency, lockfile version or toolchain is introduced. This adds private
identity-administration data to the existing database and own-account reference metadata
to a verified pilot-denial response. Its retention/hold schedule needs owner approval.
Reasons/labels must not contain patient information, credentials or bearer tokens.

## Changed files and purpose

| File | Purpose |
| --- | --- |
| `Frontend/src/admin/PractitionerPilotAdmin.tsx` | Clinic-scoped queue, record/review/approve/revoke/history, exact uncertain retry, private-state cleanup, bilingual modal and explicit focus restoration. |
| `Frontend/src/central/main.tsx` | Server-advertised tab; account-bound authority state; preserve forms across language changes. |
| `Frontend/src/i18n/practitionerPilot.ts` | English/French wording. |
| `Frontend/src/i18n/index.ts` | Register feature translations. |
| `Frontend/scripts/validate-i18n.mjs` | Include feature resources in actual validation. |
| `Frontend/tests/application-admin.spec.ts` | Nine managed-pilot browser cases; synthetic review/mobile screenshots. |
| `Frontend/tests/practitioner-applications.spec.ts` | Two English/French own-reference denial and retry cases. |
| `api/src/Service/PractitionerPilotAccessService.php` | Current operator/clinic checks, one approval authority, bounds, pending/approve/revoke, atomic events/retries and execution gate. |
| `api/src/Service/PractitionerPilotLock.php` | Bounded connection-scoped advisory lock, namespaced to database/clinic/exact identity. |
| `api/src/Auth/ExternalStaffAuthenticator.php` | Managed callback only after JWT verification; minimal own reference on pilot denial; missing required authority denies. |
| `api/src/Api.php` | Central route, authenticated feature metadata and server-clinic callback; release execution lock after response audit. |
| `api/src/Config.php` | Strict default-off flag and rejection of mixed external approval authorities. |
| `api/src/PilotPolicy.php` | Detect legacy external approvals without exposing them. |
| `api/src/Http/Request.php` | Stricter body limit for the new administration paths. |
| `api/src/Service/AccessAudit.php` | Canonical redacted pilot resource vocabulary. |
| `api/database/migrations/058_practitioner_pilot_access.sql` | Three nonclinical administration tables; no seed/clinical changes. |
| `api/.env.example` | Placeholder/default-off configuration and prerequisite guidance. |
| `api/bin/pilot-preflight.php` | Validate flag and reject mixed authority, with counts-only output. |
| `api/tests/external-staff-auth.php` | Signed-token/reference/gating, missing authority, invalid identity and non-pilot regressions. |
| `api/tests/pilot-preflight.php` | Valid managed settings, malformed flag and legacy authority rejection. |
| `api/tests/request-body-limits.php` | Actual HTTP exact/oversize capture for root/decision paths. |
| `api/tests/integration/practitioner-pilot-access.php` | Disposable SQL/HTTP, exact JWT, stale/foreign/role/replay/Inactive/lock concurrency and audit failures. |
| `api/tests/integration/practitioner-pilot-http-fixture.php` | Guarded loopback router with synthetic JWKs and the real managed callback. Not deployed. |
| `scripts/test-recurring-sql.ps1` | Explicit new-suite selection for disposable MariaDB. |
| `scripts/test-mysql57-sql.ps1` | Explicit new-suite selection for disposable MySQL 5.7. |
| `scripts/build-current-deployment.ps1` | Include current guide/evidence and accurate 058 migration policy in generated release metadata. |
| `documentation/PRACTITIONER_PILOT_ADMIN.md` | Operator workflow, migration/settings/deployment boundaries and rollback. |
| `documentation/PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md` | This current evidence/38-control ledger. |
| `documentation/PILOT_SAFETY_CONTROLS.md` | Link the additional default-off managed authority without rewriting historical evidence. |
| `documentation/WELLNEXA_PRACTITIONER_SIGN_IN.md` | Link pilot management and remaining hosted prerequisites. |
| `documentation/WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md` | Record actual entry/callback/pilot return and new prerequisite for continued tests. |
| `documentation/README.md` | Index the new guide and ledger. |

## Validation and initial failures

All SQL tests use explicitly created disposable loopback databases and synthetic data, not
hosted credentials. Windows RSA fixtures set process-only
`OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`. No provider sends or real payment calls.
Final broad/browser/artifact results are recorded below; local checks do not establish hosted acceptance.

Already observed: 67 external-token checks, 16 preflight checks, 80 pilot-policy checks;
Final **61 root PHP scripts and 254 syntax files** passed, including the body-limit follow-up.
Request-body suite then passed 55 actual HTTP checks. MySQL 5.7 ran the existing 36-ledger +
119-application SQL/HTTP suite successfully. Final managed-pilot runs on both engines passed **36 ledger + 62 pilot SQL/HTTP/concurrency
checks**, including ordinary Inactive/restore with the same signed bearer. The full central
Admin suite passed **45/45**. A later screenshot-only two-case rerun had one timeout waiting
for the initial Admin tab while other suites were running; the French case passed. That
startup timeout is retained; the affected case subsequently passed (1/1) after the broad
suite, without changing timeouts/retries or application behavior. Desktop and French
390-pixel mobile screenshots were visually inspected after disabling screenshot animations;
labels, wrapped identity/reference text and dialog actions remain readable.

Initial failures retained in tool output/ignored test artifacts:

- Browser expectations used raw error messages; existing safe API handling deliberately
  substitutes translated generic 403/409 errors. Assertions now check that behavior.
- A modal initially had no accessible language switch, and implicit focus restoration raced
  the disabled opener. Added an in-dialog language button and explicit transition-end focus
  restoration to the opener, or the section heading when it is unavailable. Focus test passed.
- New French test text was initially written with the Windows code page, and one assertion
  used English Close after switching language. Corrected the test suffix to UTF-8 and French
  label. No lint suppression, test skip, forced dependency update or weakened control.
- MySQL canonicalizes JSON object-key order, so a strict PHP array-order assertion on the
  retry response failed. Compare the full semantic object; status/id/version/key behavior is
  unchanged. MariaDB/MySQL retry/revocation tests subsequently passed.
- Several discovery/check commands used an incorrect relative path or unsupported Windows
  glob. They did not execute the intended check; corrected commands were run explicitly.

Dependency locks remain unchanged. Runtime: Node 22.22.1, npm 11.19.0, PHP 8.4.14,
Composer 2.9.5; disposable MariaDB 11.4.8 and MySQL 5.7.44. Inspected live runtime previously:
PHP 8.4.27/MySQL 5.7.44. Exact locked versions relevant to this work:
Frontend React 19.2.8, TypeScript 5.7.3, Vite 6.4.5, MSAL browser 5.19.0/react 5.6.0,
MUI 7.3.11, Playwright 1.63.0, ESLint 10.13.0; Wellnexa React 19.3.0, TypeScript 6.0.3,
Vite 8.3.4, Playwright 1.64.1, Oxlint 1.88.0. Composer: dompdf 3.1.6, php-font-lib 1.0.2,
php-svg-lib 1.0.2, firebase/php-jwt 7.1.0, libphonenumber 9.0.40, locale 2.9.0,
masterminds/html5 2.11.0, FastRoute 1.3.1, php-css-parser 9.5.0, polyfill-mbstring 1.43.0.

Current audits: `npm audit --json` Frontend exit 1, **11 moderate build-tree findings**;
`npm audit --omit=dev --json` exit 0, no runtime findings. Wellnexa full audit exit 0,
no findings. `composer validate --strict` and `composer audit --format=json` exit 0,
no advisories/abandoned packages. This does not approve the build exposure; the minimatch
review/remediation owner remains unassigned. Existing oversized frontend chunk warnings and
four Wellnexa lint warnings remain (two App mutation, two Details export warnings). No new
runtime dependency or advisory is introduced by this change.

## Final local checkpoint

| Exact command (working directory) | Result |
| --- | --- |
| `npm run lint` and `npx tsc --noEmit` (Frontend) | Exit 0, zero lint warnings. |
| `npm run build` (Frontend) | Earlier full build passed lint, 4 content entries, 2,566 EN/FR translations, strict types and all three surfaces. Final clean package build recorded after source checkpoint. |
| `npm test` (Frontend) | Exit 0; **285 passed, 2 pre-existing optional-fixture skips**, 287 total. |
| `npx playwright test --config playwright.application-admin.config.ts` (Frontend) | Final full suite **45 passed**, including nine new pilot cases. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` (Frontend) | **17 passed**, including two own-reference EN/FR denial/retry cases. |
| `npx playwright test --config playwright.invitations.config.ts --reporter=line` (Frontend) | **7 passed**. |
| `npm ci` (Wellnexa) | Exit 0; exact lock restore, 30 packages, no audit findings. |
| `npm run lint`, `npm run build`, `npm run test:browser` (Wellnexa) | Exit 0; four existing lint warnings, build passed and **24 browser cases passed**. |
| `php api/tests/external-staff-auth.php` (root) | **67 passed**. |
| `php api/tests/pilot-preflight.php` (root) | **16 passed**, temporary synthetic settings only. |
| `php api/tests/pilot-policy.php` (root) | **80 passed**. |
| `php api/tests/request-body-limits.php` (root) | **55 passed**, actual loopback HTTP input capture. |
| Each root `api/tests/*.php`; `php -l` each file under `api/src`, `api/bin`, `api/tests` | **61 scripts and 254 syntax files passed**, zero failures. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13343 -IntegrationTest practitioner-pilot-access.php` (root) | Final **36 ledger + 62 managed-pilot checks passed**; disposable server stopped. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13344 -IntegrationTest practitioner-pilot-access.php` (root) | Final **36 + 62 passed** on 5.7.44; disposable server stopped. |
| Same MySQL runner/runtime, `-Port 13345 -IntegrationTest practitioner-applications.php` | Existing **36 ledger + 119 registration/review/activation/retirement SQL/HTTP checks passed**; disposable server stopped. |
| PowerShell parser on `scripts/build-current-deployment.ps1`; `git diff --check` | Zero parse/whitespace errors; expected Git CRLF normalization notices only. |
| High-confidence private-key/token signature scan over all 32 task text files | No candidates. This is not an exhaustive secret/history/PHI audit. |

Default Playwright omits central Admin, practitioner applications, invitations and built-artifact
smoke suites; dedicated configurations are necessary and reported separately. Hosted provider
flows, representative load, full accessibility, restoration and organizational evidence remain
excluded as listed below. No broad SQL sweep of unrelated modules or hosted abuse test was run.

## Hosted evidence and exclusions

The previous source `6808300` release has been uploaded by the owner with Beyond Compare.
140 HTTPS asset checks matched all 72 distinct JS/CSS files across clinic/central/website
hosts. Nine entry/body routes matched after separately identifying stale Wellnexa website
root cache and rechecking the canonical root. Clinic workforce and independent practitioner
entries render separately. Google initially returned AADSTS50011 for its missing Wellnexa
callback; the callback appeared in the authenticated admin session during inspection (the
agent did not perform Save), and Google then returned to Wellnexa. Personal Microsoft also
returned. Both owner-approved accounts currently stop at the private pilot denial. These
are provider-return observations, not registration/activation/normal retirement acceptance.

The prior approved 053–055 updates succeeded after backup verification. The existing host
has two workforce pilot approvals, no static external approvals and no allowlisted delivery
destinations. Mail/SMS stay disabled; Wellnexa intake/activation settings are still closed
and no plan/terms have been published. The new 058 code/flag/UI is **not deployed**.

Excluded/unverified: hosted 058/grants/lock privileges, managed allow/revoke, registration →
review → activation → ordinary Inactive for both real providers, MFA/idle/absolute/revocation
policies, actual restoration, independent audit integrity/monitoring, representative load,
full assistive-technology/contrast/theme/AODA assessment, and accountable organizational
approvals. Existing identity-library sessionStorage exceptions remain unresolved. No new
session architecture is approved or implemented. No legal/compliance certification is implied.

## All 38 controls reassessed for this revision

Decision date: 1 November 2026; reviewer Codex for stated local scope. Source binding is the
implementation revision recorded in this report's final checkpoint. Organizational owners
below are roles awaiting named appointments; no human approval is inferred. Historical
[29 October findings](COMPLIANCE_AUDIT_2026-10-29.md) are not treated as current verification.

| Control | Status | Current evidence / next action and owner |
| --- | --- | --- |
| GOV-01 | Unverified | No new custodian/operator/agent appointments or clinic-boundary contracts. Custodian/operator: name owners and incident lead. |
| GOV-02 | Unverified | No current legal applicability/commencement register approved here. Privacy/legal: verify current sources before real-data release. |
| GOV-03 | Unverified | Commercial/cross-border applicability unchanged and unapproved. Privacy/legal: record PIPEDA determination. |
| GOV-04 | Partial | New function is identity administration; no clinical inference. Product/clinical/legal: approve intended use. |
| GOV-05 | Partial | Synthetic fixtures, minimized reference and documented flow; complete signed PIA/TRA absent. Privacy/security: assess database identity-administration flow and residual risk. |
| PRIV-01 | Unverified | This feature does not establish custodian-specific bilingual notices/contacts. Custodian/privacy: verify published practices. |
| PRIV-02 | Partial | Independent operator attestation/reason, no registration consent auto-accepted. Privacy/clinical: approve actual joining terms and collection authority. |
| PRIV-03 | Partial | Current grant/clinic/identity checks and serialized pilot revocation; no consent/clinical bypass. Security/custodian: finish hosted restriction/worker acceptance. |
| PRIV-04 | Unverified | No minor/representative authority added. Clinical/custodian: approve supported populations and safe assisted process. |
| PRIV-05 | Unverified | Pilot history is not patient access/correction service. Privacy operations: verify intake/deadline/protected delivery process. |
| PRIV-06 | Unverified | No deletion; new identity/events/retry records retained. Records/privacy: approve retention, holds, backup/disposal schedule and restoration. |
| PRIV-07 | Unverified | No breach exercise or external notice action. Incident/privacy lead: approve/rehearse runbook. |
| AUTH-01 | Implemented (local changed scope) | Full signed JWT checks before callback/reference; missing authority denies, callback cannot accept raw provider tokens. Identity/security: verify final hosted configuration/MFA. |
| AUTH-02 | Partial | Exact clinic/identity/active grant, case sensitivity, no membership grant, stale replay/Inactive/current bearer tests. Security/custodian: complete hosted application and clinical boundaries. |
| AUTH-03 | Partial | Principal-bound UI cleanup; pilot revocation only additional gate. Identity/security: approve idle/absolute/MFA/lost-device and normal departure procedures. |
| AUTH-04 | Partial | New draft/reference stays in memory; no new token storage. Existing MSAL sessionStorage exception unresolved. Security: approve time-bounded exception or separate architecture remediation. |
| AUTH-05 | Partial | Same existing central admin grant/scope, no Graph/provider permission changes. Identity/operations: inventory actual deployed grants and DB lock/runtime privileges. |
| SEC-01 | Partial | Synthetic tests, private files/PDF/builds excluded; final artifact scan pending. Build/security: complete package and indicated history review. |
| SEC-02 | Partial | No transport relaxation; private DB/lock usage follows existing transport. Infrastructure: verify TLS/encryption/backups and rehearse restoration. |
| SEC-03 | Partial | New admin responses no-store; exact central host restriction. Operations: verify final hosted headers/cache/CORS/session behavior. |
| SEC-04 | Implemented (local changed scope) | Exact reference realm/fields, prepared SQL, bounded scalar validators, case-sensitive identity columns, safe React text. Security: independent review and hosted acceptance. |
| SEC-05 | Partial | 64-KiB bodies, tight fields, 50-row/history bounds, indexed cursor, five-second lock bound, duplicate/retry/concurrent tests. Operations/security: hosted throttling/DoS and volume assessment. |
| SEC-06 | Partial | Atomic decision events/retries, failure rollback, canonical redacted read/denial audit. DB/security/privacy: approve privileges, independent integrity/retention/monitoring. |
| SEC-07 | Partial | Generic errors, redacted logs, minimal own reference, no token bodies. Operations: verify actual proxies/provider/web logs and support handling. |
| SEC-08 | Partial | No new external endpoint/provider; documented existing DB/account metadata boundary. Privacy/vendor owner: complete actual vendor/retention/support/incident approvals. |
| SEC-09 | Partial | No sends, new message template or patient content; delivery gates preserved. Privacy/operations: approve actual sender/recipient/CASL evidence before enabling channels. |
| DEV-01 | Implemented (task scope) | Dedicated feature branch, existing work/PDF preserved, instructions/config/locks/migrations/docs reviewed. Maintainer: review/push/merge only within authority. |
| DEV-02 | Implemented (local scope) | Focused changes, no new any/ts-ignore/skips/dependency or suppressed failure; initial defects recorded. Maintainer/security: independent review. |
| DEV-03 | Partial | Locks unchanged; exact versions/audits above; 11 moderate build findings unresolved. Build/security: exposure decision/remediation and clean release restore. |
| DEV-04 | Partial | Real lint/types/build/browser/PHP/dual-engine checks recorded; hosted acceptance still separate. QA/release: finish final checkpoint and provider flows. |
| DEV-05 | Partial | EN/FR modal, labels, keyboard/unsaved guards, focus restoration and mobile checks. Accessibility/legal/product: full AT/contrast/zoom and applicability review. |
| DEV-06 | Partial | No polling/dependencies; bounded indexed queries and identity-scoped locks. Build/performance: assess existing chunks, clinic-lock contention and representative scale. |
| REL-01 | Partial | This dated 38-control ledger/file/test matrix; named human owners/decisions absent. Release owner: assign and retain evidence. |
| REL-02 | Partial | Local commands/results and failures recorded; dedicated suites explicitly required. QA: verify final counts and operational omissions. |
| REL-03 | Partial | Old hosted static evidence does not verify this addition; final new artifact/058/rollback rehearsal pending. Operations/release: complete exact source package and hosted synthetic acceptance. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; no failed/pending control waived by local tests. Accountable owners: resolve or approve lawful scoped internal exceptions. |
| REL-05 | Partial | Implementation authorized; new 058 not approved/applied, no feature flags/roles broadened live. Release/custodian/security/privacy/clinical: exact DDL approval after backup, deployment and acceptance/signatures. |
| REL-06 | Implemented (report scope) | File purposes, versions/flows, commands, findings, limits, owners and rollback recorded; final checkpoint to bind exact source. Release owner: confirm report. |

## Deployment and rollback implications

Prepare a fresh verified private database backup that includes the already applied 053–055
objects. Obtain exact approval for 058, apply its three CREATE TABLE statements once, and
verify structure before setting the new flag. Current settings have no static external
approvals to migrate; recheck before enabling. Preserve the two workforce entries, pilot
mode and disabled mail/SMS. Deploy matching reviewed API/central/shared assets, verify hashes
before HTML, then enable only the approved Wellnexa flow. Owner-reviewed joining terms/plan
are still required before the hosted registration/activation test. Keep LivinLively intake closed.

Rollback: pause the managed flow and restore matching private configuration/API/frontend,
preserving new tables, decisions, retries and audit/application/clinical history. Do not drop
tables or overwrite newer records with an old dump. Do not disable PILOT_MODE as a workaround.
The old external static list must not regrant a revoked identity. Database restoration and
live configuration rollback remain untested; release/operations owner must rehearse them.

RELEASE STATUS: NOT READY FOR RELEASE
