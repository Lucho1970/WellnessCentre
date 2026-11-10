# Care-assignment review workflow — 10 November 2026

**Scope:** `feature/care-assignment-review-workflow`, based on `6840746`; local only. The owner selected clinic review only for practitioner copies after care ends. The four dependent governance/access branches and migrations 062–064 remain unmerged and undeployed. Do not deploy this branch by itself.

## Operation

Clinic Super Admin and Clinic Admin already have the care-team screen. A grant or end action now requires a structured reason and an explicit confirmation that the operator reviewed the current care relationship. Grant reasons are starting care, transfer, temporary coverage and access correction. End reasons are care completed, transfer, temporary coverage, access correction and practitioner departure. The API validates the reason against the action; the existing audit record stores the code, actor, clinic, client, practitioner, action and time without free-text clinical detail. An unchanged/stale decision remains a 409. The client, practitioner, active-account, role and patient-restriction checks remain under the clinic consent lock.

The English and French dialog now explains the broader access effect: assignment permits current-care record routes subject to restrictions; ending assignment removes practitioner contact, appointment, shared-note and financial-detail access while preserving clinic records. The save button is unavailable until reason and confirmation are selected. Tests cover both directions.

This is an operator attestation, **not independent proof of clinical review**. The clinic must define who confirms current involvement, how transfers and temporary coverage are requested, and how a former practitioner requests a reviewed copy. Existing assignments are not auto-migrated or backfilled. The full departure/retention workflow is still open.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/ClinicCareRecordService.php` | Validate action-specific reason and review confirmation; audit reason code. |
| `api/tests/integration/clinic-care-records.php` | Reject missing/invalid review inputs; verify reason audit and normal grant/end flow. |
| `Frontend/src/clients/ClientCareTeam.tsx` | Require reason and review confirmation; explain access effect. |
| `Frontend/src/i18n/clinicRecords.ts` | English/French dialog and reason labels. |
| `Frontend/tests/record-governance.spec.ts` | Exercise disabled save and both action payloads. |
| `documentation/CARE_ASSIGNMENT_REVIEW_WORKFLOW_2026-11-10.md` | Decision, limitations and evidence ledger. |
| `documentation/README.md` | Index this follow-up. |

No dependency/lockfile version, schema, identity grant, new endpoint, external provider, consent text or hosted configuration change. The API request body adds required reason/review fields; old clients must use the matching UI. No new PHI leaves the clinic. Local Node 22.22.1, npm 11.19.0, PHP 8.4.14 and MariaDB 11.4.8 are checked runtimes; hosted versions remain unverified.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 10 November 2026. Each status applies only to this branch. Named custodian/privacy, clinical, security/operations and release owners must record dated decisions. Missing source evidence does not prove organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic assignment authority exists; owner/legal confirm agency and transfer responsibility. |
| GOV-02 | Unverified | Privacy/legal refresh current Ontario and profession rules at release. |
| GOV-03 | Unverified | Privacy/legal assess commercial and cross-border data. |
| GOV-04 | Partial | Administrative access workflow; clinical owner confirms intended use. |
| GOV-05 | Partial | Care flow documented here; update PIA, TRA and inventory with named owners. |
| PRIV-01 | Unverified | Custodian reviews published privacy notice against review/copy process. |
| PRIV-02 | Partial | Confirmed current-care purpose and existing restriction check; clinical review process not evidenced. |
| PRIV-03 | Partial | Existing lock and role/restriction checks retained; hosted acceptance remains. |
| PRIV-04 | Unverified | Representative/minor authority outside this slice. |
| PRIV-05 | Missing | Former-practitioner copy and patient access/correction process need tracking. |
| PRIV-06 | Missing | Profession-specific retention/holds/disposal remain. |
| PRIV-07 | Unverified | Breach runbook not exercised here. |
| AUTH-01 | Partial | Existing verified identity context retained; hosted realm check outstanding. |
| AUTH-02 | Partial | Existing clinic/admin/target/consent checks retained; full route review remains. |
| AUTH-03 | Partial | Assignment revocation tested locally; hosted sessions still need acceptance. |
| AUTH-04 | Unverified | No token storage change; release exception review remains. |
| AUTH-05 | Unverified | No grant changed; deployed permissions remain to be inventoried. |
| SEC-01 | Partial | No secret/dump intended; final artifact and historical scan remain. |
| SEC-02 | Unverified | Operations verifies transport, storage and backups. |
| SEC-03 | Unverified | Hosted headers, cache and device tests remain. |
| SEC-04 | Partial | Server allowlists reason codes; no new unsafe rendering; security review remains. |
| SEC-05 | Partial | Fixed-size enum body and existing request bounds; load/race volume remain. |
| SEC-06 | Partial | Actor/action/target/reason audit verified locally; audit monitoring/retention remain. |
| SEC-07 | Partial | Existing safe error responses retained; hosted diagnostics remain. |
| SEC-08 | Not applicable | No external endpoint/subprocessor. |
| SEC-09 | Not applicable | No communication delivery change. |
| DEV-01 | Partial | Dedicated branch and affected configs/migrations reviewed; commit/push follows checkpoint. |
| DEV-02 | Partial | Focused, typed UI and enum validation; human review remains. |
| DEV-03 | Unverified | No dependency change; clean install/audits remain release gates. |
| DEV-04 | Partial | Local results below; hosted and omitted suites remain. |
| DEV-05 | Partial | Bilingual labels and browser interaction checked; manual AT/zoom/French audit remains. |
| DEV-06 | Unverified | No query/index change; representative volume and bundle warning review remain. |
| REL-01 | Partial | Dated ledger here; named sign-offs and final SHA pending. |
| REL-02 | Partial | Changed-surface local checkpoint passed; broader release checks remain. |
| REL-03 | Missing | No release artifact or hosted acceptance. |
| REL-04 | Missing | Clinical review process, parent migrations and hosted gates remain. |
| REL-05 | Partial | No production/schema/grant action; distinct deployment approval later. |
| REL-06 | Partial | Changed files, flows, risks and rollback recorded; final result after validation. |

## Validation and release limits

`npm run build` passed ESLint, content, 2,671 bilingual keys, TypeScript and public/portal/application-admin production builds. `npx playwright test tests/record-governance.spec.ts --workers=1 --reporter=line` passed 4/4 and `npx playwright test --config playwright.build.config.ts --workers=1 --reporter=line` passed 16/16. Existing large-chunk and mixed-import warnings remain. `php -l` passed for both changed PHP files. The disposable `./scripts/test-recurring-sql.ps1 -MariaDbDirectory '.tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64' -IntegrationTest clinic-care-records.php -Port 13388` passed 121 clinic-care checks and 92 nested booking-access checks. `git diff --check` passed. No hosted database or patient record was used. Clean dependency install/audit, MySQL 5.7, hosted identities, manual accessibility and operational rollback remain untested.

Rolling back the API without the UI would reject assignment saves; rolling back the UI without the API would submit insufficient fields. Restore both together. A code rollback would remove structured reasons and review confirmation, and would not erase prior audit history. Production migration/deployment and real-PHI release need separate owner decisions.

RELEASE STATUS: NOT READY FOR RELEASE
