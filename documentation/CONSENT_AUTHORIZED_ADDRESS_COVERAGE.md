# Consent-authorized address coverage

19 October 2026. Branch `feature/consent-authorized-address-coverage` builds on `feature/consent-authorized-client-registration`. The four staff address-coverage routes now enforce patient and selected-practitioner authorization in `AddressCoverageService` instead of refusing the entire operation because an unrelated patient has restricted staff access.

## Protected operations

| Operation | Boundary |
| --- | --- |
| POST `/api/v1/address-coverage/validate` | Requires the selected active patient, booking-contact scope and permitted selected practitioner before configuration errors, private-origin loading or provider requests. |
| POST `/api/v1/address-coverage/approval` | Uses the same current scope before looking up a saved approval. |
| POST `/api/v1/address-coverage/approve` | Requires both booking/patient scope and approval authority; rechecks a patient-bound proof and commits the approval with its business audit. |
| POST `/api/v1/address-coverage/revoke` | Requires the same current scope and approval authority; deletion and its business audit commit together. |
| Customer validate/status and internal `verifyBooking` | Enforce the same service boundary, including patient self-context and selected-recipient restrictions. |

Staff must submit a positive integer `client_id` for every operation. The portal now sends it during validation as well as approval/status/revocation. Ordinary practitioners require an active profile and their existing booking-contact relationship; `schedule_for_other_practitioners` retains its existing positive delegation scope. Administrators and reception retain their positive clinic scope. No role, capability, selected practitioner or submitted clinic ID can override global or actor-specific `staff_access` restrictions. Inactive, merged, foreign or inaccessible patient targets are concealed with the normal unavailable-client response.

The selected practitioner must have an active same-clinic staff account/profile and active location/service assignments offering mobile visits. Existing own practitioner-managed booking restrictions still apply. The patient's negative permission for that practitioner is checked independently of the acting staff member's access, before loading a private starting address. Approval permission alone does not grant client access or a booking role.

Patients may omit `client_id`; their own identity is assigned from authentication. Submitting another patient's ID is denied. Their self-access does not authorize sending an address to a restricted practitioner workflow. An independently permitted recipient remains available; patient history access is unchanged. `patient_communications` retains its existing separate appointment-message purpose and is not reinterpreted as general mapping permission.

## Proofs, writes and concurrency

New signed proofs bind the patient in addition to the staff/client actor, clinic, practitioner, location, service, destination, origin version, radius and pricing policy. A proof for one patient cannot be reused for another patient, even by the same authorized staff account using the same visit address. Missing-patient legacy proofs require validation again. Proofs and saved approvals do not grant access: every use rechecks current patient and recipient authorization, including replay after withdrawal. Existing encrypted distance quotes, private-origin fingerprints, fee thresholds and approval limits remain intact.

Service calls acquire the shared clinic consent advisory lock through authorization, provider activity and the protected operation. The API retains its outer lock through its response and booking transaction. Where verification participates in an existing transaction, explicit current consent reads prevent an old consistent-read snapshot from ignoring a newly committed restriction. Direct callers combining verification with later writes must coordinate the whole transaction with the shared lock, as the API does; a returned proof is not continuing authorization for future work.

Standalone approve/revoke calls start their own transaction and lock the active clinic row before patient checks. Failure rolls back the mutation, including business audit failures. Nested approval/revocation mutation transactions are rejected without taking over the caller's transaction. Missing policy storage and lock timeouts fail closed. Revocation remains idempotent; a no-op still receives the central response audit.

Earlier provider requests or information already seen cannot be recalled. Withdrawal can commit only after the protected in-flight operation releases the shared lock; a timeout returns the existing retry error. The existing single-primary lock namespace, connection lifetime and throughput assumptions require hosted acceptance.

## Portal, audit and provider payloads

On authoritative 401/403/404 validation, approval or revocation failures, the staff booking flow discards its cached proof, approval flag and selected times, aborts pending approval lookup, and disables progression until another authorized check succeeds. This is cleanup after server interaction, not remote erasure of a previously loaded address. Customer requests remain scoped by their authenticated identity.

Central audit responses retain attribution and requested numeric patient/practitioner targets without recording street addresses, visit instructions, private-origin addresses or proof contents. Provider payloads contain address components and routing coordinates; they do not include patient-ID/name, clinical-answer, visit-instruction or proof fields. Addresses and attendance-related metadata can still be sensitive: provider terms, purpose, processing locations, retention and patient transparency remain independent release gates.

## Rollout and validation

No new schema migration is introduced. Existing migrations through 050 and the preceding access/consent changes are prerequisites. Deploy API and portal together: older staff portals omit the patient during validation and will now be refused. Renew in-progress coverage proofs after rollout. Existing saved approvals remain stored, but only current authorized lookups and booking checks can use them; restoration does not rewrite a directive or approval. Drain older API/worker versions and rehearse the updated synthetic workflows on the intended host.

Run [consent-address-coverage.php](../api/tests/integration/consent-address-coverage.php) through `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-address-coverage.php`. The test-only loopback router injects a synthetic maps transport for this suite, shared with its booking service. No production test bypass, external maps request, private environment file or real patient record is used.

Local results on 19 October 2026:

- 223 new SQL/HTTP coverage checks passed on MariaDB 11.4.8, including all four operations, positive relationships/delegation boundaries, negative actor/recipient permissions, patient self-context, proof rebinding, stale quotes/approvals, current transaction reads, inactive profiles/accounts, revoked assignments, invalid IDs, audit rollback, lock contention and missing policy storage. Denied paths assert zero provider calls and no approval/business-audit changes.
- The same run passed 74 booking-contact, 28 access-audit, 70 consent-directive, 59 dashboard/schedule, 61 notification, 94 travel, 107 time-off and 85 registration checks.
- Separate distance/waiver SQL acceptance passed 79 checks alongside 34 recurring-booking checks, preserving private-origin handling, fee boundaries, tier pricing, waivers, approval limitations and recurrence behavior.
- All 51 root PHP scripts passed, including 12 proof checks covering patient rebinding and legacy-proof refusal. Syntax checks for 91 source/public files and both new/changed integration sources passed.
- Four selected browser scenarios passed, including the extended mobile booking flow checking patient-bound validation and denied-revocation cleanup, customer saved-address discovery/booking and scoped practitioner mobile booking.
- Full public, portal and application-admin production build passed, including content, TypeScript and 2,077 English/French translations. Existing bundle-size warnings remain.

Hosted identity, database locking/isolation, provider/vendor, network and production performance acceptance remain outstanding. The full browser suite was not rerun and the original unrelated role-policy failure remains outside this change. The [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open; local tests do not approve real-data use or certify compliance. Recurring availability mutations and one-time overrides are addressed in the subsequent [availability authorization change](CONSENT_AUTHORIZED_AVAILABILITY.md). Unclassified administrative routes remain conservative.
