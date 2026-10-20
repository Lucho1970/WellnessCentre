# Consent-authorized practitioner account management

## Scope and priority

The practitioner administration screen previously could not deactivate an account when an unrelated patient had a global or acting-administrator `staff_access` restriction. This change replaces the blanket blocks for local profile creation, explicit identity onboarding and practitioner updates with service-owned authorization. The already-exempt administrative directory now uses the same current-authority checks.

These routes maintain staff accounts and profiles; they do not list patients, transfer patient records, alter bookings, reconcile consent history or bind an existing account to a new identity. Patient consent and relationship checks remain independent on every clinical read and booking assignment. An existing workforce token is refused after its local account is made inactive. Reactivation preserves all named and global patient restrictions.

## Current authority and clinic scope

`AdminService` checks both the supplied staff principal and the persisted active staff account, current role and active clinic. Directory access, identity onboarding and account updates require Super Admin. The older profile-only creation endpoint retains its existing Super Admin/Clinic Admin policy; it creates a profile for an active local staff account without assigning roles, permissions or an identity. Privacy-review permission or application/global administrator status alone grants none of these operations.

Submitted clinic/user/role/permission fields cannot redirect an edit or escalate onboarding. New identity onboarding still requires explicit valid tenant/object identifiers, rejects existing identity bindings and local email collisions, and assigns only the practitioner role with the existing MFA-required account setting. The API's central-administration onboarding restriction remains in place. Edits keep the existing identity binding; changing the contact email does not change Microsoft sign-in identity.

Target practitioners must resolve to staff accounts in the current clinic. Foreign or missing targets and locations are concealed; malformed client-backed practitioner rows cannot expose or edit a patient through this endpoint. The directory excludes foreign staff and foreign location references, including malformed cross-clinic practitioner-location links. Existing central-registry guards remain enforced for the authorized local practitioner: centrally removed assignments and practitioner-owned credential restrictions cannot be bypassed.

Account activation flags must be booleans; PHP truthiness can no longer interpret the string `false` as activation.

## Coordination, audit and portal

All four operations acquire the shared clinic consent lock, own their transaction, lock the active clinic coordination row and recheck current authority. First inserts and all role, identity, account, profile and location writes commit together with the business audit. Audit failure or lock contention cannot partially deactivate or onboard an account. Nested caller-owned transactions are refused without disturbing the caller. Clinical reads and privacy updates serialize through the same clinic lock; changes to the account status take effect on subsequent authenticated requests.

Successful business audits contain staff/profile/location references, and central disclosure/denial auditing remains enabled. No patient IDs, affected counts, contacts or identity subjects are added to audit metadata by this change. The portal clears cached directory records, selected details and edit/onboarding forms after authoritative 401/403/404 responses and requires a successful directory reload before another action.

Deactivating an account does not cancel appointments, retract previously viewed information or void accepted receipts. Use the separately authorized scheduling and follow-up workflows for operational changes. This boundary deliberately permits account revocation even when the administrator cannot read an assigned patient's record.

## Remaining conservative blocks

Staff invitation management is resolved in the subsequent [staff invitation authorization change](CONSENT_AUTHORIZED_STAFF_INVITATIONS.md). [Practitioner credential administration](CONSENT_AUTHORIZED_QUALIFICATIONS.md) is resolved by its subsequent authorization change. [Staff avatar moderation](CONSENT_AUTHORIZED_STAFF_AVATARS.md) is also resolved by its subsequent authorization change. [Receipt-template maintenance](CONSENT_AUTHORIZED_RECEIPT_TEMPLATES.md) is resolved by its subsequent authorization change. [Practitioner enquiry review](CONSENT_AUTHORIZED_PRACTITIONER_ENQUIRIES.md) is resolved by its subsequent authorization change. [Clinic location administration](CONSENT_AUTHORIZED_LOCATIONS.md) is resolved by its subsequent authorization change. [Room maintenance](CONSENT_AUTHORIZED_ROOMS.md) now resolves list/create/update with guarded turnover and relocation. The route inventory still includes room capability/practitioner assignment workflows; service configuration and assignments; clinic branding, themes and booking settings. Each retains its existing route/consent boundary until its full disclosure and mutation effects are classified and tested. Patient-scoped appointment, client, form, receipt and recurring-series routes retain their existing target checks. Client merges with any consent history still require reviewed reconciliation, including restored directives. Unknown routes retain the fail-closed fallback.

## Validation and rollout

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-practitioner-management.php` for disposable synthetic local SQL/HTTP checks. It includes the existing booking-contact and access-audit regressions and applies the central administrator and practitioner registry prerequisite migrations only inside the test database. No private environment, hosted records or external identity/provider calls are used.

No new production migration or environment variable is required. Deploy matching API and portal after reviewing current role assignments, staff identity bindings and malformed practitioner/location links. Hosted database/identity acceptance, shared-lock concurrency, operational access revocation and audit retention still need verification. Older API instances must be drained for consistent authorization behavior. The [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open; this change does not authorize real patient data or certify compliance.

Local validation on 19 October 2026:

- 147 new SQL/HTTP authorization checks passed on MariaDB 11.4.8, including all four service entry points, role/account/clinic revocation, forged principals, cross-clinic targets and locations, malformed patient-backed profiles, deactivation of an account with restricted assigned patients, refusal of its existing signed token, reactivation with consent preserved, minimal onboarding roles and MFA setting, unchanged identity bindings despite contact-email edits, legacy Clinic Admin profile creation, central credential/assignment guards, audit rollback, lock contention and nested transactions. The same run passed 74 booking-contact and 28 access-audit checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new integration file and updated PowerShell runner passed syntax checks.
- Three selected browser scenarios passed: existing directory/details/edit/onboarding flow, 403 edit-denial cleanup and 404 edit-denial cleanup.
- Full public, portal and application-admin production build passed, including TypeScript, bilingual content and 2,110 English/French translations. Existing bundle-size warnings remain. The complete browser suite was not run.
- Initial SQL fixtures were corrected for an already-seeded location assignment and the central administrator prerequisite migration. A stale-principal assertion was corrected to respect the legacy profile-only Clinic Admin permission. Browser assertions were corrected to use the edit form's existing Email label. These corrections did not weaken role or consent enforcement.

These are local checks; hosted acceptance and real-patient-data readiness remain outstanding.
