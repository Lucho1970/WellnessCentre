# Practitioner historical financial access — 10 November 2026

**Decision:** The owner selected clinic review only after a practitioner care assignment ends. The clinic retains historical appointments, receipts and fee details; a former practitioner requests any needed copy through the clinic. This local implementation is on `bugs/practitioner-historical-financial-access`, based on `014b1ff`. The governance parent and migrations 062–064 remain unmerged and undeployed. Do not deploy this branch alone.

## Implementation and boundaries

`SessionReceiptService` now checks active care assignment, active practitioner/staff status and the persisted practitioner role for appointment receipt context, practitioner receipt list, PDF download, profile maintenance, issue and void. `OnsiteFeeService` applies the same check to fee context and waiver. The shared care predicate also rejects a stale identity context after the practitioner role is removed or the database user ceases to be staff. A missing assignment returns a concealed not-found result for a single record, or an empty practitioner list. Client access to their own issued receipt and clinic administrator access are retained. Existing data is not deleted and no receipt is voided by ending care.

Financial writes take the same clinic consent lock as care-assignment changes, from authority check through transaction commit and audit. A pending assignment change cannot race receipt issuance, voiding, profile maintenance or a travel-fee waiver. A missing lock fails closed with a retriable 503 before mutation. Reads check current assignment at query time; already rendered or downloaded copies cannot be recalled. Hosted concurrency and session behavior still need acceptance.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/SessionReceiptService.php` | Current-care filter on appointment and receipt queries; serialize financial writes with assignment changes. |
| `api/src/Service/OnsiteFeeService.php` | Current-care filter on fee reads/writes and lock on waiver. |
| `api/src/Service/BookingClientAccess.php` | Require a current persisted practitioner role in the shared care predicate. |
| `api/tests/integration/recurring-bookings.php` | Explicit synthetic care assignment and practitioner role in the shared disposable fixture. |
| `api/tests/integration/session-receipts.php` | Revocation, client/clinic continuity and competing-lock checks. |
| `api/tests/integration/onsite-distance-fees.php` | Fee denial, administrator continuity and competing-lock checks. |
| `documentation/PRACTITIONER_HISTORICAL_FINANCIAL_ACCESS_2026-11-10.md` | Decision, evidence and release ledger. |
| `documentation/README.md` | Documentation index. |

No dependency/lockfile version, schema, role/grant, consent wording, endpoint, provider or configuration change. The data flow is narrower: former practitioners no longer receive historical financial details through these routes. Local runtime: PHP 8.4.14 and disposable MariaDB 11.4.8; hosted versions are unverified.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 10 November 2026. Status applies only to this slice. Custodian/privacy, clinical, security/operations and release owners must make dated review decisions. Missing local evidence is not a finding that an organization is noncompliant.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Owner approved clinic review after care ends; legal review of agency/custody remains. |
| GOV-02 | Unverified | Privacy/legal reviewer refreshes applicable Ontario and profession rules at release. |
| GOV-03 | Unverified | Privacy/legal reviewer assesses commercial and cross-border data. |
| GOV-04 | Partial | This is administrative record access; clinical owner confirms intended use. |
| GOV-05 | Partial | Financial flow narrowed; owner updates PIA, TRA and inventory. |
| PRIV-01 | Unverified | Custodian verifies site notice describes requests and clinic retention. |
| PRIV-02 | Partial | Former-practitioner disclosure removed; lawful practitioner-copy process needs approval. |
| PRIV-03 | Partial | Current-care checks and mutation lock locally tested; hosted and other routes remain. |
| PRIV-04 | Unverified | Representative process outside this change. |
| PRIV-05 | Missing | Clinic needs tracked access/correction and former-practitioner request process. |
| PRIV-06 | Missing | Profession-specific retention, hold and disposal schedule outstanding. |
| PRIV-07 | Unverified | Breach runbook not exercised here. |
| AUTH-01 | Partial | Existing identity validation unchanged; hosted realm verification remains. |
| AUTH-02 | Partial | Client, practitioner, stale-role, stale-account-type and clinic scopes locally tested; full route inventory remains. |
| AUTH-03 | Partial | Current database authority checked; hosted session/revocation remains. |
| AUTH-04 | Unverified | No browser credential storage change; release exception review remains. |
| AUTH-05 | Unverified | No grant changed; actual deployed permissions remain to be inventoried. |
| SEC-01 | Partial | No secret or dump added; final artifact and historical exposure scans remain. |
| SEC-02 | Unverified | Transport/storage/backup controls are operations checks. |
| SEC-03 | Unverified | Hosted headers and cache behavior remain. |
| SEC-04 | Partial | Fixed SQL expressions and bound values; human security review remains. |
| SEC-05 | Partial | Existing page and PDF limits retained; representative load remains. |
| SEC-06 | Partial | Existing issue/void/download/waiver audit retained; audit outage and monitoring remain. |
| SEC-07 | Partial | Not-found/503 responses avoid record details; hosted diagnostics remain. |
| SEC-08 | Not applicable | No new external endpoint or subprocessor. |
| SEC-09 | Not applicable | No new email, SMS or marketing flow. |
| DEV-01 | Partial | Dedicated branch and affected files reviewed; commit/push follows this checkpoint. |
| DEV-02 | Partial | Focused query and lock change; independent human review remains. |
| DEV-03 | Unverified | No dependency change; clean install/audits remain release checks. |
| DEV-04 | Partial | Local SQL/PHP evidence below; broader and hosted checks remain. |
| DEV-05 | Not applicable | No UI change in this slice; accessible clinic request workflow remains future work. |
| DEV-06 | Unverified | Existing care index used; realistic receipt volume and query plan remain. |
| REL-01 | Partial | Dated ledger here; named owner decisions and final SHA pending. |
| REL-02 | Partial | Changed-surface local checkpoint passed; broader release checks remain. |
| REL-03 | Missing | No package or hosted synthetic acceptance. |
| REL-04 | Missing | Parent migrations, operational requests, hosted and owner gates remain. |
| REL-05 | Partial | No production/schema/grant change; separate approval needed for deployment. |
| REL-06 | Partial | Changed files, flows, risks and rollback recorded here; final report after validation. |

## Validation and release implications

`php -l` passed for all six changed PHP files; `php api/tests/session-receipts.php` passed 20 policy/validation checks. `git diff --check` passed and an added-line secret/endpoint keyword scan found no values. With disposable MariaDB 11.4.8, `./scripts/test-recurring-sql.ps1 -MariaDbDirectory '.tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64' -IntegrationTest booking-client-access.php -Port 13384` passed 92 checks; `-IntegrationTest onsite-distance-fees.php -Port 13386` passed 83 checks; and the final `-IntegrationTest session-receipts.php -Port 13387` passed 44 checks. The inherited recurring-booking fixture passed 34 checks in each receipt/fee run. The new checks cover post-assignment denial, client/clinic continuity, competing care locks, stale role and stale account type. An initial fee run failed because its older test expected `receipt_not_found`; the new query correctly concealed the appointment earlier with `appointment_not_found`. The expectation was corrected, and both later fee runs passed.

No hosted database or real patient record was used. Frontend build/browser tests, exact-lock dependency audits, MySQL 5.7, hosted identities, accessibility and production rollback rehearsal were not run for this backend-only slice. These omissions do not constitute hosted acceptance.

Rollback of this code alone restores former-practitioner access to historical receipts and fees. Any rollback needs the custodian's privacy decision, not an automatic switch. The clinic request process and migration/hosted acceptance remain required before real-data release.

RELEASE STATUS: NOT READY FOR RELEASE
