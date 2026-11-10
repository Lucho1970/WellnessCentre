# Practitioner access route review — 10 November 2026

**Scope:** `bugs/practitioner-access-remaining-paths`, based on `6869c1b`. This is a local continuation of the [care-assignment boundary](PRACTITIONER_CLIENT_ACCESS_BOUNDARY_2026-11-06.md). No production, hosted database, identity configuration, agreement, or external provider was changed. The parent governance branch and migrations 062–064 remain unmerged and undeployed. Do not deploy this branch alone.

## Reviewed paths and decisions

| Path | Local result | Remaining decision or verification |
| --- | --- | --- |
| Appointment list, detail, calendar, reschedule/cancel, recurring-series management | Practitioner-only access now requires an active local staff account, active practitioner profile and current care assignment for the client. Scheduling delegation does not grant unassigned client appointment details or a mutation shortcut. Clinic admin/reception and client scopes keep their separate authority. | Test hosted identities, role combinations, concurrent revocation, older inactive-client records and realistic volume. |
| Practitioner day cards, visit history/outcomes, travel estimates, logistics notes | The same assignment check applies before client contact, visit directions, notes, destination or external route request. | Hosted and accessibility checks; confirm whether any approved transition task needs a narrow alternative. |
| Practitioner dashboard and time-off impact | Appointment projections and affected-client lists/counts apply current care authority. Mutations fail if an overlapping appointment is outside current authority. | The notification-summary widget requires clinic-wide notification capability and is not a practitioner projection; review other aggregates as features are added. |
| Client invitation outbox | The queued mail worker rechecks current care assignment immediately before provider submission, replacing historical `client.create` evidence. Current permission, identity, pilot and restriction checks remain. | Hosted worker timing and operational monitoring. |
| Staff appointment email/SMS | Recipient authority now checks current care assignment before transport; a queued notice is canceled after revocation. The care-assignment writer and worker use the clinic consent lock through the provider call. | Rehearse cancellation and budget effects on a synthetic host. |
| Receipts and travel-fee adjustment | Existing practitioner routes still permit historical access based on appointment ownership. | Owner decision pending on post-assignment practitioner access to historical financial records; apply it consistently to receipts and travel fees. |
| Practitioner notification history | Own-recipient and current-care filters now hide historical appointment notifications after assignment revocation, before pagination and counts. The clinic-wide notification-summary widget is unavailable in the practitioner workspace. | Verify hosted audit and retention handling; historical messages remain in the clinic record. |

No automatic care assignment is created from an appointment, client creation, form task or notification. Existing relationships require a clinician-reviewed administrative assignment workflow before this access model can be enabled. The code rollback would restore broad historical appointment and worker access; treat it as a privacy decision rather than a safe automatic fallback.

## Changed files

| File | Purpose |
| --- | --- |
| `api/src/Service/BookingClientAccess.php` | Shared active-care SQL predicate with fixed client expressions. |
| `api/src/Service/BookingService.php` | Apply current care to appointment list/detail/calendar/change. |
| `api/src/Service/PractitionerVisitService.php` | Apply current care to day, summary, history and outcome paths. |
| `api/src/Service/PractitionerTravelService.php` | Hide next visit and stop travel-provider requests after care ends. |
| `api/src/Service/AppointmentLogisticsNotesService.php` | Apply current care to logistics note reads and writes. |
| `api/src/Service/DashboardService.php` | Scope practitioner appointment widgets and follow-ups. |
| `api/src/Service/AdminService.php` | Scope practitioner time-off counts, previews and mutation authority. |
| `api/src/Service/ScheduleChangeAccess.php` | Deny practitioner schedule changes touching unassigned clients. |
| `api/src/Service/CommitSafeEmail.php` | Recheck assignment at invitation dispatch. |
| `api/src/Service/StaffNotificationRecipient.php` | Recheck assignment at staff email/SMS dispatch. |
| `api/src/Service/NotificationStatusService.php` | Hide own appointment-notification history when care assignment ends. |
| `api/src/Service/ClientOverviewService.php` | Present assignment-limited access in Admin. |
| `Frontend/src/clients/ClientOverview.tsx` | Explain the effective appointment scope. |
| `Frontend/src/i18n/clinicRecords.ts` | English and French scope labels. |
| `api/tests/client-overview.php` | Scope policy checks. |
| `api/tests/integration/booking-client-access.php` | Appointment history/delegation/revocation checks. |
| `api/tests/integration/consent-schedules.php` | Care-aware day/travel/logistics regression fixtures and revocation checks. |
| `api/tests/integration/commit-safe-email.php` | Invitation queued before care revocation. |
| `api/tests/integration/automated-communications.php` | Staff notice canceled after care revocation. |
| `api/tests/integration/consent-notifications.php` | Notification-history revocation and pagination check. |
| `Frontend/tests/client-overview.spec.ts` | Admin access wording and no-assignment presentation. |

No dependency or lockfile version, schema, grant, new endpoint, consent text, provider or configuration change. Data flows are narrowed: practitioner PHI reads and two queued communications stop when care authority ends. Local Node 22.22.1, npm 11.19.0, PHP 8.4.14 and disposable MariaDB 11.4.8 are the checked runtimes; deployed versions remain unverified.

## Change-scoped 38-control ledger

**Reviewer:** Codex, 10 November 2026. Status is limited to this branch, not the whole organization. Custodian, clinical, privacy/legal, security/operations and release owners must record dated decisions. Missing evidence is not proof of organizational noncompliance.

| Control | Status | Evidence / next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic care model inherited; owner/legal confirm agency and tenant boundaries. |
| GOV-02 | Unverified | Privacy/legal update applicability and RMT standards at release. |
| GOV-03 | Unverified | Privacy/legal assess non-health and cross-border data. |
| GOV-04 | Partial | Administrative record access only; clinical owner confirms intended use. |
| GOV-05 | Partial | Route inventory updated here; PIA/TRA and flow diagram need owner review. |
| PRIV-01 | Unverified | Existing notice unchanged; custodian rechecks it against care workflow. |
| PRIV-02 | Partial | Less access/disclosure; owner approves assignment and historical receipt decisions. |
| PRIV-03 | Partial | Changed routes recheck care; receipt, other exports and hosted races remain. |
| PRIV-04 | Unverified | Minor/representative process remains outside this slice. |
| PRIV-05 | Missing | Tracked patient access/correction process remains outstanding. |
| PRIV-06 | Missing | Profession-specific retention/hold/disposal remains outstanding. |
| PRIV-07 | Unverified | Breach runbook not rehearsed in this slice. |
| AUTH-01 | Partial | Existing identity context retained; hosted token/realm verification outstanding. |
| AUTH-02 | Partial | Assignment/account and role checks tightened; endpoint inventory and cross-clinic host checks remain. |
| AUTH-03 | Partial | Local revocation tests; hosted session and in-flight effects remain. |
| AUTH-04 | Unverified | No credential storage change; existing exception review remains. |
| AUTH-05 | Unverified | No grant changed; deployed API/DB permissions need review. |
| SEC-01 | Partial | Added-line keyword scan found no credential, private-key or endpoint values; no release artifact prepared. Historical exposure review remains. |
| SEC-02 | Unverified | No transport/storage change; operations verifies TLS and backup. |
| SEC-03 | Unverified | Hosted headers/cache/shared-device behavior outstanding. |
| SEC-04 | Partial | Fixed SQL expressions and bound values; final security review required. |
| SEC-05 | Partial | Existing query/worker caps retained; representative load and race tests remain. |
| SEC-06 | Partial | Existing read/send audits retained; audit outage and monitoring review remain. |
| SEC-07 | Partial | Existing safe denials retained; hosted log redaction review remains. |
| SEC-08 | Not applicable | No new provider or endpoint; travel provider is denied sooner. |
| SEC-09 | Partial | Queued staff and invitation sends recheck current authority; hosted send/retry review remains. |
| DEV-01 | Partial | Dedicated branch and source/config/migration review; commit/push follows this checkpoint. |
| DEV-02 | Partial | Focused authorization changes; final code review pending. |
| DEV-03 | Unverified | No lockfile change; exact-lock audits remain a release check. |
| DEV-04 | Partial | Local test results below; hosted and omitted suites remain. |
| DEV-05 | Partial | Bilingual Admin wording; manual keyboard/AT/zoom/French review outstanding. |
| DEV-06 | Unverified | Assignment index exists; representative query/bundle performance outstanding. |
| REL-01 | Partial | This dated ledger; named reviewers and final SHA outstanding. |
| REL-02 | Partial | Changed-surface local checkpoint passed; omitted release suites and hosted acceptance are below. |
| REL-03 | Missing | No artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Open financial-history, rights, retention, operations and hosted gates prevent release. |
| REL-05 | Partial | No production/migration/grant action; distinct production authorization later. |
| REL-06 | Partial | Changed files, flows, risks and rollback recorded; final evidence follows. |

### Local evidence

`npm run build` passed lint, content validation, 2,663 bilingual translations, strict TypeScript and three production builds; existing large-chunk and mixed-import warnings remain. `php api/tests/client-overview.php` passed 65 checks. `php api/tests/practitioner-visits.php`, `php api/tests/practitioner-travel.php` and `php api/tests/appointment-logistics-notes.php` passed. `php -l` passed for all 18 changed PHP files. The disposable MariaDB `booking-client-access.php` suite passed 92 checks, `commit-safe-email.php` passed 188, `automated-communications.php` passed 59, `consent-schedules.php` passed 66, and the final `consent-notifications.php` run passed 62, including the new revoked-care history check. Nested suites also passed booking 92, access audit 28 and consent 71. The focused `client-overview.spec.ts` browser suite passed 5/5 and `playwright.build.config.ts` passed 16/16. `git diff --check` passed. An added-line keyword scan showed only test variable names and route paths, no credential values. No hosted database or real patient data was used. Exact-lock clean install, MySQL 5.7, full browser suite, hosted identity and manual accessibility tests remain unrun in this branch.

RELEASE STATUS: NOT READY FOR RELEASE
