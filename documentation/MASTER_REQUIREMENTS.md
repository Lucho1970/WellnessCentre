# Wellness Centre — Master Requirements

Version 1.2 · Updated 18 September 2026 · Product owner: Luis Duran

Onboarding checkpoint: new-client registration, reviewed existing-record invitations,
own contact profile/read-only appointments and server-tracked client sessions are now
implemented behind a default-off flag. Owner confirmed staff approval and 30-minute
client idle / 8-hour absolute limits. Hosted provider/Netfirms acceptance is still required.
See [CLIENT_ONBOARDING.md](CLIENT_ONBOARDING.md). This is part of R3, not completion of
all AUTH-04/05/06, CRM-03 or client booking requirements.

## 1. Authority and purpose

This is the single authoritative product requirements document: **what the solution must do, for whom, and how completion is judged**. [System Design](SYSTEM_DESIGN.md) defines how to build it. Component READMEs and test/release records describe implementation, not competing requirements.

This consolidation preserves the original product requirements, subsequent agreed development scope, and existing working features. The September site-structure proposal is additive: separate public and authenticated experiences, without replacing scheduling, client management, billing, privacy, or other planned modules. A schema table, a screenshot, or a deployed development build does not establish production readiness.

Sources reconciled: `wellness-centre-app-requirements.docx`, `SOLUTION_BUILD_PLAN.md`, `SITE_STRUCTURE_REQUIREMENTS.md`, `Recommended_site_structure.md`, `../api/DATABASE_PLAN.md`, current application source, and the staff-booking/client-management/test documentation. Older documents and public-site guidance are supporting sources; conflicts are resolved here and in System Design. Existing operational runbooks remain useful but must follow these two documents.

Requirement identifiers below are stable references for implementation and tests. “Must” describes required product scope, not a claim that the feature exists. Delivery stages sequence scope; they do not silently remove it.

## 2. Product scope and retained decisions

- **GOV-01 — Product:** A Canadian wellness-centre scheduling and practice-management system supporting clinic-managed services and room-rental practitioners managing their own bookings. Start with one centre; retain location-aware records and permission boundaries for growth.
- **GOV-02 — Technology:** Retain the React/TypeScript frontend, PHP API, MySQL database, and current Netfirms deployment. Do not rewrite completed functionality or move hosting merely to restructure pages.
- **GOV-03 — No production sample data:** No demonstration seed data in live deployment. Real operational configuration and approved user provisioning are distinct from test fixtures.
- **GOV-04 — Incremental delivery:** Use feature branches, verify bounded sections, commit and push completed work, document required SQL updates, and produce reproducible deployment packages. Merge tested work to main deliberately.
- **GOV-05 — Honest UI:** Dashboards use authorized real data or explicit empty states. Do not show invented counts, successful bookings, slot holds, sent messages, or payments that have not actually occurred. Hide unreleased navigation; a labelled preview must not resemble an operational feature.
- **GOV-06 — Scope boundaries:** This includes operational client relationship management, not a full sales/marketing CRM. Marketing automation, sales pipelines, native apps, third-party developer API access, telehealth, and AI assistance are future extensions, not current delivery commitments.

## 3. Public website and portal

- **EXP-01 — Separation:** Deliver a public marketing/discovery website and an authenticated operational portal on separately configurable hosts. Names such as `www.wellnesscentre.com` and `portal.wellnesscentre.com` in the proposal are examples, not purchased or approved domains.
- **EXP-02 — Public navigation:** Home, Services, Practitioners, New Clients, FAQs, Resources, About, Contact/Locations, Book Appointment and Login. Approved rooms/facilities content may live under About or Locations. Conditions/goals and classes/workshops appear only when the governed content and supporting domain workflow described below exist. Resources may initially be curated static content; a blog/content-management editor is not implicitly required.
- **EXP-03 — Public discovery:** Anonymous visitors browse published service detail pages and practitioner profiles as well as disciplines, duration choices, prices, preparation/policy summaries, eligible locations and available times. Service details explain who the service is for, the first visit, preparation/contraindication guidance, follow-up options, eligible appointment modes and providers. Practitioner details include approved photo, credentials, biography, areas of focus, treatment philosophy, languages, services, modes, pricing and a direct booking action. Filter by practitioner, service, specialty/focus, language, appointment mode, duration, date and location where supported. Public availability is a sanitized projection from the booking engine, never a raw staff calendar. Never expose client identities, private event details, mobile addresses or unpublished operational data.
- **EXP-04 — Authentication handoff:** Public login and booking confirmation lead to portal-hosted authentication. Retain non-sensitive booking preferences, then recheck availability and pricing after login. No access tokens or client information in handoff URLs.
- **EXP-05 — Role workspaces:** Provide `/client`, `/practitioner`, and `/admin` workspaces. Reception and accountants use permission-filtered operational navigation, not unrestricted administrator privileges. Users with multiple approved personas have a workspace switcher and a remembered eligible default; a URL or switch does not grant permissions.
- **EXP-06 — Client navigation:** Dashboard, Appointments, Book Appointment, Practitioners, Messages, Forms, Invoices, Profile. Show upcoming appointments, required forms, messages and notifications when those modules ship.
- **EXP-07 — Practitioner navigation:** Dashboard, Schedule, Clients, Treatment Notes, Messages, Availability, Reports, Profile. Prioritize today's appointments, upcoming work, relevant submissions and exceptions.
- **EXP-08 — Administration navigation:** Dashboard, Clients, Practitioners, Appointments, Billing, Reports, Settings, Audit Logs, User Management. Render only authorized, released modules. Operational alerts, volume, utilization and revenue must be permission-filtered.
- **EXP-09 — Account menu:** The top-right control is a round application-owned photo or initials, without a permanent name/“My account” label. The accessible menu contains identity details, eligible workspace switching, profile options, and sign out. Users upload their own photo; SuperAdmin can replace/remove inappropriate photos. Do not use the Azure profile photo.
- **EXP-10 — Usability:** Shared branding, typography and interaction patterns, but no marketing sections inside operational workspaces. Mobile-first client journeys, tablet-friendly practitioner work, responsive staff tables/calendars, keyboard access and clear validation. Target WCAG **2.2 AA**, retaining and strengthening the new proposal's 2.1 AA target.
- **EXP-11 — Localization:** All user-facing interface copy uses centralized language resources rather than embedded display text. English and Canadian French are the initial supported languages. Language choice is available on public, client and staff surfaces, persists in the browser, updates document language metadata, and controls locale-sensitive date, time, number and currency formatting. Business-entered content remains in its authored language until translated-content administration is explicitly introduced.
- **EXP-12 — Discovery paths and calls to action:** Support service-first, practitioner-first and guided-discovery entry paths backed by the same catalogue and availability engine. Published service cards and practitioner profiles provide direct booking actions; booking calls to action are present in primary navigation, relevant detail pages, the first-time-client path, footer and mobile navigation without obscuring informational content. Preserve selected service/practitioner/mode preferences through authentication, then revalidate server-side.
- **EXP-13 — Guided content and claims:** A “New here?” path explains how booking works, what to expect/bring, policies, payment/insurance information and how to ask for help. Conditions/goals content may map visitors to relevant published services or practitioner categories, but must be curated, non-diagnostic, professionally reviewed and free of unsupported medical claims. It must not present an automated recommendation as clinical advice.
- **EXP-14 — Trust, media and measurement:** Prefer approved photographs of the actual centre, rooms and practitioners, with consent, alt text, responsive optimization and replacement controls. Reviews/testimonials remain deferred until the owner approves consent, moderation, retention and applicable professional advertising rules. Measure privacy-safe page-to-booking conversion and abandonment without placing health interests, form answers, client identity or appointment details in analytics. Public pages require useful metadata, canonical URLs, sitemap behavior and appropriate structured data where accurate.

## 4. Identity and permissions

17 September delivery note: owner configured External ID with Google, personal Microsoft
and email one-time passcode for development. An isolated customer sign-in/API identity
checkpoint is implemented; hosted acceptance is pending. No local record linking, client
booking or clinical access is enabled by it. AUTH-04/05/06 and R3 remain open.

- **AUTH-01 — Staff:** Microsoft Entra workforce SSO remains the staff authentication strategy, with MFA enforced through the organization's identity policies. Creating/managing Entra accounts remains a separate administrative activity; the application provisions/links authorized local staff records and assignments. No new local staff password system is planned.
- **AUTH-02 — Clients:** Clients must not need an account in the clinic's workforce tenant. Customer authentication uses an approved standards-based identity service. A customer identity record at that service is acceptable; an organizational Microsoft 365 account is not required.
- **AUTH-03 — Provider scope:** Owner confirmed: Google and Microsoft **personal** accounts first; Apple and Facebook/Meta remain planned extensions. Confirm Microsoft-personal support in a proof of concept before selecting the broker. Optional email/password or passwordless email is an open product choice; do not implement local password storage by default. This stages, rather than deletes, original Meta support.
- **AUTH-04 — Account linking:** Authenticate with immutable provider identifiers. Never grant access to an existing client record solely because an email string matches. Support verified claiming of staff-created client records, explicit linking/unlinking of multiple providers, recovery, and audited duplicate-resolution workflows. Do not permit removal of the last viable sign-in method without recovery.
- **AUTH-05 — Personas:** One person may be both practitioner/staff and client. Client access must not inherit staff privileges, and social sign-in must never create staff access. Linking these identities requires explicit proof of both accounts or a reviewed recovery process.
- **AUTH-06 — Session control:** Clear sign-out, safe expiry/re-authentication, minimal scopes, protected tokens, and safe return paths. Owner-approved client limits: 30-minute inactivity and 8-hour absolute application session, implemented behind the onboarding flag with hosted acceptance pending. A 15-minute staff inactivity lock remains a proposed target, not an enforcement claim. Sensitive identity/financial/privacy actions require recent authentication.
- **AUTH-07 — API authority:** Enforce authentication, clinic/location scope, role and resource relationship on every protected operation. Neither frontend hiding, CORS, guessed object IDs, nor a browser-shipped secret is an authorization boundary. Role/status changes must affect subsequent API authorization.

### Role policy (target, not a statement of every current endpoint)

| Role | Allowed scope | Explicit restrictions |
| --- | --- | --- |
| SuperAdmin | Business branding, staff linking/role assignments, security/integrations, approved clinic administration and audit/compliance operations | Cross-clinic access must be explicit and audited; sensitive records still follow purpose-based access |
| ClinicAdmin | Assigned clinic's practitioners, rooms, services, schedules, bookings, operational settings and reports | No staff privilege assignment or platform identity/security configuration by default; clinical notes are not automatically available |
| Reception | Client contact records, scheduling, cancellation/rescheduling, waitlists, authorized payment recording and follow-up tasks | No clinical-note access or system/role management by default |
| Practitioner | Own availability, allowed services/preferences, create/manage own bookings for authorized clients, relevant records/forms/notes for clients with an authorized care relationship; limited related invoice visibility | No unrelated clients, other practitioners' private records, unrestricted finance or global settings |
| Accountant | Invoices, payments, refunds/reconciliation, finance reports/exports, explicitly delegated accounting integration operations | Minimum necessary client details; no clinical notes, scheduling administration or staff access management |
| Client | Own profile, appointments, forms, messages, invoices, payment history and privacy requests | No other clients or staff administration |

**AUTH-08:** Owner confirmed keeping separate permissions. Do not collapse reception or accountant into a generic administrator role. Restrict staff permissions/system configuration to SuperAdmin unless the owner explicitly approves a delegated permission. Existing SuperAdmin-only catalogue operations can be expanded to ClinicAdmin only with API authorization tests.

## 5. Business, people and catalogue

- **ORG-01:** Central editable business/legal name, branding and public contact information; location name/address/contact/timezone. SuperAdmin manages business-wide configuration. No hardcoded business name/address requiring search-and-replace; secrets and deployment settings never belong in this editor.
- **ORG-02:** Administer active/inactive local staff and practitioners, immutable identity links and scoped roles. Preserve history when disabling access. Practitioner profiles include discipline, biography, credentials, areas of focus, treatment philosophy, languages, offered services, appointment modes, forms, room preferences, location-specific working hours, contact preferences and explicit public-publishing fields. Track the operational engagement model needed for booking ownership, records, payments and policies; publish only owner-approved wording about employment or independent practice.
- **ORG-03:** Retain personal contact email separately from organizational sign-in email. Later delivery preferences may select work, verified personal, or both for permitted notifications. Changing it never changes login identity or automatically forwards sensitive clinical information.
- **CAT-01:** Manage locations and rooms, capabilities/equipment, availability/bookable status, permitted practitioners/services and cleaning/turnover buffers.
- **CAT-02:** Manage service categories/descriptions, fixed or approved selectable durations, integer-cent prices/currency, taxes, preparation, required forms, lead time, booking horizon/cutoff, before/after buffers, recurrence eligibility, cancellation policy and delivery modes. Practitioner-editable settings stay within delegated clinic rules.
- **CAT-02A — Duration pricing:** A service may have one or more explicit duration-and-price options (for example massage at 60/90/120 minutes). Duration options are not separate services unless care type, intake, policy or operational rules differ. Mobile/travel charges and time buffers remain separate. Appointment price snapshots protect historical bookings from later catalogue changes. Unit-based clinical billing is a future pricing model, not the default scheduling model.
- **CAT-03:** Appointment start times and service durations use 15-minute scheduling increments. Operational buffers may use finer minute precision where configured; a five-minute room turnover is not a five-minute bookable service.
- **CAT-04:** Services are explicitly assigned to locations and practitioners. Preserve in-clinic/mobile eligibility and existing travel metadata; catalogue flags alone do not make mobile booking complete.
- **CAT-05 — Public catalogue publishing:** Service, practitioner, location and facility records have explicit draft/published/archived behavior, stable public slugs and allowlisted public projections. Cards show an approved summary, duration/range, starting price when accurate, provider category and Learn More/Book actions. Detail content and booking options come from the same authoritative catalogue; the public site must not duplicate prices, durations or eligibility as disconnected hardcoded copy.

## 6. Scheduling and appointment management

- **SCH-01:** The application database is the authoritative schedule. Support recurring local working hours, ad hoc extra availability, blocked time, vacations, sick days and manual overrides. Only hours offered to this centre are published, not a practitioner's entire personal calendar.
- **SCH-02:** Combine hours, overrides, time off, imported busy blocks, existing appointments, practitioner buffers, eligible rooms and room turnover. Prevent overlapping practitioner bookings across locations and room bookings at a location. Validate active/eligible client, practitioner, service and location.
- **SCH-03:** Store instants in UTC and display/input using the location's named timezone. Define predictable handling of ambiguous/nonexistent daylight-saving times; do not silently move a requested appointment.
- **SCH-04:** Browse without login; require authorized identity before confirmation. Both staff-assisted and self-service bookings use one authoritative validation path. The default public sequence is service or practitioner/guided entry, duration where applicable, practitioner or any eligible practitioner, appointment mode/location, date/time, minimum client details, review and confirmation. Show duration, location/delivery mode, price/tax, applicable fees/policy and preparation/form obligations before confirmation. Collect only information required to identify, validate and confirm the booking; assign sensitive intake/consent forms to the protected client workflow before the visit unless a reviewed rule makes completion a booking prerequisite.
- **SCH-04A — Staff client finder:** Staff appointment creation searches active clients automatically after two characters with a 300 ms debounce, cancels stale requests, and renders accessible result details (name, email, phone) before explicit selection. Changing the search clears the prior selection to reduce wrong-client bookings. Exact birthdate filtering is deferred; when added, treat birthdate as sensitive input and do not expose it in result lists.
- **SCH-05:** Confirm only after durable server success. Revalidate availability in a transaction, protect against concurrent conflicting writes, and use idempotency for retries. A displayed slot is not reserved unless a real expiring hold mechanism exists. Return actionable conflicts without losing the user's choices.
- **SCH-06:** Support appointment history and controlled lifecycle: Draft, Requested, Confirmed, Rescheduled, Canceled by client, Canceled by clinic, No-show, Completed, Invoiced, Paid. Keep operational and financial state consistent rather than treating an invoice status as permission to move an appointment.
- **SCH-07:** Clients and authorized staff cancel/reschedule within policy. Configurable windows and fixed/percentage fees; show fee impact first. Authorized waivers/adjustments require a reason and audit trail. No fixed 24-hour rule is assumed until configured.
- **SCH-08:** Confirmation/reminder links lead directly to a safe cancel/reschedule flow. Any link permitting an action without a session needs a high-entropy, expiring, narrowly scoped, revocable token; an appointment ID is insufficient.
- **SCH-09:** Eligible recurring requests have explicit pattern, end/count limits, per-occurrence validation and clear conflict results. No silent partial success, series replacement or cancellation.
- **SCH-10:** Time-off/sick-day changes identify affected appointments, notify clients/staff and create follow-up call tasks. Staff review rescheduling/cancellation; a block must not silently delete or cancel bookings.
- **SCH-11:** Support calendar reference/imported busy blocks, initially manual/file-based if necessary, distinguishable from internal appointments. Automated provider sync is later scope under EXT-01.

## 7. Mobile appointments and practitioner safety

- **MOB-01:** Clients can maintain private service addresses: recipient, address lines, city, province, postal code, country, optional access instructions and preferred/active designation. Collect only necessary information with an identified purpose.
- **MOB-02:** A mobile booking stores its delivery mode, base clinic/location and an immutable destination snapshot. Editing a client's address must not silently change an existing visit. Mobile appointments do not consume a clinic room unless explicitly configured to do so.
- **MOB-03:** Enforce practitioner/service eligibility, coverage areas/radius, travel charges, lead time, travel buffers and feasibility against both preceding and following appointments. Exact map/routing provider is undecided; fixed buffers can be the first validated implementation.
- **MOB-04:** Authorized schedules clearly show mobile visits and necessary destination/travel details. Only the client, assigned practitioner and operational staff with a need to know access the full address; audit sensitive access/changes. Do not expose it in public availability, general analytics or external calendars.
- **MOB-05:** Provide visit check-in/check-out and a defined overdue escalation/follow-up workflow so designated staff know planned work locations and exceptions. This is not continuous GPS tracking. Any future location tracking requires separate consent, design and approval.

## 8. Client records, forms and communication

- **CRM-01:** Preserve staff client search, create/edit, active/inactive status and appointment linkage. Profiles include contact details, communication preference, optional date of birth/emergency contact, addresses, operational notes and history. Define purpose, required/optional status and access for sensitive fields. Operational notes are not a substitute for protected clinical notes.
- **CRM-02:** Protect against lost concurrent edits and duplicate records. Staff-created records can exist before client login; verified onboarding links rather than duplicates them. Family/shared email and dependent booking need an explicit policy before enabling them; do not assume one email proves one person.
- **CRM-03:** Client self-service includes own profile, upcoming/past appointments, bookings, cancellation/rescheduling, forms/consents, invoices/payment history and privacy requests. Staff profiles and practitioner-client scope use the same underlying records, not parallel CRM copies.
- **FORM-01:** Versioned practitioner/service/appointment-type form templates, assignments and intake/consent/follow-up submissions; retain exactly what the client saw and submitted. Record consent purpose/version/time and withdrawal. Signed/finalized submissions use amendments, not silent overwrites.
- **FORM-02:** Separate clinical/treatment notes and permissions from reception-facing information. Preserve author/time/history and amendments. Define finalization and client-release rules with the business/privacy reviewer before enabling clinical use.
- **FORM-03:** Private attachments with file allowlists, size limits, malware screening, non-guessable storage references, authorized downloads and retention controls. No public document URLs.
- **MSG-01:** Secure in-portal client/practitioner/staff conversations with explicit participants, authorized routing, read/unread state and auditable access. A notification queue is not a messaging inbox. Email alerts contain a safe portal link, not sensitive message contents. Publish response expectations and that messaging is not an emergency channel.

## 9. Notifications, waitlists and finance

- **NOT-01:** Email first: durable confirmations, configurable global reminders, changes/cancellations, sick-day alerts, waitlist offers, forms, invoices/payments/refunds and privacy-request notifications. Authorized staff edit templates and schedules. Track attempts, delivery/failure and retry state. No claim that queued means delivered.
- **NOT-02:** Provider-neutral background delivery with safe retries, duplicate suppression, expiry, cancellation of obsolete reminders and operator failure visibility. Honor channel preferences and consent; marketing consent must be separate from operational notices. SMS remains optional later.
- **WAIT-01:** Opt-in waitlists by service/practitioner/location/date/day/time preferences. Match released slots, create explicit expiring offers, enforce one successful acceptance transactionally, and continue matching after expiry/decline. Staff can manage exceptions with an audit trail.
- **FIN-01:** Generate an invoice when a completed appointment requires billing. Snapshot descriptions, quantities, prices, tax and adjustments; do not recompute historical invoices from edited catalogue data. Preserve money in integer cents with explicit currency and deterministic rounding.
- **FIN-02:** Record immediate payment and balances, receipts, payment methods, authorized credits/adjustments/refunds and reconciliation. Keep a traceable financial history; no destructive edits to settled records. Model multiple payments; partial-payment policy can be enabled only when approved and tested.
- **FIN-03:** Manual payment recording supports initial operation. Later online payment collection uses a hosted/tokenized processor; never store raw card details. Payment retries/webhooks require idempotency and verification.
- **FIN-04:** Prepare QuickBooks Online customer/service/tax mappings and invoice/payment export or push, protected connection metadata, synchronization status, retries and manual reconciliation. Accounting outages must not block scheduling. Rich/bidirectional synchronization is later scope.
- **REP-01:** Permission-filtered dashboards/reports for appointment volume, practitioner/room utilization, cancellations/no-shows, reminders, waitlist conversion, invoice totals, payments/refunds/balances and export activity. Define metric denominators/timezones and avoid exposing clinical information in general reports. Audit sensitive exports.

## 10. Privacy, security and operations

- **SEC-01:** Publicly reachable API with HTTPS, server-side validation, parameterized SQL, least-privilege permissions, exact allowed origins, request/body/file limits, pagination and rate limits. Protect authentication and abuse-prone public endpoints; introduce challenge controls when warranted. Errors use safe messages and correlation IDs, not stack traces or secrets.
- **SEC-02:** Keep secrets/configuration, dependencies, private files and backups outside the web root. Protect credentials and integration refresh tokens; verify hosting encryption-at-rest, database transport and backup safeguards rather than assuming an internal network makes them sufficient.
- **SEC-03:** Audit authentication successes/failures where observable, denied access, sensitive record reads/exports, bookings/status changes, form/note changes, staff/role changes, configuration and financial actions. Authorized staff can search audit records. Include actor, time, action, resource, result and correlation, excluding tokens and clinical contents.
- **PRIV-01:** Privacy-by-default with identified collection purposes, consent/version evidence, correction/access/export workflows, retention/legal holds and reviewed deletion/anonymization. Appoint a privacy-responsible business contact and maintain breach-response procedures. Canadian healthcare/privacy applicability requires qualified review; this document does not certify compliance. U.S./HIPAA expansion is separate future work.
- **PRIV-02:** Verify identity for privacy requests, include legally releasable profile/appointments/forms/notes/finance information, review scope, generate protected exports and deliver through expiring access. Track requests, corrections, refusal reasons and disposition. Retention durations are not invented by developers.
- **OPS-01:** Separate development/staging/production configuration and data; use synthetic data outside production. Publish repeatable builds, manifests/checksums, numbered migrations, preflight/rollback instructions and backup/restore evidence. Never deploy secrets in tracked ZIPs or overwrite server `.env` during updates.
- **OPS-02:** Monitor errors, job failures, booking conflicts and critical dependencies with safe logs/alerts. Public liveness exposes minimal information; database/schema diagnostics require operator protection. Support bounded, resumable background jobs on the actual host.
- **OPS-03:** Test role/resource denial, transaction races, idempotency, DST, policy changes, accessibility, device/browser flows and restoration. Proposed initial performance target: public availability p95 <= 2 seconds and booking p95 <= 3 seconds under an agreed representative dataset/load; establish and approve the load profile before treating this as a release gate.

## 11. Deferred integrations and extensions (retained)

- **EXT-01:** Optional Outlook/Microsoft 365 then Google Calendar integration; start busy-only inbound conflict blocking and privacy-safe outbound events. Revocable iCal subscription/export may precede full sync. Per-user consent, minimal scopes, connection health, encrypted credentials, retry/revocation and clear source of truth. External event edits never silently cancel internal appointments. No personal titles/attendees or client/address details copied unnecessarily.
- **EXT-02:** Verified personal-email delivery preferences, Apple/Meta sign-in, optional client email authentication, online payments, SMS and QuickBooks expansion remain tracked follow-on work, not forgotten requirements.
- **EXT-03:** Multi-centre expansion, native apps, telehealth, advanced analytics, document-sharing expansion and AI assistance require separate approved scopes, privacy/security review and operational budgets.

## 12. Verified source baseline versus remaining work

**17 September 2026 mobile-first slice:** staff-assisted mobile booking now supports
per-practitioner clinic/mobile eligibility, destination snapshots, fixed travel buffers,
base-price/mobile-surcharge snapshots and authorized schedule display. See
[Mobile booking](MOBILE_BOOKING.md) for migration 004, setup, limitations and acceptance.
Coverage is staff-verified, not map-enforced; reusable client addresses, automated taxes,
customer confirmation and the remaining R4 lifecycle/safety features are still pending.

**R1 source update (16 September 2026):** The public/portal separation is now implemented on `codex/public-portal-separation`: separate builds, role-filtered routes, preserved staff screens, legacy links and truthful public booking handoff. See [the checkpoint](PORTAL_SEPARATION.md) for tests and deployment steps. Hosted acceptance and customer identity remain pending. The table below preserves the pre-R1 baseline for traceability.

Baseline: source reviewed on 16 September 2026, combining `origin/main` at `9b5fa3a` and staff-booking work at `57d8212`. This documentation branch includes both histories. Source status is not deployment verification.

| Area | Source baseline | Still required |
| --- | --- | --- |
| Staff identity/roles | Entra JWT validation, local linkage, role checks and staff session UI | Role-route separation, customer identity, multi-persona linking, full authorization/session acceptance |
| Profile and business setup | Application avatars, profile menu, SuperAdmin configuration/practitioner management | Broader delegated operational permissions, personal email preferences, media hardening verification |
| Catalogue | Locations, rooms, capabilities, services/durations, assignments, taxes/settings and mobile metadata | Hosted acceptance; practitioner self-service permissions where not yet exposed |
| Availability | Recurring rules/overrides, buffers, room constraints and server slot calculation | Complete practitioner UI, shared mutation locking, database concurrency/DST acceptance |
| Staff/practitioner booking | Administrator/reception client and slot selection plus practitioner-scoped own booking, rescheduling and cancellation; transaction/idempotency, optimistic versions, history/audit and scoped lists | End-to-end hosted MySQL verification, full lifecycle/fees/recurrence and notification delivery |
| Client management | Staff search/create/edit, role checks, stale-edit handling and audit | Client sign-in/claiming, self-service, addresses, full history/forms/privacy flows |
| Public booking | Catalogue/availability browsing and presentation flow | Replace local-only confirmation with authenticated server booking; never claim a slot is held without a hold |
| Notifications | Durable event/schema foundation | Sender worker, provider/templates, reminders, delivery/failure handling |
| Forms, waitlist, billing, exports, accounting | Schema foundations | Complete services, authorization, UI, jobs and acceptance; tables are not completed modules |
| Portal split/messages/mobile safety | Requirements and some supporting fields | Separate deployable experiences; conversations; address snapshots/travel/safety workflows |
| Deployment/tests | Build/package script and focused local tests | CI, repeatable migration tracking, MySQL/browser/security/accessibility/restore acceptance |

## 13. Delivery plan and acceptance checkpoints

No broad phase is “complete” solely because a related UI or schema exists. Each delivery has a feature branch, requirements/tests checklist, migration notes if needed, verified build, commit/push and reviewable release evidence.

| Stage | Work and dependencies | Exit evidence |
| --- | --- | --- |
| R0 — Consolidated baseline | These documents, reconcile current source/main, inventory release and SQL state | Traceable requirements, explicit status and decisions; no scope loss |
| R1 — Portal structure | Extract shared components/config; public and portal builds; real routes, role layouts, account menu, truthful empty states; preserve current features | Current staff workflows work in new routes; unauthorized deep links denied; refresh/back/mobile tested |
| R2 — Deploy split and customer identity proof | DNS/TLS/config/callback/CORS setup; broker proof for Google + Microsoft personal; choose safe account-claim/session strategy | Staff and customer test identities work in distinct flows; no email-only claim or workforce requirement |
| R3 — Public discovery, customer onboarding and booking | Published service pages/cards, practitioner directory/profiles, first-time-client and guided-discovery paths; identity schema migration, claim/link flow, client profile/dashboard and real public-to-portal booking | Approved catalogue content drives discovery and booking without duplication; existing staff-created client can securely sign in; duplicate/race/retry tests; durable confirmation and accurate price/policy |
| R4 — Complete scheduling operations | Practitioner availability UI, shared concurrency protection, cancel/reschedule/fees, recurring/time-off tasks, mobile visits and safety | Real MySQL races/DST and lifecycle tests; scoped practitioner/client journeys; destination privacy and travel validation |
| R5 — Communications | Worker/provider, confirmations/reminders, templates/preferences, operator failures; secure messaging as separate module | End-to-end delivery and retry/duplicate tests; authorized conversation access; overdue follow-ups observable |
| R6 — Client care and privacy | Versioned forms/consents, protected notes/files, history, export/correction/retention controls | Role-separated data access, form-version integrity, reviewed export and retention workflows |
| R7 — Waitlists and finance | Transactional expiring offers, invoices at completion, manual payments/refunds, reports | Race-safe offer acceptance; financial reconciliation and policy tests |
| R8 — Launch readiness | Full regression/security/accessibility, hosting controls, recovery drill, runbooks and owner/privacy acceptance | No unresolved critical/high security issues; required operations complete; documented launch approval |
| R9 — Extensions | Calendar sync, personal email delivery, extra identity providers, gateways/SMS/QBO expansion | Separately approved integration-specific tests and deployment evidence |

R5 confirmation delivery must be available before opening real client booking, even if R3 is tested privately first. Privacy/security safeguards are built throughout, not postponed until R8. Independent work can move earlier without bypassing dependencies. Secure messaging is retained product scope; its public launch timing must be explicitly approved if deferred.

Historical phase crosswalk: old phases 1–2 feed R1–R3/security gates; phase 3 catalogue is retained and verified during R1/R4; phases 4–5 scheduling feed R3–R4; phase 6 notifications feeds R5; phase 7 clients/forms/privacy feeds R3/R6; phase 8 waitlist and phase 9 billing feed R7; phase 10 reporting/launch feeds R7–R8; phase 11 calendar/email/integrations feeds R9. This renumbers delivery sequencing, not product commitments.

Within R3, deliver public discovery in reviewable slices: (1) publishing controls and
sanitized projections; (2) service directory/details; (3) practitioner directory/details
and filters; (4) New Clients, FAQ, About/Locations and curated resources; (5) service-first,
practitioner-first and non-diagnostic guided entry into the shared booking flow; and
(6) privacy-safe conversion measurement, SEO metadata, accessibility and performance
acceptance. Classes/workshops require a separately designed event/capacity model and are
not part of ordinary appointment booking. Reviews/testimonials remain deferred under
EXP-14.

### Release-wide definition of done

Each shipped module must have server authorization/validation, useful error/empty/loading states, real persistence, audit where required, migration/rollback guidance, automated checks proportionate to risk and recorded user-flow acceptance. Full operational launch retains social client login, booking/lifecycle, practitioner scheduling, sick-day workflows, email, client records/forms/exports, waitlists, internal finance, roles and audits from the original MVP. A smaller private pilot is not a claim that this MVP is finished.

## 14. Confirmed decisions and remaining questions

Recommended defaults allow design to proceed; they are not evidence of an answer or authorization to buy/configure services.

| Decision | Planning default / required confirmation |
| --- | --- |
| Client providers | Owner confirmed 16 September 2026: Google + Microsoft personal first; Apple and Meta later. Broker selection subject to proof of concept. |
| Staff administration | Owner confirmed separate permissions 16 September 2026: SuperAdmin assigns staff permissions/system settings; retain ClinicAdmin/reception/accountant operational roles. |
| Final domains, branding and tenant | Environment configuration; keep current development host until approved. Do not infer final name/tenant from examples. |
| Identity recovery/shared email | Reviewed verified account claims; decide recovery evidence, shared-family-email and dependent-account scope before schema rollout. |
| Session/performance policies | Proposed values in AUTH-06/OPS-03 require approval and technical feasibility testing. |
| Privacy/retention/clinical records | Business/privacy reviewer sets jurisdiction, field purposes, access/release rules, retention and legal holds before clinical production use. |
| Operational services | Choose email provider, scheduler capability, backup/restore objectives and mobile escalation owners before enabling those workflows. |

Changes to these decisions must update this document and the matching design/acceptance tests together.
