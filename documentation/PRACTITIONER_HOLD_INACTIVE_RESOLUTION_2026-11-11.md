# Resolve a practitioner hold without reactivation — 11 November 2026

**Scope:** `feature/practitioner-hold-inactive-resolution`, based on `f8e1a66`; local only. This is a narrow resolution of the operational blocker found in the emergency-hold review. `main`, hosted accounts and production settings are unchanged.

## Behavior

A Super Admin closing an emergency hold must choose `active` or `inactive`. The API rejects a missing/invalid outcome and rejects an outcome when creating a new hold. Both resolutions require the existing held, locked practitioner and the current Super Admin authority. In one transaction the API changes the account status, clears the active hold and writes a distinct audit action. An audit failure rolls back the status and hold together. The Inactive outcome never makes the account Active. Clinic admins and office users can still place holds but cannot resolve them.

The Staff access and practitioner editors remain blocked while a hold is active, as recorded in [the guard review](PRACTITIONER_HOLD_EDIT_GUARD_2026-11-11.md). Once resolved to Inactive, deliberate future reactivation is possible through the existing authorized Staff workflow; this feature does not create a permanent termination state. It also does **not** cancel bookings, distribute or transfer records, notify clients, complete a practitioner departure, or set a retention schedule. Those actions need a separately approved workflow. Policy document drafts remain unpublished.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/AdminService.php` | Validate explicit resolution; atomically restore Active or remain Inactive with a distinct audit action. |
| `api/tests/integration/practitioner-emergency-access.php` | Test both resolutions, authorization, missing outcome and audit-failure rollback. |
| `Frontend/src/admin/PractitionerSafety.tsx` | Require an explicit outcome in the Super Admin hold dialog and show the resulting state. |
| `Frontend/src/i18n/practitionerSafety.ts` | English/French outcome, explanation and confirmation text. |
| `Frontend/tests/record-governance.spec.ts` | Browser exercise of Active and Inactive choices, disabled confirmation before selection, and French mobile flow. |
| `documentation/PRACTITIONER_HOLD_INACTIVE_RESOLUTION_2026-11-11.md` | Decision, validation and control ledger. |
| `documentation/PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md` | Update the release gate for this resolved local blocker. |
| `documentation/README.md` | Index the new evidence. |

No dependency/lockfile, schema, new endpoint, identity grant, external service, consent text or hosted configuration change. The existing POST request now requires `resolution` when `hold=false`; a stale client receives 422 and cannot silently reactivate a practitioner. A code rollback after a new client is deployed must use a matching prior client/API pair and preserve hold/audit rows. No production artifact or migration was prepared in this branch.

## Local evidence

On PHP 8.4.14, disposable MySQL 5.7.44 and MariaDB 11.4.8 each passed **97 inherited booking-access** and **127 emergency-access** checks using `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <portable-5.7.44> -IntegrationTest practitioner-emergency-access.php -Port 13406` and `./scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-11.4.8> -IntegrationTest practitioner-emergency-access.php -Port 13407` with process-scoped `OPENSSL_CONF`. `Frontend/npm run build` passed lint, content/i18n, TypeScript and all three builds. `npx playwright test tests/record-governance.spec.ts --workers=1 --reporter=line` passed **7/7**, including both resolutions and French mobile. `npx playwright test --config playwright.build.config.ts --workers=1 --reporter=line` passed **16/16**. Existing large-bundle and mixed-import warnings remain. Wellnexa public-site browser tests and dedicated application-admin tests were last run on the unchanged surfaces in the parent integrated checkpoint; they were not repeated for this focused dialog change. PHP syntax, staged diff and added-line secret checks are precommit gates. No hosted database, real identity, real record, provider or manual screen-reader test was used.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 11 November 2026. Status applies only to this branch. Named custodian/privacy, clinical, security/operations and release owners must record dated decisions against the final revision. Missing repository evidence does not prove organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic hold responsibility drafted; custodian/legal confirm agency. |
| GOV-02 | Unverified | Privacy/legal refresh rule/profession register. |
| GOV-03 | Unverified | Privacy/legal assess commercial/cross-border processing. |
| GOV-04 | Partial | Administrative resolution; clinical owner confirms departure implications. |
| GOV-05 | Partial | Status/audit flow documented; inventory/PIA/TRA sign-off pending. |
| PRIV-01 | Unverified | Custodian reviews actual notice and departure contact. |
| PRIV-02 | Partial | Explicit resolution avoids unintended access; formal authority remains. |
| PRIV-03 | Partial | Local atomic revocation tests; hosted/in-flight proof pending. |
| PRIV-04 | Unverified | Representative/minor process outside scope. |
| PRIV-05 | Missing | Patient request/correction and practitioner-copy process remain. |
| PRIV-06 | Missing | Retention, legal hold and formal departure remain. |
| PRIV-07 | Unverified | Breach runbook not exercised. |
| AUTH-01 | Partial | Existing verified membership/authentication retained. |
| AUTH-02 | Partial | Super Admin and current target checked; full route review remains. |
| AUTH-03 | Partial | No Active interval in local Inactive resolution; hosted sessions remain. |
| AUTH-04 | Unverified | No browser token-storage change. |
| AUTH-05 | Unverified | No new grant; deployed grants remain. |
| SEC-01 | Partial | No private files intended; final artifact/history scan pending. |
| SEC-02 | Unverified | Operations checks TLS, encryption and backup restore. |
| SEC-03 | Unverified | Hosted headers/cache remain. |
| SEC-04 | Partial | Server allowlists outcome; human review remains. |
| SEC-05 | Partial | Fixed-size request enum; volume/abuse review remains. |
| SEC-06 | Partial | Distinct audit and rollback on audit failure pass locally; hosted integrity remains. |
| SEC-07 | Partial | Safe 422/403/409 errors; hosted diagnostics remain. |
| SEC-08 | Not applicable | No new endpoint/subprocessor. |
| SEC-09 | Not applicable | No notification behavior change. |
| DEV-01 | Partial | Dedicated feature branch; final commit/push pending. |
| DEV-02 | Partial | Focused explicit state transition and tests; human review pending. |
| DEV-03 | Unverified | No dependency change; release restore/audits remain. |
| DEV-04 | Partial | Dual-engine SQL and browser/build checks; hosted acceptance remains. |
| DEV-05 | Partial | Bilingual labelled dialog; manual keyboard/reader/zoom review remains. |
| DEV-06 | Unverified | No representative load/bundle analysis beyond existing warnings. |
| REL-01 | Partial | Dated ledger; named decisions/final SHA pending. |
| REL-02 | Partial | Local checkpoint above; full integrated suites remain. |
| REL-03 | Missing | No artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Formal departure and other release gates remain. |
| REL-05 | Partial | No live/schema/auth change; approvals later. |
| REL-06 | Partial | Scope, tests, risks and rollback recorded; operational evidence pending. |

RELEASE STATUS: NOT READY FOR RELEASE
