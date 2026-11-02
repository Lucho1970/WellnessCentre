# Practitioner pilot access administration

**Latest checkpoint — manual upload handoff:** Migration 058 verification completed: all
three objects are InnoDB, with column counts 12/9/6 and foreign-key counts 4/2/2; each had
zero rows. A fresh private database export was verified and saved before file changes.
The freshly downloaded live configuration matches the reviewed candidate except for the
five intended rollout flags; mail/SMS remain disabled and pilot mode/workforce approvals
remain intact. Five supporting PHP files have uploaded through Netfirms HTTPS File Manager:
the two new pilot services, AccessAudit, Config and PilotPolicy. No other upload is confirmed;
the complete API package, public assets, HTML and settings still need deployment/verification.
The owner requested taking over file copies. Use the three prepared transfer ZIPs described
in the ledger; copy API/assets first and verify public asset hashes before the entry pages.
The inspected Beyond Compare profile uses FTP on port 21; no profile setting was changed and
no transfer was performed through it. Use verified encrypted transfer or the HTTPS manager.

**1 November hosted follow-up:** The owner reports applying migration 058. The existing
phpMyAdmin `wellness_centre` SQL result screen shows three successful CREATE TABLE results
for the pilot access, events and requests objects. The agent did not replay the migration.
Detailed resulting-schema verification and a current backup check remain pending: computer
control could read the result screen but failed to activate the browser after recovery.
No new API, assets, HTML, private flag or approval has been deployed by this follow-up.
Both clinic public intake endpoints still report unavailable/closed, without plan or terms;
anonymous central `/application/me` still returns 401. The package verifier again passed
239 checks and all three transfer ZIP hashes remain unchanged. See the
[current evidence ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md).

1 November 2026. Development on `feature/practitioner-pilot-admin`, continuing the
authorized Wellnexa Google/personal-Microsoft sign-in work. The owner requested managing
allowed practitioners in the Admin portal after both test accounts reached the existing
private pilot denial. This document records implementation scope, not deployment approval
for a new migration or real-PHI acceptance.

## Intended operator workflow

Use central **Application administration → Practitioner pilot access**, scoped to one
clinic. An existing active application-administrator grant is required. Clinic workforce,
billing identity, practitioner status or a matching email alone does not authorize this UI.

1. The practitioner signs in through the dedicated external application. Full JWT signature,
   issuer, tenant, API audience, staff SPA, lifetime and scope validation must succeed before
   a denied pilot user receives an own-account `WPI1` support reference. It contains only
   adapter/issuer/subject, with short groups for the existing bilingual validation summary.
   It contains no bearer token, email, display name, directory roles or other token claims.
2. The operator independently confirms the intended account with its owner, records that
   reference and a descriptive label, and supplies a reason. This creates a **pending**
   entry, with no access. The reference is an encoding, not a signed attestation or an
   authentication credential: do not trust unsolicited references or identify someone by email.
3. The operator reviews the exact clinic and identity and explicitly **approves** the entry.
   Approval permits the additional pilot login gate only. Professional application review,
   joining terms, separate activation, active clinic membership, practitioner role, record
   relationship and consent restrictions still apply.
4. **Revoke pilot access** denies the same otherwise valid identity on subsequent protected
   API requests. Reapproval is explicit, versioned and audited. Records and history remain.
   Pilot revocation is separate from ordinary practitioner departure: use Staff access →
   Inactive and review bookings, publication, billing and communications as recorded in the
   [live test runbook](WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md).

The queue is populated by the authorized operator recording a verified own-account
reference. No new anonymous or pre-allowlist write endpoint is introduced. No password,
provider token, patient record or payment detail belongs in the label/reason fields.

## Runtime and data boundary

`PRACTITIONER_PILOT_ACCESS_ENABLED=false` is the default. With this flag and `PILOT_MODE=true`,
the database is the authority for external practitioner pilot approvals for the server-resolved
clinic. Workforce and client pilot policies retain their existing private environment lists.
Existing static external-practitioner approvals must be reviewed/migrated before enabling
the database feature; the configuration rejects a mixed authority instead of preserving an
environment bypass. The current inspected host has two workforce approvals and no static
external-practitioner approvals.

The feature does not control ordinary production login when pilot mode is off; its Admin
controls must report unavailable then. Do not turn pilot mode off to accommodate testing.
Missing/unavailable required tables or locking authority must fail closed. An approval for
Wellnexa cannot satisfy another clinic's pilot gate.

Sensitive API execution holds a bounded, identity-and-clinic-scoped database advisory lock.
The matching administrator decision acquires that lock too. A revoke waits for already
authorized execution to finish, then commits; later execution rechecks the current decision.
This avoids claiming that a completed revocation overlaps an earlier request's sensitive
effects. Rendered/downloaded data and already committed notification work still require their
normal controls; no promise of remotely erasing data is made.

## Proposed schema and release gate

**Migration 058 was applied by the owner; schema/key and current-backup checks passed.** It adds three
tables only: `practitioner_pilot_access`, `practitioner_pilot_events` and
`practitioner_pilot_requests`. It seeds no approval, membership, role, term, price or flag.
The access row holds clinic, product identity, operator label, pending/approved/revoked
status, optimistic version, actor IDs and timestamps. Events preserve reason/actor/action;
request keys preserve idempotent administrator outcomes. No deletion endpoint is planned.

Admin reads/writes recheck the active explicit grant and clinic. Lists/history are bounded;
decisions require version, unique request key and reason. Uncertain retries reuse the same
payload/key; stale or changed retries conflict. Approval replay must never restore revoked
access. Writes and their required audit event commit atomically.

These nonclinical identity-administration records need an approved retention/hold schedule;
no automatic expiry/deletion is added. API access auditing retains its current redacted
resource/actor/correlation boundary. No new provider, app registration, Graph permission,
runtime dependency, password, authentication protocol or clinical privilege is introduced.

## Evidence and outstanding work

The three HTML entry files were uploaded by the owner. Nine route/file checks passed after
a stale Wellnexa homepage response was revalidated; a subsequent canonical homepage GET
also matched the release. Rendered `/staff/external` shows both independent provider choices,
while `/staff/login` shows workforce Microsoft only. Both named test accounts completed the
provider return and currently receive pilot denial; no application/membership has been
created or approved. Their emails and eventual identity references remain private.

Google initially hit `AADSTS50011` for the missing Wellnexa callback on staff SPA
`2ccf904c-2b78-4348-9acb-72773d931416`. The admin session initially listed three SPA callbacks;
the Wellnexa callback appeared during inspection, bringing the list to four. The agent did
not perform a Save action. The live return then progressed to the application pilot gate.
Existing callbacks, app IDs, scopes, secrets and MFA/Conditional Access were preserved.

The implementation now includes the Admin queue, exact current-grant/clinic checks,
identity-scoped execution/decision locking, atomic events and idempotent retries. Local
validation includes **67 signed external-token checks**, **16 private preflight checks**,
**80 pilot-policy checks**, **55 request-body HTTP checks**, and the full **45-case central
Admin browser suite**, including nine new pilot cases. MySQL 5.7 passed **36 ledger + 62
managed-pilot SQL/HTTP/concurrency checks** plus the existing **36 + 119 application flow**
checks. Final MariaDB also passed 36 + 62; all six browser configurations total
**393 passes with two existing skips**, and the clean package has **239 artifact checks**.
Exact source, commands, package/transfer hashes and limitations are recorded in the
[current 38-control ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md).

Five supporting private PHP files are uploaded; the full pilot update and settings are not yet deployed. The reviewable source package and transfer set are prepared under
`deployments/2026-11-01-practitioner-pilot-admin` and
`deployments/2026-11-01-practitioner-pilot-admin-transfer`. Verify a current backup and
obtain exact 058 approval, then complete hosted gate/registration/activation/
normal departure acceptance and accountable review before opening the flow. An Admin pilot
approval still cannot supply membership, application approval, payment entitlement or a role.

Rollback closes the new feature flag and restores matching API/admin code, preserving
new approvals/events and all application/clinical/audit records. Do not drop tables or
restore an old database over newer records. Review any previously effective static pilot
configuration before rollback; it must not silently regrant an operator-revoked identity.

The [38-control evidence ledger](WELLNEXA_PRACTITIONER_SIGN_IN_VALIDATION_2026-11-01.md)
and existing privacy/session/infrastructure/operational gaps remain applicable. Custodian,
security/operations, privacy, clinical and release-owner sign-offs remain distinct from
implementation authorization and the owner's choice of two test accounts.

RELEASE STATUS: NOT READY FOR RELEASE
