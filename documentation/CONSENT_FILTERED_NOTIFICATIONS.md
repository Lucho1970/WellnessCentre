# Consent-filtered notification lists and reviews

19 October 2026. Branch `feature/consent-filtered-notifications` builds on the [filtered dashboards and schedules](CONSENT_FILTERED_DASHBOARDS_SCHEDULES.md) and [consent directive enforcement](CONSENT_RESTRICTIONS_WITHDRAWAL.md).

## Lists and counts

`GET /api/v1/admin/notifications` retains its Super Admin/Clinic Admin requirement. The permitted patient predicate now runs before status counts, totals, ordering and pagination. Channel/date filters and the existing all-status summary behavior remain unchanged. The scheduler's overdue count already uses the same predicate.

`GET /api/v1/practitioner/notifications` retains its practitioner role, own recipient ID and supported staff-event types. It additionally requires an active practitioner profile and active same-clinic staff account. Delegated scheduling, combined roles and submitted recipient/clinic IDs do not broaden this own-history scope. Historical reassignment-away notices remain eligible for their original recipient; current appointment assignment is separately checked before a retry.

Both lists use the existing shared patient resolution: the notification's same-clinic appointment, or a same-clinic client recipient when the appointment is null. Missing/mismatched appointment links and unscoped staff-only/orphan events are omitted. Payload text and destination strings are not used as proof of patient identity. These events remain stored, but require a controlled privacy/operations investigation rather than an unscoped application review.

Global and named `staff_access` directives apply to the viewer. Communication withdrawal does not remove a permitted administrator's ability to inspect or resolve an event. An all-restricted view returns normal empty items and zero counts, without disclosing a suppressed-record count. Missing consent storage fails closed.

## Review and retry authorization

`POST /api/v1/admin/notifications/{id}/review` retains the same administrator roles. It acquires the clinic consent lock, then reads/locks only an event the reviewer is currently allowed to access. Hidden, foreign, unscoped and absent IDs return the same `404 notification_not_found`, before decision/status validation. A previously loaded list or a submitted client/clinic ID cannot authorize the review.

Permitted resolutions still require provider-history and recipient-check confirmations. Status update, `notification_reviews` evidence and the `notification.review` business audit commit together; required business-audit failure rolls them back. Repeated or stale decisions cannot append another review. The response-boundary access audit logs successful or denied access without copying contact strings or payloads.

An explicit retry only queues an eligible recent staff SMS; it does not send it immediately or reset its attempt count. Existing event-type, appointment-status, one-hour age, attempt-cap, provider-ID, sent-at and supersession rules remain. Supersession lookup is now explicitly clinic-scoped. Email, reassignment-away notices and older/ambiguous/already-accepted messages still require manual handling.

Retry also requires the current recipient to be permitted under the patient's staff-access instruction and to pass the worker's shared recipient checks: active same-clinic staff account and practitioner profile, current appointment assignment, SMS preference enabled, allowed Canadian number and unchanged destination. A reviewer with access cannot queue a notice for a restricted or otherwise unauthorized recipient. Failures return `409 retry_unavailable` without changing the event or creating review evidence. Resolution remains available to a permitted reviewer without creating a new outbound disclosure.

`StaffNotificationRecipient` now supplies the existing destination/assignment checks to both review and delivery, including the worker's historical reassignment-email checks. The worker rechecks consent and recipient eligibility before preparing/sending, so a withdrawal after an approved retry cancels its queued delivery. Clinic advisory locking prevents review from racing a committed withdrawal; direct review-service calls use that lock as well as API requests.

## Browser behavior

List refreshes reconcile open details with the permitted rows; failed refreshes clear cached rows and details. If a review returns an authentication/access/not-found denial, the administrator's drawer and cached list are cleared and the filtered list is reloaded. A bilingual message explains that the notification is no longer available. This does not recall information already seen or messages already delivered, and it does not claim immediate removal from an idle browser without a new server interaction.

## Rollout and validation

No new database migration is required. Migrations 049/050 and the preceding consent/access changes remain prerequisites. Deploy the new recipient helper with the API and notification workers; retain the coordinated rollout and hosted advisory-lock/performance validation described in the preceding consent notes. Existing audits, provider confirmations, patient-rights procedures and PHIPA real-data gates remain required. This branch is for review, without a merge or deployment.

Local validation:

- 61 new SQL/HTTP notification authorization checks passed on disposable MariaDB 11.4.8, including pagination beyond a hidden page, filtered counts, role/clinic isolation, stale decisions, recipient changes, queued delivery cancellation, audit rollback, lock contention, restoration, empty views and missing policy storage.
- The same run passed 74 booking-contact, 28 access-audit, 69 updated withdrawal and 59 dashboard/schedule checks. The withdrawal suite now expects per-record filtering for these routes rather than their removed whole-operation blocks.
- All 51 root PHP scripts and syntax checks for 91 source/public PHP files passed.
- Five selected browser checks passed, including three new review/refresh cases that verify stale contact details are removed after denial.
- The full public/portal/application-admin frontend build passed, including content, TypeScript and 2,073 bilingual translations. Existing bundle-size warnings remain.

Run `scripts/test-recurring-sql.ps1 -IntegrationTest consent-notifications.php` with a disposable localhost MariaDB runtime. It uses synthetic signed identities and a loopback API; it does not load private environment files, real patient records or real message transports. Hosted migration, identity-provider, provider-delivery and production performance acceptance have not been performed. The full browser suite was not rerun.
