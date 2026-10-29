# Automated communications and SMS budget enforcement

Implemented on `feature/automated-communications`, following practitioner applications.
Migration 056 adds the central **Communications** tab, operational email outbox and SMS guard.
No hosted migration, sender, phone number, provider account, message, payment or deployment
was performed by this work. New runtime flags default off.

## Delivered behavior

- Application submissions, decisions, withdrawals and activations queue generic applicant and
  operator emails atomically with the application audit. Replay creates no duplicate recipes.
  Earlier application versions are canceled before sending. Messages contain no declared
  credentials, review reasons, verification codes or patient information.
- Scheduled scans queue trial notices for the practitioner and operator during 30-, 14-, 7-
  and 1-day windows, plus expiry day. Windows are 24-hour intervals relative to stored UTC
  expiry. Changed dates/status cancel stale notices; missed windows are not replayed in bulk.
  No payment, retroactive debt, account suspension or paid conversion occurs.
- Budget emails are deduplicated at 50%, 80% and 100% of confirmed plus committed costs per
  member and pool/month. Operators and current active members receive applicable alerts.
  Billing remains the balance/funding/overhead dashboard. Communications shows notice health,
  held SMS, provider references, costs and review controls without patient data.
- Before each existing staff booking SMS, the worker requires active clinic/identity/member/
  practitioner role, a currently usable CAD practitioner subscription with `sms_reminders`,
  funded enrollment, explicit sender, current conservative rate and opt-out review, no
  suppression, current preferences and patient privacy authorization. It reserves both caps
  and rechecks before the atomic one-attempt provider claim. Replays never authorize a resend.
- Managed appointment emails use the explicitly configured clinic Graph sender. Patient
  appointment Reply-To uses a separately verified practitioner address only after explicit
  approval for patient-facing sharing. Private staff notification addresses are never copied
  automatically; otherwise clinic Reply-To is used. Staff/operational notices also use clinic
  Reply-To. Managed links resolve the actual
  active clinic host, without falling back to another clinic’s portal or sender.

The existing SMS channel contains staff booking/change/cancellation/reassignment alerts. This
slice meters that channel; it does not introduce patient SMS, incoming conversations, calls,
voicemail, mailbox/DID purchases or custom-domain OAuth connections. Patient SMS needs a
separate explicit consent/destination workflow. Eligible email continues independently when
SMS is held; no extra fallback email is fabricated and email opt-outs remain respected.

## Spending and provider boundary

The beta defaults remain CAD 10 per member, at most ten funded members and CAD 100 shared per
calendar month, including overhead, with no rollover or automatic paid overage. Billing manages
these limits. Before SMS, the current period must contain a shared overhead reservation or
confirmed entry. Reserve known number rental/fees each new month. A confirmed zero is an
explicit operator decision, never a seeded assumption.

Communications records operator-approved CAD cost per billable segment plus optional fixed
message cost, in integer millionths of a CAD dollar. The UI accepts six decimals. Include FX
buffer, provider fees and taxes. GSM extension characters consume two septets; ASCII backtick
requires UCS-2 estimates. Multipart capacities and the existing 160-character ASCII transport
limit are accounted for. Each message estimate rounds up to a whole CAD cent. Missing/expired
rates stop SMS; maximum approved validity is 90 days. No provider price or FX rate is invented.

Provider send acceptance supplies a reference, not a confirmed CAD bill. Both acceptance and
timeouts retain the original commitment until central reconciliation. Record the exact final
converted cost even above the estimate/cap; corrections require the previous actual amount
and a reason. Settlement stays in the original month after rollover/removal/suspension.
Explicit provider-confirmed no-send releases an unsubmitted reservation or settles a submitted
attempt to zero; it never permits another send under that notification reference. Acceptance
does not establish handset delivery.

Automatic provider cost import, FX conversion, suppression synchronization and delivery receipts
remain adapters to implement against a verified provider contract. Until then, operators enter
actual costs and synchronize provider STOP requests here. Suppressions store hashes of canonical
Canadian `+1` destinations. Unblocking requires renewed opt-in/provider-clearance confirmation.
SMS stops when the provider opt-out review is over 24 hours old. The attestation records the
operator’s review; it does not inspect the provider account.

Known caps prevent new authorized commitments above known costs. They cannot guarantee the
final bill against unknown fixed/inbound charges, FX changes or provider adjustments. Unknown
and over-cap costs stay visible and prevent further spending.

## Outbox and recovery

`communication_notices` stores unique application/subscription/budget recipes, attempts,
leases and outcomes. Trial scans exclude already materialized recipes before their batch limit.
Current clinic, recipient, trial window and threshold are checked again at send time. Operator
alerts use the configured inbox; membership notices use the current active member’s work email.
Application contacts are self-declared, so generic email does not prove identity.

Known non-delivery failures use bounded backoff, at most five attempts. Transport ambiguity and
expired leases become **Needs review**, with no automatic resend. Operators can resolve or retry
an email only after checking provider history and proving no-send. Retry does not reset limits.
Mutations require audit reason/UUID request key; uncertain saves freeze the original request.
Revoked authority and stale costs/settings block replay and clear private UI details.

Normal scheduled `NotificationWorker::run` reserves one third of its limit for operational
notices when automation is on and limit is at least three. CLI defaults to 20; protected hosted
cron uses three. Immediate appointment dispatch processes that appointment only. Operational
notices wait for the scheduled worker. `MAIL_ENABLED` remains the master delivery gate.
Existing scheduler health records include combined results; Communications shows its own
overdue/stale outbox items. Monitor scheduler freshness and backlog, not just process success.

## Administration and API

Current explicit application-administrator grant and central host are required. Clinic admin
roles do not grant global communications authority. Private responses use no-store/access
auditing. Secrets and patient content are absent from this management interface.

| Under `/api/v1/application/clinics/{id}/communications` | Purpose |
| --- | --- |
| `GET` | Settings/gates, latest 100 notices/deliveries, latest 50 held SMS and aggregate health |
| `POST /settings` | Revisioned senders, conservative tariff/expiry and provider attestations |
| `POST /suppression` | Block a Canadian destination or confirm renewed opt-in |
| `POST /reply-address` | Versioned patient-facing practitioner Reply-To with explicit sharing approval |
| `POST /deliveries/{notificationId}/reconcile` | Final CAD cost, confirmed no-send or correction |
| `POST /notices/{id}/review` | Resolve or explicitly retry an uncertain operational email |

Mutations, reasoned events and replay results are atomic. Central cost reconciliation uses an
explicit joined ledger transaction so audit failure also rolls back money. Pool rows still
lock before ledger rows. The worker holds the clinic consent lock through preparation/send;
configuration/suppression changes use the same lock. Existing patient privacy controls remain.

## Migration and rollout

Apply `api/database/migrations/056_automated_communications.sql` once after 055 and its
prerequisites, including consent schema 050. Back up and rehearse first; never import the full
schema over an existing database. New settings/suppression/delivery/outbox/audit/replay tables
seed no senders, rates, recipients or provider activity.

```dotenv
AUTOMATED_COMMUNICATIONS_ENABLED=false
SMS_BUDGET_ENFORCEMENT_ENABLED=false
```

Retain existing Graph/VoIP.ms credentials, valid global transport initialization settings,
`MAIL_ENABLED`, `SMS_ENABLED`, `CLIENT_PORTAL_URL` and `MAIL_TRIGGER_SECRET`. Managed sends use
the explicit clinic identities; loading the screen purchases nothing and sends no test.

1. Rehearse/apply 056. Verify clinic hosts, Graph-authorized senders, reply/alert addresses and
   assigned Canadian SMS numbers. Sender ownership and Graph/DID authority are operator checks.
2. Enable runtime administration flags after upload/readiness checks while keeping actual
   delivery off as appropriate. Automation also enables clinic routing for existing appointment
   emails: configure every affected clinic before resuming mail, or messages require review
   instead of using the global fallback. Flags off preserve existing deployment behavior.
3. In Billing, configure the practitioner SMS plan, usable subscriptions, funded cohort and
   current-month overhead. Practitioner signup retains its independent privacy/identity gates.
4. Configure Communications rates/expiry and sender addresses, synchronize suppressions and
   attest provider review. No mailbox, DID or DNS/domain is provisioned automatically.
5. Verify cron authentication/cadence, result/backlog health, deliberate synthetic hosted
   delivery and recovery before enabling production. Beta SMS must use the guard, not legacy
   unmetered mode. Reconcile costs, renew rates/reviews and reserve each new month’s overhead.

No automatic account suspension, invoice collection, company provisioning or paid conversion
is added. Those remain independent stages with commercial policy decisions.

## Verification

Both portable SQL runners accept `-IntegrationTest automated-communications.php`. Tests chain
ledger/application/HTTP checks, race real PHP workers against fake transports, and never read
private `.env` or contact providers. Run standalone PHP scripts, frontend build, main and central
browser regression, and existing applications/invitations browser configurations. Local tests
do not establish hosted identity, sender ownership, rates/fees, opt-outs or provider delivery.

## Local validation, 28 October 2026

- Public, portal and central production builds, TypeScript, content checks and 2,474 bilingual
  translations passed. Existing bundle-size notices remain non-blocking.
- Main browser regression: 283 passed, two optional local-input tests skipped. Central admin:
  32 passed, including eight communications/reply-address/recovery checks. Existing application
  and invitation suites: nine and seven passed. Total: 331 passed, two skipped. The French
  mobile communications editor screenshot was inspected.
- All 55 standalone PHP scripts passed, including ten segment/currency estimate assertions.
  PHP syntax passed for 231 source/test files. Windows RSA fixtures used the installed Git
  OpenSSL configuration through `OPENSSL_CONF`.
- MySQL 5.7.44 and MariaDB 11.4.8 each passed 36 inherited ledger checks, 69 application SQL/HTTP
  checks and 58 communications SQL/worker/HTTP checks. These cover competing worker claims,
  retained uncertainty, real over-cap cost recording, stale corrections, sender isolation,
  entitlement/role/consent/opt-out checks, monthly overhead, outbox audit rollback, trial/budget
  deduplication, explicit patient-facing reply consent and host/grant/body-size boundaries.
- An intermediate MariaDB run used the earlier fixture without the newly required monthly
  overhead entry. The final fixture records its explicitly reviewed synthetic overhead and
  both engines passed afterward. A completed Windows central-browser run retained its own
  Vite helper during teardown; that specific helper was stopped and final central regressions
  completed normally. PowerShell parsing, documentation links and diff checks passed.

Private settings, hosted data, live delivery, DNS, sender/number ownership and payment accounts
were unchanged. Rollout acceptance and reviewed provider synchronization/reconciliation remain
required before production activation; automatic provider adapters remain future work.
