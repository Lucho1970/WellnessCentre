# Consent-authorized client registration

19 October 2026. Branch `feature/consent-authorized-client-registration` builds on `feature/consent-authorized-time-off`. This addresses the remaining blanket consent block on `POST /api/v1/clients` and the duplicate-candidate disclosure that would have made simply exempting that route unsafe.

## Implemented boundary

An unrelated patient restriction no longer prevents authorized staff from registering a distinct patient. `ClientService::save` now acquires the shared clinic consent lock and transactional clinic row lock, verifies the active clinic/account, and requires an active practitioner profile for practitioner-only creators. Existing role and `add_clients` requirements remain. Practitioners still cannot supply administrative fields or use operator duplicate confirmation.

Before duplicate suggestions, the 10-candidate limit, confirmation or any committed identity change, the service checks every matching local patient against the same positive relationship/delegation and negative consent boundary as booking contacts. Global and actor-specific restrictions override administrator roles and delegation. A hidden match returns only 409 `client_registration_review`, with no candidate records, counts, IDs, contact details or reason identifying a restriction. Confirmation cannot bypass this check. The same check prevents editing an accessible record's identity to match a hidden record.

Non-identity updates to an already permitted patient retain their current target authorization and revision checks; a different person's matching name does not block a phone-only update. Changing first name, last name or primary email triggers registration-match review again.

Matching uses case-insensitive first/last names or the stored display name. Legacy duplicate names resolve through their same-clinic merge record to the surviving patient's current authorization. Unresolvable or further-merged survivors fail closed. Primary-email, email-alias and staff-account collisions use the same generic review response, and cannot overwrite/reuse another account. No consent directive, consent history or existing relationship is restored or reassigned by registration.

Fully permitted duplicate suggestions retain their existing cap and positive scope, with birthdates omitted from the response. A permitted operator can explicitly confirm a duplicate; this remains unavailable to ordinary practitioner creators. If any matching record is hidden, the entire registration is refused, including cases where that record falls after the first ten permitted candidates. Other clinics' identities are not disclosed or treated as local candidates.

Patient/profile/address/email writes and the creation business audit commit together. A failed business audit rolls back the new identity and creator relationship. Central access auditing records permitted candidate IDs in duplicate validation responses and records registration-review refusals as denied access without disclosed resources or contact content. The portal translates the review response in English/French and clears previous duplicate suggestions before retrying; a stale confirmation refusal offers no **Create anyway** bypass.

## Limits and remaining conservative blocks

This is authorization around known registration matches, not a reliable person-matching or identity-verification system. Changed names, spelling variations and different contact details may not match. Registering staff must use the approved identity/duplicate review process; these checks do not establish authority to collect new data or approve real-data use. False-positive name matches require authorized handling rather than bypassing a patient's instructions. There is no automatic statutory/emergency exception or administrator override.

Remaining conservative route groups identified in this review:

| Group | Why retained / next work |
| --- | --- |
| Recurring availability rules and one-time overrides | Resolved in the subsequent [availability authorization change](CONSENT_AUTHORIZED_AVAILABILITY.md): old/new buffered appointment windows, timezone/recurrence phase and unbounded recurrence, with atomic mutation audits. |
| Address coverage validation, approvals and revocation | Resolved in the subsequent [address coverage authorization change](CONSENT_AUTHORIZED_ADDRESS_COVERAGE.md): patient/recipient checks before provider requests or writes, patient-bound proofs and atomic mutation audits. |
| Remaining administrative configuration | No blanket exemption was added. [Privacy-review permission commands and the patient-free Super Admin staff directory](PRIVACY_AUDIT_REVIEW.md) have explicit authorization. [Reminder schedules](CONSENT_AUTHORIZED_REMINDER_SCHEDULES.md) now authorize all matching pending queue effects separately. [Dashboard widget management](CONSENT_AUTHORIZED_DASHBOARD_WIDGETS.md) now rechecks current clinic Super Admin authority and validates declarative definitions without granting patient access. [Practitioner account management](CONSENT_AUTHORIZED_PRACTITIONER_MANAGEMENT.md) now authorizes local directory, profile creation, identity onboarding and updates explicitly, preserving patient restrictions. [Staff invitations](CONSENT_AUTHORIZED_STAFF_INVITATIONS.md) now have current clinic authority, verified claim, membership and atomic audit boundaries. Other configuration routes retain their existing boundaries pending separate classification/tests. |
| Client merge with any consent history | The explicit reviewed-migration requirement remains, including restored directives. Registration does not transfer or reconcile consent instructions. |

Unknown/new authenticated staff routes retain the fail-closed fallback. Path-scoped appointment, client, form, receipt and recurring-series handlers retain their existing patient checks. This task implements the registration gap; it does not claim all conservative blocks have been removed.

## Rollout and validation

No new migration is required. Existing migrations through 050, including 049 access auditing, and the preceding access/consent changes are prerequisites. Deploy API and portal together, drain older versions, and retain the shared lock's single-primary database namespace and throughput assumptions. Rehearse registration, stale confirmation, concurrent withdrawal and audit rollback with synthetic identities on the intended host. Review database privileges and the operational identity-verification procedure before real patient data.

Run [consent-client-registration.php](../api/tests/integration/consent-client-registration.php) through `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-client-registration.php`. The suite uses disposable localhost MariaDB, synthetic signed identities and all preceding consent regressions, without private environment files or real patient data.

Local results on 19 October 2026:

- 85 new SQL/HTTP registration authorization checks passed on MariaDB 11.4.8, including positive and negative scope, hidden matches before the cap/confirmation, stale decisions, global/named restoration, legacy merged names, alias/account collisions, identity edits, permitted non-identity edits, inactive actors/profiles, direct calls, clinic separation, audit rollback, lock contention and missing storage.
- The same successful run passed 74 booking-contact, 28 access-audit, 70 consent-directive, 59 dashboard/schedule, 61 notification, 94 travel enquiry/message and 107 bulk time-off checks. Registration fixtures explicitly remove inherited scheduling delegation before testing ordinary practitioner relationships.
- The separate client-merge regression passed 20 checks. Its fixture now uses the established client/booking schema baseline, migration 050 and the required service slug. Its earlier failures occurred before exercising application behavior, in unrelated form-library fresh-schema foreign keys and outdated seed data. This fixture repair does not fix or validate the complete fresh-schema bootstrap.
- All 51 root PHP scripts passed, including the added denied-access audit assertion. Syntax checks for 91 source/public files and both changed integration scripts, plus PowerShell runner parsing, passed. RSA tests used the installed Git OpenSSL configuration via `OPENSSL_CONF`.
- Six selected browser scenarios passed across targeted runs: stale duplicate confirmation, existing duplicate/merge behavior, practitioner registration/invitation, client details/history, uncertain form assignment and contact finding. Contact finding first failed on an extra debounced request under the parallel run, then passed on isolated rerun; its implementation and timing assertion were not changed.
- Full public, portal and application-admin frontend production build passed, including content validation, TypeScript and 2,077 English/French translations. Existing bundle-size warnings remain.

Hosted database, identity-provider, concurrency, vendor and production performance acceptance remain outstanding. The complete browser suite was not rerun; this does not resolve the unrelated broad-suite role-policy failure recorded in the original readiness review.

The [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md) release gates remain open pending actual evidence and approval. Local authorization tests do not constitute hosted acceptance or compliance certification.
