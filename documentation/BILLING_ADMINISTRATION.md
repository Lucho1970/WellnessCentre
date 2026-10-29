# Central billing administration and SMS cost monitoring

**28 October extension:** [Automated communications](AUTOMATED_COMMUNICATIONS.md) adds opt-in
runtime enforcement for existing staff SMS, threshold/trial emails, explicit clinic senders
and provider-cost reconciliation. Reserve each current month’s known overhead here before
enabling funded sends. The earlier slice description below records its original boundaries.

26 October 2026. Implemented on `feature/billing-administration`, based on the committed
registration foundation `41a45a9`. This implements the administration and monitoring stage of
[Registration, subscriptions and communications](REGISTRATION_BILLING_PLAN.md).

## What the operator can manage

Application Administration at `portal.copihue.ca/admin/` has a **Billing** tab, available in
English and French. Select a clinic and cost month to review its financial metadata.
An active explicit application-administrator grant is required; a clinic Super Admin,
matching email, hostname or supplied clinic ID does not grant central billing access.

- **Shared plan catalogue:** create clinic or practitioner plans, with a name, stable code,
  optional CAD monthly price and explicit feature selections. Create a new version to change
  a plan; existing versions and subscriptions are not rewritten. A plan code cannot change
  between clinic and practitioner scope. An undecided price is null, distinct from CAD 0.
- **Subscriptions:** create separate clinic or practitioner subscriptions in pending or
  trialing state, start a six-calendar-month trial, extend it, suspend/resume it or cancel it.
  New subscriptions cannot be labeled paid/active without payment integration. At most one
  non-canceled subscription exists per billing account, including suspended accounts.
- **Trial dates:** activation uses the selected timezone and month-end clamping. Suspension
  does not pause or extend the trial clock. An extension specifies the last inclusive local
  date; expiry is midnight after it, converted to UTC. It must extend the existing end, be in
  the future and be within two years of the operation. Every change needs a reason.
- **SMS budget:** configure CAD monthly pool/member limits, maximum funded practitioners,
  timezone and active/inactive status. Initial values are CAD 100 overall, CAD 10/member and
  10 funded practitioners; a new pool starts inactive. The timezone cannot change after pool
  creation because it defines historical period boundaries.
- **Funding cohort:** add eligible active practitioner memberships or remove funding. A
  revoked/inactive identity, foreign-clinic member, non-practitioner or full cohort is denied.
  Reducing the cohort cap below currently funded members requires removing funding first.
  Removing funding retains costs and outstanding reservations; it does not delete membership
  or clinical records. Inactive members retain their allocated slot until explicitly removed.
- **Shared overhead:** reserve anticipated number rental/taxes/fees, record a confirmed CAD
  cost, reconcile an existing overhead reservation, or correct a confirmed amount with a
  reason. Actual costs may exceed a reservation or cap and are recorded honestly. A correction
  includes the amount the operator reviewed; a stale correction cannot overwrite a newer one.
  The audit retains old and new amounts. Enter a confirmed zero when an anticipated cost did
  not occur. Use opaque/provider references without patient details.

Billing records currently do not enforce clinic access or trigger SMS. No card, payment
provider, phone number, mailbox, sender, automatic paid conversion or public signup is
provisioned by these operations. Practitioner invitations/approval and credential publication
remain independent. The UI states this boundary explicitly.

## Costs and alerts

The monthly dashboard separates confirmed costs, outstanding committed estimates, remaining
allowance and over-limit amounts. Shared confirmed/reserved overhead is shown separately but
included in overall totals. Submitted messages awaiting reconciliation remain committed.
Member rows show individual limits, confirmed costs, commitments, funding/membership status
and the highest reached threshold: 50%, 80% or 100%. The pool has a corresponding on-screen
warning; over-limit costs and unresolved submissions have additional notices.

Thresholds are **on-screen alerts**, not queued emails or delivered operator notifications.
Automatic provider-cost conversion, ingestion/reconciliation, email alerts and reminder-worker
enforcement remain the communications integration stage. Costs in this screen are CAD amounts
recorded by the ledger or explicitly entered by the operator; do not mistake them for an
automatically synchronized provider statement.

All totals aggregate the entire period, even though only the latest 50 cost entries are listed.
Recent change history shows the latest 30 clinic/global-plan events. The catalogue and clinic
subscription list show up to 200 records each; candidate practitioners are capped at 1,000.
This is an operator pilot, not an unbounded customer financial reporting/export API.

### Period snapshots

Pool and per-member spending limits are snapshotted when a period is first created. Editing
defaults changes new periods only, including when a previously funded practitioner first uses
an already existing period. The ledger and dashboard use the same member allowance snapshot.
Existing member-period limits retain their original values. No unused allowance rolls over.

Status and cohort-cap changes apply immediately. An overhead reservation can be recorded
while a pool is inactive, allowing costs to be budgeted before funded sending is enabled.
Recording actual costs is permitted even if a cap is exceeded; new commitments are blocked.
Provider costs are not made smaller or hidden to keep a displayed cap green.

## Authorization, concurrency and recovery

All billing routes are under `/api/v1/application/billing` on the exact configured central host.
They inherit signed workforce-token verification and the central host boundary. The service
rechecks and locks the current administrator grant on every read/write, including replayed
requests. It never reads clinical tables or grants clinic/identity-provider permissions.

Mutations are transactional with reasoned `billing_admin_events` and `billing_admin_requests`.
An opaque UUID request key belongs to one administrator and one exact operation/payload.
A retry returns the original committed result; reusing the key for a changed payload fails.
If the audit/request result cannot be stored, the financial mutation rolls back. Global plan
codes serialize version allocation. Billing subjects have clinic/member scope foreign keys;
open subscriptions have a database uniqueness constraint. Pool/cohort writes share the ledger's
pool lock. Subscription and pool mutations check explicit versions/revisions.

Unknown save outcomes freeze the editor and retain the exact request for an identical retry.
Validation errors retain drafts and focus their fields. Authorization/not-found/conflict
responses clear stale financial data/editor state and require a fresh load. Clinic switching
hides the previous clinic immediately, and obsolete responses cannot restore its details.
Unsaved edits require confirmation before leaving the editor or Billing tab.

Billing JSON request bodies are limited to 64 KiB. Plan/amount/feature/date/reference inputs
are validated independently on the server. Only documented boolean feature keys are accepted;
unknown fields cannot add permissions or bypass billing scope/currency rules.

## API surface

Prefix: `/api/v1/application/billing`. All POST bodies include `request_key` and a reason of
5–500 characters; exact integer monetary amounts are CAD cents, never floating-point dollars.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | root | Shared versioned plan catalogue and allowed feature keys |
| POST | `/plans` | Append plan version; no in-place editing |
| GET | `/clinics/{clinic}` | Dashboard; optional `month=YYYY-MM`, otherwise pool-local current month |
| POST | `/clinics/{clinic}/subscriptions` | Create pending or trial subscription |
| POST | `/clinics/{clinic}/subscriptions/{id}` | Versioned activate_trial/extend/suspend/resume/cancel |
| POST | `/clinics/{clinic}/pool` | Configure pool with revision; zero means new pool |
| POST | `/clinics/{clinic}/members` | Enroll/remove funding with pool revision |
| POST | `/clinics/{clinic}/overhead` | Reserve/record/settle/correct shared costs for an explicit month |

Supported plan features: booking, calendar, client_management, reporting, sms_reminders,
dedicated_number and custom_email. These are configuration entitlements, not activation of
unfinished services or a replacement for resource authorization. Payment-based active/past_due
transitions and changing an existing subscription's plan are deliberately unavailable here.

## Migration and deployment

1. Verify the deployed source/schema and take a backup. Apply **053 once if not already
   installed**, then **054_billing_administration.sql once** against a rehearsal first.
   Do not import schema.sql or rerun prior migrations.
2. Run the duplicate-open-subscription preflight at the top of 054. If it reports rows, stop
   and reconcile the accounts; do not delete records or force the uniqueness migration.
3. Migration 054 adds plan names/code locks, trial timezone/resume fields, one-open-account
   uniqueness, pool revisions, member-limit period snapshots, audit events and request replay
   storage. Existing periods are initialized from their pool's current member default; existing
   member-period allowances remain untouched. Nothing is automatically enrolled/activated.
4. Deploy matching private API source (including Config, Api, Request, BillingAdministrationService
   and SmsBudgetLedger) and the rebuilt central administration bundle. The updated ledger
   requires 054's period snapshot column even though live reminder integration is still absent.
   Preserve private .env, uploads/runtime data and dependencies. Use the maintained deployment
   layout and release scripts; newly generated packages must record the committed revision.
5. Leave `BILLING_ADMIN_ENABLED=false` until migrations, files and current administrator access
   are verified. Enable it explicitly alongside existing APPLICATION_ADMIN_ENABLED and
   CLINIC_MANAGEMENT_ENABLED. This enables configuration/monitoring, not paid collection/SMS.
6. Use synthetic accounts for hosted acceptance: both subscription scopes, scope mismatch,
   duplicate/retried writes, trial dates/version conflict, budget snapshots, cohort maximum,
   shared overhead/adjustments, cross-host denial, revoked grant and reliable audit storage.

No hosted migration, private setting, provider configuration or external resource was changed
by local development. For rollback, disable BILLING_ADMIN_ENABLED and restore the matching
previous central UI/API as appropriate. Preserve the financial/audit tables and data; do not
drop or rewrite them to roll back UI access. Review ledger compatibility before rolling back
any future live reminder integration.

## Validation

`api/tests/integration/billing-administration.php` extends the real SQL budget fixture with
central authorization, immutable plans, scope checks, trial transitions, version/replay
conflicts, cohort changes, snapshot consistency, overhead corrections and atomic-audit failure.
It starts a loopback PHP server with synthetic signed JWTs to test actual API dispatch,
anonymous/clinic-role denial, central-host gates, disabled gating, POST retries and body limits.
It never reads private .env or contacts a payment, mail or SMS provider.

Both maintained SQL runners accept `-IntegrationTest billing-administration.php`. Supply the
installed Git OpenSSL configuration for local JWT tests. Run the frontend build, central
administration browser suite and general portal/customer regression, plus standalone PHP,
source/test syntax, PowerShell parse and diff checks. Local checks do not establish hosted
database, authentication-provider, mail/SMS delivery or payment acceptance.

### Completed local evidence — 26 October 2026

- Frontend build passed: public site, clinic portal and application administration, TypeScript,
  content validation and 2,310 English/French resources. Existing large-bundle notices remain.
- Central browser suite: **21 passed**, including 13 billing tests for scope, trial controls,
  plan versions, funding, overhead, optimistic conflicts, validation focus, uncertain retries,
  revocation, clinic switching and French mobile layout. Desktop/mobile screenshots were inspected.
- General portal/customer regression on stable source: **283 passed, two existing optional
  tests skipped**. An earlier run was interrupted after editing shared translation modules
  caused Vite hot-reload query strings to bypass exact authentication mock URLs. A trace
  confirmed the timestamped AuthProvider request; restarted dev servers and completed the
  full suite without changing production authentication or weakening assertions.
- All **54 standalone PHP scripts** passed. Syntax checks passed for **221 source/test PHP
  files**, with changed final service/test files checked again after currency validation.
- Real **MySQL 5.7.44** and **MariaDB 11.4.8** each passed **36 ledger/concurrency checks plus
  60 administration SQL/HTTP checks**. This includes account/plan currency agreement, atomic
  audit failure, request replay, immutable versions, matching scope, trial changes, existing
  period member-limit snapshots, late overhead/corrections, stale correction denial, signed
  token/host boundaries, disabled gating and the 64 KiB JSON limit.
- Both SQL runner PowerShell files parsed, documentation links resolved and git diff --check
  passed. Synthetic databases/logs and browser artifacts remain ignored local outputs.

The branch contains no private configuration, dependencies, generated packages or user-owned
untracked spreadsheets/branding/sales documents. It is pushed for review; main and the active
foundation branch are preserved. No hosted acceptance or provider activation is claimed.
