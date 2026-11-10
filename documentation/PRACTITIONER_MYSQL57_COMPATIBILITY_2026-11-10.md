# Practitioner care SQL on MySQL 5.7 — 10 November 2026

**Scope:** `bugs/practitioner-mysql57-join-order`, based on `acd8314`; local only. The governance/access branch stack and migrations 062–064 remain unmerged and undeployed. This branch must travel with its ancestors.

## Finding and correction

The first disposable MySQL 5.7 care-record run failed while searching booking clients: `BookingClientAccess::condition` referred to the outer client alias in a nested join `ON` clause. The first rerun reached appointment listing and failed for the same pattern in `careCondition`. MariaDB 11.4 had accepted both. The predicates now put the outer-client comparison in the subquery `WHERE` clause. Practitioner identity, active staff/profile, persisted role, current care assignment, clinic and patient restrictions are unchanged. The MySQL 5.7 runner now explicitly allows the existing care-record and emergency-hold suites; it still creates a disposable local database on an unused loopback port and does not read hosted credentials.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/BookingClientAccess.php` | Place correlated client comparisons in MySQL 5.7-compatible subquery clauses. |
| `scripts/test-mysql57-sql.ps1` | Allow two existing governance suites on the hosted-version-compatible test engine. |
| `documentation/PRACTITIONER_MYSQL57_COMPATIBILITY_2026-11-10.md` | Record failures, reruns, scope and release gates. |
| `documentation/README.md` | Index this review. |

No dependency/lockfile, schema, API, identity grant, consent, endpoint, provider or configuration change. Authorization is intended to be identical; the negative suite is the evidence for the tested cases. A rollback to the earlier query would reintroduce MySQL 5.7 failures. PHP 8.4.14, MySQL 5.7.44 and MariaDB 11.4.8 were local runtimes; hosted code/configuration was not changed.

## Local validation

With process-scoped `OPENSSL_CONF`, `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <portable-5.7.44> -IntegrationTest clinic-care-records.php -Port 13393` failed at the first query, and the port-13394 rerun failed at the second. After both query corrections, port 13395 passed **97 booking-access** and **126 care-record** checks. The emergency suite on port 13396 passed **97 booking-access** and **118 emergency-hold** checks. `./scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-11.4.8> -IntegrationTest clinic-care-records.php -Port 13397` passed **97 booking-access** and **126 care-record** checks. All database runs used disposable synthetic schemas, with local logs retained by their runners. `php -l api/src/Service/BookingClientAccess.php`, PowerShell parse of the changed runner and `git diff --check` passed. No fresh dependency restore/audit, full frontend rebuild/browser suite, hosted database, identity test, provider send, MySQL query plan at representative volume or rollback rehearsal was performed on this backend compatibility slice; parent branches record their unchanged-UI checks.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 10 November 2026. These statuses cover this slice only. Named custodian/privacy, clinical, security/operations and release owners still need dated decisions against the final revision. Missing evidence is not proof of organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Existing clinic and role predicates retained; owner confirms custody/agency. |
| GOV-02 | Unverified | Privacy/legal updates rule and profession register. |
| GOV-03 | Unverified | Privacy/legal assesses commercial/cross-border flows. |
| GOV-04 | Partial | Administrative care-record access; clinical owner confirms use. |
| GOV-05 | Partial | Flow finding recorded; privacy/security updates inventory, PIA and TRA. |
| PRIV-01 | Unverified | Custodian checks clinic notice/contact. |
| PRIV-02 | Partial | Existing purpose/consent predicate retained; owner reviews authority. |
| PRIV-03 | Partial | Negative local care/access tests pass; hosted races remain. |
| PRIV-04 | Unverified | Representative/minor process outside this slice. |
| PRIV-05 | Missing | Patient request/correction and reviewed copy workflow remain. |
| PRIV-06 | Missing | Profession-specific retention/hold/disposal remain. |
| PRIV-07 | Unverified | Breach runbook not exercised. |
| AUTH-01 | Partial | Existing validated identity context retained; hosted realm check remains. |
| AUTH-02 | Partial | Current care/role/clinic predicates tested; full inventory remains. |
| AUTH-03 | Partial | Local revocation cases pass; hosted sessions remain. |
| AUTH-04 | Unverified | No token-storage change; exception review remains. |
| AUTH-05 | Unverified | No grant change; deployed grant inventory remains. |
| SEC-01 | Partial | No private files intended; final artifact/history scan remains. |
| SEC-02 | Unverified | Operations checks transport, encryption and backup restore. |
| SEC-03 | Unverified | Hosted headers/cache remain. |
| SEC-04 | Partial | Fixed SQL fragment and bound values; security review remains. |
| SEC-05 | Partial | Existing pagination/bounds retained; volume testing remains. |
| SEC-06 | Partial | Existing access audit retained; hosted integrity/review remains. |
| SEC-07 | Partial | Safe API error path retained; hosted diagnostics remain. |
| SEC-08 | Not applicable | No new external endpoint or subprocessor. |
| SEC-09 | Not applicable | No notification behavior changed. |
| DEV-01 | Partial | Dedicated fix branch; commit/push after checks. |
| DEV-02 | Partial | Focused SQL correction and regression; human review remains. |
| DEV-03 | Unverified | No dependency change; release restore/audit remains. |
| DEV-04 | Partial | MySQL and MariaDB checks above; broader/hosted checks remain. |
| DEV-05 | Not applicable | No UI change. |
| DEV-06 | Unverified | Query-plan/volume comparison remains. |
| REL-01 | Partial | Dated ledger; named owner decision and final SHA remain. |
| REL-02 | Partial | Disposable dual-engine SQL checkpoint recorded. |
| REL-03 | Missing | No artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Governance and real-PHI gates remain. |
| REL-05 | Partial | No production/schema/auth change; distinct approval later. |
| REL-06 | Partial | Scope, exact tests, failures and rollback recorded; operational evidence remains. |

RELEASE STATUS: NOT READY FOR RELEASE
