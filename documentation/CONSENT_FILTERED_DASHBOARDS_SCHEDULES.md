# Consent-filtered dashboards and practitioner schedules

19 October 2026. Branch `feature/consent-filtered-dashboards-schedules` builds on the [consent restriction and withdrawal implementation](CONSENT_RESTRICTIONS_WITHDRAWAL.md).

The following views now return records and counts permitted for the authenticated staff account instead of blocking the entire operation when any patient has restricted access:

| View | Filtered information |
| --- | --- |
| Operations/practitioner dashboards | Appointment counts, next appointment, built-in and custom widget projections. |
| Practitioner time-off follow-ups | Both distinct appointment count and the first five permitted appointments. Duplicate time-off overlaps do not inflate counts. |
| Dashboard email/SMS activity | Today/seven-day channel and status totals, unresolved review total and overdue queue count. |
| Practitioner today | Client contact cards and associated visit steps, filtered before the 50-card cap. |
| Practitioner seven-day activity | Appointments filtered before the 500-visit cap and before milestone loading/counting. |
| Next On-Site visit | The earliest permitted visit in the existing 24-hour window. Its address cannot be sent to the route provider after access is restricted. |
| Schedule exceptions and time-off impact/preview | Permitted affected-appointment counts and details, filtered before the impact limit. Completed appointments remain included where the existing impact rules include them. |
| Schedule setup | Existing role-scoped availability rules and practitioner availability context remain available; they do not disclose patient records. |

Appointment/calendar list filtering from the previous branch remains in force. Filtering uses the verified staff user and clinic, not submitted clinic/practitioner IDs. Own-practitioner views retain their own-practitioner scope even with delegated scheduling. Administrators can use their existing Operations permissions but cannot override a patient's restriction. Global and named restrictions both apply. An inactive practitioner profile has no own day cards, next visit or time-off impact records.

The predicate runs in SQL before aggregation, sorting, limits, cap checks and event hydration. There is no hidden-record count or suppressed-record placeholder. When every eligible patient is restricted, views return normal empty lists, zero counts and null next appointments. Patient self-access is unchanged. Built-in Operations widget descriptions clarify that counts concern records the viewer can access; custom widget authors should use the same scope in their wording.

## Notification activity and operational health

A notification's patient is resolved through its same-clinic appointment. For an appointment-less message, its recipient must be a client in that clinic. Staff notices with appointments inherit the appointment patient's restriction. Events with no provable patient association, missing appointment links or mismatched appointment clinics are excluded from these dashboard patient-activity totals, even without an active restriction. Payload JSON and destination strings are never used to infer patient identity.

Appointment email and SMS buckets, unresolved totals and overdue counts use that same boundary. The scheduler's existing process status and run timestamps remain operational metadata; the overdue patient-message count is filtered. The subsequent [notification filtering change](CONSENT_FILTERED_NOTIFICATIONS.md) applies that patient boundary to notification lists and adds current authorization for reviews/retries.

## Authorization that remains restrictive

- Direct visit-history, milestone, outcome, travel-estimate and appointment actions still deny restricted patient access. Visit and travel service queries enforce the restriction even when called directly.
- Time-off previews are filtered reads. Bulk create/update/delete operations retain the existing conservative API block: their full affected set is needed for safe mutation and impact confirmation. A filtered preview is not authorization to change hidden appointments. Mutation logic still examines its complete affected set.
- Travel-enquiry routes and other unclassified routes retain the existing conservative boundary. Notification lists/review now have their own tested filtering and authorization, described in the follow-up above.
- Public availability and booking conflict checks still treat all occupied slots as busy. Hiding records does not make their times available for double booking.

No consent, relationship, role, clinic or patient-rights grant is added by this change. The clinic advisory lock continues to serialize disclosures, withdrawals and notification delivery. Missing consent storage blocks patient-bearing queries, and response audits record disclosed resource IDs without including hidden appointments or clinical content.

## Deployment and verification

No new schema migration is required. Migrations 049 and 050 and the preceding access/consent changes are prerequisites. Deploy the API changes together; retain the previous rollout's coordination, runtime database privileges and hosted advisory-lock checks. This work has not been deployed or merged and does not close the original PHIPA real-data release gates.

Run `scripts/test-recurring-sql.ps1 -IntegrationTest consent-schedules.php` with a disposable localhost MariaDB runtime. The test starts a loopback API with synthetic signed identities and includes the prior booking-contact, access-audit and withdrawal suites. It seeds hidden records beyond the day/summary limits and verifies permitted counts, next-record selection, event summaries, time-off impact, notification scoping, custom widgets, delegation, role/clinic isolation, restoration interactions, empty views, denied writes and missing storage. It does not load private environment files or hosted records.

Local validation on 19 October 2026:

- 59 new dashboard/schedule SQL/HTTP authorization checks passed on disposable MariaDB 11.4.8, alongside 74 booking-contact, 28 access-audit and 71 withdrawal checks. Stored custom-widget projections were tested; widget publication is outside this read-authorization test.
- All 51 root PHP scripts and syntax checks for 90 source/public PHP files passed.
- Ten selected dashboard, practitioner calendar, visit activity, day-card and time-off browser regressions passed.
- The full public/portal/application-admin frontend build passed, including content, TypeScript and 2,072 bilingual translation checks. Existing bundle-size warnings remain.

Hosted database, identity-provider and production performance acceptance remain outstanding. The entire browser suite was not rerun; this does not close the unrelated broad-suite role-policy failure recorded in the original readiness review.
