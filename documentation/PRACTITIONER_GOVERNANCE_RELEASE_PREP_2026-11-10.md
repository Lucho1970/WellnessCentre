# Wellnexa practitioner governance release preparation — 10 November 2026

**Scope:** `feature/practitioner-governance-release-prep`, based on `212fd2794d51c2f28b495fb4f44492c6483a7cce`. This is a review plan, not a deployment instruction or release approval. `main` and production are unchanged. The seven stacked implementation/fix branches from `feature/wellnexa-record-governance` through `bugs/practitioner-mysql57-join-order` are unmerged; they must be reviewed and integrated as one compatible change. The accepted operating rule is **clinic review only** for any practitioner request for historical appointment/receipt information after a care assignment ends.

## Rehearsal evidence and current blockers

Migrations 062, 063 and 064 were applied in order by `api/tests/integration/clinic-care-records.php` to a synthetic reconstructed pre-migration schema. On MySQL 5.7.44, the final run passed 97 booking-access and 126 care-record checks; the emergency-hold suite passed 97 booking-access and 118 hold checks. On MariaDB 11.4.8, the final care run passed 97 and 126 checks. The initial MySQL runs exposed two correlated-query compatibility failures; `212fd27` corrected them and the reruns passed. These tests do not inspect the hosted schema, existing note data, database grants or a restored copy of the live database. See [the compatibility report](PRACTITIONER_MYSQL57_COMPATIBILITY_2026-11-10.md) and preceding branch reports for exact commands and limitations.

The following are release blockers: actual hosted schema/migration state unknown; fresh backup and restore unverified; existing `practitioner_client_notes` disposition not reviewed; clinic/operator access and runtime DB grants unverified; draft bilingual practitioner agreements lack custodian/privacy/legal and clinical approval; no executed-signature method is approved; patient rights, retention/departure and real-PHI gates remain incomplete; broad hosted synthetic acceptance and rollback have not occurred. Approval to implement or to apply earlier migrations 053–055 does not approve 062–064 or deployment.

## Exact proposed change set

| Order | File | SHA-256 | Effect requiring review |
| --- | --- | --- | --- |
| 1 | `api/database/migrations/062_practitioner_emergency_access.sql` | `e666be9aa1cb076f592f5a7b4083e2d0171e9c45e9490c2dc040a8f67f19cda6` | Adds emergency-hold table and one permission code; grants it to nobody. |
| 2 | `api/database/migrations/063_clinic_care_notes.sql` | `4e76c6949d504a82c054c1840f4f74ea96b698ea087f5bc7df27c7e29354aa4c` | Adds care assignments; adds note visibility, retry key and indexes; existing notes default to author visibility. |
| 3 | `api/database/migrations/064_practitioner_documents.sql` | `f8679fb977a69f3c359398e10fc2d082038c14d1959c671148d916595697187f` | Adds document-version and acceptance tables; publishes no document. |

The application deployment must use a single reviewed source revision that includes all stacked access fixes and the matching React portal. The final artifact, source SHA, lockfile hashes, build ID, runtime versions and manifest must be recorded **after** review/integration; these three migration hashes alone do not establish artifact provenance. No new external provider, identity grant, payment, SMS or email flow is proposed. The new office hold permission is intentionally unassigned; any later assignment needs a separate least-privilege review and authorization.

## Operations sequence for a later approved release

1. **Preflight, read-only:** identify the release owner, privacy/custodian, clinical reviewer, security/operations and incident contact. Confirm hosted MySQL/PHP versions, exact current application revision, migrations 059–061 and every object affected by 062–064. Inspect table definitions and indexes rather than inferring migration state from a page load. Count existing notes, practitioner accounts and active clinic assignments without exporting identifying rows to developer tools. Review whether existing notes were created under a different custody/sharing promise; do not bulk-share or silently backfill them. Confirm the Wellnexa clinic ID and that no LivinLively data or roles are swept into the change.
2. **Backup and rehearsal:** create a fresh encrypted database backup in a restricted location, record timestamp, source server and hash, and restore it to an isolated approved non-production server. Verify row/object counts and an application read on the restore. Apply 062, 063 and 064 to that copy in order using migration credentials, record output and compare before/after definitions and counts. Test a forward-compatible rollback on that copy: restore prior application files while preserving new tables, notes, holds, audit and acceptances. Do not use a destructive down migration or make a backup of already changed data the only recovery path.
3. **Approval and change window:** present the exact source/artifact, migrations, preflight/rehearsal evidence, operational effects, rollback and monitoring plan. Obtain distinct REL-05 authorization for the hosted schema change and production deployment, plus custodian/privacy, security/operations, clinical and release-owner decisions for their responsibilities. Pause relevant writes/workers during any maintenance window and confirm a way to recover from partial DDL, since MySQL DDL is not a single atomic transaction across all three scripts.
4. **Apply and deploy, only after approval:** apply the three verified scripts once in order with migration credentials; verify objects and permission code; deploy matching API and portal as one release. Keep policy documents as drafts until exact English/French text and signing process are approved. Do not grant the office hold permission or expand pilot intake as a side effect.
5. **Hosted synthetic acceptance:** use separately approved synthetic identities for Wellnexa practitioner, client, reception/office admin, clinic admin and central admin, plus a LivinLively negative case. Verify current assignment grants necessary access; ending assignment removes notes, contact, booking and financial history; stale role or emergency hold denies the next protected request and queued communication; note sharing stays author-only unless deliberately shared; consent restrictions and cross-clinic boundaries deny; audit persists; document acceptance binds exact version and identity; denied/canceled jobs do not send. Exercise in-flight revocation, repeated submissions, unavailable audit/identity/provider, browser logout/cache, bilingual keyboard/screen-reader flow and representative query volume. Record actual host, API, database, identities and request references without copying PHI or tokens into tickets.
6. **Monitor and rollback:** monitor audit denials, hold/restore events, SQL errors, PHP errors, queue cancellation and clinic support reports during the change window. On a defect, stop new writes/sends, preserve evidence, restore the prior compatible application artifact and private settings, then assess new rows/notes before any data restoration. Never drop 062–064 tables or note columns as a routine rollback. Reconcile queued and in-flight effects before restarting workers. Record incident lead, decision, timestamps and post-rollback smoke results.

This sequence is not authorization to use the hosted database. Existing `practitioner_client_notes` and direct SQL/operator access need explicit privacy/security review before any live migration. Real patient records remain outside the test scope until the accountable owners sign the separate real-data gate.

## Change-scoped 38-control ledger

**Automated reviewer:** Codex, 10 November 2026. The statuses describe release-preparation evidence at the proposed source; they do not certify production. Named reviewers must add dated decisions and the final integrated SHA. Missing repository evidence is not proof of organizational noncompliance.

| Control | Status | Evidence / owner next action |
| --- | --- | --- |
| GOV-01 | Partial | Wellnexa custody model drafted; custodian/legal confirm agency and clinic boundary. |
| GOV-02 | Unverified | Privacy/legal refresh applicability/profession register. |
| GOV-03 | Unverified | Privacy/legal classify commercial and cross-border processing. |
| GOV-04 | Partial | Notes/admin intended use documented; clinical owner confirms. |
| GOV-05 | Partial | Flow reviewed; inventory, PIA and TRA owner sign-off pending. |
| PRIV-01 | Partial | Existing notice; custodian approves any note/copy wording change. |
| PRIV-02 | Partial | Current-care rules; authority, existing notes and exact agreement text need review. |
| PRIV-03 | Partial | Local revocation tests; hosted/in-flight proof pending. |
| PRIV-04 | Unverified | Minor/representative limitation/process needs owner decision. |
| PRIV-05 | Missing | Request/correction and reviewed practitioner-copy process not complete. |
| PRIV-06 | Missing | Profession-specific retention, holds, closure and disposal not approved. |
| PRIV-07 | Unverified | Breach runbook and notice rehearsal pending. |
| AUTH-01 | Partial | Existing token validation retained; hosted realm/grants pending. |
| AUTH-02 | Partial | Local clinic/role/record negatives; hosted full inventory pending. |
| AUTH-03 | Partial | Local hold/revocation; hosted sessions and MFA pending. |
| AUTH-04 | Unverified | Browser token-storage exception/server-session decision pending. |
| AUTH-05 | Unverified | New permission ungranted; deployed app/mail/DB grants pending. |
| SEC-01 | Partial | No private artifact prepared; final secret/package scan pending. |
| SEC-02 | Unverified | Backup/restore, DB TLS and encryption proof pending. |
| SEC-03 | Unverified | Per-host headers, cache and shared-device checks pending. |
| SEC-04 | Partial | Local SQL/input tests; human security review pending. |
| SEC-05 | Partial | Bounds and retries tested locally; volume/abuse pending. |
| SEC-06 | Partial | Local audit assertions; hosted integrity and monitoring pending. |
| SEC-07 | Partial | Safe errors retained; hosted PHP/provider log redaction pending. |
| SEC-08 | Partial | No new provider; existing endpoint/subprocessor register approval pending. |
| SEC-09 | Partial | Local queued-send revocation; hosted provider/opt-out check pending. |
| DEV-01 | Partial | Dedicated prep branch; source/config/migrations reviewed; commit/push pending. |
| DEV-02 | Partial | Compatibility failure fixed on ancestor; human full diff review pending. |
| DEV-03 | Unverified | No dependency change; clean restore and audit pending. |
| DEV-04 | Partial | Dual-engine local suites; broad/hosted checks pending. |
| DEV-05 | Partial | Parent bilingual UI tests; manual accessibility pending. |
| DEV-06 | Unverified | Representative query plans/bundle warning review pending. |
| REL-01 | Partial | This dated ledger; final SHA and named decisions pending. |
| REL-02 | Partial | Local checks in branch reports; full integrated checkpoint pending. |
| REL-03 | Missing | No final artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Required gates remain, so no release decision. |
| REL-05 | Partial | No live changes; distinct schema/deployment approvals pending. |
| REL-06 | Partial | Reviewable plan and risks recorded; operational result pending. |

RELEASE STATUS: NOT READY FOR RELEASE
