# Wellnexa record-governance change report

**Date and source:** 6 November 2026, `feature/wellnexa-record-governance` based on `main` `6cb3376`. No merge, production deployment, hosted migration, account permission grant, external provider, new runtime dependency or new endpoint to a third party. Migration 062 registers one narrow permission code but grants it to no account. No legal document is seeded or published. The unrelated untracked `documentation/FULLSCRIPT_INTEGRATION.md` is excluded. Node 22.22.1, npm 11.19.0, PHP 8.4.14, Composer 2.9.5, disposable MariaDB 11.4.8. No package or lockfile change.

## Changed files and purpose

| File | Purpose |
| --- | --- |
| `api/database/migrations/062_practitioner_emergency_access.sql` | Reversible, audited access-hold state and narrow office permission code. |
| `api/database/migrations/063_clinic_care_notes.sql` | Care assignments; author/care-team note visibility and retry key. |
| `api/database/migrations/064_practitioner_documents.sql` | Bilingual versioned documents and immutable acceptance evidence. |
| `api/database/schema.sql` | Fresh-schema equivalents of migrations 062–064. |
| `api/src/Api.php` | Routes for holds, care team, notes and documents. |
| `api/src/Service/AdminService.php` | Immediate hold/restore authorization, audit, and prevention of status-edit bypass. |
| `api/src/Service/ClientService.php` | Block client merges with care assignments pending reviewed migration. |
| `api/src/Service/ConsentAccess.php` | Classify new routes for existing consent-boundary dispatch. |
| `api/src/Service/ClinicCareRecordService.php` | Current-care assignment and restriction checks, append-only note reads/creates, idempotency and audit. |
| `api/src/Service/PractitionerPolicyDocumentService.php` | Draft/publish/accept exact versions and identity-bound evidence. |
| `api/tests/integration/clinic-care-records.php` | Synthetic SQL negative/positive care and document tests. |
| `api/tests/integration/practitioner-emergency-access.php` | Synthetic SQL hold/restore and bypass tests. |
| `scripts/test-recurring-sql.ps1` | Allow the two new disposable SQL suites. |
| `Frontend/src/admin/AdminPolicyDocuments.tsx` | Admin document draft and reviewed publication screen. |
| `Frontend/src/admin/PractitionerSafety.tsx` | Clinic admin urgent hold and super-admin restore screen. |
| `Frontend/src/admin/StaffAdmin.tsx` | Super Admin grants the narrow hold permission to a reception/office user. |
| `Frontend/src/clients/ClientCareTeam.tsx` | Explicit care assignment controls in client details. |
| `Frontend/src/clients/ClientManagement.tsx` | Mount care-team controls only for eligible admin users. |
| `Frontend/src/portal/PractitionerNotes.tsx` | Insert/read notes with author-only default and deliberate care-team sharing. |
| `Frontend/src/portal/PractitionerPolicyDocuments.tsx` | Exact EN/FR document review and affirmative acknowledgement. |
| `Frontend/src/portal/PractitionerClients.tsx` | Mount clinic notes in practitioner client drawer. |
| `Frontend/src/portal/StaffPortal.tsx` | Wire document and hold pages. |
| `Frontend/src/portal/access.ts` | Role-scoped navigation and routes. |
| `Frontend/src/i18n/clinicRecords.ts` | EN/FR care, notes and document strings. |
| `Frontend/src/i18n/practitionerSafety.ts` | EN/FR hold strings. |
| `Frontend/src/i18n/index.ts` | Register both feature catalogs. |
| `Frontend/scripts/validate-i18n.mjs` | Validate both new catalogs in the quality gate. |
| `Frontend/playwright.config.ts` | Include new browser tests in default regression. |
| `Frontend/tests/record-governance.spec.ts` | Browser tests for hold, care assignment and bilingual acknowledgement. |
| `Frontend/tests/portal.spec.ts` | Update role-policy expectation for new scoped pages. |
| `documentation/WELLNEXA_RECORD_GOVERNANCE_2026-11-06.md` | Operating model, implementation decision, gaps and release sequence. |
| `documentation/WELLNEXA_RECORD_GOVERNANCE_EVIDENCE_2026-11-06.md` | 38-control evidence ledger with owners/actions. |
| `documentation/WELLNEXA_PRACTITIONER_RECORDS_AGREEMENT_DRAFT_2026-11-06.md` | Review-only bilingual records agreement. |
| `documentation/WELLNEXA_PRACTITIONER_CONFIDENTIALITY_DEPARTURE_DRAFT_2026-11-06.md` | Review-only bilingual companion acknowledgements. |
| `documentation/WELLNEXA_PRACTITIONER_SERVICES_AGREEMENT_OUTLINE_2026-11-06.md` | Counsel drafting/signature decisions for first RMT cohort. |
| `documentation/WELLNEXA_RECORD_OPERATIONS_DRAFT_2026-11-06.md` | Outside-record, patient request, retention and departure process draft/gaps. |

## Privacy, security and release limits

The new note path is scoped to current care assignments and patient restrictions; another practitioner's note is visible only if marked for care-team sharing. An emergency hold locks an active local practitioner immediately for new protected requests and can only be restored by a super administrator. A policy acceptance is bound to the current authenticated identity subject hash; a relink requires review. No delete/update note API exists, but direct database privilege, legacy merge and operator access require release review. Existing practitioner contact, form and scheduling paths still use historical relationship rules; they remain a major real-PHI blocker. Document acceptance gates only the new note API. Formal departure, outside-record intake, access/correction, retention/legal hold and executed-copy delivery remain incomplete. The draft documents require custodian, legal, clinical and signature-method approval. DocuSign is a candidate only; no data is sent to it.

**Rollback implications:** application code can be reverted before a hosted migration. After migrations 062–064, a code rollback must account for new rows and notes; dropping the tables or columns could destroy evidence. Rehearse forward-compatible rollback using a fresh verified backup and copied data, with clinic service paused and owner approval. No automatic downgrade SQL is supplied. Do not deploy this branch or accept real PHI from this feature until the evidence ledger and hosted acceptance are complete.

## Validation ledger

Final commands/results and initial failures are recorded in [the implementation decision](WELLNEXA_RECORD_GOVERNANCE_2026-11-06.md). Local checks do not constitute hosted acceptance or approval. A release owner must record source SHA, package/lockfile hashes, backup/restore, exact host configuration, smoke checks, monitoring, incident contact and custodian/privacy, security/operations, clinical and release-owner sign-offs before any production decision.

RELEASE STATUS: NOT READY FOR RELEASE
