# Consent-authorized practitioner enquiry review

## Scope and authority

Practitioner enquiry lists, status changes and notification retries previously inherited the conservative patient-consent block. Their service also trusted supplied administrator contexts without checking current local roles or central grants. These three clinic routes now have explicit recruitment authorization and are the only new consent exemptions.

Clinic review requires Super Admin or Clinic Admin authority in both the principal and persisted active staff account in the selected active clinic. Practitioner, reception, accountant, client, application-admin labels on a clinic principal, and privacy-review permission alone do not grant access. Removed roles, inactive accounts and foreign clinic accounts are rejected. Current authority is checked inside the service transaction, including direct service calls.

Application administration remains separate: each list, update, settings save and retry rechecks the active global grant against its ID, verified issuer and verified subject. A local Super Admin role does not confer central access; a central grant does not provision a clinic staff account. Central HTTP feature flags, central host restrictions and signed-token verification remain mandatory. Central administrators explicitly select the clinic. Only central administrators can change the configured recipient and public-form enabled setting.

The inbox contains independently submitted recruitment/contact information, not patient charts. It does not join, match, expose, merge or create patient accounts, practitioner accounts, invitations, assignments or consent instructions. An applicant email matching an existing restricted patient does not restore clinical access. The recruitment contact checkbox is never treated as patient-care consent or authority to override a patient restriction. Free text can still contain sensitive information and receives restricted administrator access and content-free access auditing.

## Transactions, delivery and auditing

Lists and mutations own their transactions, acquire the shared clinic consent lock, lock the active clinic coordination row and recheck the current actor/grant. Pagination is scoped to the clinic before slicing. Foreign and missing enquiry IDs return the same 404 for updates and notifications. Stale revisions return 409 for an authorized existing record. Status changes and recipient settings commit with their business audit, or roll back together. Nested caller transactions are refused without disturbing the caller's work.

Manual and automatic notification delivery share a claim protocol. System-generated correlation IDs fit the existing 36-character audit column, including legacy schemas. Under the clinic lock, the service validates the saved enquiry and current recipient configuration, then commits an audit record and `sending` state before contacting the provider. Local staff or central-admin attribution is retained on manual claims; trusted post-submission automatic delivery is recorded as system activity. Disabled mail creates an audited `disabled` state without contacting a provider. A lock or audit failure before claiming preserves the saved enquiry for a later authorized retry. Closing the public form prevents new submissions; it does not cancel saved recruitment enquiries or their notification eligibility.

Provider messages contain only the existing clinic name, enquiry reference and a sign-in instruction. Applicant names, contacts, credentials and message text are excluded. Only pending, failed or disabled notices can be claimed. Sent, sending and needs-review notices cannot be resent. Typed confirmed provider refusals remain failed; ambiguous responses or unexpected exceptions after entering the transport require review. If outcome storage or its audit fails after a provider call, the committed `sending` claim remains and blocks another send. No database transaction is held during the provider call, while the clinic consent lock remains held. Committed in-flight delivery and information already viewed cannot be retracted by this change.

The API supplies the request correlation ID to both clinic and central notification commands. Central access auditing records disclosed enquiry IDs and verified actors, including denied access, without copying applicant content or recipient settings. Successful disclosures retain no-store and fail-closed audit handling. Business claims/results and the separate response access audit serve different boundaries; a response failure is not permission to resend a notice.

## Portal behavior

The shared clinic/central enquiry component clears cached applicant rows and recipient drafts after failed refreshes, status changes, settings saves or notification commands. Retry requires a successful authorized reload before the records and controls return. Request generation checks prevent responses from an older clinic/page/authentication load from repopulating the current view. Existing recipient settings remain available only in the central UI.

## Validation and rollout

No new production migration or environment variable is introduced. Existing enquiry migration 047, application-admin grant table 040, clinic role/identity foundations, consent migration 050 and audit storage remain prerequisites. Reconcile current administrator grants before rollout. Deploy matching API and portal after review and drain older API instances so retries use the same claim protocol. Review existing sending/needs-review records against provider/mailbox evidence through the existing support process; this task introduces no reset or automatic resend tool.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-practitioner-enquiries.php` for disposable local SQL/HTTP authorization acceptance. Provider tests use injected callbacks; the synthetic HTTP router forces mail disabled and never loads a private environment. The existing recruitment integration fixture now seeds the persisted administrator roles required for review and uses a typed confirmed provider refusal for its retry case.

Local validation on 20 October 2026:

- 171 new SQL/HTTP checks passed on MariaDB 11.4.8, including current local/global authority, cross-clinic and missing targets, pagination, immutable patient boundaries, attributed pre-send claims, provider payload minimization, ambiguous outcome handling, audit rollback and post-provider storage failure, real central and clinic HTTP authorization, fail-closed disclosure, lock contention and nested caller transactions. The same run passed 74 booking-contact and 28 central access-audit checks.
- Existing public recruitment regression passed all 37 checks, including idempotency, validation, quotas, recipient changes and notification handling on the older schema. Its inherited suites also passed 34 recurring-booking, 111 client-form and 53 form-library checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new/updated integration PHP sources and maintained PowerShell runner passed syntax checks.
- Four selected browser scenarios passed: existing clinic review/settings separation, plus refresh, status-change and notification-denial cleanup with successful retry recovery.
- Full public, portal and application-admin production build passed, including TypeScript, bilingual content checks and 2,111 English/French translations. Existing bundle-size warnings remain. The complete browser suite was not run.

All checks are local and synthetic. Hosted identity/database/concurrency/provider acceptance and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open. No real applicant/patient data or real messages were used. Remaining conservative blocks include room/location/service configuration and assignments, clinic branding/themes/booking settings, and reviewed reconciliation for client merges with any consent history. Unknown authenticated staff routes retain their fail-closed fallback.
