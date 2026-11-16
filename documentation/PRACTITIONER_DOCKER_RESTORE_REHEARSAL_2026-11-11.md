# Practitioner database restore rehearsal — 11 November 2026

This is local evidence for source `deccccd24a5e31a78359b9000eb63da7e69874a6`, not a hosted release decision. The custodian stated that all rows in the exported database are synthetic. No production database, application, worker, identity configuration, provider or repository code was changed.

## Environment and input

- Docker Desktop 29.9.3, Linux containers; isolated `mysql:5.7` image digest `sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb`; server reports MySQL 5.7.44.
- Separate disposable container `wellness-mysql57-rehearsal`: `--network none`, no published ports, no bind mounts, disabled Docker logs, temporary `/var/lib/mysql` and `/tmp` filesystems. Root has no password **only inside this networkless disposable container**. The existing unrelated MySQL 8 container was untouched. Docker Desktop or host restart can lose this data; it is not a backup.
- Input was the custodian's 11 November `wellness_centre.sql.gz` export, 568,043 compressed bytes, SHA-256 `52d0d2d9700961fab26bea437985ad4c3a89e50a9f09c1efde7fe04e16c28a1c`. It stayed outside the repository. The decompressed 2,230,857 bytes were streamed directly to the isolated MySQL client; no second SQL file or output of row contents was created.
- C: and D: showed BitLocker off in the owner's screenshot. Docker isolation does not encrypt the original export on D: or prove encryption of Docker Desktop's underlying storage. The synthetic-data declaration is the sole basis for this local rehearsal; do not use this method for real PHI or other real personal records without a separately approved protected environment.

## Result

1. The gzip stream imported with exit code 0 into an empty `wellness_centre` database. The restored copy had 152 tables, matching the earlier live count. It had zero existing practitioner notes, nine practitioner rows, nine client-profile rows and two practitioner pilot entries; no row values were displayed.
2. The first CLI submission of migration 060 stopped at its first `CREATE TRIGGER` (MySQL error 1064, line 24) because the CLI splits trigger bodies on semicolons. Migration 059 and the first non-trigger 060 statements had applied to that disposable copy. The entire disposable database was reset and the export was imported again. For the successful run, the CLI stream used temporary `DELIMITER //` directives around each trigger; the versioned SQL was unchanged. This was a rehearsal invocation error, not evidence that the SQL itself failed.
3. Migrations `059_browser_sessions.sql`, `060_browser_session_integration.sql`, `061_commit_safe_email.sql`, `062_practitioner_emergency_access.sql`, `063_clinic_care_notes.sql` and `064_practitioner_documents.sql` then applied sequentially, each with exit code 0. This tests the actual pre-migration export shape against MySQL 5.7.44. Applying 059–061 in production would require their own review and approval; this rehearsal grants no such authority.
4. The resulting copy has 162 tables and 29 triggers. The checked original counts stayed at nine practitioners, nine client profiles, two pilot entries and zero notes. Both new note columns exist. Care assignments, document versions and document acceptances are empty, and the new emergency-hold permission is assigned to zero users.

The local SQL exit codes and object counts establish a successful restore and forward schema rehearsal only. They do not establish an application read, row-by-row reconciliation of all 152 original tables, representative query performance, direct-SQL privilege restrictions, rollback with the prior application artifact, hosted identity/revocation, workers, encrypted storage, or production migration safety. The MySQL CLI is running as an unrestricted disposable root; deployed runtime grants remain unverified.

## Control impact and next action

The [38-control release-preparation ledger](PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md#change-scoped-38-control-ledger) remains the baseline for all controls not listed here. This is a dated evidence update, not a replacement approval:

| Control | Current evidence / unmet action |
| --- | --- |
| GOV-05 | Synthetic-only local use attested by the custodian; inventory, PIA/TRA and real-data gate remain open. |
| AUTH-02, AUTH-05 | No role was granted; runtime SQL grants and hosted authorization remain unverified. |
| SEC-01, SEC-02 | No dump or secret entered Git; the host export is on an unencrypted D: drive. Protected real-data backup, storage, restore and DB transport evidence remain open. |
| DEV-04, REL-02 | Restored-schema MySQL 5.7 migration check passed; PHP/browser suites were not rerun in this documentation-only slice. Existing isolated SQL suite results are in the linked baseline. |
| REL-01, REL-03, REL-04 | Evidence is local and synthetic. Named reviewers, final artifact, hosted acceptance and release decision remain open. |
| REL-05 | No hosted migration or deployment occurred. New approval is needed for 059–064 and the matching application artifact after reviewable release preparation. |
| REL-06 | This report records the result and omissions. Retain the original backup privately; the disposable Docker copy is not a recovery source. |

Before a hosted release, compare all affected hosted objects and grants, complete encrypted backup/restore and rollback evidence, approve the documents and signing workflow, then obtain the separate accountable decisions described in the baseline plan. Do not treat the one historical candidate care pair as an automatically authorized assignment.

RELEASE STATUS: NOT READY FOR RELEASE
