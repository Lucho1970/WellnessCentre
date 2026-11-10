# Practitioner stale-role integration — 10 November 2026

**Scope:** `bugs/practitioner-stale-role-boundaries`, based on `deae127`; local only. The five dependent governance/access branches and migrations 062–064 remain unmerged and undeployed. This branch must not be deployed alone.

## Finding and change

The shared current-care predicate already rechecks the persisted practitioner role for appointment and receipt paths. Integration review found older client-directory, booking-contact, form and invitation paths that still relied on a role in the signed-in context. `StaffNotificationRecipient` also checked an active profile, care assignment and recipient preference but did not independently recheck the persisted practitioner role before provider dispatch. A cached context or queued message could therefore outlive a role removal if other rows remained active.

Those paths now require the current database role, active local staff account, active practitioner profile and care assignment. The email/SMS recipient check runs under the worker's existing clinic consent lock before provider dispatch. The stored job is canceled on role removal; it does not send or reserve new SMS spend. An administrator or client keeps only their separately authorized scope. No care assignment, role, consent or clinical record is changed automatically.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/BookingClientAccess.php` | Recheck persisted practitioner role for saved booking contacts. |
| `api/src/Service/ClientService.php` | Recheck current staff type and role for practitioner client directory. |
| `api/src/Service/ClientFormsService.php` | Recheck current staff type and role for practitioner form routes. |
| `api/src/Service/CustomerOnboarding.php` | Recheck current staff type and role for practitioner client invitations. |
| `api/src/Service/StaffNotificationRecipient.php` | Recheck persisted role before queued staff email/SMS delivery. |
| `api/tests/integration/booking-client-access.php` | Stale-role directory, booking, invitation and appointment denial. |
| `api/tests/integration/client-forms.php` | Stale-role form denial and explicit synthetic fixture roles. |
| `api/tests/integration/automated-communications.php` | Queued email canceled after role removal. |
| `documentation/PRACTITIONER_STALE_ROLE_INTEGRATION_2026-11-10.md` | Finding, evidence and release ledger. |
| `documentation/README.md` | Index this review. |

No dependency/lockfile version, schema, endpoint, permission grant, consent wording, provider or configuration change. Data flow is narrower: revoked practitioners lose contact/form/invitation access and queued messages stop before transport. A code rollback would restore those stale-role gaps and needs a privacy/security decision. Local PHP 8.4.14 and disposable MariaDB 11.4.8 were used; hosted versions and grants remain unverified.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 10 November 2026. Status applies only to this branch. Named custodian/privacy, clinical, security/operations and release owners must date their decisions. Missing evidence does not prove organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic-scoped roles retained; legal/owner agency and tenant review remains. |
| GOV-02 | Unverified | Privacy/legal updates current rule/profession register at release. |
| GOV-03 | Unverified | Privacy/legal assesses commercial and cross-border processing. |
| GOV-04 | Partial | Administrative record/communication routing; clinical owner confirms use. |
| GOV-05 | Partial | This flow review informs inventory/PIA/TRA; owner updates them. |
| PRIV-01 | Unverified | Custodian reviews actual notice and copy process. |
| PRIV-02 | Partial | Less access/disclosure after role removal; purpose/recipient owner review remains. |
| PRIV-03 | Partial | Persisted-role checks added at reviewed boundaries; hosted concurrency remains. |
| PRIV-04 | Unverified | Minor/representative process outside scope. |
| PRIV-05 | Missing | Patient rights and reviewed practitioner-copy process remain. |
| PRIV-06 | Missing | Profession-specific retention/hold/disposal remain. |
| PRIV-07 | Unverified | Breach runbook not exercised here. |
| AUTH-01 | Partial | Existing identity validation retained; hosted realm verification remains. |
| AUTH-02 | Partial | Stale role checked in changed routes and worker; full clinic/record inventory remains. |
| AUTH-03 | Partial | Local revocation tested; hosted session and in-flight acceptance remain. |
| AUTH-04 | Unverified | No browser token-storage change; exception review remains. |
| AUTH-05 | Unverified | No grant changed; actual deployed grants remain to review. |
| SEC-01 | Partial | No private file intended; final artifact/historical scan remains. |
| SEC-02 | Unverified | Operations checks TLS, encryption, backups and restore. |
| SEC-03 | Unverified | Hosted headers and cache tests remain. |
| SEC-04 | Partial | Bound query values and fixed role code; security review remains. |
| SEC-05 | Partial | Existing page/worker bounds retained; realistic volume remains. |
| SEC-06 | Partial | Existing read/denial/send audit retained; monitoring/integrity remain. |
| SEC-07 | Partial | Existing safe denial/cancellation behavior retained; hosted logs remain. |
| SEC-08 | Not applicable | No new external provider or endpoint. |
| SEC-09 | Partial | Worker cancels stale recipient before mail/SMS; hosted send review remains. |
| DEV-01 | Partial | Dedicated branch and affected configs/migrations reviewed; commit/push recorded with final revision. |
| DEV-02 | Partial | Focused authorization checks; human code review remains. |
| DEV-03 | Unverified | No dependency change; clean install/audit remain release checks. |
| DEV-04 | Partial | Local results below; broader and hosted checks remain. |
| DEV-05 | Not applicable | No UI change in this slice. |
| DEV-06 | Unverified | Existing role/care indexes used; query plans and load remain. |
| REL-01 | Partial | Dated ledger here; named owner decision and final SHA remain release evidence. |
| REL-02 | Partial | Changed-surface SQL/PHP checkpoint below. |
| REL-03 | Missing | No artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Parent migrations, operational and hosted gates remain. |
| REL-05 | Partial | No production/schema/grant action; distinct approval later. |
| REL-06 | Partial | Changed files, flows, risk and local evidence recorded; hosted and owner evidence remains. |

## Local evidence and remaining gates

`php -l` passed for all eight changed PHP files and `git diff --check` passed. With process-scoped `OPENSSL_CONF`, `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest <suite> -Port <disposable-port>` passed for `booking-client-access.php` (97 checks), `client-forms.php` (115 checks plus 34 inherited recurring checks), and `automated-communications.php` (36 inherited subscription/SMS checks, 122 practitioner-application checks and 60 communication checks). The first communications run's output was truncated by the command tool; the captured rerun on port 13392 exited 0 with the counts above. The script retained only its local synthetic database and logs under `.tmp`.

Frontend build/browser checks were completed on the unchanged UI in parent `deae127`, not repeated for this backend-only slice. No hosted database or real patient record was used. Clean dependency install/audits, MySQL 5.7, hosted identity/provider behavior, production rollback and manual accessibility remain unverified.

RELEASE STATUS: NOT READY FOR RELEASE
