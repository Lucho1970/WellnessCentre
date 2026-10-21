# Consent-authorized bulk time-off changes

19 October 2026. Branch `feature/consent-authorized-time-off` builds on `feature/consent-filtered-travel-enquiries`. Create, update and delete time-off routes now authorize the full affected appointment set instead of blocking staff because an unrelated patient has restricted access.

## Authorization and behavior

- `POST /api/v1/admin/time-off` checks every non-canceled appointment overlapping the proposed period for that practitioner in the active clinic.
- `PATCH /api/v1/admin/time-off/{id}` checks both the existing and proposed practitioner/period. Moving, shrinking or reassigning a block cannot bypass a restriction in the period being released.
- `DELETE /api/v1/admin/time-off/{id}` checks the existing period before deleting. Deletion and its business audit are now atomic.
- A global or actor-specific `staff_access` restriction on any affected patient denies the entire operation with 403 `time_off_access_restricted`. Administrators and combined roles cannot override it. There is no partial mutation or hidden affected-record count, patient identity or appointment-ID list in the error.
- An unrelated restriction does not block empty periods or periods containing only permitted patients. A restriction targeting another staff member does not restrict the acting staff member. Restoration is evaluated on the next request through the existing consent workflow.
- Existing staff role, clinic, practitioner ownership/management and location-assignment rules remain required. Direct service calls also verify an active same-clinic staff actor and acquire the shared consent lock. Delegated booking permission does not grant another practitioner's schedule management.

The authorization query examines appointments without truncating them to the preview's 500-record cap or discarding invalid patient references through joins. Cross-clinic/unresolvable client, service or location references, and legacy merged duplicate clients, fail closed. A restricted appointment after 500 permitted rows still produces the generic privacy denial before a cap error. Fully permitted create/update periods retain the 500-appointment review limit; deleting a fully permitted block does not hydrate or truncate its affected set.

Existing interval semantics remain unchanged: actual appointment start/end overlap, excluding canceled appointments and including completed appointments; touching only a boundary is not overlap. Time off covers this practitioner's active-clinic schedule across locations. It does not change the same person's separate clinic schedule. No appointment is canceled, rescheduled or reassigned by these operations, and no notification is sent. The response's existing affected count and optional expected-ID comparison concern the proposed period after full authorization. Optional legacy review acknowledgements do not bypass consent authorization.

## Transactions, concurrency and portal

Each mutation takes the database/clinic consent advisory lock, starts a transaction and locks the active clinic row before loading the actor, target record or affected set. Updates and deletes recheck ownership and lock the time-off row inside that transaction. Booking mutations already use the same clinic row lock. Consent changes use the same advisory lock. Authorizations, writes and business audit events therefore share the mutation boundary; failures roll back, including delete audit failures. Central response auditing still records denied access with the requested target, without disclosed patient resources or contents.

The API's outer consent lock remains held through the response. The existing single-primary lock namespace, connection lifetime and throughput assumptions still apply. Old application versions and direct database writers cannot be controlled by these checks; drain older deployments during rollout and restrict direct writes.

The portal provides the generic denial in English and French. Failed saves clear the reviewed appointment list and require another preview before retrying. A denied removal clears the selected time-off details and review state. Neither path reports a successful save. These controls do not erase information already seen or copied.

## Rollout and checks

No new schema migration is required. Existing migrations through 050, including 049 access auditing, are prerequisites. Deploy the API and portal together and rehearse synthetic mutation/withdrawal scenarios on the intended host before real patient data. Confirm `GET_LOCK`/`RELEASE_LOCK`, transactional tables, runtime audit privileges, concurrent booking/withdrawal behavior and acceptable latency. Existing privacy, identity, retention, vendor and organizational release gates in the [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md) remain outstanding.

The versioned SQL/HTTP suite is [consent-time-off.php](../api/tests/integration/consent-time-off.php), runnable through `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-time-off.php`. It uses disposable localhost MariaDB, synthetic signed identities and the preceding consent regression suites. It does not load private environment files, hosted databases or real patient data. Portal regressions cover permitted preview/save and privacy-denied save/removal.

Local validation on 19 October 2026:

- 107 bulk time-off SQL/HTTP checks passed on MariaDB 11.4.8: permitted empty/affected periods, mixed restrictions, old/new periods, stale review and withdrawal, named/global restoration, administrator bypass attempts, direct service calls, role/clinic/location isolation, inactive accounts/profiles, canceled/completed/boundary cases, hidden rows beyond the cap, invalid/merged references, audit rollback, lock contention and missing policy storage.
- The same successful run passed 74 booking-contact, 28 access-audit, 70 consent-directive, 59 dashboard/schedule, 61 notification and 94 travel enquiry/message checks.
- All 51 root PHP scripts, syntax checks for 91 source/public files and the new integration script, and PowerShell runner parsing passed. Local RSA tests used the installed Git OpenSSL configuration via `OPENSSL_CONF`.
- Five selected browser scenarios passed across targeted runs: denied save/removal, existing preview/save/follow-up, practitioner availability navigation and dashboard time-off follow-ups. Fixture assertions were corrected for the API's 201 creation response, delete argument positioning, appointment buffer constraints and the modal drawer interaction.
- Full public, portal and application-admin production build passed, including content validation, TypeScript and 2,076 English/French translations. Existing bundle-size warnings remain.

Hosted database, identity-provider, concurrency and production performance acceptance remain outstanding. The entire browser suite was not rerun; the unrelated broad-suite role-policy failure recorded in the earlier review remains outside this change.

This work is an application authorization improvement, not PHIPA certification or hosted acceptance.
