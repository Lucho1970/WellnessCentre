# Wellness Centre Solution Build Plan

> Historical source, superseded as the authoritative blueprint on 16 September 2026 by [Master Requirements](MASTER_REQUIREMENTS.md) and [System Design](SYSTEM_DESIGN.md). Preserved for traceability; phase labels, status statements and hosting assumptions below may be outdated. Use the new documents for current scope, design and delivery order.

## 1 Purpose

This document is the build blueprint for the complete Wellness Centre scheduling and practice operations platform. It converts the product requirements into an architecture, security model, component plan, delivery sequence, and measurable acceptance criteria.

The solution will be built incrementally. Each phase should leave the system in a testable state, and later phases should extend established domain boundaries rather than bypassing them. The first hosted release will be a private development or staging environment. Production launch occurs only after the MVP, privacy, security, operational, and recovery gates in this document are complete.

## 2 Product outcome

The finished platform will let clients discover care, authenticate, book and manage appointments, complete forms, receive reminders, review invoices and payments, and request access to their information. Staff will manage practitioners, rooms, services, schedules, clients, bookings, waitlists, forms, reminders, invoicing, payments, reporting, and privacy workflows according to their roles.

The initial market is a single Canadian wellness centre with support for future locations. The application must follow privacy-by-default and least-privilege principles suitable for PIPEDA obligations. Native mobile applications, a third-party developer API, mandatory SMS, full external calendar synchronization, and complete bidirectional accounting synchronization are outside the initial MVP.

## 3 Current implementation baseline

The repository contains a deployed foundation and completed Phase 3 catalogue/administration increment, but not yet a complete scheduling product.

| Area | Current state | Remaining work |
|---|---|---|
| React frontend | Responsive Material UI public catalogue and role-aware staff portal with business, staff, practitioner, location, room, capability, service, assignment, and profile administration | Phase 4 calendar/booking screens, remaining domain portals, accessibility verification, tests, and bundle splitting |
| PHP API foundation | Deployed versioned API with Entra authentication, local authorization, audited Phase 3 administration, public catalogue, initial availability, and appointment endpoints | Scheduling policy completion, client identity, remaining domain endpoints, workers, rate limiting, production hardening, and tests |
| MySQL schema | Broad normalized schema plus numbered avatar, service-delivery, and catalogue-settings migrations | Migration runner, production database identity, backup/restore validation, retention jobs, and later-phase migrations |
| Authentication | Working Microsoft Entra staff sign-in with app-role/local-role intersection and Super Admin role management | Client identity, consent flows, broader isolation tests, and production-tenant configuration |
| Notifications | Durable notification records exist in the data model | Email provider, templates, rendering, worker, retries, delivery webhooks, and monitoring |
| Billing | Tables exist | Invoice lifecycle, taxes, payments, refunds, receipts, reconciliation, UI, and tests |
| Deployment | No repeatable infrastructure or deployment workflow | Hosting decision, network configuration, secrets, environments, CI/CD, monitoring, and rollback process |

No existing implementation should be considered production-ready until it satisfies the applicable acceptance criteria below.

## 4 Target architecture

The browser application and PHP API are internet-accessible. MySQL is private and accepts connections only from the API hosting environment.

```text
Client and staff browsers
        |
        | HTTPS
        v
React web application
        |
        | HTTPS JSON API calls with access tokens where required
        v
Public PHP API
        |-- authentication and authorization
        |-- scheduling and business rules
        |-- background-job records
        |-- audit events
        |
        | private network and TLS
        v
MySQL database

Background workers --> email provider
                  --> future SMS provider
                  --> future QuickBooks Online
                  --> future calendar providers
```

### 4.1 Recommended Azure mapping

| Component | Recommended service | Responsibility |
|---|---|---|
| React frontend | Azure Static Web Apps | Builds and serves static React assets over HTTPS |
| PHP API | Azure App Service for Linux initially | Hosts PHP, terminates HTTPS, exposes health checks, and connects to the private database network |
| MySQL | Existing private MySQL service | Stores authoritative application data; not internet-accessible |
| Secrets | App Service settings backed by Key Vault when practical | Holds database credentials, application keys, provider credentials, and signing/configuration values |
| Email | Azure Communication Services Email, SendGrid, or selected transactional provider | Sends confirmations, reminders, schedule alerts, and privacy workflow notifications |
| Monitoring | Application Insights and Azure Monitor | Captures traces, metrics, failures, dependency timing, and alerts without recording sensitive payloads |
| Background processing | Scheduled App Service WebJob, worker application, or later queue-driven worker | Sends notifications, produces exports, processes waitlists, and runs synchronization jobs |

Container Apps remains an alternative for the API and workers if container-based deployment or independent scaling becomes valuable. The first implementation should avoid unnecessary infrastructure complexity.

### 4.2 Repository structure

```text
Frontend/          React and TypeScript application
api/               PHP API and API-specific operational scripts
api/database/      Clean schema and numbered migrations
documentation/     Cross-solution requirements, decisions, and build plan
infrastructure/    Future deployment templates and environment configuration
```

## 5 Security architecture

### 5.1 Core rule

A secret embedded in React is not a secret. Browser users can inspect JavaScript bundles, storage, and network requests. The system must not use a shared API key in the frontend as proof that a request is legitimate.

The API remains secure by authenticating people, authorizing every protected operation, restricting public endpoints, validating every request, and applying abuse controls.

### 5.2 Request categories

| Request category | Authentication | Authorization and controls |
|---|---|---|
| Public catalogue | None | Only active, sanitized locations, services, practitioner profiles, and computed availability; rate limited and cacheable where safe |
| Booking confirmation | Authenticated client | Client may create only for self or permitted attendees; server revalidates price, duration, availability, policy, and identity |
| Client portal | Authenticated client | Resource ownership checked on every appointment, form, invoice, payment, consent, and export request |
| Practitioner portal | Microsoft Entra access token | Practitioner role plus resource relationship; access limited to their schedule and eligible clients |
| Reception operations | Microsoft Entra access token | Clinic/location-scoped permissions; no unrestricted clinical-note access |
| Administration | Microsoft Entra access token | Explicit clinic-admin or super-admin permission; sensitive changes audited |
| Accounting | Microsoft Entra access token | Financial permissions by default; no clinical notes unless separately granted |
| Worker-to-API calls | Managed identity or server-held credential | Credential exists only in the trusted server environment, uses narrow scope, and rotates |

### 5.3 Identity plan

Staff authentication will use the development Microsoft 365 tenant and Microsoft Entra ID initially. The production tenant decision must be made before launch. React uses MSAL with Authorization Code Flow and PKCE. The API validates access tokens, not ID tokens.

For every protected Entra request, the API must validate:

- Cryptographic signature using the tenant's published signing keys.
- Issuer and expected tenant.
- Audience matching the API registration.
- Expiration and not-before times with a small clock-skew allowance.
- Required delegated scope or application role.
- Immutable subject identifiers such as tenant ID and object ID, never email alone.
- Application roles and application-level resource permissions.

Client identity must support Microsoft and Google for the MVP, with Meta-compatible identity introduced only after its provider and policy requirements are confirmed. Client identities link to one internal user through immutable provider identifiers. The application must not store social-provider passwords.

### 5.4 Authorization plan

Authorization belongs in the API, not merely in hidden frontend controls. Implement named permissions and reusable policy checks rather than scattered role-name comparisons.

Minimum permission groups:

- Catalogue and public availability management.
- Practitioner, room, service, and schedule administration.
- Appointment view, create, change, cancel, complete, and override.
- Client demographic data access.
- Practitioner-client clinical forms and notes access.
- Billing, payment, refund, and accounting access.
- Notification and template administration.
- Reports and exports.
- Role and staff administration.
- Audit and privacy operations.

Every query for a protected resource must include the appropriate clinic, location, owner, or practitioner relationship boundary. Super-admin access across clinics must be deliberate, visible, and audited.

### 5.5 Public API protection

- Require HTTPS and redirect or reject plain HTTP.
- Enable HSTS after HTTPS behavior is verified.
- Set exact production CORS origins; never use wildcard origins with credentials.
- Treat CORS as browser isolation, not authentication.
- Apply endpoint-specific rate limits by IP, identity, and operation.
- Use CAPTCHA or Turnstile for suspicious public booking, waitlist, and account-recovery traffic.
- Apply request body, upload size, timeout, and pagination limits.
- Reject unsupported content types and malformed JSON.
- Use generic production errors with a correlation ID; log technical details securely.
- Do not expose stack traces, SQL errors, database names, server versions, or private configuration.
- Return minimal public health output such as `{"status":"ok"}`.
- Add idempotency keys to booking, payment, cancellation, and other retry-sensitive mutations.
- Consider Azure Front Door or API Management later if centralized web application firewall, throttling, or API governance becomes necessary.

### 5.6 Database security

- Keep MySQL on a private network or a tightly restricted firewall allowlist.
- Permit access only from the API/worker network path and administrator access paths explicitly approved for operations.
- Use TLS and server-certificate verification even on the internal network.
- Use a runtime database account with only required data permissions.
- Use a separate migration identity allowed to change schema.
- Store database credentials outside Git and rotate them.
- Use parameterized SQL exclusively.
- Encrypt backups and regularly verify restoration.
- Record database administrative activity using platform capabilities where available.

### 5.7 Application and privacy controls

- Separate routine scheduling data, financial data, forms, and practitioner notes in both authorization and service layers.
- Record sensitive reads and writes in append-oriented audit logs.
- Capture consent purpose, version, timestamp, source, and withdrawal state.
- Define mandatory, optional, and practitioner-specific fields and document each sensitive field's purpose.
- Establish retention periods and legal exceptions before automated deletion is enabled.
- Support access, correction, export, and legally permitted deletion workflows.
- Avoid sensitive data in URLs, analytics, telemetry, email subject lines, and log payloads.
- Encrypt transport everywhere and use platform encryption at rest.
- Create breach response, privileged-access review, and privacy-request operating procedures before production.

## 6 User experiences to build

### 6.1 Public and client experience

1. Browse practitioners, disciplines, services, duration choices, prices, locations, policies, and available slots anonymously.
2. Filter availability by practitioner, service, duration, date, room-dependent offering, and location.
3. Authenticate before confirming a booking.
4. Review booking details, required forms, preparation instructions, price, taxes, and cancellation policy.
5. Confirm a single or eligible recurring booking without double-booking.
6. Receive confirmation and reminder email.
7. View upcoming and past appointments.
8. Cancel or reschedule within policy and understand any fee before confirmation.
9. Join and manage waitlists.
10. Complete assigned intake, consent, and follow-up forms.
11. View invoices, payments, balances, refunds, and receipts.
12. Request data access/export and submit corrections.

### 6.2 Practitioner portal

- View personal schedule and appointment details.
- Define recurring availability, exceptions, blocks, time off, and sick days.
- Set services, duration options, room preferences, buffers, and permitted rules within assigned authority.
- See only clients with a valid practitioner relationship.
- Review assigned forms and maintain practitioner-specific notes with additional authorization protection.
- Identify forms due and operational follow-up tasks.
- Mark appointment status, including completion and no-show.
- View related invoice status without receiving broader accounting access.
- Import external calendar blocks for reference/conflict prevention.
- Connect and manage a personal Outlook, Microsoft 365, or Google calendar without sharing account passwords with clinic administrators.
- Maintain a separately verified personal notification email and choose whether operational notices go to the work address, personal address, or both.

### 6.3 Reception portal

- Search clients within the clinic.
- Book, cancel, and reschedule on behalf of clients.
- Resolve scheduling conflicts within policy.
- Record authorized cancellation fee exceptions with a reason.
- Manage waitlists and expiring offers.
- Record payments and issue receipts within permission.
- Work sick-day follow-up tasks and contact affected clients.
- Avoid clinical notes unless separately authorized.

### 6.4 Clinic administration portal

- Manage locations, practitioners, staff, roles, rooms, capabilities, services, pricing, taxes, and policies.
- Configure availability, booking horizons, lead times, cutoffs, buffers, cancellation fees, recurrence, and room requirements.
- Manage notification templates and reminder schedules.
- Review appointment, room, practitioner, cancellation, no-show, waitlist, and financial reports.
- Oversee invoices, payments, refunds, synchronization, privacy requests, retention, and audit review.

### 6.5 Accounting portal

- View clients only as needed for financial operations.
- Review invoices, taxes, payments, refunds, adjustments, balances, and receipts.
- Export financial information and manage accounting reconciliation.
- Review QuickBooks synchronization errors when enabled.
- Remain excluded from clinical notes and detailed form responses by default.

## 7 Domain requirements

### 7.1 Organization and locations

Support a clinic and one or more locations from the data model onward, while optimizing the first UI for one centre. Store timezone, address, contact information, active status, and business defaults by location where applicable.

### 7.2 Practitioners and rooms

Practitioner profiles include discipline, biography, credentials, services, forms, appointment rules, room preferences, contact preferences, and wellness-centre-specific hours. Rooms include type, capabilities, equipment notes, booking state, turnover buffers, and practitioner/service restrictions.

The Microsoft Entra sign-in address and personal notification address are separate data elements. A personal address is optional, must be verified before use, and must have explicit delivery preferences. Changing it must not alter or relink the practitioner's Entra identity. The application sends notifications directly to selected verified addresses; automatic mailbox forwarding is an email-system concern and is not configured by this application.

Profile photos belong to this application and must not be read from or synchronized with Microsoft Entra, Outlook, or Google. A user may upload, replace, or remove their own photo, and a Super Admin may replace or remove any clinic user's photo for moderation. Uploads require authenticated authorization, allowlisted decoded image types, file-size and pixel-dimension limits, randomized storage names, metadata stripping/re-encoding, and private storage outside the public web root. Images are served only through an authorized endpoint. The UI uses initials when no approved photo exists. All administrative replacements and removals are audited.

The booking engine must reject practitioner overlap, room overlap, incompatible capabilities, prohibited room/practitioner combinations, and appointments outside valid availability.

### 7.3 Services and rules

Services include category, description, fixed or approved selectable durations, price in integer cents, preparation instructions, form assignments, booking lead time, cutoff, booking horizon, buffers, cancellation rules, recurrence eligibility, location availability, practitioner eligibility, and room requirements.

All durations and scheduling boundaries use 15-minute increments. The API recalculates server-authoritative price and duration rather than accepting browser values as trusted.

### 7.4 Availability

The application database is the authoritative schedule. Availability combines:

- Recurring working-hour rules.
- One-off added or removed availability.
- Time off, vacation, and sick-day blocks.
- Imported external calendar entries configured to block or inform.
- Existing appointment intervals including before/after buffers.
- Room availability and capabilities.
- Service lead-time, cutoff, horizon, duration, and recurrence rules.

Availability responses should expose bookable slots, not private calendar event details. Search must be fast enough for interactive use and remain correct during concurrent booking attempts.

Mobile appointments must also reserve travel time before and after the visit. Availability calculations use the practitioner's preceding and following appointment locations, configurable travel buffers, and the mobile service area. The public booking experience may confirm that an address is eligible but must never reveal another client's address or a practitioner's live location.

### 7.5 External practitioner calendars

External calendar integration is a post-MVP capability that must support Microsoft Outlook/Microsoft 365 and Google Calendar, with iCalendar subscription as an optional limited fallback.

- Each practitioner authorizes their own connection using OAuth; the system never stores their calendar password.
- Incoming synchronization imports only the time range, provider event identifier, busy state, and synchronization metadata needed to block availability. Private titles, descriptions, attendees, meeting links, and attachments are not copied into clinic records.
- Outgoing synchronization publishes confirmed clinic appointments using privacy-safe event text and excludes clinical details.
- The wellness-centre database remains authoritative for clinic bookings, cancellations, services, rooms, and client information.
- Changes or deletions in an external calendar may change availability but must never silently cancel a clinic appointment.
- Connections expose provider, selected calendar, direction, last successful synchronization, current health, error state, manual synchronization, disconnect, and consent/reauthorization controls.
- OAuth refresh tokens and webhook secrets are encrypted at rest, never returned to the browser after connection, and revoked or deleted when disconnected.
- Synchronization is idempotent, retryable, observable, and audited. Provider outages cannot corrupt clinic appointments.
- Private iCalendar subscription URLs are revocable credentials. They are outgoing-only unless a deliberately configured external feed is imported, and the UI clearly explains their limitations.

### 7.6 Appointment lifecycle

Supported statuses are Draft, Requested, Confirmed, Rescheduled, Canceled by client, Canceled by clinic, No-show, Completed, Invoiced, and Paid. Status transitions must be explicitly allowed, audited, and transactionally consistent with notifications and billing events.

Booking and rescheduling must execute transactionally:

1. Validate identity, authorization, service, duration, location, lead time, horizon, and policy.
2. Convert location-local time to UTC.
3. Calculate practitioner and room intervals including buffers.
4. Lock relevant schedule records or use an equally safe concurrency strategy.
5. Recheck availability, room restrictions, time off, and blocking imports.
6. Write the appointment and status history.
7. Queue confirmation/reminder events.
8. Commit and return the authoritative record.

#### Mobile and client-location appointments

The system supports practitioners who provide services at a client's home or another client-selected address.

- Client addresses are reusable private profile records with recipient name, address lines, municipality, province, postal code, country, access instructions, and an explicit active/preferred state.
- Every mobile appointment stores an immutable address snapshot so later client-profile edits do not change the historical destination or audit record.
- An appointment explicitly identifies its delivery mode as clinic, mobile/client location, or virtual. A clinic location remains the practitioner's operational base where required, but a mobile appointment does not require a room.
- Only the assigned practitioner and authorized reception/administration roles may see the exact destination. Public catalogue, availability, reporting, logs, and external calendar synchronization must not expose it.
- Address access, changes, exports, and administrative views are audited. Access instructions are treated as sensitive personal information and excluded from routine notifications.
- The schedule displays travel blocks and a clear mobile-visit indicator. Privacy-safe external calendar events may say “Mobile appointment” but cannot include the client's name or address by default.
- Mobile eligibility is configurable by practitioner and service, including service areas, maximum travel distance or supported postal regions, travel fees, minimum travel buffers, and appointment lead time.
- The assigned practitioner can check in on arrival and check out after departure. Timestamps, overdue check-out escalation, and designated clinic safety contacts support worker safety; this is not continuous GPS tracking.
- Exact device location is not collected unless a later, separately consented safety design is approved. Staff safety status is visible only to authorized operational staff and retained for a documented period.
- Booking and rescheduling must reject travel-time conflicts as well as ordinary practitioner and room conflicts.

### 7.7 Cancellation and rescheduling

Policies support configurable windows, fixed or percentage fees, service overrides, exception reasons, authorized waivers, and audit entries. The API must calculate the consequence before the client confirms. Every confirmation/reminder includes a secure route to cancel or reschedule.

Public links use high-entropy, single-purpose, expiring, revocable tokens. Store only a hash of the token when feasible. A link must not expose the client or appointment identifier as sufficient authorization.

### 7.8 Recurrence

Eligible services can request recurring appointments by pattern, limit, and end date. Creation returns per-occurrence success and conflict results. Changing one occurrence must not silently alter the series; series-level operations require explicit intent.

### 7.9 Time off and sick days

Adding time off must identify impacted appointments. Sick-day processing must notify clients and relevant staff, create follow-up call tasks, offer safe bulk actions, preserve exceptions, and maintain an audit trail.

### 7.10 Waitlists

Waitlist entries capture acceptable services, practitioners, locations, dates, days/times, and notification consent. When a slot opens, matching rules create an expiring offer. Acceptance is transactional; only one client can claim the slot. Expired or declined offers advance according to configurable rules.

### 7.11 Forms and practitioner notes

Form templates are versioned and assigned by practitioner, service, appointment type, or appointment. Submissions preserve the exact version answered, timestamps, consent where relevant, and amendment history. File uploads require allowlisted types, size limits, malware scanning, randomized storage names, private storage, and authorized download endpoints.

Practitioner notes are separated from ordinary booking data and use stricter permissions. Legal/privacy review must decide which notes are included in client exports.

### 7.12 Notifications

Email is the first channel; the design must support SMS later. The notification service owns provider-independent messages, templates, variables, scheduling, retries, delivery state, failures, and correlation to business events.

Initial events include booking confirmation, reminders, changes, cancellations, sick-day impact, waitlist offer, form due, invoice, payment, refund, export-ready, and internal operational alerts. A worker claims queued events safely so retries do not send unintended duplicates.

### 7.13 Billing and payments

Completing an appointment creates an invoice snapshot. Invoices contain immutable line descriptions, quantities, unit amounts, discounts/adjustments, taxes, totals, balances, and statuses. Payments, refunds, and credits are ledger events rather than destructive edits.

The MVP records payment and payment method details permitted for operations; raw card numbers must never enter this system. If online payment is added, use a PCI-compliant payment provider and hosted/tokenized payment components.

### 7.14 QuickBooks Online

Store OAuth credentials and refresh tokens only in protected server-side secret storage. Maintain mappings for clients/customers, services/items, taxes, invoices, and payments. Track pending, successful, failed, retrying, and manually reconciled synchronization states. Accounting outages must not block scheduling.

### 7.15 Reporting

Provide appointment volume, practitioner utilization, room utilization, cancellation/no-show rates, reminder delivery, waitlist conversion, invoice totals, payments, balances, refunds, and export activity. Reports must enforce the same clinic, role, and data-sensitivity boundaries as transactional endpoints.

### 7.16 Privacy operations

Support data export requests, identity verification, review, generation, secure delivery, expiry, correction requests, consent history, retention policies, and approved deletion/anonymization. Generated exports must be encrypted or delivered through authenticated, expiring access and must never be placed in a public storage location.

## 8 API standards

### 8.1 General conventions

- Base path: `/api/v1`.
- JSON request and response bodies using UTF-8.
- Standard error envelope: `error.code`, `error.message`, optional field errors, and `correlation_id`.
- Cursor pagination for changing collections; bounded page sizes.
- Explicit filtering and stable sorting.
- ISO 8601 timestamps in UTC at the API boundary, with location timezone metadata where needed.
- Money represented as integer minor units plus currency.
- Optimistic concurrency or explicit version checks for editable administrative records.
- Idempotency keys for critical mutations.
- OpenAPI specification maintained with implementation.
- Deprecation and compatibility policy before introducing `/api/v2`.

### 8.2 Endpoint groups

| Group | Required capabilities |
|---|---|
| Health | Minimal liveness and protected/internal readiness |
| Auth and identity | Current user, identity linking, role/permission context, logout/session cleanup as applicable |
| Locations and catalogue | Locations, practitioners, services, duration options, public details |
| Rooms | Rooms, capabilities, restrictions, availability, allocation |
| Schedules | Recurring availability, overrides, time off, sick days, imports |
| Availability | Sanitized slot search with service/practitioner/location/date filters |
| Appointments | List, create, retrieve, reschedule, cancel, status transitions, recurrence |
| Clients | Profile, history, permitted relationships, corrections |
| Waitlists | Join, update, leave, match, offer, accept, decline, expire |
| Forms and notes | Templates, versions, assignments, submissions, authorized notes |
| Notifications | Templates, schedules, event inspection, resend/cancel where allowed |
| Billing | Invoices, line items, taxes, payments, refunds, receipts |
| Accounting | Connections, mappings, synchronization, retry, reconciliation |
| Privacy | Consent, export, correction, retention operations |
| Reports | Scheduling, utilization, waitlist, reminder, and financial aggregates |
| Audit | Authorized filtered audit search and export |

### 8.3 API quality requirements

- Validate input at the boundary and enforce business invariants in domain services.
- Avoid returning fields merely because they exist in database rows; use explicit response models.
- Prevent mass assignment by allowlisting writable fields.
- Audit sensitive access, permission changes, overrides, exports, clinical-note access, and financial changes.
- Use database transactions for multi-record operations.
- Include unit, integration, authorization, concurrency, and contract tests.

## 9 Frontend architecture

### 9.1 Recommended libraries

- React and TypeScript with strict compiler settings.
- Vite for local development and production builds.
- Material UI for accessible component primitives and design consistency.
- React Router for public, client, practitioner, reception, admin, and accounting route boundaries.
- TanStack Query for server state, caching, mutation state, and invalidation.
- React Hook Form with Zod for typed forms and validation.
- MSAL Browser and MSAL React for Microsoft identity.
- date-fns for display and calendar calculations; server remains authoritative for booking logic.
- Vitest and React Testing Library for component/integration tests.
- Playwright for critical browser workflows.

### 9.2 Frontend modules

```text
src/
  app/             application setup, routing, providers, error boundaries
  auth/            login, callback, token acquisition, route guards
  api/             generated/typed client, request wrapper, error mapping
  components/      shared accessible UI components
  features/
    catalogue/
    availability/
    booking/
    appointments/
    clients/
    schedules/
    rooms/
    services/
    waitlist/
    forms/
    billing/
    reporting/
    privacy/
    administration/
  layouts/         public, client, staff, and admin shells
  theme/           design tokens and Material UI theme
  test/            shared test setup and fixtures
```

The browser stores no application secret. Tokens should be kept according to MSAL security guidance, requested only for the API scopes needed, and never written to application logs.

### 9.3 Accessibility and usability

- Target WCAG 2.2 AA for public and portal workflows.
- Support keyboard navigation, visible focus, landmarks, labels, error summaries, and logical heading order.
- Do not rely on color alone.
- Announce asynchronous errors and booking state changes.
- Provide adequate touch targets and responsive layouts.
- Test at browser zoom, small mobile widths, high contrast, and screen-reader-relevant semantics.
- Keep booking short and allow anonymous browsing before authentication.

## 10 Data and migration plan

The existing clean-install schema is the baseline, but shared environments require numbered, immutable migrations. Never edit an already-applied migration. Record migration version and execution time.

Data rules:

- MySQL 8 with InnoDB, foreign keys, and `utf8mb4`.
- Store timestamps in UTC and retain the location timezone for interpretation/display.
- Store currency in integer cents with a currency code.
- Preserve appointment, audit, consent, and financial history with status and event records.
- Avoid hard deletion where legal, financial, or audit history must remain; use documented retention/anonymization behavior.
- Use realistic synthetic data only in development and automated tests.
- Do not seed production with demonstration identities or records.

Before production, perform migration rehearsal against a staging copy, backup, restore test, and rollback/forward-fix review.

## 11 Background jobs

Workers are required for reminders, email delivery, waitlist offers, exports, accounting synchronization, calendar import, retention, and periodic cleanup.

Every job type must have:

- Durable state in the database or a managed queue.
- Safe claim/lease behavior for multiple workers.
- Idempotent processing.
- Attempt count, next-attempt time, last error, and terminal failure state.
- Exponential backoff with bounded retries.
- Dead-letter/manual review workflow.
- Correlation ID and business-record reference.
- Metrics and alerts for backlog and repeated failures.
- No sensitive payloads in routine logs.

## 12 Observability and operations

- Use structured logs with timestamp, severity, environment, service, correlation ID, event name, and safe identifiers.
- Propagate correlation IDs from frontend/API requests into database audit and worker events.
- Monitor API availability, latency, error rate, database dependency latency, worker backlog, email failures, auth failures, and booking conflicts.
- Alert on sustained failures, not isolated expected validation errors.
- Maintain liveness and readiness endpoints; detailed dependency status must be protected.
- Define incident response, support escalation, maintenance windows, and status communication.
- Document deployment, rollback, credential rotation, database restore, and provider-outage procedures.

## 13 Testing strategy

| Test level | Required coverage |
|---|---|
| PHP unit tests | Policies, validation, fee calculations, recurrence, mappings, and transition rules |
| API integration tests | Authentication, authorization boundaries, CRUD, transactions, errors, pagination, and audit writes |
| Concurrency tests | Two users attempting the same practitioner/room slot; retries with same idempotency key |
| Database tests | Migrations, constraints, timezone behavior, money totals, retention, and export queries |
| Frontend unit/component tests | Forms, validation, permissions presentation, errors, loading states, and accessibility behavior |
| End-to-end tests | Browse, login, book, reschedule, cancel, forms, staff scheduling, completion, invoice, payment, export |
| Security tests | Token validation, role/resource isolation, IDOR attempts, injection, rate limits, CORS, headers, and secret scanning |
| Operational tests | Deployment, health checks, worker retries, provider outage, backup/restore, and rollback |

Production-critical appointment and financial paths require automated regression coverage. Tests must prove that practitioners cannot access unrelated clinical records or the general client directory; the minimal booking-only client projection is the explicit exception. Accountants cannot access clinical information by default.

## 14 Environment strategy

Use separate Development, Staging, and Production environments with separate databases, identities, secrets, hostnames, and provider configurations.

| Environment | Purpose | Data rule |
|---|---|---|
| Development | Local and shared feature development | Synthetic data only |
| Staging | Deployment rehearsal, integrations, security and acceptance testing | Synthetic or formally approved sanitized data only |
| Production | Live centre operations | Real data with production controls, retention, monitoring, and backup |

Never reuse production credentials in lower environments. Promotion should use the same versioned build artifacts and migration set that passed staging.

## 15 Delivery roadmap

Each phase is intended to become a separately reviewable build unit.

### Phase 0 Foundation and decisions

Build:

- Confirm Azure subscription, region, hosting plan, database network path, domains, and environment names.
- Decide production identity tenant and client identity providers.
- Select email provider.
- Record privacy owner and obtain legal/privacy review of collected fields, forms, notes, exports, retention, and deletion.
- Add coding standards, branching/review rules, issue tracking, and decision records.
- Commit the existing API baseline after review.

Exit criteria:

- Major external services and owners are named.
- Development and production boundaries are documented.
- No secrets are committed.
- Current code is reproducible from Git.

### Phase 1 Deployable secure API foundation

Build:

- Declare required PHP extensions including PDO MySQL and OpenSSL.
- Add configurable MySQL TLS and certificate verification.
- Make public health output minimal and add protected/internal readiness behavior.
- Add production HTTP security headers, strict CORS configuration, request limits, and rate limiting.
- Add numbered database migrations and migration command.
- Add PHP test framework, static analysis, formatting, and CI.
- Add repeatable Azure infrastructure/deployment configuration with `api/public` as the document root.
- Configure Key Vault/application settings, monitoring, health checks, and private database connectivity.

Exit criteria:

- Clean environment can build and deploy automatically.
- Health check succeeds and database is unreachable from the public internet.
- API-to-MySQL connection uses verified TLS and least privilege.
- CI passes lint, static checks, tests, dependency audit, and secret scan.
- Development API is available over HTTPS.

### Phase 2 Identity and authorization

Build:

- Create Entra API and frontend registrations, scopes, app roles, redirect URIs, and tenant restrictions.
- Integrate MSAL into React.
- Complete identity linking and first-admin provisioning.
- Implement permission policies and clinic/location/resource scoping.
- Add staff/role administration and audit events.
- Keep Entra account lifecycle management separate while allowing Super Admins to link existing Entra identities and atomically create local practitioner profiles.
- Decide and implement client Microsoft/Google login architecture.

Exit criteria:

- Staff can sign in and receive only their assigned permissions.
- Client, practitioner, reception, accountant, admin, and super-admin isolation tests pass.
- Invalid issuer, audience, scope, tenant, signature, and expired tokens are rejected.

### Phase 3 Catalogue and administration

Implementation status: **complete in source as of the Phase 3 completion release**. Production readiness remains governed by Phase 10.

Build:

- Complete CRUD and archival for locations, practitioner profiles, services, duration choices, rooms, capabilities, restrictions, pricing, taxes, and core policies.
- Add application-owned user profile photos with self-service upload/removal, Super Admin moderation, private storage, and initials fallback.
- Store clinic identity and branding as database-backed configuration, with a Super Admin-only editor and audited changes.
- Build admin screens and validation.
- Replace frontend mock data with typed API calls.
- Publish sanitized public catalogue endpoints.

Exit criteria:

- Admin can configure the resources needed to schedule a valid appointment without direct database editing.
- Public responses contain only approved fields.
- Audit history exists for sensitive configuration changes.

### Phase 4 Scheduling and booking engine

Staff booking checkpoint: the Appointments portal page now supports administrator/reception client selection, active service/practitioner/location combinations, available times and rooms, review, confirmation, retry handling, and paginated appointment lists. Practitioners can view only their own appointments. No migration is required. Signed-in deployment and MySQL acceptance remain pending; see `STAFF_BOOKING.md`. Public client booking, practitioner booking, mobile destinations, and schedule-write concurrency coordination remain open.

Client-management checkpoint: administrators and reception can search, create, and edit clinic-scoped client contact profiles, including inactive status and stale-edit protection. The Clients portal page is implemented; no migration is needed. Practitioner directory access, client sign-in, and mobile addresses remain future work. See `CLIENT_MANAGEMENT.md` for deployment and acceptance checks.

Public-service checkpoint: governed bilingual service publication fields, stable slugs, category-filtered public directory/detail routes, sanitized anonymous projections, and service/practitioner booking handoff are implemented in source. Migration 009 and deployed acceptance remain required; see `PUBLIC_SERVICE_CATALOGUE.md`. The next public discovery slice is the practitioner directory and detail experience using the existing published team profile as its source.

2026-09-15 confirmation checkpoint: booking creation revalidates against availability, checks client/location ownership and practitioner permissions, serializes clinic confirmations, and verifies idempotent retries. Local request tests pass; MySQL concurrency acceptance remains pending. See `BOOKING_VALIDATION_TESTS.md` for deployment tests and remaining administrative-edit coordination.

Progress: recurring practitioner/location working hours plus one-time availability overrides and time off are implemented in the API and Super Admin portal. The scheduling API restricts practitioner access to their own records; practitioner portal access still needs to be connected. Availability search now includes extra openings, recurrence intervals, merged hours, service buffers and horizons, cross-location practitioner conflicts, and eligible rooms with capabilities, restrictions, and turnover. These changes require deployed MySQL verification. Next: enforce the same rules during booking, add concurrency tests, and connect the booking interface. Phase 4 remains in progress.

20 September checkpoint: linked clients can now carry public preferences into the client
portal and confirm their own clinic or On-Site appointment through the same availability,
coverage, price, locking and idempotency rules as staff booking. Client identity and clinic
scope are derived server-side. Client cancellation/rescheduling, hosted MySQL race
acceptance and notification delivery remain required before completing the phase.

Build:

- Recurring availability, overrides, time off, imported blocks, room constraints, buffers, lead times, cutoffs, and horizons.
- Fast public availability search.
- Transactional appointment creation with idempotency and concurrency protection.
- Client booking UI and confirmation review.
- Staff booking on behalf of clients.
- Mobile-service eligibility, private client addresses, appointment destination snapshots, travel buffers, and travel-conflict validation.

Exit criteria:

- Practitioner and room double-booking is prevented under concurrent requests.
- All 15-minute duration, availability, buffer, room, and policy constraints are tested.
- Browser never supplies authoritative price or permission decisions.
- Exact client destinations are visible only to the assigned practitioner and authorized operational roles.

### Phase 5 Appointment management and recurrence

Build:

- Appointment detail and allowed status transitions.
- Client/staff cancellation and rescheduling.
- Fee calculation, preview, overrides, and waiver reasons.
- Recurring series creation and occurrence/series changes.
- Secure expiring self-service links.
- Full status history and audit behavior.
- Mobile-visit calendar presentation, destination access controls, arrival/departure check-in, and overdue safety escalation.

Exit criteria:

- Users can act only on permitted appointments.
- Fees are explained before commitment and financial effects reconcile.
- Retries do not duplicate changes.

### Phase 6 Notifications and operational workflows

Build:

- Provider-neutral notification service and email provider adapter.
- Editable versioned templates and global reminder schedules.
- Worker with safe claims, retries, and failure review.
- Booking, reminder, cancellation, change, and internal staff notifications.
- Sick-day impact discovery, client notices, staff alerts, and call tasks.

Exit criteria:

- Messages send once under normal retry conditions.
- Delivery attempts and outcomes are visible and auditable.
- Provider outage does not corrupt appointments and failed jobs can recover.

### Phase 7 Client profiles forms and privacy

Build:

- Client portal and profile correction workflow.
- Versioned form builder/templates, assignments, submissions, consent, and uploads.
- Practitioner relationship enforcement and separated notes.
- Data export request, review, generation, secure delivery, and expiry.
- Retention configuration and approved deletion/anonymization workflow.

Exit criteria:

- Client can retrieve their eligible information securely.
- Practitioner access is limited to eligible clients.
- Purpose, consent, access, amendment, and export events are auditable.
- Privacy/legal review signs off before production data is accepted.

### Phase 8 Waitlist

Build:

- Client/staff waitlist creation and preference management.
- Matching engine triggered by openings.
- Expiring offers, acceptance, decline, and next-candidate processing.
- Notification and reporting integration.

Exit criteria:

- Only one client can claim an offered slot.
- Expired offers cannot be reused.
- Matching honors provider, service, date, location, and time preferences.

### Phase 9 Billing and payments

Build:

- Invoice generation from completed appointments.
- Tax and line-item snapshots, adjustments, balances, receipts, and statuses.
- Payment recording, refunds, permissions, and audit behavior.
- Client, reception, admin, practitioner summary, and accountant views.
- Financial reports and exports.

Exit criteria:

- Invoice totals and payment/refund ledger reconcile in automated tests.
- Financial edits preserve history.
- No raw payment-card data is stored.

### Phase 10 Reporting and production readiness

Build:

- Operational dashboards and authorized reports.
- Accessibility audit and remediation.
- Performance/load testing for availability, booking, and portals.
- Security review, dependency review, penetration testing, and privacy review.
- Backup/restore rehearsal, incident runbooks, monitoring alerts, and support procedures.
- Production domains, certificates, email authentication, and final environment configuration.

Exit criteria:

- MVP acceptance suite passes in staging.
- Restore and rollback procedures are proven.
- Critical/high security issues are closed.
- Privacy, operational, and business owners approve launch.

### Phase 11 Post MVP integrations

- QuickBooks Online synchronization and reconciliation.
- Practitioner notification profiles with separately verified personal email addresses and work/personal/both delivery preferences.
- Outlook/Microsoft 365 calendar connection, busy-time import, privacy-safe appointment publishing, connection health, and disconnect/re-consent flows.
- Google Calendar connection with the same privacy and synchronization behavior.
- Optional revocable iCalendar subscription feeds for practitioners who do not connect a supported provider.
- Background calendar synchronization, webhook renewal, retry handling, reconciliation, monitoring, and audit history.
- SMS notifications.
- Online payment gateway.
- Advanced analytics and forecasting.
- Multi-location operational enhancements.
- U.S.-specific compliance controls if expansion is approved.

## 16 Production launch checklist

The application is production-ready only when all applicable items are complete:

- Full MVP functionality and acceptance tests pass.
- All source, lockfiles, migrations, infrastructure, and documentation are committed.
- Production build and deployment are reproducible from CI/CD.
- HTTPS, HSTS, strict CORS, security headers, request limits, and rate limits are active.
- Auth token and authorization isolation tests pass.
- MySQL is private, TLS-verified, least-privileged, backed up, and restore-tested.
- Secrets are outside Git and rotation is documented.
- Monitoring, alerts, health checks, worker monitoring, and log retention are configured.
- Sensitive data is excluded from logs and analytics.
- Email sending domain and delivery behavior are verified.
- Privacy policy, purposes, consent, retention, access/correction/export, and breach procedures are approved.
- Accessibility review passes agreed WCAG 2.2 AA scope.
- Incident response, rollback, database restore, and support runbooks are tested.
- No critical or high security findings remain open.

## 17 Definition of done for each build piece

A feature is done only when:

1. Requirements and permission rules are explicit.
2. Database changes are delivered as a numbered migration.
3. API contract and validation are documented.
4. API authorization is enforced and tested.
5. Frontend covers loading, empty, success, validation, and failure states.
6. Accessibility behavior is tested for the feature.
7. Unit/integration tests cover business rules and important failures.
8. Sensitive operations create appropriate audit records.
9. Telemetry is useful but contains no prohibited personal or clinical data.
10. Documentation and operational considerations are updated.
11. CI passes and the feature is verified in the intended environment.

## 18 Decisions still required

These decisions should be made at or before the phase that depends on them:

| Decision | Needed by | Current recommendation |
|---|---|---|
| API hosting | Phase 1 | Azure App Service for Linux |
| Infrastructure format | Phase 1 | Bicep or Terraform chosen consistently; use Azure Developer CLI only if deliberately adopted |
| Production Entra tenant | Phase 2 | Separate production configuration; do not assume the development tenant is permanent |
| Client identity broker/provider design | Phase 2 | Microsoft and Google first; add Meta later |
| Email provider | Phase 6 | Compare Azure Communication Services Email and SendGrid for region, deliverability, and cost |
| Practitioner calendar integration order | Phase 11 | Microsoft Outlook/Microsoft 365 first because staff already use Entra; Google Calendar second; iCalendar as a limited fallback |
| Calendar synchronization policy | Phase 11 | Busy-only inbound and privacy-safe appointment details outbound; clinic bookings remain authoritative |
| Form upload storage | Phase 7 | Private Azure Blob Storage with authorized API access and malware scanning |
| Retention and deletion rules | Phase 7 | Legal/privacy-reviewed policy by data category |
| Payment behavior | Phase 9 | Record payments first; add a hosted/tokenized gateway only when selected |
| QuickBooks synchronization direction | Phase 11 | Start with controlled outbound push plus reconciliation |

## 19 Recommended next build piece

Proceed to **Phase 4 Scheduling and booking engine**, beginning with practitioner availability administration: recurring working hours, location-specific rules, one-time overrides, time off, and role-scoped calendar views. Then connect those rules to conflict-safe slot generation before enabling real appointment confirmation.
