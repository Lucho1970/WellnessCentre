# Wellness Centre Documentation

- [Consent-authorized reminder schedules](CONSENT_AUTHORIZED_REMINDER_SCHEDULES.md): complete pending queue authorization, strict worker timing parsing, atomic cancellation audits and settings denial handling.

- [Restricted privacy-audit review](PRIVACY_AUDIT_REVIEW.md): explicit clinic permissions, minimal searchable access history, audited reviews/grants and migration 051.

- [19 October current application release](CURRENT_APP_RELEASE_2026-10-19.md): corrected Livin Lively portal destination, cumulative consent changes, archive destinations and migration prerequisites.

- [Practitioner enquiries and FAQ](PRACTITIONER_ENQUIRIES_RELEASE.md): public joining form, private admin inbox, editable central notification recipient and migration 047.

- [Public services price correction](PUBLIC_SERVICE_PRICE_FIX.md): one-file API patch for services failing after the Wellnexa discovery release.

- [Wellnexa discovery and social profiles](WELLNEXA_WEBSITE_DISCOVERY_RELEASE.md): sticky navigation, service/profile pages, joining information and practitioner social links; migration 046 and deployment.

- [Starter form templates](../api/database/templates/README.md): bilingual massage intake JSON and repeatable SQL submission to the global library.

- [Form library](FORM_LIBRARY_RELEASE.md): approved global/clinic templates, previews, independent drafts and editing controls; migration 045 and deployment.

- [Practitioner travel settings](PRACTITIONER_TRAVEL_SETTINGS_RELEASE.md): edit own clinic-local travel thresholds, fees and buffers; API patch and shared portal deployment.

This folder contains the living technical and delivery documentation for the Wellness Centre scheduling platform.

- [Ontario PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md): implemented safeguards, application gaps, unverified hosting/operational controls and required gates before using real patient data.
- [Practitioner booking contact access](PRACTITIONER_BOOKING_CONTACT_ACCESS.md): relationship-scoped contacts/addresses, direct-booking bypass protection and synthetic authorization acceptance.
- [Sensitive access audit](SENSITIVE_ACCESS_AUDIT.md): response-boundary read/search/export coverage, denied-access logging, migration 049, failure behavior and acceptance evidence.
- [Consent restrictions and withdrawal](CONSENT_RESTRICTIONS_WITHDRAWAL.md): patient controls, negative access permissions, queued-message enforcement, migration 050, conservative bulk-route limits and synthetic authorization acceptance.
- [Consent-filtered dashboards and schedules](CONSENT_FILTERED_DASHBOARDS_SCHEDULES.md): permitted-record counts, day cards, next visits, schedule impacts and notification activity without a clinic-wide consent block.
- [Consent-filtered notifications](CONSENT_FILTERED_NOTIFICATIONS.md): permitted notification lists/counts, current review and retry authorization, recipient rechecks and stale-contact browser handling.
- [Consent-filtered travel enquiries and messages](CONSENT_FILTERED_TRAVEL_ENQUIRIES.md): permitted inboxes/unread counts, thread actions, patient history, recipient restrictions and stale-thread browser handling.
- [Consent-authorized bulk time-off](CONSENT_AUTHORIZED_TIME_OFF.md): complete affected-set authorization for create/update/delete, old/new-period checks, atomic audit rollback and denial handling.
- [Consent-authorized client registration](CONSENT_AUTHORIZED_CLIENT_REGISTRATION.md): scoped duplicate review, hidden-match refusal before confirmation, legacy identity checks and remaining conservative-route inventory.
- [Consent-authorized address coverage](CONSENT_AUTHORIZED_ADDRESS_COVERAGE.md): patient/recipient checks before maps requests, patient-bound proofs, atomic approvals/revocations and denial cleanup.
- [Consent-authorized availability](CONSENT_AUTHORIZED_AVAILABILITY.md): old/new recurring rule and override windows, buffered appointment authorization, open-ended recurrence and atomic mutation audits.

Start with these two authoritative documents:

- [Master Requirements](MASTER_REQUIREMENTS.md): product scope, retained features, role permissions, implementation baseline, delivery stages and acceptance criteria.
- [System Design](SYSTEM_DESIGN.md): public/portal architecture, identity and account linking, API/data boundaries, transactions, deployment, migrations and testing.
- [MT0 architecture and inventory](MT0_IDENTITY_AND_PRACTICE_ARCHITECTURE.md): source findings, additive data model, practitioner invitation journey and migration sequencing.
- [Multi tenant development path](MULTI_TENANT_DEVELOPMENT_PATH.md): provider-neutral login, application-owned clinic membership, isolation gates and sequencing before a second clinic.
- [Practitioner invitation pilot](PRACTITIONER_INVITATIONS.md): disabled staff registration, manual invitations, administrator approval, dedicated External ID apps and rollout checks.
- [Client overview](CLIENT_OVERVIEW.md): appointment history, saved changes, configured practitioner access, role/clinic boundaries and deployment acceptance.
- [Appointment action links](APPOINTMENT_ACTION_LINKS.md): sign-in-required email links, expiry/revocation, cancellation fee acknowledgement, migration and activation checks.
- [Deployment layout](DEPLOYMENT_LAYOUT.md): confirmed marketing folders, shared clinic portal, general landing and private API; package destinations and configuration.
- [Runtime website configuration](RUNTIME_WEBSITE_CONFIGURATION.md): live public website destination, temporary .com cutover, deployment order and checks.
- [Practitioner external link plan](PRACTITIONER_EXTERNAL_LINK_PLAN.md): optional website/social-page links for independent practitioners and portal-only virtual clinics; local implementation and pending hosted acceptance.
- [Practitioner person card](PRACTITIONER_PERSON_CARD.md): preferred public name, biography, optional public contacts, and hover previews.
- [Public website content](PUBLIC_CONTENT.md): bilingual Markdown locations, validation rules, editing workflow and the boundary between editorial content and API-backed catalogue data.
- [Public practitioner directory](PUBLIC_PRACTITIONER_DIRECTORY.md): published practitioner projections, directory/profile behavior, preferred names and practitioner-first booking acceptance.
- [Configurable branding](CONFIGURABLE_BRANDING.md): business logo/favicon roles, secure image constraints, migration and deployment acceptance.
- [Dashboard widgets](DASHBOARD_WIDGETS.md): governed widget registry, authorized data projections, per-workspace personalization, delivery sequence and acceptance criteria.
- [Limited testing readiness](PILOT_TEST_READINESS.md): hosted acceptance gates, safe pilot journeys, feedback capture and current scope limits.
- [Temporary hosted test suite](HOSTED_TEST_SUITE.md): Netfirms upload layout, private PHP checks, one protected results page and required cleanup.
- [Client intake and consent forms](CLIENT_INTAKE_FORMS.md): versioned questionnaires, client assignments/submissions, restricted practitioner answers and migration 036 pilot acceptance.

## Supporting records and historical sources

- [12 October development checkpoint](GIT_CHECKPOINT_2026-10-12.md): integration baseline, validation evidence, coordinated release and the next forms slice.

- [Public/portal separation checkpoint](PORTAL_SEPARATION.md): build outputs, routes, preserved permissions, subdomain deployment steps and acceptance checks.
- [Portal treatment catalogue](PORTAL_CATALOGUE.md): configurable bilingual welcome text, category navigation, public practitioner previews, booking entry points and migration 028.

- [Staff booking checkpoint](STAFF_BOOKING.md), [Client management](CLIENT_MANAGEMENT.md) and [Booking validation tests](BOOKING_VALIDATION_TESTS.md) describe specific implementation/test checkpoints; they do not establish production readiness.
- [Practitioner appointment management](PRACTITIONER_APPOINTMENTS.md) records the scoped practitioner booking, rescheduling, cancellation, authorization and deployment checkpoint.
- [Cancellation policy](CANCELLATION_POLICY.md) covers service policy configuration, appointment snapshots, client fee previews, authorized waivers, SQL deployment, and acceptance checks.
- [User manual](USER_MANUAL.md) is the non-technical, role-oriented guide for staff using released portal features. Expand it as each administration area adopts the list-and-command-bar model.
- [Client duplicate prevention and merge](CLIENT_MERGE.md) records email aliases, duplicate warnings, Super Admin merge controls and migration 006.
- [Recommended public site structure](Recommended_site_structure.md) records reviewed UX and content guidance for public discovery. Accepted scope, safeguards and sequencing are incorporated into the Master Requirements and System Design; the guidance is not a separate master specification.
- [API setup](../api/README.md) and [Entra setup](../ENTRA_SETUP.md) remain component runbooks. Follow the master documents if a cross-system design statement conflicts.
- [Original product requirements](wellness-centre-app-requirements.docx), [former solution plan](SOLUTION_BUILD_PLAN.md), [site restructuring proposal](SITE_STRUCTURE_REQUIREMENTS.md) and [former database plan](../api/DATABASE_PLAN.md) are preserved historical inputs, superseded as master specifications.
- Upgrade SQL lives in `api/database/migrations/`; `api/database/schema.sql` is the fresh-install schema, not a bulk upgrade for an existing database.

## Document maintenance

- Update Master Requirements when scope or acceptance changes and System Design when architecture changes. Keep stable requirement IDs traceable to implementation and tests.
- Do not create another competing master plan. Put procedural details and release evidence in linked supporting documents.
- Mark work complete only after its acceptance criteria and tests pass.
- Never place credentials, tenant secrets, database passwords, client records, or production configuration values in documentation.
- Keep implementation-specific setup instructions beside the relevant component, such as `api/README.md`, while keeping cross-solution decisions here.
