# Consent-authorized practitioner qualifications

## Priority and scope

An unrelated patient `staff_access` instruction previously blocked administrators from reviewing or revoking practitioner qualifications. Administrative type creation, practitioner qualification history, on-behalf submission and review now have explicit service authorization instead of the blanket fallback. Existing own-profile types/history/submission routes use the same current-authority boundary. These operations do not disclose patient records or change appointments, patient assignments or consent instructions.

## Current authority

All operations require an active local staff account, active clinic, a permitted role in both the supplied principal and current database, and an independent transaction under the shared clinic consent lock and active clinic coordination-row lock. Staff can read local qualification types; current Clinic Admin/Super Admin roles are required for administration. Own history/submission additionally require current practitioner authority and an active own practitioner profile. Explicit practitioner targets require administrator authority and resolve only to staff-backed profiles in this clinic. Administrators can still review/revoke credentials for inactive practitioners; credential review does not reactivate an account.

Client, application/global administrator status and privacy-review permission alone do not grant credential access. Caller-supplied clinic, practitioner and review-status fields cannot redirect an own submission or mark it verified. Foreign/missing types or practitioners are concealed. Malformed client-backed practitioner profiles and credential provenance referring to a patient or foreign submitter/reviewer are not disclosed through history or review.

## Independent decisions and credential ownership

Submissions remain pending. Verification requires a pending record, an active local qualification type, its required expiry date, and an unexpired qualification. A reviewer cannot verify their own qualification, including one uploaded by someone else, or a record they submitted for another practitioner. A separate authorized administrator must verify it. Rejection requires a note; revocation requires an already verified record and a note. Stale transitions remain refused. Inactive types do not prevent rejection or revocation.

The global-credential ownership guard remains: when central practitioner registration is enabled, registered practitioners must select credentials through their global library and share them with the clinic. Neither the practitioner nor an administrator can bypass that ownership by local submission. Authorized clinic staff can still read and independently review/revoke shared or existing local qualifications. No new role, patient access permission, emergency exception or administrator override is created.

## Audit, transactions and portal

Type/record/review writes and their business audits commit together. Failed auditing rolls back the operation. Current account authority and affected credential/type/provenance rows are checked inside the transaction; nested caller transactions and lock contention are refused without partial changes. The central trail records credential, practitioner and qualification-type IDs and denied access without copying issuer text or review-note content.

After authoritative 401/403/404 responses, the portal clears credential records, type choices, issuer/date drafts, review notes and success messages. Submission/type/review controls stay unavailable until a successful reload. The independent-review refusal has English and French explanations. Server authorization remains authoritative; this does not retract information already viewed.

## Validation and rollout

No new production migration or environment variable is required. Deploy matching API and portal after reviewing staff role assignments and legacy qualification provenance. Existing identity, consent, access-audit and optional global registry prerequisites remain. Drain older API instances so self-review and current-role checks apply consistently. Review malformed records through an authorized maintenance process; do not bypass the new checks to reactivate them.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-qualifications.php` for disposable local SQL/HTTP checks. It retains booking-contact and central access-audit regressions, applies optional registry prerequisites only in the test database, and uses synthetic identities and loopback HTTP. No private environment, hosted records or external provider operations are used.

Local validation on 19 October 2026:

- 154 new SQL/HTTP authorization checks passed on MariaDB 11.4.8, including all service entry points, current role/account/clinic checks, own-profile scope, inactive profiles, administrative route denial, clinic/type isolation, submitted-field overrides, owner/uploader self-verification refusal, independent Clinic Admin decisions, safe revocation, expiration/type policy, malformed patient provenance, audit rollback, global ownership preservation, lock contention and nested transactions. The same final run passed 74 booking-contact and 28 central access-audit checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new integration file and updated PowerShell runner passed syntax checks.
- Three selected browser scenarios passed: existing own submission/renewal warning, denied verification cleanup/reload and denied revocation cleanup/reload.
- Full public, portal and application-admin production build passed, including TypeScript, content and 2,111 English/French translations. Existing bundle-size warnings remain. The full browser suite was not run.
- The first SQL run correctly refused self-review in the audit-rollback fixture. The fixture was corrected to use an independently submitted record; the final full run passed without weakening the independent-review rule.

All checks are local; hosted acceptance and real-data readiness remain outstanding.

[Staff avatar moderation](CONSENT_AUTHORIZED_STAFF_AVATARS.md) is resolved by the subsequent staff-only authorization change. Remaining conservative blocks include practitioner enquiries, receipt templates, room/location/service configuration and assignments, clinic branding/themes and booking settings. Consent-history merges still require reviewed reconciliation. Unknown routes retain the fail-closed fallback. Hosted database/identity/concurrency acceptance, organizational procedures and audit retention remain open under the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md); these changes do not certify compliance or authorize real patient data.
