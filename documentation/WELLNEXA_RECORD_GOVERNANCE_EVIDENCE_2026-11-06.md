# Wellnexa record-governance control ledger

**Review date:** 6 November 2026. **Scope/revision:** `feature/wellnexa-record-governance` from `main` `6cb3376`, before commit. **Reviewer:** implementation review by Codex; custodian, Ontario health privacy/legal, clinical, security/operations and release-owner decisions remain outstanding. Status applies only to this change and linked repository evidence, not to the whole organization's compliance. See [implementation decision](WELLNEXA_RECORD_GOVERNANCE_2026-11-06.md) and the 29 October audit for unrelated baseline findings.

| Control | Status | Evidence in this change | Owner decision / next action |
| --- | --- | --- | --- |
| GOV-01 | Partial | Custody/agent model and tenant boundary drafted; RMT first and written agreement intended. | Custodian and counsel confirm actual group-practice arrangement, operator contracts and expansion to other professions. |
| GOV-02 | Partial | Current PHIPA/IPC/Electronic Commerce Act sources linked in decision. | Privacy/legal owner update dated applicability register and college standards. |
| GOV-03 | Unverified | No PIPEDA flow assessment in this branch. | Privacy/legal owner classify commercial and interprovincial data. |
| GOV-04 | Partial | Clinical notes and administration scoped in decision. | Clinical owner review intended use before real-data release. |
| GOV-05 | Partial | New notes/acceptance/hold data described. | Privacy/security owners complete data inventory, flow, PIA and threat assessment. |
| PRIV-01 | Partial | Existing `Wellnexa/src/Privacy.tsx` identifies Wellnexa as responsible for portal PHI and separate practitioner records as independently held; new drafts identify privacy contact. | Custodian confirm actual group-practice status and whether the approved notice needs revision before activating new notes. |
| PRIV-02 | Partial | Note sharing defaults to author; care assignment and patient restriction checked by `ClinicCareRecordService`. | Privacy/legal owner approve purposes, import/transfer authority and final wording. |
| PRIV-03 | Partial | New note read/write and assignment use current authority, restriction and audit; older client/form/booking routes remain broad. | Engineering narrow every legacy PHI boundary and test revocation/races. |
| PRIV-04 | Unverified | Representative/minor flows not changed. | Clinical/privacy owners define supported assisted process or implementation. |
| PRIV-05 | Missing | No access/correction case tracker or protected delivery in this branch. | Privacy owner specify and rehearse request procedure. |
| PRIV-06 | Missing | Notes are not deleted by new API; CMTO ten-year/minor-age rule recorded in services-agreement outline, but no approved schedule/hold/disposal process. | Clinical/privacy/legal owners approve schedule for RMT and each later profession; engineering implement hold/disposal evidence. |
| PRIV-07 | Unverified | Breach process not changed. | Privacy lead rehearse notification/reporting runbook. |
| AUTH-01 | Partial | New services recheck local active user and role using existing validated context. | Security verify hosted identity realms, tokens and unavailable-authority behavior. |
| AUTH-02 | Partial | New notes require active care team and restrictions; emergency hold blocks account. Legacy access remains. | Engineering perform full endpoint permission inventory and negative cross-record tests. |
| AUTH-03 | Partial | Emergency hold SQL test covers next protected request; hosted session/device revocation untested. | Security/operations verify MFA, idle/absolute limits and in-flight revocation. |
| AUTH-04 | Unverified | No browser credential storage change. | Security review existing exception/server-session rollout. |
| AUTH-05 | Unverified | No new external permission grant; new SQL tables require runtime privilege review. | Security/DB owner inventory deployed grants and separate migration/runtime access. |
| SEC-01 | Partial | No private file intentionally added; Git/artifact scan remains required. | Security scan final branch and package, including untracked files. |
| SEC-02 | Unverified | No transport/storage infrastructure change. | Operations verify DB TLS, backups, encryption, restore and private file placement. |
| SEC-03 | Unverified | No hosted header/browser cache check in this branch. | Security/operations test each host/API and shared device. |
| SEC-04 | Partial | New note/document input validation, prepared SQL and plain-text rendering; no HTML renderer. | Security review hostile content and direct SQL privileges. |
| SEC-05 | Partial | New note page 50, care-team cap 500, document length cap and request-key retry protection. | Security test concurrent duplicate and abuse/size behavior. |
| SEC-06 | Partial | New note reads/writes, care changes, emergency holds and document actions call audit. | Operations test audit failure, monitor review and direct SQL/provider logs. |
| SEC-07 | Partial | New API errors use stable codes; malformed UI responses recover. | Security inspect final package and hosted diagnostics for identifying data. |
| SEC-08 | Unverified | No new external endpoint/subprocessor; DocuSign remains a candidate only. | Privacy/security review provider terms/data flow if selected and maintain existing register. |
| SEC-09 | Not applicable | This branch sends no email/SMS/calendar data. | Reassess if agreement delivery or departure notices are automated. |
| DEV-01 | Partial | Dedicated branch; source/schema/config inspected. | Commit/push after final checks, preserve unrelated untracked work. |
| DEV-02 | Partial | Reviewed new code and fixed SQL-string boolean UI state and malformed responses. | Complete focused security review of all changed routes. |
| DEV-03 | Unverified | No lockfile or dependency change. | Run exact-lock dependency audits before release. |
| DEV-04 | Partial | Build, PHP syntax, synthetic MariaDB migration rehearsal and built browser checks recorded in decision; full browser run had a fixture timeout that passed on isolated rerun. | Run dedicated admin/application suites and hosted negative tests; investigate intermittent fixture startup. |
| DEV-05 | Partial | EN/FR UI strings, semantic controls and dialogs added. | Manual keyboard, screen-reader, contrast, zoom and French mobile review. |
| DEV-06 | Unverified | Query caps and indices added; build warns on existing large chunks. | Representative-volume query and bundle review. |
| REL-01 | Partial | This dated 38-control ledger. | Named reviewers record decisions with final SHA and dated evidence. |
| REL-02 | Partial | Local checkpoint completed for changed surfaces; exact initial failures, reruns and omissions in decision. | Run exact-lock clean restores, omitted dedicated suites and supported MySQL/hosted tests. |
| REL-03 | Missing | No release artifact or hosted synthetic acceptance. | Release owner inspect package/provenance and test on approved non-production host. |
| REL-04 | Missing | Real-PHI gate is not signed; important access/rights/retention gaps remain. | Keep not ready until duties and project gates are satisfied. |
| REL-05 | Partial | No production deployment, hosted migration, grants or external flow in this branch. | Obtain distinct human approvals for schema/deployment and named sign-offs after reviewable package. |
| REL-06 | Partial | Dated branch change report names files, checks, privacy/security gaps and rollback implications. | Release owner add final source SHA, operational/hosted evidence and named decisions before release. |

RELEASE STATUS: NOT READY FOR RELEASE
