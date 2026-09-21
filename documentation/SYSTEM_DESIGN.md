# Wellness Centre — System Design

Version 1.4 · 20 September 2026 · Companion to [Master Requirements](MASTER_REQUIREMENTS.md)

### Customer onboarding implementation checkpoint

Migration 005 adds separate customer identity/link, invitation/claim, reusable address,
challenge, session and rate-limit tables. `CUSTOMER_CLINIC_ID` explicitly selects the
deployment clinic; `CUSTOMER_ONBOARDING_ENABLED` defaults false. Immutable issuer/subject,
not email/name/DOB, identifies the external principal. Staff approve existing-record
claims after independent verification plus a customer-provided review code. New records
are explicitly created, never automatically matched to an existing email.

Protected customer endpoints require both the customer API access token and a hashed,
server-tracked opaque session credential. Session establishment validates a separate
signed SPA ID-token freshness proof (matching object/tenant, one-time nonce, auth_time);
ID tokens are never accepted as API authorization. Confirmed client limits are 30-minute
idle / 8-hour absolute. No background renewal or `iat` fallback. Staff sessions and
permissions are unchanged. Profile writes use current locking reads/revisions; ownership
changes serialize on the configured clinic with unique constraints as a second guard.

Local integration/browser checks are in place; real External ID freshness proof and
Netfirms acceptance remain release gates. See [CLIENT_ONBOARDING.md](CLIENT_ONBOARDING.md)
for exact endpoints, rollout and remaining R3 scope. Older target statements below about
linking multiple providers, recovery, personas and client booking remain unimplemented.

## 1. Authority and design posture

This is the authoritative cross-system design. Requirements and delivery scope live in Master Requirements; setup commands and operational procedures remain in component runbooks. Sections explicitly distinguish **current source** from **target**. Do not assume the deployed environment matches source, or that existing tables imply working modules.

Preserve the working React/TypeScript + PHP + MySQL implementation and Netfirms deployment. Refactor into separate public and portal experiences sharing domain components and one backend. No Azure hosting migration, new paid service or production identity tenant is authorized by this design.

## 2. Architecture and repository

```text
Public website (anonymous discovery) ─── sanitized catalogue/availability ─┐
    │ booking preferences / login link                                  │
    v                                                                   v
Portal (client / practitioner / admin layouts) ── HTTPS ── PHP /api/v1 API
    │ staff Entra or approved customer identity                 │
    └─ acquire API-scoped access token ──────────────────────────┤
                                                               ├─ MySQL (internal network)
                                                               ├─ Private files / audit
                                                               └─ Durable jobs → email/integrations
```

The browser calls an internet-accessible API; “internal API” means first-party use, not private reachability. Database network isolation is a separate control. Authorization belongs at the API and resource layer, never in a shared frontend secret.

**Current source after R1:** `Frontend/src/App.tsx` is public-only. Separate public/portal bootstrap modules share providers/theme, while only the portal initializes MSAL. Explicit Vite configs emit `dist/public` and `dist/portal`; real role-filtered routes replace query/hash navigation, with legacy-link compatibility. Existing domain components remain in place and load on demand. PHP still lives in `api/`, with private application/dependency files and a public front controller. Staff-booking source was merged into main before this work. [Portal separation checkpoint](PORTAL_SEPARATION.md) records deployment and acceptance still required.

**Target repository:** Keep `Frontend/` and `api/`; extract frontend areas without a framework rewrite:

```text
Frontend/src/
  public/          public entry, layout, marketing and discovery routes
  portal/          portal entry, role routes, layouts and workspace selection
  shared/          theme, business configuration, UI, API/error helpers
  features/        auth, profiles, clients, catalogue, scheduling, forms,
                   messaging, billing, reports and administration
api/
  public/          index.php and web-server routing only
  src/             HTTP/auth/policy + domain services + persistence
  database/        fresh schema and ordered additive migrations
  bin/             protected operator and scheduled-job commands
documentation/     master requirements, system design, supporting runbooks
```

This is a proposed extraction map, not a claim these directories already exist. Prefer two Vite entry/build configurations with explicit outputs (`dist/public`, `dist/portal`) and shared source. Keep a single dependency lockfile and theme initially; a workspace monorepo migration is unnecessary. Preserve existing imports/workflows in small steps.

Current libraries include React 19, TypeScript, Vite, MUI, Lucide, date-fns and MSAL. Retain them. Evaluate a query/cache library, schema-backed form validation and browser/component testing when the relevant module is built; TanStack Query, React Hook Form/Zod and Playwright/Vitest are candidates, not installed or mandatory claims.

## 3. Routes, navigation and host boundaries

| Surface | Target paths | Access and behavior |
| --- | --- | --- |
| Public | `/`, `/services`, `/services/:slug`, `/practitioners`, `/practitioners/:slug`, `/new-clients`, `/faq`, `/resources`, `/resources/:slug`, `/about`, `/locations`, `/contact`, `/book` | Anonymous published content/discovery; no operational dashboard. Conditions/goals routes are enabled only for reviewed non-diagnostic content. |
| Portal entry | `/login`, provider callback routes, workspace selection | Choose staff/customer flow; authenticated return path must be allowlisted |
| Client | `/client`, `/client/appointments`, `/client/book`, `/client/forms`, `/client/messages`, `/client/invoices`, `/client/profile` | Own records; show only released routes |
| Practitioner | `/practitioner`, `/practitioner/schedule`, `/practitioner/clients`, `/practitioner/notes`, `/practitioner/availability`, related messages/reports/profile | Active practitioner and authorized care relationships |
| Operations | `/admin`, `/admin/clients`, `/admin/practitioners`, `/admin/appointments`, `/admin/billing`, `/admin/reports`, `/admin/settings`, `/admin/audit`, `/admin/users` | Per-module permissions; reception/accountant get restricted subsets |

Portal initialization waits for identity initialization, obtains a token for the chosen API, calls the authenticated current-user endpoint and receives server-derived roles/scopes/personas. Pick an eligible remembered workspace, otherwise present a chooser or safe default. Route guards improve UX; every API call independently checks authorization. Handle forbidden, inactive/unlinked account, expired identity and unavailable API separately.

On public-to-portal booking navigation, transfer only service/practitioner/location/duration/date preferences. Avoid personal data in URLs; allowlist return paths to prevent open redirects. A slot selection is advisory. Re-fetch availability and a server quote after login. Never move tokens between domains through query strings, fragments, localStorage copying or postMessage shortcuts.

Public service, practitioner, location and facility views use dedicated allowlisted
projections from the same catalogue records that drive booking. Publication state and
stable slugs are explicit; internal notes, personal contact data, raw calendar entries,
private addresses and operational-only fields never enter a public serializer. Curated
New Clients, FAQ and resource content may remain version-controlled initially. Do not
introduce a CMS until editing roles, review/publishing state, localization, media handling
and audit requirements justify it.

**Implemented content checkpoint (20 September 2026):** public editorial pages and
embeddable sections load from matching `Frontend/content/en` and `fr` Markdown trees.
YAML metadata controls title, description and draft/published state; raw HTML and unsafe
URLs are rejected, media is restricted to reviewed `/content-assets/`, and builds require
matching bilingual files and publication status. Markdown rendering is code-split from
the public bootstrap. Catalogue facts remain API-backed. See [Public website content](PUBLIC_CONTENT.md).

**Public team checkpoint (20 September 2026):** the Contact page reads an allowlisted
`GET /api/v1/team` projection ordered by practitioners then administration. SuperAdmin
controls publication, section, bilingual title/summary, ordering and practitioner booking
action in `/admin/team`; unpublished staff and account email are never returned. Approved
application profile images are served through the published slug only, with bounded public
caching. Migration `008_public_team_profiles.sql` is required. See [Public team profiles](PUBLIC_TEAM.md).
The Contact layout renders this public projection directly; a reusable pop-up person-card
pattern is deferred to compact appointment and schedule contexts rather than duplicating
the same information over an already expanded Contact card.

Public media stores consent/provenance, alt text, crop/variant metadata and publication
state; serve optimized responsive formats while preserving a controlled original outside
the public document root. Conversion events use an allowlist of non-sensitive event names
and coarse page/flow context. Do not send search terms about health goals, identity,
addresses, form answers, tokens, appointment details or free text to analytics providers.

### 3.1 Theme and brand configuration

Keep one maintained component system for every clinic and express customization through a versioned, schema-validated token document. Tokens cover semantic roles—not arbitrary selectors—including primary/secondary/accent, text/background/surface/status colours, approved font family/scale, spacing density, radius, shadows and asset references. Components consume semantic tokens through the shared MUI theme and CSS custom properties; clinic code, raw CSS, script and unrestricted HTML are never accepted. Public and portal surfaces may use controlled variants of the same brand identity but cannot diverge into separate frontend forks.

Store draft and published theme revisions with clinic, schema version, author, timestamps and optional change note. Publishing is a privileged audited operation using optimistic revision checks. Validate colour syntax, contrast for normal/large text, focus visibility, status distinguishability, responsive layout bounds, supported font/assets and fallback behaviour before publish. Preview drafts through a protected preview context that cannot affect other users or be mistaken for the published site. Rollback republishes a prior validated revision as a new revision so history remains intact.

Upload logos, marks, favicons and related brand assets through the existing protected media pipeline: allowlisted types, bounded dimensions/size, server-side decode/re-encode, metadata removal, malware/content checks where applicable, generated light/dark or responsive variants and immutable versioned asset URLs. Never fetch arbitrary remote fonts or assets at render time. Use locally hosted/licensed approved fonts or safe system stacks compatible with CSP and performance targets.

Anonymous public bootstrap resolves the clinic from an exact configured host mapping, then returns only the published public business/theme projection with an ETag/version. The portal receives the same published projection plus authorized draft-management endpoints. Cache by clinic and version, never by an unvalidated `Host` value alone. Load a safe embedded default immediately and apply the validated theme without blocking authentication; API failure must retain a usable accessible interface rather than a blank page. Theme publication invalidates the relevant caches without requiring a new frontend build.

Email and generated-document renderers use the same semantic brand projection but have channel-specific allowlists and fallbacks because email clients and print/PDF do not support the full web theme. Legal name, sender identity, accessibility text and required financial/clinical content cannot be hidden or recoloured into illegibility by branding.

### 3.2 Dashboard widget system

The current staff dashboard is an explanatory placeholder, not a live metric dashboard. Replace it incrementally with the governed widget model in [Dashboard widgets](DASHBOARD_WIDGETS.md). Maintain a code-owned widget registry rather than accepting arbitrary HTML, SQL, URLs or user-authored scripts. Each definition has a stable ID, workspace, supported presentation/size, required capability, destination route, data contract, refresh policy, localization keys and explicit loading/error/empty behavior.

Resolve visible widgets as the intersection of released registry entries, the authenticated user's eligible workspace/capabilities, and their saved layout. A saved preference can arrange or hide an authorized widget but can never make an ineligible widget visible. Re-evaluate this intersection after role, permission, practitioner relationship, clinic status or feature-release changes. Destination routes repeat normal authorization and accept only documented filter parameters; a clickable metric is navigation convenience, not an access-control boundary.

Persist layout preferences server-side per user and workspace using stable widget IDs, order, supported width/size and a schema version. Do not persist rendered values, client names, appointment details or other operational/clinical information in preferences or browser storage. Supply versioned role/workspace defaults and a reset operation. Unknown/retired widget IDs are ignored safely; new defaults do not unexpectedly re-enable a widget the user deliberately hid without a documented preference migration.

Dashboard projections use purpose-built server aggregation queries with the same clinic, role, practitioner, client and record-relationship policies as destination modules. Definitions specify timezone, date boundary, status inclusion, units and denominator so a metric has one reproducible meaning. Responses include an `as_of` value where freshness matters and avoid free text or unnecessary sensitive fields. Cache only within the authorization scope and sensitivity of the projection. Never calculate authoritative operational counts by downloading broad records into the browser.

Build shared accessible shells for metric, compact-list, alert/action, timeline and later chart widgets instead of one unbounded conditional component. The full card may act as one descriptive link when it has no other controls; widgets with secondary controls use a linked heading/action to avoid nested interactive elements. Edit mode supports pointer and keyboard reordering, show/hide, allowed sizing, save/cancel and reset. Desktop placement collapses deterministically to the saved order on narrow screens; drag-and-drop is never the only mechanism.

Dedicated callback behavior must match the chosen identity SDK; do not allow the general router to consume/rewrite authorization responses before processing. Test popup and redirect flows under real production-style headers. Public and portal deep-link refreshes need separate SPA fallbacks; `/api/*` must never fall back to frontend HTML.

## 4. Authentication and authorization design

### 4.1 Staff — retained implementation, explicit hardening

Current staff authenticator validates Entra token signatures using JWKS, supported signing algorithm, tenant, issuer, audience, scopes and expiry. It links immutable tenant/object identifiers to local users and intersects recognized Entra app roles with local role assignments. Preserve these controls; don't repair token failures by disabling validation.

| Entra app role | Existing local role |
| --- | --- |
| `Wellness.SuperAdmin` | `super_admin` |
| `Wellness.ClinicAdmin` | `clinic_admin` |
| `Wellness.Reception` | `reception` |
| `Wellness.Practitioner` | `practitioner` |
| `Wellness.Accountant` | `accountant` |

Keep Entra user provisioning outside this app. SuperAdmin links existing identities and manages permitted local assignments. Enforce active account/clinic, allowed role and location scope on each request. A role removal in the app must take effect on subsequent requests; document identity-provider token/role revocation latency separately. Verify MFA policy in the tenant; an `mfa_required` database flag alone does not enforce MFA.

### 4.2 Customer identity — planned proof before rollout

17 September checkpoint: isolated customer MSAL bootstrap/callback and a read-only
`GET /api/v1/customer/auth/me` token-proof endpoint are implemented. The endpoint validates
the configured External ID issuer/audience/SPA/scope and returns no local user/record access.
There is no email matching or identity persistence in this slice. Google, personal Microsoft
and email-code flows are configured by the owner, but hosted end-to-end proof is pending.
See [CLIENT_SIGN_IN_SETUP.md](CLIENT_SIGN_IN_SETUP.md) for public IDs, release settings and
acceptance checks. R3 claims/personas/session gates remain; no SQL migration in this slice.

Use authorization-code flow with PKCE and a maintained broker/SDK capable of issuing tokens explicitly intended for this API. Entra External ID is a candidate, not a committed service. Google sign-in and Microsoft **personal** sign-in must both be proven; organizational Microsoft federation is not the same requirement. Never send a Google/Microsoft Graph access token to this API and treat it as an application token.

For reference, Microsoft states Azure AD B2C is no longer available to purchase for new customers from 1 May 2025: [B2C identity-provider documentation](https://learn.microsoft.com/en-us/azure/active-directory-b2c/add-identity-provider). Review [External ID customer authentication methods](https://learn.microsoft.com/en-us/entra/external-id/customers/concept-authentication-methods-customers) during the proof of concept; provider availability/configuration must be checked at implementation time. Do not assume turnkey personal Microsoft support from a workforce setup.

The proof must establish supported provider/account types, custom API tokens/audience, issuer/subject semantics, callbacks/logout, MFA/step-up options, recovery, consent, pricing/tenant constraints and Netfirms-compatible validation. If the broker cannot issue suitable API tokens, decide on a backend session/exchange architecture explicitly; do not improvise acceptance of arbitrary ID tokens. No client passwords are stored locally by default.

Introduce distinct trusted staff and customer authentication adapters producing a common principal: internal user ID, clinic memberships, authentication context, linked persona IDs and granted scopes. Choose adapters only from a configured issuer allowlist and then validate fully; never select trust/key URLs from unchecked token input. Customer identity cannot populate staff roles from user-editable claims.

### 4.3 Local identity migration and safe claims

Current `users.user_type` is `client|staff`, email is unique per clinic, and `identity_links.provider` is an enum (`microsoft`, `google`, `meta`, `internal`). Those constraints do not yet satisfy dual personas, Apple or broker-neutral identity linkage.

Target additive migration, designed before customer rollout:

1. Keep stable user IDs and all existing appointment/finance references. Represent client/staff persona membership independently rather than requiring two people with the same identity. Continue supporting existing profiles during transition.
2. Add normalized provider/issuer + immutable subject identity keys with a uniqueness constraint, plus tenant/object metadata where applicable. Backfill existing Entra links without changing who can sign in. Do not switch an existing issuer identity to an email match.
3. Create claim/invitation records with hashed single-use random token, intended record, expiry, consumed/revoked state and audit. Agree verification evidence and recovery rules. Email possession alone must not silently claim a pre-existing sensitive record without the approved invitation/review process.
4. Authenticated users explicitly link additional identities through fresh proof; lock linking/claiming transactions and prevent duplicate assignment. Staff+client linking must prove both identities or use a controlled reviewed process.
5. Resolve shared-email/dependent policy before relaxing email uniqueness. Email is a contact attribute, not a universal unique person identifier. Test duplicates, reassigned email, Apple relay-style addresses and changed email without losing records.
6. Record default workspace/preferences separately from permissions. Block staff persona access when signed in with a client-only authentication context, even for a linked person.

Detailed migration names are not allocated until implemented. This document is not executable SQL.

### 4.4 Resource policies and sessions

Centralize policy helpers by capability and resource: clinic scope, location assignment, practitioner relationship, client ownership, finance rights and sensitive clinical access. Apply equivalent checks to list filters, counts, downloads, mutations and nested IDs. An authorized appointment ID does not authorize arbitrary replacement client/practitioner/location IDs.

Practitioner scheduling is ownership-scoped by default. The booking UI derives the signed-in user's practitioner profile, renders it read-only, and the API independently rejects another practitioner ID. SuperAdmin, ClinicAdmin and Reception retain role-based cross-practitioner scheduling. A SuperAdmin can grant `schedule_for_other_practitioners` as an application-local permission to a practitioner covering scheduling duties; it is intentionally separate from Microsoft Entra app roles and is included in the authenticated application context. Migration 007 introduces the permission catalogue and auditable user assignment table.

Current catalogue/configuration administration is largely SuperAdmin-only; target ClinicAdmin permissions in Master Requirements are an intentional future expansion requiring tests, not permissions that can be assumed today. Within appointment creation, practitioners may search a deliberately minimal active-client projection (name, email, and phone) so they can schedule a newly added clinic client. That capability does not authorize the general client directory or clinical chart. Creating the appointment establishes the care relationship used by later record policies; deactivated/ended relationships require an explicit historical-access policy.

The booking client finder accepts an optional exact `date_of_birth` filter and an optional contact search term. Birthdate-only searches run immediately; contact text is debounced and requires at least two characters. The API applies both filters when supplied but returns only the minimal name, email and phone projection—never birthdate—so a sensitive identifier is used for matching without being exposed in the result list.

Saved client service addresses use a separate booking-specific read after explicit client selection. The endpoint verifies active-client clinic scope and a booking-capable staff role, returns only the reusable address, and records the sensitive read in the audit log. The frontend loads that value into the shared Google-assisted `AddressEntry`; edits remain local to the booking. Google confirmation, route coverage and the immutable appointment destination snapshot remain mandatory, so loading a saved address never silently changes the client profile or bypasses coverage checks.

Authenticated client booking must present a versioned, unbundled information-sharing acknowledgement or consent when the client selects a practitioner. Persist actor or substitute decision-maker, practitioner, service/appointment scope, exact text version, timestamp, restrictions and withdrawal state in `consent_records`, linked to the resulting appointment or pending booking transaction. Clinical access begins only through the resulting authorized care relationship and remains limited to information necessary for the booked care. Treatment consent is a separate professional workflow; booking consent must never imply unrestricted access to the entire chart.

Session targets are in AUTH-06. Existing bearer-token validation alone does not implement authoritative inactivity revocation. Design a server-tracked session/revocation mechanism compatible with the selected broker before claiming these limits are enforced. UI locks alone are insufficient. If adopting cookie sessions, add Secure/HttpOnly cookies, appropriate SameSite behavior and explicit CSRF defenses; cross-origin cookie choices require a separate reviewed configuration. Keep tokens out of logs/URLs, minimize browser persistence, reauthenticate sensitive actions and clear private client caches on logout/account switch.

## 5. Domain model and database strategy

Current host evidence reports MySQL **5.7.44**, not the MySQL 8+ aspiration in the old database plan. Test migrations against the actual host dialect; an upgrade assessment is a production-readiness task, not a silent schema prerequisite. Use InnoDB transactions, foreign keys, `utf8mb4`, UTC instants and named location timezones. Avoid assuming unsupported SQL features or enforced constraints without verification.

| Domain | Existing foundation | Planned extensions / responsibility |
| --- | --- | --- |
| Organization/identity | `clinics`, `locations`, `users`, `roles`, `user_roles`, `staff_accounts`, `identity_links`, `user_profile_images` | Persona/issuer-aware linkage, claims/recovery, policy/session state; versioned theme revisions and processed brand assets; retain app-owned avatar storage |
| Practitioner operating model | Practitioner/profile/location/service foundations | Effective-dated operating agreement/configuration, scheduling/reception delegation, client/record stewardship, merchant/payee and compensation responsibility, coverage and departure workflow |
| Related people | No complete relationship/proxy-access domain | Directed client/contact relationships, independently granted purpose permissions, verification/consent evidence, notification and revocation history; never shared credentials |
| Catalogue | Practitioners, services/durations, practitioner/service assignments, rooms/capabilities, `service_locations`, taxes and `clinic_booking_settings` | Delegated editing, explicit policy versions/quotes, treatment add-ons; central branding and publication projection; optional retail catalogue kept separate |
| Availability | `availability_rules`, `availability_overrides`, `time_off`, `imported_calendar_entries` | Practitioner UX, import provenance, shared conflict locking and impacted-booking tasks |
| Appointments | `appointments`, `appointment_attendees`, `appointment_status_history`, `cancellation_adjustments` | Price/policy/destination/add-on snapshots, recurrence operations, client-arrival state and mobile travel/safety events |
| Clients | `client_profiles` associated with users | Private reusable addresses, client onboarding, record claims and profile permissions |
| Care/forms | `form_templates`, `form_assignments`, `form_submissions`, `practitioner_client_notes`, `consent_records` | Immutable version history, protected clinical-note workflows, treatment plans, outcome instruments/results, supervision/co-signature, amendments and private attachment metadata |
| Messaging | No complete conversation domain | Conversations, participants, messages, read state, attachments and access policies; not notification events |
| Notifications/waitlist | Templates/events/reminder schedules and waitlist foundations | Reliable delivery worker, offers/expiry/claims, follow-up tasks and operational visibility |
| Finance | Invoices/lines, payments/refunds, taxes, accounting connection/mapping/sync records | Immutable snapshots, ledger/reconciliation policy, gateway adapters and idempotency; insurance policies/claims/remittances; package/membership/gift-card liabilities and redemption; effective-dated compensation rules/exports |
| Optional retail | No approved operational module | Products/SKUs, per-location stock ledger, reorder levels, taxable sales/refunds and commission only after explicit business approval |
| Privacy/operations | `audit_logs`, `data_export_requests`, `retention_policies` | Controlled exports, legal holds/disposition, audit querying, migration tracking and recovery evidence |
| Client identity consolidation | `client_email_addresses`, `client_merge_records` | Searchable email aliases, explicit survivor selection, immutable merge provenance and conflict-safe customer identity ownership |
| Data import/migration | No general-purpose import subsystem | Versioned source profiles/templates, protected uploads and staging, field/value mapping, validation/dry runs, duplicate review, bounded commit batches, row provenance, reconciliation and retention cleanup |

Table presence does not imply endpoints/UI or tested business behavior. Read `api/database/schema.sql` and actual migrations for exact physical names and constraints before implementation; this table maps responsibilities, not a replacement schema.

Existing upgrade scripts are the numbered files in `api/database/migrations`, currently 001 through 013. Apply only the migrations required after the last verified deployment, in numeric order. Fresh-install `schema.sql` is not a repeatable upgrade script for an existing database. Inspect actual schema before applying any migration; do not rerun a bulk create or seed file to repair a live deployment.

Add migration version/checksum tracking and preflight checks. Back up first; make additive changes, backfill in bounded steps, verify counts/constraints, then switch readers/writers. MySQL DDL may commit implicitly: transaction wrappers are not a universal rollback guarantee. Every release must identify applicable migrations, compatibility with the previous app, restore path and validation queries. Never rebuild/drop production data to adopt this design.

Application avatars currently use `user_profile_images` database storage. Preserve it initially; validate actual size/type/dimension protections and add metadata stripping/re-encoding and abuse controls as needed. Private form attachments/exports need an explicitly chosen protected storage strategy; do not assume an Azure Blob account exists.

### 5.1 Added practice-management module boundaries

**Clinic of independent practitioners.** Represent the operating relationship as effective-dated configuration linked to the practitioner and clinic, not as a role, user type or irreversible employee/contractor flag. It supplies policy inputs to scheduling, client/care relationships, clinical access, invoicing/payment routing, compensation and notifications; each domain still enforces its own authorization and invariants. Separate responsibility/stewardship fields from legal ownership language. A clinic administrator may configure only approved operational choices, while changes affecting clinical custody, merchant/payee identity, historical balances or exports require the appropriate privacy/finance authority and prospective effective date.

The model must support clinic-managed practitioners, independently managed practitioners and explicitly approved hybrids without branching the application into separate code paths. Reception authority is a scoped grant: for example, reception may book and collect an authorized payment without reading clinical notes or changing practitioner-owned policies. Client search does not establish a care relationship. Cross-practitioner clinical sharing requires the client/authorized decision-maker rule and applicable professional-policy checks. Shared rooms, locations, waitlists and public branding remain clinic resources even when the appointment or financial responsibility belongs to a practitioner.

Departure and coverage are first-class transitions. Before deactivation, produce an impact review for future appointments, waitlists, outstanding forms/messages, active care plans, claims, balances, packages, calendar connections and record/export obligations. Reassignment, temporary coverage, client communication and permitted record transfer are explicit audited operations; deactivation must not orphan or silently re-own data. Historical appointments, authorship, ledger entries and audit events retain the original responsible parties.

**Related clients and contacts.** Model a relationship as two stable person/profile references plus a relationship type and independently revocable permission grants. Do not copy a client's identity link onto a parent/caregiver, allow shared passwords, or infer authority from matching surname, address or email. Resolve the acting principal and target client on every request, then enforce the exact grant for booking, forms, notifications, finance or profile maintenance. Store who established the relationship, verification/consent basis, effective and revoked times, restrictions and audit events. Clinical notes/files require a separately approved release rule; ordinary booking or payment authority is insufficient.

**Treatment add-ons.** Keep add-ons distinct from duration options and independent services. Define an add-on catalogue record, eligibility links to base services and optional practitioner/location restrictions. A booking request names selected add-on IDs; the server re-resolves active eligibility and calculates combined practitioner time and price before availability checking. Appointments and invoice lines retain immutable add-on descriptions, durations, prices, taxes and policy versions so later catalogue edits do not alter history. An add-on that changes practitioner, discipline, appointment mode or clinical workflow must instead be modelled as another service/appointment.

**Treatment plans and outcomes.** Store plans as versioned clinical records with stable identity, author/responsible practitioner, status transitions, review schedule and separately marked client-visible content. Outcome instruments have immutable published versions; submissions retain raw answers and deterministic score/version metadata. Generated scores do not create diagnoses, modify bookings or authorize care. Draft, signed, co-signed, amended and released states use explicit permissions and append-only provenance. General administrators and reception do not gain access merely because they can manage a client profile.

**Insurance.** Keep insurer/policy/claim state separate from the invoice ledger. A policy records the client relationship, insurer identifiers, coverage metadata and verification provenance; it is not proof of eligibility. A claim snapshots the appointment, billing codes, submitted amounts and responsibility split, then appends submission, response, rejection, reversal, remittance and reconciliation events. Begin with manual portal/reference tracking and insurer-ready receipts. Each direct-billing provider is an adapter behind one internal contract with encrypted credentials, strict logging redaction, idempotent submission/reversal, authoritative external identifiers, retry/reconciliation queues and a kill switch. Provider outages or ambiguous responses leave claims pending for review rather than marking them paid.

**Prepaid value and payment collection.** Packages, memberships and gift cards use an auditable entitlement/value ledger rather than mutable remaining-balance fields alone. Every issue, payment, redemption, expiry, transfer, adjustment and refund references its source and reversing entry where applicable. Booking may quote an eligible entitlement, but redemption occurs transactionally at the approved business event and is idempotent. Separate deferred/liability balances from earned appointment revenue. Store only payment-provider customer/payment-method references; verified webhooks update local attempts through idempotent event handling and unmatched events enter reconciliation review.

**Compensation and optional retail.** Compensation rules are effective-dated and evaluated from settled source lines, not current catalogue values. Refunds and adjustments create traceable compensation corrections. Exports contain only payroll/accounting-required data and require finance permission. Do not implement tax withholding or statutory filing in the core application. If retail is approved later, use a per-location stock-movement ledger for receipts, sales, returns, transfers and adjustments; never rely solely on an editable quantity counter.

### 5.2 Import and migration architecture

Use a pipeline of **upload → parse → normalize → map → validate → match → dry-run report → authorized commit → reconcile → expire artifacts**. Parsing and source-specific transformation never write production domain tables. Store an import job with clinic, source type/profile version, file checksum, actor, locale/timezone assumptions, state, counts and retention deadline. Store staged rows and structured issues separately from committed domain records; protect all of them as client data.

Define versioned canonical import records per domain, initially client/contact, address, catalogue and appointment. CSV templates are one source adapter. Named-system adapters convert vendor exports into the same canonical records so domain validation is not duplicated. Parsers must handle documented encoding, delimiter, quoting, line endings, date formats and blank/null semantics; reject ambiguous dates or require an explicit locale. Treat spreadsheet formulas as untrusted text and neutralize formula injection in every downloadable report.

Matching is deterministic and explainable. Prefer an existing source-system key previously committed for the same clinic/profile. Otherwise produce candidate matches using approved normalized fields, but require review for ambiguity and never attach authentication identity from imported email. A match decision records its rule, reviewer and outcome. Source keys are unique within clinic + source profile and make retries idempotent. Mapping a source record to an existing entity does not erase the source snapshot or merge unrelated records.

The commit worker reads only a frozen, approved dry-run revision. It processes bounded deterministic batches, records create/update/skip/quarantine outcomes and resumes from durable checkpoints. Domain services—not ad hoc importer SQL—perform writes so authorization-independent invariants, audit, normalization and historical snapshots remain consistent. Updates require an explicit per-field policy; blank incoming values do not erase populated data by default. Future appointments use the booking/schedule validation service and enter quarantine when references or conflicts cannot be resolved. Historical appointments may use a distinct migration path that preserves source status/provenance without emitting live reminders or invoices unless explicitly approved.

Clinical notes/files, forms/consents, insurance and finance require dedicated import contracts with legal/privacy and accounting review. Preserve original author/source/timestamps only as labelled imported provenance; never represent an imported note as cryptographically signed or locally authored when it is not. Financial opening balances and settled transactions require reconciliation totals and corrective-entry strategy. Attachment archives need path traversal protection, file allowlists, size limits, malware screening and private storage.

Before commit, display entity counts, duplicates, unresolved references, warnings, errors and expected side effects. After commit, reconcile input, staged and resulting counts plus domain totals where applicable. Generate a protected migration report and audit record. Reversal is implemented only when a domain-specific compensating plan exists and no subsequent dependent activity makes it unsafe; otherwise restore from a verified pre-import backup or apply reviewed corrections. Source files, row payloads and reports expire under configured retention, while minimal provenance/checksum/outcome records remain according to audit policy.

## 6. Scheduling, transactions and state

### Availability and confirmation

Existing `AvailabilityService` derives slots from recurring rules/overrides, busy/time-off data, existing appointments, eligibility, room capabilities and buffers. Current implementation may perform repeated per-slot queries; profile and batch relevant date-window data before scaling rather than weakening constraints.

Existing `BookingService` uses a **clinic-row transaction lock**, rechecks availability, validates scope/client eligibility, creates the appointment/history/audit/notification event and supports idempotent requests. This is a coarse but useful starting lock, not a practitioner-level lock or a complete guarantee against all writers. Availability/canonical schedule edits do not all currently participate in the same lock protocol.

Target critical write path:

1. Authenticate/authorize actor, validate body and referenced records, bind idempotency key to actor + operation + request fingerprint.
2. Begin transaction and acquire the agreed schedule lock(s) in deterministic order. Initially reuse the clinic lock for **all** conflicting booking/reschedule/cancel/time-off/availability/import mutations; optimize only with proven equivalent locking and cross-location practitioner coverage.
3. Re-read current availability, statuses, travel, room/service rules, price and policy. If the quote changed, return an explicit refresh/review requirement rather than silently charge differently.
4. Persist appointment and relevant immutable snapshots; append history/audit and durable outbox/notification records in the same transaction.
5. Commit, then return durable IDs and authoritative status. Deliver messages outside the booking transaction.
6. Replay the same key/body to the same recorded result; reject the same key with different content. Lock/unique-key conflicts return safe retry/conflict errors. Persist enough request state for controlled retries across refreshes; current in-memory form state alone does not do that.

Do not add slot-hold messaging until a real hold table/expiry/locking design exists. For now selection does not reserve capacity. Client confirmation and staff confirmation call the same service with different actor policies. No booking flow may bypass availability using direct inserts.

Maintain explicit transition rules with allowed actors, prerequisite state, fee effects, notification effects and audit. Rescheduling must reserve the new slot and release the old atomically. Recurrence needs a series model with per-occurrence results and explicit all-or-partial policy; design before enabling it. Invoice/payment states are related financial projections, not a single free-form appointment status editor.

A future physical-location self-check-in route is location scoped and reveals no appointment roster. It accepts a short-lived, rate-limited identity proof or an authenticated client session, returns only the caller's eligible near-term appointments and records a check-in event without automatically completing clinical arrival/invoicing unless the owner approves that transition. QR codes identify the location/entry route, not a client or appointment. Schedule privacy mode is a frontend projection that masks identifiers while leaving server authorization, audit and sensitive-data handling unchanged.

### Mobile and safety

Portal and public copy call this delivery mode **On-Site (client location)**. The internal `mobile` delivery-mode value and `mobile_*` database columns remain stable compatibility identifiers and must not be renamed casually.

Implemented mobile-first staff booking slice: [MOBILE_BOOKING.md](MOBILE_BOOKING.md).
Migration 004 adds practitioner/service clinic eligibility and appointment delivery,
destination, travel and price snapshots. Existing base locations remain scheduling scopes;
mobile booking skips rooms but applies travel buffers through the same availability engine.
Coverage is validated server-side using Google Address Validation and Routes. The API
compares driving distance with the practitioner/service radius and signs a short-lived
proof bound to the exact actor, user-supplied destination and booking choices. The browser
never receives the Google key. Fixed travel buffers remain separate; there is still no
dynamic travel-time scheduling, tax computation or real-time practitioner tracking.

A reusable address-entry control uses Places API (New) through Google Maps JavaScript with
a separate HTTP-referrer-restricted browser key. It requests address components only,
restricts suggestions to Canada, keeps manual entry available, and shows Google attribution.
The server key remains separate. Provider-normalized address responses and coordinates are
transient; persisted destination/contact records remain the user-supplied operational data.

Introduce protected address records, appointment destination snapshots, delivery-mode validation, travel blocks and check-in/out/escalation events. Existing mobile flags/radius/fee fields do not yet provide this workflow. Store base clinic/location even when no room is used. Default to configurable travel buffers until a routing provider is chosen; enforce adjacent appointment feasibility and revalidate after changes. Do not publish destinations through public responses, logs, notification previews or external calendar sync.

### Time off and external busy data

Block creation must identify impacted booked appointments and create an exception/follow-up queue; notification alone does not complete resolution. Imported blocks retain source IDs, provenance and a distinguishable type. Later provider sync requires deduplication, update/deletion semantics, freshness/connection health and conflict review. Internal appointments remain authoritative when external entries change.

## 7. API and frontend contracts

Preserve `/api/v1` and the existing JSON success/error envelope, including safe error code/message/correlation ID. Integer database identifiers (`id`, `*_id` and `*_ids`) are JSON numbers at the API boundary even when PDO returns numeric strings; frontend request helpers defensively normalize the same fields before storing or comparing them. UUIDs, provider subjects, idempotency keys and other external identifiers remain strings. Use ISO-8601 timestamps with explicit zones at boundaries; avoid ambiguous local strings. Money uses integer cents and currency. Validate request size, supported content type, enumerations, ranges and nested resource scope. Lists need bounded pagination and stable sorting; preserve current page contracts unless versioning a change.

Expected domain groups: auth/current user; public catalogue/availability; business/practitioner/room/service/add-on administration; clients/profiles/relationships; appointments/recurrence/cancellation/check-in; availability/time off; waitlists; forms/notes/files/treatment plans/outcomes; messaging; notifications; finance/accounting; insurer policies/claims; packages/memberships/gift cards; compensation/reporting; imports/migration; audit/privacy. Optional retail remains a separate disabled group until approved. Not all groups are implemented. Produce an OpenAPI contract alongside each new group; do not document planned routes as live.

Mutation contracts need optimistic revisions where concurrent edits are possible, and idempotency for booking, offer acceptance, payment/refund, package or gift-card redemption, claim submission/reversal and external synchronization. Derive authoritative actor/client identity from the principal; a client-supplied user ID never proves ownership. Acting for a related client requires an active server-resolved relationship permission for that exact purpose. Public projections are allowlists separate from internal entity serializers.

Central frontend request handling must tolerate empty/non-JSON infrastructure errors without replacing the real failure with an unexplained JSON parse exception. Distinguish 401 reauthentication, 403 forbidden, validation, stale-edit/conflict, rate-limit and service failures. Show a safe correlation reference when available. Guard against retry loops and abort obsolete queries on account/workspace changes.

Do not cache API/auth responses in a service worker. Cache only explicitly selected same-origin static GET assets; never attempt to cache POST, extension-scheme requests or sensitive user data. Version/retire existing caches during split deployment and test upgrades from previous installed workers.

## 8. Background work, communications and integration boundaries

Current notification records are only a foundation; no verified complete email sender/reminder service exists. Build a durable worker using pending/leased/delivered/failed states, attempt counts, next-attempt time, lease expiry, retry backoff and dead-letter/operator review. Use an atomic MySQL-5.7-compatible claim mechanism; do not assume `SKIP LOCKED` support. Jobs must be idempotent, bounded and recover from process interruption.

Import commits use the same bounded-worker principles but a separate queue/state machine and dedicated permission. An import worker never selects a newer upload or mapping after approval: it consumes the frozen dry-run revision and checksum. Pause on tenant/scope mismatch, changed source revision, excessive error threshold or unreconciled domain failure, and require an authorized review before resuming.

Confirm Netfirms scheduling/CLI capabilities. Preferred execution is a protected scheduled command outside the public web root. If unavailable, explicitly approve a safe external scheduler/worker arrangement; never expose an unauthenticated “send all reminders” URL or rely on visitors to trigger jobs. Queue and provider delivery events are distinct; track both.

Email templates contain minimal necessary details, local timezone and secure action links. Cancel superseded reminders after state changes. Personal-email verification/preferences are independent of Entra login identifiers. Messaging uses its own participant-authorized persistence; email merely announces an unread message. Never copy clinical message content into an email by default.

Invoice generation on completion must be idempotent; snapshot service/add-on/tax amounts and preserve adjustments/refunds as auditable records. Manual payments first. Gateway and QuickBooks adapters use encrypted credentials, scoped permissions, stable external IDs, verified webhooks where applicable and reconciliation queues. Accounting outages cannot roll back an otherwise valid appointment. Insurance adapters follow the same isolation rule: claim submission, rejection or remittance cannot rewrite appointment history or silently convert an invoice to paid. Package/membership/gift-card workers must expire or bill only from explicit effective-dated rules and record ledger entries atomically.

Calendar integrations are optional adapters, not alternate scheduling authorities. Store encrypted refresh tokens outside public access, use minimal scopes, revoke on disconnect and manage webhook renewal/polling safely. Inbound busy-only data and outbound privacy-safe blocks come before richer sync. A revocable iCal feed token is a secret and must not expose client identity or address.

## 9. Security, privacy and observability

Apply defense in depth: HTTPS, strong token validation, least-privilege DB/runtime identities, parameterized queries, bounded requests, explicit CORS origins, safe headers, rate limiting, upload controls and generic errors. HSTS/CSP rollout must be tested against both hosts, authentication flows and resource loading. CORS is not protection against non-browser callers.

Use a purpose/permission matrix for operational notes, clinical notes, forms, addresses, finance, messages and exports. A broad administrator role does not inherently justify clinical access. Keep sensitive values out of logs, job errors and metrics. Audit actor/resource/action/outcome/correlation rather than copying changed clinical text. Define protected audit retention and tamper-resistance appropriate to the host; existing tables alone are insufficient evidence.

Enforce server-side validation and re-encoding of supported profile images, private attachment delivery, upload size/dimension limits and malware scanning for document uploads. Export generation should be identity-verified, reviewed when necessary, time-limited and logged. Retention workflows honor legal holds and verified legal/business policies; they are not simple cascade deletes.

Record structured request errors with correlation IDs, booking conflict/latency metrics, authentication failures, worker lag/failure and integration health. Public health returns only minimal liveness. Restrict database/schema diagnostic routes and remove temporary diagnostic files before live use. Do not expose database names/versions/table counts to anonymous visitors as normal health information.

The host must provide evidence of supported software, TLS/database transport, encryption/backup protection, protected secrets, access control and recovery. MySQL 5.7 compatibility is a development constraint, not a production security endorsement. Identify host support/upgrade options and residual risks before clinical production use. This document does not certify PIPEDA or healthcare compliance.

## 10. Hosting, configuration and release packaging

Current development site: `https://wellness.copihue.ca/`. Existing layout:

```text
account-private-root/wellness-api/       .env, src, vendor, bin, private data
public_html/wellness/                   frontend static assets
public_html/wellness/api/               index.php, .htaccess
```

Target adds a separate portal document root/host while retaining one PHP application and database. A sibling `public_html/wellness-portal/` is a possible layout, subject to DNS/document-root confirmation. Configure the front controller's private root explicitly; do not assume a changed subdomain has the same relative filesystem depth. Do not duplicate private API state or business logic per host.

Deployment decision (16 September 2026): expose `/api/v1` on both the public and portal hosts using identical thin PHP entry points. Both load the one private `/wellness-api` application, configuration, vendor directory and database. The confirmed sibling document roots are `/public_html/wellness` and `/public_html/wellness-portal`; both API entry points resolve the same private directory at the same filesystem depth. Production portal builds use relative `/api/v1`, avoiding the Netfirms cross-origin OPTIONS failure. This is not an HTTP proxy or a second backend. Retain exact CORS allowlists for intentional cross-origin development; do not bypass token validation or authorization. Avoid domain-wide cookies and wildcard credentialed CORS. Register exact callbacks/logout URLs for each enabled identity environment; frontend build configuration contains public identifiers, never secrets.

Maintain environment settings for public URL, portal URL, API base URL, accepted token issuers/audiences/scopes, CORS origins, database credentials, private paths and worker/provider credentials. Map names to actual `.env`/build configuration when implemented rather than inventing live variable names here. Business display settings belong in the database; secrets and infrastructure settings do not.

Retain `scripts/build-deployment.ps1`, release manifests/checksums and documented Netfirms packages. Extend it for public + portal outputs and explicit SQL upgrade instructions. Rebuild dependencies from lockfiles and replace each vendor bundle consistently: mixing `vendor/autoload.php` with another build's generated Composer files previously caused fatal startup errors. Preserve server `.env`, private uploads and other runtime data; deployment is not a blind overwrite of the private directory.

Clinic branding assets are governed configuration rather than compiled page content. Migration 011 stores a bounded logo and favicon per clinic in `clinic_brand_assets`; public endpoints serve validated PNG/WebP bytes with content-hash ETags. Only Super Administrators may replace or delete them, and those actions are audited. The public and portal headers share the logo while the document favicon is updated from the same site configuration. Built-in assets remain the safe fallback.

Release sequence: record source commit → build/test → package and checksum → back up database/runtime assets → inspect/apply applicable migrations → deploy compatible private API/vendor + public front controller → deploy public/portal assets → verify deep links, health/auth and core flows → record outcome. Use a maintenance window or compatible staging/swap strategy supported by the host to avoid partially uploaded code. Keep prior artifacts and a tested recovery plan; data rollback may require restore, not just older PHP files.

Tracked release packages must exclude `.env`, secrets, tokens, real client data and private uploads. Supporting deployment documents specify exact paths/package contents. No release package is required for this documentation-only consolidation.

## 11. Verification and rollout gates

Current focused local checks cover client validation/authorization, booking request validation, interval/DST helpers and appointment-role filtering. They are useful but are not evidence of real MySQL races, complete browser flows, legal compliance or successful production recovery.

| Test layer | Required evidence |
| --- | --- |
| Static/build | PHP syntax/dependency checks, TypeScript/frontend builds, lint and secret/package inspection |
| Unit/policy | Permission matrix incl. negative cases, fees/money, durations/buffers, state transitions, idempotency fingerprints and timezone edges |
| Real MySQL integration | Concurrent booking/reschedule/time-off/import conflicts, transaction rollback, duplicate requests/offers/payments, migration/backfill integrity, import retry/resume/idempotency and clinic-scope isolation |
| Identity | Staff MFA/roles, inactive/unlinked users, Google and personal Microsoft, wrong issuer/audience/expired token, safe linking/recovery, dual persona and privilege non-escalation |
| Browser | Public discovery → portal auth → durable booking; staff/reception/practitioner/accountant paths; deep links, refresh/back/logout/account switch; error recovery and upgrades from old workers |
| Care/privacy | Unrelated practitioner/client denial, directed family/caregiver permission and revocation, note/form/plan/outcome/address/file restrictions, consent versions, supervision/release rules, reviewed export expiry, audit contents and retention/legal-hold behavior |
| Jobs/finance | Retries after crashes/timeouts, obsolete reminder suppression, delivery failure visibility, import frozen-revision/partial-batch recovery and reconciliation, invoice uniqueness/refund reconciliation, entitlement double-redemption prevention, gift-card/package liability reconciliation, claim submit/reverse idempotency and provider outage isolation |
| Accessibility/operations | Keyboard/screen-reader/mobile/tablet checks, WCAG 2.2 AA review across default and configurable theme tokens, invalid-theme rejection, brand-asset fallback/cache isolation, measured load target, monitoring alerts and timed backup restore drill |

Stage gates follow R0–R9 in Master Requirements. Keep deployment acceptance separate from local tests. Do not enable real client booking before email/operational follow-up exists, or clinical records before protected storage/access and approved privacy policies exist. Run schema tests with synthetic fixtures only; never seed demonstration records into the live clinic.

## 12. Decision record and next implementation slice

| Decision | Status and consequence |
| --- | --- |
| Keep Netfirms/PHP/MySQL and existing React stack | Accepted consolidation direction; no rewrite/hosting migration |
| Separate public and portal; share one API | Accepted direction; exact domains/document roots pending |
| Preserve all six roles | Owner confirmed separate permissions; further delegation beyond SuperAdmin requires approval |
| Staff Entra, customer identity separately | Accepted direction; broker/provider proof and linking/session design still required |
| Google + personal Microsoft first; Apple/Meta later | Owner confirmed 16 September 2026; broker proof still required |
| Stable user identity, multiple personas | Target; additive migration required before client account rollout |
| Database remains schedule authority | Retained; all conflicting writers must join lock protocol |
| Existing phases are not erased | Mapped to R stages; code-complete and production-accepted remain distinct |

The public/portal split, staff sign-in, customer sign-in and language handoff are implemented
and owner-tested on the development hosts. This is development evidence, not production
acceptance of all R1–R3 requirements; account recovery/linking, real client confirmation,
communications and remaining authorization/privacy gates retain their own acceptance work.

Practitioner-scoped appointment creation, rescheduling and cancellation are implemented in
source as the current R4 slice. Deploy and verify the signed-in practitioner, clinic-managed
denial, MySQL conflict, exact-retry and notification-record scenarios in
[Practitioner appointment management](PRACTITIONER_APPOINTMENTS.md) before extending its
lifecycle scope.

The R3 public service and practitioner discovery slices are implemented in source: governed
bilingual service/profile fields, stable slugs, allowlisted anonymous projections, filtered
directory and detail routes, and direct handoff to the existing booking flow. Practitioner
profiles reuse published team data and active service assignments rather than creating a
second source. Public booking supports service-first and practitioner-first filtering. Migration
009/010 and the administrative/public acceptance checks in [Public service catalogue](PUBLIC_SERVICE_CATALOGUE.md)
and [Public practitioner directory](PUBLIC_PRACTITIONER_DIRECTORY.md) remain deployment gates.
Deliver New Clients/FAQ/static resources next. Guided discovery, analytics and search metadata follow once the owner has
approved the content taxonomy and measurement policy. Do not start reviews/testimonials or
classes/workshops as part of this slice.

Before each later domain, refine its detailed endpoint/data/test design against stable
requirement IDs. This document owns cross-system decisions; module runbooks can supply
procedural details without becoming a third master specification.
