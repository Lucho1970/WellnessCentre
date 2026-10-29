# Registration, subscriptions and communications

Decision record and delivery contract, 26 October 2026. Development is authorized; hosted
migrations, production payments and paid resource provisioning remain separate rollout steps.

## Two registration journeys

**Company signup** creates an independently administered tenant and `clinic-name.copihue.ca`
portal. Its own marketing website is optional. The application captures the verified owner,
business/billing details, timezone, requested hostname, plan and accepted terms. Approval
starts resumable provisioning; the owner completes branding, locations, services and staff.
Publication and public booking require readiness checks. Never grant platform administration
to a customer owner or assign the operator as the customer merely because they approved it.

**Wellnexa practitioner signup** adds a membership to the existing Wellnexa tenant, not a new
tenant. Extend the existing enquiry flow with verified sign-in, application, credentials,
review, agreement acceptance and onboarding. Reuse existing identities only through trusted
identity claims, never matching email. Preserve existing enquiries and practitioner IDs.
Joining, credential approval, publication, billing and booking eligibility are separate states.

Wellnexa's own clinic subscription/trial is independent from its practitioners' memberships.
One unpaid practitioner must not disable the global login, other practitioners or another
clinic assignment. Clinic billing managers do not acquire clinical-record access.

## Tiers, add-ons and usage

Use separate clinic and practitioner plan catalogues with immutable versioned prices and
explicit server-enforced feature entitlements. Optional add-ons include SMS, dedicated phone
numbers and custom email connections. Support effective-dated fixed fees, included allowances,
booking overages and later percentage pricing. Only implement agreed commercial rules.
Unknown prices remain unpublished; do not advertise zero or invent a beta dollar estimate.

Record idempotent booking usage and reversals locally for current estimates. Define completion,
rescheduling, cancellation, refunds, complimentary appointments, group bookings and recurring
occurrences before charging. Proposed first rule: completed appointments, pending confirmation.
Finalized invoices snapshot prices/quantities; corrections are linked adjustments. Subscription
payments, Wellnexa membership payments and patient appointment payments are distinct flows.

## Six-month beta

Practitioners receive six calendar months free, with normal usage and waived-cost estimates.
Calculate in the agreed timezone, clamp month ends and store exact exclusive expiry in UTC;
do not substitute 180 days. Covered trial charges never become retroactive debt. Proposed
start: approval plus usable portal access. Proposed conversion: no required card during beta
and explicit paid agreement before charging. These defaults, eligibility, enrolment closing
date, existing practitioners and Wellnexa's own trial/exemption still need final decisions.

## Confirmed communications and budget

- Outgoing SMS reminders only; no calls, voicemail or practitioner messaging inbox.
- Shared Wellnexa clinic communications by default; dedicated practitioner numbers/email
  configurations are optional paid upgrades.
- Initial email: clinic-branded sending address, replies directed to the practitioner's
  existing inbox. A sending address does not include a mailbox. Custom sending domains need
  verification/authentication; connected services need authorization and connection monitoring.
- Beta SMS: **CAD 10 per practitioner per calendar month**, at most **10 funded practitioners**,
  and **CAD 100 overall per calendar month**. Others may register without a funded SMS slot.
- Owner requires cost monitoring. Individual allowances, estimates, committed reservations,
  provider-confirmed costs, shared number rental, taxes/fees and conversion must be visible.

The clinic cap includes shared overhead: ten member allowances do not promise CAD 100 of
message usage on top of number rental. Reserve known/estimated overhead before funded sends.
Use the pool timezone, initially America/Toronto. No rollover or automatic paid overage.
Proposed alerts: 50%, 80%, 100%, deduplicated per member/pool/month; show provider exceptions.

Before sending, estimate billable segments and fees in CAD cents with a conservative currency
buffer. Atomically reserve against both spent-plus-reserved limits. All mutations lock the
pool first so simultaneous sends and cohort enrollments cannot spend the same allowance.
References must be opaque and stable, without patient data. Replaying a reservation is not
permission to send again. One worker claims each delivery before calling the provider.

Lifecycle: reserved -> submitted -> settled. A definitive no-send before submission releases
the reservation. Provider timeouts retain commitments until reconciliation; do not blindly
retry or send a duplicate email fallback. Prior-month reservations cannot be newly submitted.
Previously submitted deliveries settle against their original period after rollover, removal
or pool deactivation. Record actual provider costs even above the estimate, flag the exception
and stop new sends. A local spending guard cannot guarantee the final provider bill under
currency movements, unavoidable fixed/inbound charges or adjustments; never hide those costs.

At a cap, stop further SMS and continue eligible email reminders with duplicate prevention.
Future worker integration must recheck entitlement, membership, patient consent/opt-out,
reminder eligibility and current contact before submission. Provider opt-outs still need
handling although the product has no reply inbox. Never fall back to another clinic's sender.

## Provisioning, payments and access

Company provisioning includes subdomain reservation, database/owner setup, DNS, hosting,
HTTPS, authentication callbacks and end-to-end verification. Wildcard DNS alone is insufficient.
Unknown hosts fail closed. Verify Netfirms/DNS automation; provide a manual-action queue with
retryable steps where APIs are unavailable. Do not fork application code per tenant.

Planned collection uses hosted payments, verified webhooks, idempotency, out-of-order handling,
scheduled reconciliation, invoices, allocations, credits/refunds and manual-payment support if
approved. Checkout return does not prove payment. Store no raw cards or patient information
in payment-provider metadata. Stripe Billing is proposed, not an approved account/provider.
Patient collection/payouts and retail commerce remain separate scope.

Proposed states: pending, trialing, active, past_due, restricted, canceled, suspended. Proposed
grace: seven days. Check effective dates per request rather than trusting cron. Restrictions
stop new commercial activity, preserving defined billing/existing-care/export access; never
delete records or cancel existing patient appointments for nonpayment. Payment restores only
billing restrictions, not administrative/credential suspensions. Final policy remains open.

Queue and track application, activation, trial-ending (proposed 30/14/7/1 days), payment,
restriction, cancellation and agreement-change notices. Monitor scheduling/reconciliation
health, retry failures and prevent duplication with provider-generated notifications.

## Delivery sequence and current implementation

1. **Foundation (this branch):** migration 053; separate clinic/member billing subjects;
   versioned plan/features and subscription storage; six-month and expiry policies; internal
   CAD SMS reservation/settlement ledger with real SQL concurrency tests.
2. **Administration (implemented on feature/billing-administration):** authorized versioned
   plan/trial subscription management, audited funding enrollment and overhead reservations,
   cost dashboard with on-screen thresholds and reconciliation exceptions. See
   [Central billing administration](BILLING_ADMINISTRATION.md) for migration 054 and rollout.
   Email alerts are implemented in stage 4; native provider synchronization and general paid
   subscription enforcement remain in the communications/paid-conversion stages.
3. **Wellnexa registration (implemented on feature/practitioner-applications):** signed
   application/review, independent code confirmation, retained terms/submissions, onboarding
   checklist and guarded six-month activation. See [Practitioner applications](PRACTITIONER_APPLICATIONS.md)
   for migration 055 and existing-member exceptions. Activation remains default-off pending
   independent-practitioner privacy and hosted identity acceptance. Credential/publication
   checks stay separate; stage 4 supplies application emails, while general paid subscription
   enforcement remains later work.
4. **Communication integration (initial implementation on feature/automated-communications):**
   transactional application/trial/budget emails, explicit clinic routing, segment estimates,
   funded staff-SMS spending guard, suppression and operational monitoring. See
   [Automated communications](AUTOMATED_COMMUNICATIONS.md) for migration 056. The follow-up
   [Provider evidence](PROVIDER_EVIDENCE.md) slice on `feature/provider-reconciliation` adds
   migration 057, reviewed normalized CSV charge/STOP imports, fixed CAD/USD conversion and
   shared-sender suppression. Native provider synchronization, patient SMS consent, paid sender
   provisioning and custom email connections remain.
5. **Paid conversion:** agreed rates/terms, provider setup, hosted collection, finalized invoices,
   reconciliation, grace/restriction/recovery and cancellation journeys before beta expiry.
6. **Company registration:** verified owner approval, resumable provisioning, tenant setup and
   automatic activation only where infrastructure and isolation acceptance support it.

Tenant isolation and practitioner-specific client-record boundaries remain release gates.
Registration does not complete those requirements or promise general Jane feature parity.
See [Multi tenant path](MULTI_TENANT_DEVELOPMENT_PATH.md).

## Outstanding decisions

Foundation can proceed, but dependent rollout needs: company signup domain; legal billing
entities; payment account/provider; tax settings; clinic/practitioner rates; appointment fee
rules; billing cycles/proration/refunds; launch territory; approval disciplines and credential/
insurance review; trial eligibility/start/existing members; Wellnexa trial/exemption; record
responsibility, delegated administration and departure/export/retention policy; patient payment
scope if included; DNS/hosting ownership/automation; sender addresses, alert recipients,
manual payments and language requirements. No production charge can rely on an assumed term.

## Slice 1 technical and deployment boundaries

This section records foundation commit 41a45a9. The later administration slice requires
migration 054 and its default-off gate; follow [Billing administration](BILLING_ADMINISTRATION.md)
when deploying the current code, including the updated ledger period snapshots.

Migration `api/database/migrations/053_subscription_foundation.sql` is additive and applied
once after the maintained 052 baseline and 032 membership prerequisite. No backfill, real
prices, subscriptions, funded members or pools are seeded. Pools default inactive. Rehearse
against a backed-up test database including MySQL 5.7; never import the full schema or rerun
previous migrations. No hosted changes have been performed by this branch.

`SubscriptionPolicy` is pure date/feature/budget logic. `SmsBudgetLedger` is internal accounting,
without HTTP routes, provider calls or integration into the existing SMS worker. It neither
charges nor sends nor disables existing accounts. Future entry points must enforce current
actor authority, subscription scope/currency, immutable agreement versions and auditable intent.
API/UI management, provider cost conversion, alerts and actual worker enforcement are pending.

Run `php api/tests/subscription-policy.php`. Both SQL runners accept
`-IntegrationTest subscription-foundation.php`. Tests exercise real concurrent PHP processes,
individual/shared caps, cohort limits, wrong-clinic foreign keys, replay, rollback, month
rollover, revocation and actual provider-cost reconciliation. Local tests do not establish
hosted database, payment, DNS, identity or delivery acceptance.

## Local validation, 26 October 2026

- All three Frontend production builds, TypeScript, content validation and 2,188 bilingual
  translation checks passed. Existing bundle-size notices remain non-blocking.
- Broad browser regression: 280 passed, two skipped and three timeouts. All three affected
  tests passed on a focused single-worker rerun with the ordinary timeout and no source change.
  This is not reported as a completely green first run. No frontend source was changed.
- All 54 standalone PHP scripts passed, including 33 trial/entitlement/budget policy checks.
  PHP syntax passed for 219 source/test files. Local auth tests need the installed Git OpenSSL
  configuration, supplied through OPENSSL_CONF; no application setting was changed.
- Migration 053 and 36 SQL checks passed on both MySQL 5.7.44 and MariaDB 11.4.8. Actual child
  PHP processes compete for individual and global allowances. Checks include budget rechecks
  before sending when later shared costs exhaust previously reserved capacity.
- Existing travel inbox regression passed on both engines: 74 inherited booking checks and
  28 inbox checks each. Updated SQL runner PowerShell parsing and git diff --check passed.
- The first month-rollover fixture incorrectly used UTC +1 month across a Toronto DST change;
  corrected it to the explicit next local-month boundary and reran both engine suites.

No private environment, hosted database, identity provider, DNS, phone number, sender or payment
account was changed. The foundation is ready for the next authorized administration slice,
not a claim that public signup or paid collection is complete.
