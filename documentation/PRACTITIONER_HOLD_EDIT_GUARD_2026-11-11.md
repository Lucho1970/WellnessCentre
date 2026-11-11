# Practitioner emergency-hold edit guard — 11 November 2026

**Scope:** `bugs/practitioner-hold-edit-guard`, based on review branch `eb484c5`; local only. `main` and production are unchanged.

## Finding and correction

An active emergency hold sets the practitioner account to `locked`. The ordinary Staff editor previously blocked only a change to `active`; it could set `inactive` or remove the practitioner role while the hold remained active. The practitioner editor had the same status gap. The dedicated restore action then could not find or restore the account, leaving an unresolved hold and an inconsistent operating state.

Both ordinary editors now reject **all** changes to an account with an active hold (HTTP 409) under the existing transaction/row lock. The hold/restore action remains the only current path for changing that state. The clinic consent lock on API requests and the target row locks serialize the tested access changes. This fix does not grant a permission, change clinical records or lift a hold.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/AdminService.php` | Deny both ordinary practitioner and Staff edits while an emergency hold is active. |
| `api/tests/integration/practitioner-emergency-access.php` | Assert Inactive and role-removal attempts fail while held and restore remains possible. |
| `documentation/PRACTITIONER_HOLD_EDIT_GUARD_2026-11-11.md` | Review finding, tests, operational limitation and control ledger. |
| `documentation/README.md` | Index the review. |

There is still **no safe final-disable disposition** in the hold workflow. The current release action restores `active`; an operator would then need a separate Inactive save. A later change must offer an atomic resolution that keeps the account Inactive, or an approved formal departure workflow. Do not use a brief reactivation as a departure procedure. Existing note custody, patient requests, retention and draft agreements remain separate release blockers.

No dependency/lockfile, schema, endpoint, consent wording, external flow, identity grant or configuration change. A code rollback would reintroduce the ability to strand held accounts. The test suite uses synthetic data only; hosted revocation and in-flight behavior remain unverified.

## Local evidence

With process-scoped `OPENSSL_CONF`, `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <portable-5.7.44> -IntegrationTest practitioner-emergency-access.php -Port 13401` passed 97 booking-access and 122 emergency-access checks. `./scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-11.4.8> -IntegrationTest practitioner-emergency-access.php -Port 13402` passed the same 97 and 122 checks. Local PHP 8.4.14 was used. `php -l` and staged diff checks remain the final precommit gate. Frontend builds and browser checks passed on the unchanged UI in the parent integrated checkpoint; this PHP-only slice did not rerun them. No hosted database or real patient record was used.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 11 November 2026. Status applies only to this branch. Named custodian/privacy, clinical, security/operations and release owners must date their decisions. Missing evidence is not proof of organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic hold responsibility drafted; custodian/legal confirm agency. |
| GOV-02 | Unverified | Privacy/legal refresh applicability register. |
| GOV-03 | Unverified | Privacy/legal assess commercial/cross-border processing. |
| GOV-04 | Partial | Administrative hold; clinical owner confirms use. |
| GOV-05 | Partial | State transition reviewed; inventory/PIA/TRA sign-off pending. |
| PRIV-01 | Unverified | Custodian checks notice and departure contact. |
| PRIV-02 | Partial | Hold prevents access; final-disable authority/process pending. |
| PRIV-03 | Partial | Local held-state and revocation tests; hosted race remains. |
| PRIV-04 | Unverified | Representative/minor process outside scope. |
| PRIV-05 | Missing | Patient request/correction process remains. |
| PRIV-06 | Missing | Retention, hold and departure disposition remain. |
| PRIV-07 | Unverified | Breach runbook not exercised. |
| AUTH-01 | Partial | Existing verified membership/authentication retained. |
| AUTH-02 | Partial | Held-state changes denied; full record inventory remains. |
| AUTH-03 | Partial | Local next-request denial; hosted sessions/in-flight remain. |
| AUTH-04 | Unverified | No browser token-storage change. |
| AUTH-05 | Unverified | No new grant; deployed permissions remain to review. |
| SEC-01 | Partial | No private files intended; final package/history scan pending. |
| SEC-02 | Unverified | Operations checks TLS, encryption, backup restore. |
| SEC-03 | Unverified | Hosted headers and shared-device cache remain. |
| SEC-04 | Partial | Fixed SQL predicate; security review remains. |
| SEC-05 | Partial | Existing body/request limits retained; load remains. |
| SEC-06 | Partial | Hold audit retained; monitoring/integrity remain. |
| SEC-07 | Partial | 409 is safe; hosted logs remain. |
| SEC-08 | Not applicable | No new endpoint/subprocessor. |
| SEC-09 | Not applicable | No notification behavior change. |
| DEV-01 | Partial | Dedicated fix branch; final commit/push pending. |
| DEV-02 | Partial | Focused guard and regression; human review pending. |
| DEV-03 | Unverified | No dependency change; release audits remain. |
| DEV-04 | Partial | Dual-engine SQL passed; broader/hosted checks remain. |
| DEV-05 | Not applicable | No UI change. |
| DEV-06 | Unverified | No volume/performance check. |
| REL-01 | Partial | Dated ledger; named decisions and final SHA pending. |
| REL-02 | Partial | Local SQL checkpoint; integrated parent tests linked. |
| REL-03 | Missing | No final artifact or hosted acceptance. |
| REL-04 | Missing | Final-disable and other gates remain. |
| REL-05 | Partial | No live/auth/schema change; approval later. |
| REL-06 | Partial | Files, tests, risk and rollback recorded; operational evidence pending. |

RELEASE STATUS: NOT READY FOR RELEASE
