# Consent-filtered travel enquiries and messages

Travel enquiry routes now use record authorization instead of blocking the entire staff inbox when any applicable patient restriction exists. This builds on the [consent directives](CONSENT_RESTRICTIONS_WITHDRAWAL.md), [filtered schedules](CONSENT_FILTERED_DASHBOARDS_SCHEDULES.md) and [notification authorization](CONSENT_FILTERED_NOTIFICATIONS.md) changes. It does not establish PHIPA readiness; the original [release gates](PHIPA_READINESS_REVIEW_2026-10-18.md) still apply.

## Access and recipient rules

The staff `GET /api/v1/travel-enquiries` and detail routes, plus POST reply/read/close routes, enforce the same scope in `TravelEnquiryService`, including direct service calls. The exact route family is exempted from the conservative API block because the service now owns authorization. Other unclassified routes remain blocked where applicable.

- An active same-clinic practitioner may access only enquiries assigned to their active practitioner profile. Super administrators, clinic administrators and reception have clinic-wide positive scope. Unsupported roles have no access.
- Global and actor-specific `staff_access` restrictions exclude the patient's threads, even for administrators or combined roles. Filtering happens before the 50-item limit, cursor and unread count. A hidden, foreign, unassigned or missing thread produces the same 404, including reply/read/close and replay attempts. Query parameters cannot broaden the actor's scope.
- Thread references must resolve to a same-clinic client, practitioner staff account, service and location. Legacy references to merged duplicate clients fail closed; there is no automatic travel-thread migration or implied consent transfer. Message authors must belong to the same clinic and be the thread client or staff.
- Patients retain access to their own history and read acknowledgements through the customer routes. They cannot read another patient's thread or acquire staff close authority. Withdrawal does not delete patient records.
- Creating or replying as a patient requires a currently active same-clinic practitioner recipient and no applicable access restriction for that recipient. A signed discovery token or an idempotent retry cannot restore permission. A restricted or inactive recipient returns 409 `travel_recipient_unavailable`. Restoration requires the existing explicit consent workflow; clearing a named restriction does not override a global restriction.
- A separately permitted administrator may reply while the assigned practitioner is restricted. This does not restore practitioner access. Existing appointment-communication preferences retain their separate scope; travel messages are in-app and this change sends no email or SMS.

## Concurrency, audit and browser behavior

List/count and detail/message queries hold the shared clinic consent lock, as do creation and thread actions. The API retains its outer lock through disclosure. Creation replays and reply replays recheck current authorization before returning success. Missing consent storage and unavailable locks fail closed. The existing lock throughput and single-primary-host assumptions still apply.

Replies, close actions and read markers commit with their business audit event; a business audit failure rolls back the mutation. Central API access auditing continues to record sensitive reads and denied attempts without storing message bodies, visit areas or drafts. Existing duplicate-safe references and bounded message/read cursors are preserved. Rate-limit bookkeeping and denied-access audit events may still be written on unsuccessful requests.

An authoritative 401/403/404 from the travel inbox clears loaded thread details, messages, list rows, pagination and drafts. A generation guard prevents earlier in-flight responses from repopulating the cleared view. The unread banner clears its cached count after a failed refresh. A patient-facing recipient conflict preserves their own history and editable draft; uncertain network/server failures retain the existing same-reference retry behavior. This is cleanup following a server interaction, not remote erasure of information already seen or copied.

## Rollout and validation

No new migration is introduced. Apply existing migrations through 050, including travel tables from 048 and access auditing from 049. Deploy the API and portal together, drain older versions and run synthetic acceptance on the intended host before using real patient data. Review legacy merged travel references through an approved migration process; do not repair them by bypassing consent checks.

Run the versioned suite using `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-travel-enquiries.php`. It uses disposable localhost MariaDB, synthetic identities and cached synthetic map results, without private environment files, external map requests or hosted patient data.

Local results on 19 October 2026:

- 94 new travel enquiry/message SQL/HTTP authorization checks passed on MariaDB 11.4.8. Coverage includes filtered pagination/unread counts, positive and negative authorization, named/global restrictions and restoration, patient history/outbound checks, stale replay, inactive recipients, audit rollback, lock contention and missing policy storage.
- The same run passed 74 booking-contact, 28 access-audit, 70 consent-directive, 59 dashboard/schedule and 61 notification checks. The directive test now exercises remaining conservative routes instead of the newly filtered travel list.
- All 51 root PHP scripts and syntax checks for 91 source/public files plus both changed integration scripts passed. The local RSA tests require the installed Git OpenSSL configuration (`OPENSSL_CONF`).
- Nine selected browser scenarios passed across targeted runs: four denial cleanup cases, retained patient history/draft on recipient conflict, duplicate-safe creation, permitted staff replies, and two public discovery/booking regressions. The customer test selectors were corrected to target the error and textbox precisely.
- Full public, portal and application-admin production build passed, including content validation, TypeScript and 2,075 English/French translations. Existing bundle-size warnings remain.

Hosted database, identity and operational acceptance remain outstanding. The unrelated full-browser role-policy failure recorded in the earlier review was not addressed; the entire browser suite was not rerun. These tests are not a compliance certification.
