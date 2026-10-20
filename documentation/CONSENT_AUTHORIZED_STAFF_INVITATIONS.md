# Consent-authorized staff invitations

## Priority and authorization boundary

An unrelated patient privacy instruction previously blocked revoking an unaccepted practitioner invitation. The list, create, approve and revoke routes now use explicit service authorization rather than the blanket consent fallback. An active local staff account with a current persisted Super Admin role in the active clinic is required, including for listing invitation contacts and signed-in claims. Both the request principal and database authority are checked. Clinic Admin, practitioner, reception, accountant, client, privacy-review permission and application/global administrator status do not grant invitation management.

The invitation feature remains disabled unless its existing server flag is enabled. The existing frontend pilot flag remains unchanged. Submitted clinic IDs cannot redirect management; foreign invitation IDs are concealed. A malformed legacy invitation referencing a client or staff from another clinic is excluded before the list limit. Such a local invitation can still be revoked without disclosing its target details.

## Claims, approval and patient restrictions

Creation remains practitioner-only and manually delivered: no email is sent. Only a token hash is stored. A verified external-staff identity can submit a token-bound pending claim, but receives no account or membership until explicit approval. Customer identities, conflicting claimants, expired/revoked/accepted tokens, wrong verification codes and missing recipient verification are refused. The original inviter must still be authorized and the location must remain bookable in the current clinic.

Approval creates only local practitioner access, with the existing MFA-required account setting. It cannot grant administrative roles or arbitrary permissions, publish a public profile, reuse another clinic's account, automatically match by email, or restore a revoked membership. Accepted invitations cannot be revoked to remove access; use the separately authorized account/membership deactivation workflow.

Explicit existing-practitioner invitations retain their reviewed binding requirements: an active local staff/practitioner account, no existing membership and no elevated roles. Approval retains the same local user ID and all existing named/global patient restrictions and relationships. The workflow does not transfer patients, merge records, rewrite bookings or clear consent history. Patient authorization remains independent on subsequent reads and booking assignments.

## Transactions and audits

Management and external-staff claims acquire the shared clinic consent lock, own their transaction and lock the active clinic coordination row. Current management authority is checked inside that boundary. Claim, approval and revocation therefore serialize with each other and clinic privacy/account changes. Nested caller transactions are refused without disturbing the caller. Invitation/account/identity/role/profile/location/membership changes and successful business audits commit together; audit failure rolls back the entire operation.

Central auditing retains successful invitation, claim, existing-user and membership references, and records denials. Raw tokens, verification codes, recipient contacts, claimant names and identity subjects are not copied into central metadata. Existing staff references use the `user` resource type. Denied responses do not reveal patient data or affected-record counts.

The portal discards cached invitation contacts, copyable links, create forms and review dialogs after authoritative 401/403/404 responses. Verification and the selected claim must be obtained again after a successful reload; server authorization remains authoritative.

## Rollout and validation

No new production schema migration, feature enablement or environment variable is introduced. Deploy matching API and portal with the existing invitation/identity/access-audit/consent prerequisites. Review legacy staff bindings and malformed invitation references before enabling the pilot; drain older API instances so revocation and approval use the same coordination boundary. Hosted identity-provider acceptance, concurrency, database compatibility, access revocation and audit retention remain outstanding. The [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-staff-invitations.php` for disposable local SQL/HTTP checks. The integration router's explicit synthetic-only flag enables the real invitation service only in that local test; production configuration is unchanged. External identities are synthetic verified objects for service/membership checks. These tests do not validate a hosted identity provider or send invitations externally. The test retains the booking-contact and central access-audit regressions.

Local validation on 19 October 2026:

- 180 new SQL/HTTP authorization checks passed on MariaDB 11.4.8, including all four management entry points, stale/inactive/foreign principals, revoked roles/clinic, lower roles, verified recipient/claimant requirements, token hash storage and audit redaction, foreign targets/locations, inactive inviter, transactional audit failures, local practitioner-only grants, existing-user binding with global/named restrictions preserved, rejection of inactive/elevated existing accounts, revoked memberships, expired/revoked/accepted invitations, malformed patient references, feature-disabled behavior, lock contention and nested transactions. The same final run passed 74 booking-contact and 28 central access-audit checks.
- All 53 root PHP scripts passed, including 29 invitation policy/transaction checks using the updated PDO double. Syntax passed for 94 source/public files, the new integration file, the changed synthetic router and the PowerShell runner.
- Three browser scenarios passed: successful verified approval, 403 approval denial cleanup and 404 revocation denial cleanup. The optional invitation component is mounted through the existing test-only staff-page module fixture; production feature flags and server auth are unchanged.
- The full public, portal and application-admin production build passed, including TypeScript, content and 2,110 English/French translations. Existing bundle-size warnings remain. The full browser suite was not run.
- The initial SQL run stopped at a test call to a nonexistent booking-access helper. It was corrected to exercise the real booking-address service, and the final full SQL/HTTP run passed.

All results are local. Hosted acceptance, identity-provider validation and real-data readiness remain outstanding. Remaining conservative configuration/credential blocks and consent-history merge reconciliation are separate follow-ups.
