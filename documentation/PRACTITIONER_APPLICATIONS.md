# Wellnexa practitioner applications

Implemented on `feature/practitioner-applications`, following central billing administration.
This is the Wellnexa joining workflow; company signup and tenant provisioning remain separate.
No hosted database, identity provider, messaging, payment service or deployment is changed.

## How it works

1. In central **Billing**, create an immutable CAD practitioner plan. In **Practitioner
   applications**, choose the clinic, plan and trial timezone, publish reviewed plain-text
   joining terms and open intake. The software seeds no legal terms, prices or accounts.
   An unknown base price is displayed as unpriced, not zero. This slice does not calculate
   booking-based shadow invoices.
2. A practitioner visits `https://wellnexa.copihue.ca/staff/apply` and signs in/registers using
   the dedicated external practitioner identity application. They submit contact details,
   discipline, declared credentials, service area and proposed services, explicitly confirm
   accuracy and accept the displayed terms. No patient data, payment details or identity
   documents should be submitted. An optional earlier enquiry reference is a review hint,
   never identity evidence or automatic account linkage.
3. Applicants see only their own clinic application, verification code and reviewer message.
   Central operators request changes, reject or approve. Revisions require fresh consent and
   retain immutable submission history. Approval requires independent confirmation of the
   person and code, credential/eligibility review, initial bookable location and private audit
   reason. Applicant-visible messages are separate from private reasons. Operators can
   explicitly associate a same-clinic joining enquiry after checking the intended person.
4. Approval creates no workspace access or trial. After rollout acceptance, an approved
   applicant accepts the current terms and activates. This creates a clinic practitioner
   membership, global practitioner association and billing account. Six calendar months start
   at actual activation in the approved timezone, with month-end clamping and exclusive UTC
   expiry. Review freezes plan/timezone; plan changes require reapproval and changed terms
   require fresh applicant acceptance.
5. Activated practitioners see the recorded trial end and a checklist for services,
   availability, current verified qualifications and public profile publication. They can open
   the existing practitioner portal. Checklist items report existing records; signup does not
   verify credentials, publish profiles, assign services/calendars or grant SMS funding.

Applicants can withdraw before activation. Rejected applications can be reconsidered through
an operator requesting changes; activated/withdrawn applications cannot be approved again.
Operators currently communicate review/activation availability through their existing process:
this slice sends **no application or approval emails**. Notifications, sender setup, reminders,
shadow invoices, provider reconciliation and subscription enforcement remain later stages.
A trial record does not enforce subscription access in existing booking APIs or the SMS worker.
There is no automatic paid conversion or retroactive trial charge.

## Identity and existing accounts

The exact verified `(adapter, issuer, subject)` identifies an applicant; email never binds an
account. Existing dedicated Entra External ID API/SPA trust is retained: RS256, exact
issuer/tenant/audience/authorized SPA and `access_as_staff`. Customer and workforce tokens
cannot apply through this external workflow. Application access works before membership;
other staff APIs still require current clinic membership and role. Central review requires a
current explicit application-administrator grant on the central host, independent of clinic
admin or directory roles.

Unrelated same-email accounts block activation for manual review. Existing workforce users
use the established global practitioner/clinic assignment workflow; identities are not merged.
An existing active membership for the **same verified identity** requires an explicit reviewer
reuse exception and current practitioner role. Revoked/inactive/pending memberships are never
restored. Existing valid trial/paid subscriptions are reused unchanged. Expired, suspended or
ambiguous history requires billing review instead of a new free trial. Existing global names,
private credentials and other-clinic records are preserved, without copying clinical data.

New external memberships grant only the practitioner role. Independent-practitioner client
profile/clinical-record isolation and hosted identity readiness remain **release gates**;
this feature does not complete them. See [Multi-tenant development path](MULTI_TENANT_DEVELOPMENT_PATH.md)
and [Global practitioners](GLOBAL_PRACTITIONERS.md). A readiness attestation records the
operator’s acceptance; the software cannot certify those checks.

## Migration and API

Apply `api/database/migrations/055_practitioner_applications.sql` once after the maintained
baseline, 054, global practitioners 042, enquiries 047 and access audit 049. Rehearse against
a backup first; do not import the full schema over an existing installation. Migration 055
adds clinic settings, immutable terms/submissions, one application per clinic/identity,
reasoned events, replay results and hourly counters, and extends the access-audit actor enum.

| Caller | Method and path | Purpose |
| --- | --- | --- |
| Anonymous clinic visitor | `GET /api/v1/public/practitioner-applications` | Published offer/intake state |
| External practitioner | `GET /api/v1/practitioner-applications/me` | Own status and current offer |
| External practitioner | `POST /api/v1/practitioner-applications/me` | Initial/revised submission |
| External practitioner | `POST /api/v1/practitioner-applications/withdraw` | Withdraw before activation |
| External practitioner | `POST /api/v1/practitioner-applications/activate` | Activate approved membership |
| Central admin | `GET /api/v1/application/clinics/{id}/practitioner-applications` | Settings/latest 100 applications |
| Central admin | `GET .../practitioner-applications/{id}` | Current details and submission history |
| Central admin | `POST .../practitioner-applications/settings` | Revisioned terms/rollout settings |
| Central admin | `POST .../practitioner-applications/{id}/review` | Versioned reviewed decision |

Mutations require a UUID `request_key`, optimistic version and applicable consent/attestations.
State, audit and replay result commit together. Retry the identical request after uncertain
saves; changing its payload conflicts. Activation replay checks current membership and never
restores revoked access. Forms freeze uncertain requests and clear stale private content on
authority/conflict denials. Bodies are capped at 64 KiB. Accepted submissions are limited to
five per identity/clinic/hour and 100 per clinic/hour; invalid requests do not consume those
counters. Identity creation abuse remains the identity provider’s responsibility.

Private responses use `Cache-Control: no-store`. Access audits retain resource references and
a SHA-256 identity fingerprint, never raw claims, applicant contacts or submission text.
Application/submission tables themselves hold private onboarding data, subject to reviewed
database/backups/retention governance. No deletion job or document-upload provider is added.

## Default-off rollout

API requires clinic management and central administration enabled. New flags all default off:

```dotenv
PRACTITIONER_APPLICATIONS_ENABLED=false
PRACTITIONER_APPLICATION_ACTIVATION_ENABLED=false
STAFF_EXTERNAL_AUTH_ENABLED=false
```

Retain dedicated external practitioner tenant/API/SPA settings from
[Staff invitations](PRACTITIONER_INVITATIONS.md); never reuse workforce/customer application IDs.
Portal build flags are `VITE_PRACTITIONER_APPLICATIONS_ENABLED=false` and
`VITE_STAFF_EXTERNAL_AUTH_ENABLED=false`. The separate Wellnexa marketing build has its own
`VITE_PRACTITIONER_APPLICATIONS_ENABLED=false`, exposing a link to its configured portal’s
`/staff/apply` when enabled. A marketing link does not enable API intake.

Order: rehearse/apply 055; verify real identity callbacks/MFA/recovery; publish approved terms
and plan; enable API/portal applications and clinic intake. Collect/review applications with
activation held if needed. Enable durable external sign-in in **both API and portal** before
activation. After independent-practice privacy and hosted operational acceptance, enable the
global activation flag and record per-clinic readiness in central settings. Both activation
gates default off. Closing clinic intake stops new/revised submissions while preserving status
and separately enabled approved activation. Hold activation separately to stop that too.
Disabling the entire application feature removes status routes; durable external sign-in
preserves existing approved practitioners’ portal access. Keep durable sign-in enabled when
closing intake. Legacy invitation gating stays independent.

Hosted acceptance must verify real trust/callbacks, host and per-identity separation, customer
and workforce denial, consent revisions, independent review, audit rollback, concurrent
activation, revocation, conflicts, preserved trial and setup without publication. Local tests
do not establish hosted database, identity or clinical privacy acceptance.

## Local checks

From `Frontend`, run `npm run build`, normal Playwright regression,
`npx playwright test --config playwright.applications.config.ts`,
`npx playwright test --config playwright.application-admin.config.ts` and the existing
`playwright.invitations.config.ts`. Portal browser configurations share ports and run
sequentially. Run standalone PHP scripts and both SQL runners with
`-IntegrationTest practitioner-applications.php`. SQL tests use synthetic loopback databases,
local signing keys, competing PHP processes and HTTP dispatch, with no private `.env` or
provider requests. Wellnexa also has its own production build and browser regression; its
`npx playwright test --config playwright.applications.config.ts` checks the optional marketing
link with the feature enabled.

## Verified locally, 27 October 2026

- Frontend public/portal/central production builds, TypeScript, content checks and 2,396
  English/French translation checks passed. Existing bundle-size notices remain non-blocking.
- Main browser regression: 283 passed, two optional local-input tests skipped. Application
  flow: nine passed, including real dedicated MSAL configuration, mobile French, retained
  drafts on language changes, consent, exact retries, withdrawal and held/approved activation.
- Central administration: 24 passed. Existing invitations: seven passed. Wellnexa production
  build and 24 browser regressions passed; its enabled application-entry check also passed.
  Total: 348 browser tests passed, two skipped. The mobile French entry screenshot was reviewed.
- All 54 standalone PHP scripts passed; PHP syntax passed for 223 source/test files. Windows
  RSA tests used the installed Git OpenSSL configuration via `OPENSSL_CONF`.
- MySQL 5.7.44 and MariaDB 11.4.8 each passed 36 inherited ledger checks and 69 application
  SQL/HTTP checks, including concurrent activation, identity/host isolation, revocation,
  changed terms/plan reapproval, audit rollback, same-email conflicts, preserved existing
  trials and expired-history refusal. PowerShell parsing and `git diff --check` passed.

These are local implementation results. Hosted SQL, external identity, independent-practice
privacy, reviewed joining terms, provider delivery and production activation remain unaccepted.
