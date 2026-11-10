# Receipt-first insurance roadmap decision — 4 November 2026

## Decision and scope

The owner chose client-submittable benefits receipts for the initial release. Clients should receive a complete, accurate PDF for an actually completed and paid service, then submit it to their benefits provider. Direct billing to insurers, including manual insurer-portal claim tracking and electronic submission, is scheduled **after** that initial release. This changes delivery priority, not the safeguards or feature acceptance requirements in [Master Requirements](MASTER_REQUIREMENTS.md) FIN-02A/FIN-05 and R7–R9. No insurer account, claim, policy or provider integration is configured by this decision.

The [existing receipt slice](SESSION_RECEIPTS.md) has local source/tests and is default off. It records full manual payment and produces an immutable PDF for authenticated download. It has not passed hosted issuer, profession, tax or insurer acceptance. The shared clinic template plus practitioner credential fields cannot be assumed to express every independent practitioner's actual legal issuer/payee arrangement. That must be accepted per supported service/practitioner before live issuance. A valid-looking receipt cannot guarantee reimbursement; the insurer decides eligibility and amount.

## Initial-release acceptance to finish

1. Approve the actual clinic/practitioner seller, receipt issuer and payment recipient for each supported service. Validate business name/address and practitioner designation, registration and licensing information against the applicable profession and representative insurer requirements.
2. Prove completed service and actual full manual payment, correct historical service/price/tax, unique receipt number, bilingual readable PDF, current client-only portal access, mobile download/print and no claim of insurer payment.
3. Prove corrections, void/refund/reissue, account and practitioner changes, historical snapshot preservation, role/clinic denial, duplicate issuance and restoration. Decide and test the generic email notice separately if required for first release.
4. Record hosted synthetic acceptance, privacy/finance/release approvals and feature-flag/configuration evidence before real issuance. Keep insurer claims and policy records out of this initial workflow.

## Post-release direct-billing sequence

Begin with insurer/policy, patient-versus-insurer balance, claim state and controlled staff portal-submission tracking. Define independent practitioner versus clinic account, payee and credentials; insurer/profession/service eligibility; authority to submit; consent/disclosure; pending/rejected/reversed outcomes; insurer remittance and reconciliation. Pursue TELUS eClaims or other adapters only after partner access and conformance requirements are confirmed. This is a later product decision and does not block client receipt submission. See [TELUS eClaims FAQ](https://www.telus.com/en/health/health-professionals/allied-healthcare-professionals/eclaims/faq) for current participating-provider limits and [Sun Life's separate provider eClaims service](https://www.sunlife.ca/sl/provider/en/paramedical/eclaims/) for an example of an insurer-specific route.

## Change evidence and control ledger

Source base: `main` at `6cee57b59f6c4aebe2aff41b235acf4fc26de602`; change branch: `feature/receipt-first-insurance-roadmap`. Reviewer: Codex, 4 November 2026. This is a documentation-only priority decision. No dependency, permission, consent text, API, schema, endpoint, external data flow or production configuration changed. No release artifact or hosted test was produced. “Unverified” means this change did not verify the control, not that the organization lacks it. Product/release owner must retain this decision and obtain specialist approval at implementation/release.

| Controls | Change-scoped state | Evidence, owner and next action |
| --- | --- | --- |
| GOV-01, GOV-02, GOV-03, GOV-04, GOV-05 | Unverified | Custodian/legal/product owners: review supported professions, data flow, applicability and intended use before claim or live receipt release. |
| PRIV-01, PRIV-02, PRIV-03, PRIV-04, PRIV-05, PRIV-06, PRIV-07 | Unverified | Privacy/custodian owner: approve notice, claim authority, minimum disclosure, access, representatives, retention and incident procedures. No PHI processed in this edit. |
| AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05 | Unverified | Identity/security owner: hosted receipt recipient, membership, role, session and grant checks remain required; no auth change. |
| SEC-01, SEC-02, SEC-03, SEC-04, SEC-05, SEC-06, SEC-07, SEC-08, SEC-09 | Unverified | Security/operations owner: verify PDF/artifact, transport, browser headers, input, abuse, audit, error, insurer/vendor and messaging paths when deployed. No new runtime endpoint or data transmission. |
| DEV-01 | Implemented for this edit | Dedicated clean feature branch; root instructions, current docs, source status and workflow reviewed. Commit/push after checks. |
| DEV-02 | Implemented for this edit | No code generated; requirement, source implementation and hosted acceptance remain distinct. |
| DEV-03 | Unverified | No dependency change; release dependency audit still applies to a future artifact. |
| DEV-04, DEV-05, DEV-06 | Unverified | Documentation checks only; functional, accessibility and performance acceptance belong to receipt/claim implementations. |
| REL-01 | Partial | All 38 controls named here with change scope; release owner must maintain feature-specific evidence, named decisions and unresolved items. |
| REL-02, REL-03, REL-04 | Unverified | No broad code checkpoint, artifact, hosted acceptance or release approval; receipt release remains not ready on this evidence. |
| REL-05 | Implemented for this edit | Owner explicitly authorized the roadmap priority. No merge, deployment, migration, permission or integration authorized here. |
| REL-06 | Partial | Task completion report will record exact documentation edits and checks; full feature-release report remains future work. |

Rollback: revert this documentation decision if the owner changes priority; there is no database or production state to restore. Preserve the historical receipt implementation and financial records.
