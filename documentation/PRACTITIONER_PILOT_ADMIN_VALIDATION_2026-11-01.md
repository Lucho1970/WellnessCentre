# Practitioner pilot administration — 1 November 2026

Reviewer: Codex, source/local testing only. Branch `feature/practitioner-pilot-admin`,
based on `6845e30` from the ongoing practitioner sign-in work. Implementation revision:
**`07febd06c49f9ef90995a946ca7d338a725b36d5`**. Final artifact checks are recorded
separately after the package build; subsequent documentation does not change application code.
The owner subsequently applied migration 058; see the hosted follow-up immediately below.
At the original implementation checkpoint the agent had performed no merge, schema write,
managed approval, account creation or hosted configuration change. Later authorized pilot
decisions and the owner's Staff Inactive edit are recorded below. The unrelated untracked PDF remains
untouched and excluded. Implementation authorization does not establish organizational approval.

## Hosted follow-up after the owner's database update

### 2 November latest: corrected hosted detail and synthetic approval passed

After the owner copied the one-file patch, original immutable submission detail/history
rendered successfully. Central Admin approved only the owner-authorized synthetic Microsoft
application at Virtual Clinic on the CAD 0 plan, without existing-membership reuse or claims
of real credential verification. The preserved applicant session was denied workspace access
both before and after approval; own status reports approved, activation held and trial not
started. No reusable token bytes were inspected. Google remains Inactive/pilot-revoked and
Microsoft pilot-allowed. AUTH-02/REL-03 gain only these scoped hosted checks; the existing
38-control ledger and organizational gaps remain. Activation/trial/ordinary Microsoft
Inactive/final revocation still require hosted acceptance. See the [focused report](PRACTITIONER_APPLICATION_JSON_FIX_2026-11-02.md).
No source, dependency, permission, consent wording, endpoint or external data-flow change
was made during this hosted verification. Broad local suites were not repeated.

The existing explicit rehearsal authorization then covered enabling Wellnexa's separate
activation setting. Its audit reason limits readiness to this controlled synthetic test;
it is not an organizational release/privacy/credential sign-off. Admin save and verified-TLS
anonymous offers confirm Wellnexa intake/activation true, unchanged plan/terms hash, and
LivinLively intake/activation false without plan/terms. The applicant page shows unchecked
activation consent and an initially disabled activation button. Personal acceptance and
activation have been handed to the owner; membership/trial/Inactive remain pending.
This changes only the approved clinic setting, not runtime permissions or consent wording.

### 2 November: owner submission passed; review JSON failure reproduced and corrected locally

The owner accepted the approved terms and submitted the synthetic Microsoft application;
both own status and clinic-scoped Admin queue verify submission. Two review-detail reads
failed before any approval. The private log reports JsonException/code 5 at the submission
decode line; it is excluded from Git. On continuation branch
`bugs/practitioner-application-json-encoding`, a native-driver lossy-result regression fails
before the narrow binary JSON projection and passes after it. Both engines pass 36 ledger +
122 application SQL/HTTP/concurrency checks; all 61 standalone PHP scripts pass. The
[dated finding](PRACTITIONER_APPLICATION_JSON_FIX_2026-11-02.md) records exact commands,
initial defaults-only pass, limits, files and rollback. Actual host session charset remains
unverified. The 38 statuses below apply with its stated scope qualifications; none grants
general hosted/organizational acceptance. Activation remains held, Microsoft pilot allowed,
Google Inactive/revoked; approval/trial/ordinary Microsoft Inactive are not yet tested live.

### 2 November subsequent checkpoint: approved synthetic plan and intake configuration

The owner explicitly approved the reviewed rehearsal plan and bilingual terms. Fresh private
backup structural/hash verification and two hosted Admin saves passed as detailed in the
[live-test record](WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md). Plan v1 is practitioner/CAD 0,
booking/calendar/client-management only. Wellnexa intake is open, activation held; LivinLively
remains closed with no plan/terms. Exact approved wording comparison passed. No subscription,
application, activation, membership or trial was created in this setup. Microsoft's pilot
approval was then restored with its owner-verification attestation and explicit test reason;
its preserved session now renders the new-application form with plan/terms v1. Synthetic
details are filled, acceptance/submission handed to the owner; Google remains Inactive and
revoked. Settings rollback preserves versioned plan/terms/audit
records; actual database restore remains untested. Source patch/locks/dependencies, grants,
endpoints and external data flows are unchanged. All 38 statuses remain qualified as below;
PRIV-02/REL-05 now include this exact owner-approved synthetic-only configuration, without
claiming real-data, clinical credential or general release acceptance.

### 2 November: Google returning-profile, ordinary Inactive and final revoke passed

The owner chose existing-profile testing and completed the normal Staff access edit after
browser autofill/URL-policy interruption. UI verified the original approved account email,
practitioner role, saved Inactive status and success response. A separate temporary pilot
reapproval saved, but Retry in the preserved Google session still denied permission without
workspace access. Final pilot revocation saved and was verified in Admin. This establishes
ordinary Inactive enforcement independently of the extra pilot gate; it does not prove exact
bearer-byte replay, full operational offboarding, other-clinic status or fresh Google signup.
No source/dependency/schema/configuration/provider flow changed in this operational follow-up.

### 2 November final checkpoint: Microsoft pending, allow and revoke boundaries passed

The distinct owner-confirmed Microsoft reference was recorded through central Admin, pending
for Wellnexa. The live minimized aggregate confirmed zero memberships; pending Retry denied
access. Approval saved, while the same session still received ordinary workspace permission
denial. Its own application page reported intake closed. Revocation saved and Refresh
application in the preserved session then returned pilot denial, removing the prior status.
This is same-session behavior, not a claim of byte-identical bearer replay. A final refresh
of the two-label read-only aggregate confirmed Google revoked / one retained membership /
zero active staff memberships / one Inactive staff user, and Microsoft revoked / zero
memberships. No direct SQL write, application submission, activation, trial, charge or send
occurred. No patient record was manually opened; earlier automatically rendered dashboard
content is qualified in the finding below. All identity/reference values stay private.

The 38-control statuses below remain applicable, qualified by this dated hosted evidence:
AUTH-01/02 now include both provider returns, pending/allow/revoke boundaries and Google's
ordinary Inactive denial; SEC-04/06 and DEV-04 include successful hosted versioned decisions;
REL-03/05 remain Partial because full new registration/activation, remote private-file hashes,
restore and accountable approvals remain incomplete. The older membership explains Google's
workspace outcome; an automatic-membership defect was not established. The historical
investigation hold below is superseded by the owner-authorized returning-profile test and
the verified Microsoft zero-membership result. No general clinical-access acceptance follows.

This follow-up changes only the four maintained documentation files listed in the response
fix report. `git diff --check` is the applicable documentation check; code suites are not
rerun because the tested patch and lockfiles are unchanged. Exact previous commands, versions,
failures and final counts remain in that report. A synthetic-only registration proposal is
prepared in the live-test runbook; no proposed plan or terms have been saved on the host.

### Earlier checkpoint: patch decision path passed; unexpected workspace contained

**Subsequent read-only result:** phpMyAdmin recovered and the clinic-scoped aggregate
completed: Google pilot status revoked, identity created October 8, one active Wellnexa
membership created October 14, practitioner-role flag true. The private pre-rollout backup
independently matches that reviewed subject and owner-approved Google email on older active
staff rows. Only aggregate fields/date/counts and email-match booleans were reported; no
clinical records, account identifiers or actual emails enter this ledger. The older membership
explains the workspace outcome; automatic creation by pilot approval is not established.
Keep Google revoked while the owner chooses existing-profile/Inactive testing or a separate
Google identity for new registration. Microsoft is checked separately before approval.

Owner-reported patch upload was followed by a fresh Google review and successful approval;
Admin showed Allowed for pilot and its decision history. Retry unexpectedly reached a
practitioner workspace before this rehearsal's application/activation. The owner confirms
the account should be new. The agent revoked that pilot entry immediately; Admin showed
Pilot access revoked, and reload of the same Google session denied access again. No client
record was manually opened. Microsoft remains unrecorded/unapproved. See the
[full hosted finding and containment](PRACTITIONER_PILOT_RESPONSE_FIX_2026-11-01.md).

The 38-control ledger below is qualified by an open high-impact AUTH-02/REL-03 finding:
no further approvals or registration until the actual identity/membership mapping and audit
evidence are established. The local exact-membership resolver is not hosted causal proof.
A minimized aggregate metadata query was initially blocked by hosting/session/HTTP 500 issues
and subsequently executed as recorded above. No direct SQL write, schema change, role grant,
payment or application activation occurred. The source patch, dependencies and external
flows remain unchanged; this follow-up records operational evidence only.

### Current checkpoint: both provider returns and blocked Google approval

On continuation branch `bugs/practitioner-pilot-response-types`, Google returned its
verified own-account reference. The exact UI reference was copied to central Admin and
recorded as **Wellnexa Test Google**, pending for Wellnexa. Its history loads; the same
practitioner session remains denied while pending. The attempted approval was rejected
with validation on `version`; no successful decision/membership/application/trial resulted.
Personal Microsoft also returned to Wellnexa with its own distinct pilot-denial reference
in a separate tab; no Microsoft entry/approval was created. Private values remain out of Git.

The [dated response fix](PRACTITIONER_PILOT_RESPONSE_FIX_2026-11-01.md) records the failing
PDO-string regression, numeric serialization correction, initial OpenSSL environment failures,
final 36 + 68 checks on each disposable engine and 61 standalone PHP passes. The 38 statuses
below are reassessed for this continuation with that narrow qualification: numeric response
types are corrected locally, while hosted decisions/full registration and all organizational
gates remain pending. No permission, dependency, identity architecture, schema or external
flow changes are introduced by the fix. The pre-fix private service remains hosted pending
the small patch handoff. Existing source/package bindings below describe the preceding release.

### Current checkpoint after the owner's private settings copy

The owner reports **settings uploaded**, confirming the requested private candidate was
copied to `/wellness-api/.env` outside the public root. Repeat read-only HTTPS checks
passed **2/2**: both clinics now return 200 with `available=true`, while enabled and
activation remain false and plan/terms remain absent. LivinLively intake remains closed.
Ignored report: `.tmp/pilot-admin-hosted-after-settings.json`. This verifies the public
application feature gate, not every private setting or the private file's remote hash.
The reviewed candidate's preflight evidence remains two workforce approvals, pilot mode
on, disabled mail/SMS and empty delivery lists. No credentials/configuration contents were
printed, committed or added to release archives.

Central `/admin/` renders Application administration; starting its existing Microsoft sign-in
reaches the organization account picker. Account selection and MFA were handed to the owner,
per the computer-use authentication boundary. No identity-provider setting, new permission,
approval, application, membership or financial transaction was changed by the agent.
The owner subsequently completed sign-in. Central Admin advertises Billing, Practitioner
applications and Practitioner pilot access; the pilot list loads under the current operator.
The clinic selector was explicitly changed from its initial clinic to **Wellnexa** and the
current filter reports no entries. No decisions or clinic settings were changed. This is
hosted administrator/list acceptance, not decision, lock or cross-role acceptance. The narrow
status selector truncates its displayed label at desktop width; readability needs correction
and retesting before general release (DEV-05). The clinic selector remains readable.

The separate Edge Guest practitioner session navigated from workforce login to external
practitioner login, then reached the existing External ID provider picker via Continue with
Google. Both Google and Microsoft account options are present. Password/MFA and choosing the
approved Google account were handed to the owner; no authentication dialog was automated.
Managed decisions and both provider registration flows remain pending.

### Earlier checkpoint after the owner's entry-page copy

The owner reports uploading `02-entry-pages.zip`. HTTPS `Invoke-WebRequest` checks using
Windows trust, no TLS bypass and `Cache-Control: no-cache` passed **9/9 new HTML routes**:
both clinic roots and each clinic's `/staff/login`, `/staff/external` and `/staff/apply`,
plus central `/admin/`. Exact package SHA-256, lengths and HTML MIME matched. All **140/140
asset hash/length/MIME checks passed again**. Ignored evidence:
`.tmp/pilot-admin-hosted-new-entry-results.json` and
`.tmp/pilot-admin-hosted-post-entry-asset-results.json`.

Fresh guest-browser rendering confirms two independent practitioner choices (Google and
personal Microsoft) on `/staff/external`; the separate `/staff/login` offers organization
Microsoft only. Existing workforce and guest-client tabs were preserved. No provider
credentials, account creation, joining terms or account approval was automated. One click
was rejected because of user input; observation was refreshed before the successful retry.

Repeat public API checks returned 200 for both clinics with available/enabled/activation
false and no plan/terms. The prepared private candidate passed
`php api/bin/pilot-preflight.php --env D:/Dev/WellnessCentre-backup/practitioner-pilot-admin-settings/.env`
with pilot mode on, two workforce approvals and zero email/SMS destinations. The owner was
given that private file's exact source and `/wellness-api/.env` target to complete the five
reviewed flags after static verification. **That settings upload remains unconfirmed**;
the real-provider approval/registration/activation/retirement flow is still pending.

No source, dependency/version, permission/consent, endpoint or external data flow changed.
This checkpoint supersedes earlier held-HTML notes. Preserve matching code/configuration
backups and all records; hosted private hashes, runtime grant/locking acceptance and rollback
restoration remain unverified. The 38-control limitations and accountable sign-offs below
remain open.

### Earlier checkpoint after the owner's API and asset copies

The owner reports completing the requested first two transfers, `00-api-private.zip` into
`/wellness-api` and `01-assets.zip` into `/public_html`. No new private setting, approval,
membership or application was created by these checks. `02-entry-pages.zip` remains held.

Read-only `Invoke-WebRequest` requests using Windows TLS trust and `Cache-Control: no-cache`
passed **144/144 static checks**: 140 asset URLs across 72 distinct JS/CSS members matched
the current package's SHA-256, exact byte count and MIME type; four HTML entry URLs matched
the previous package. The shared assets were checked on both clinic hosts, central assets
on the central host and unchanged website assets on the website host. The old HTML matches
confirm the intended staged rollout. Ignored reports:
`.tmp/pilot-admin-hosted-pre-entry-targets.json` and
`.tmp/pilot-admin-hosted-pre-entry-results.json`.

All **9/9 API smoke checks** passed, recorded in ignored
`.tmp/pilot-admin-hosted-api-after-manual-upload.json`:

- Health returned 200 / `status=ok` on both clinic hosts and the central host.
- Both public practitioner offers returned 200, with available/enabled/activation false
  and no plan or terms.
- Anonymous central application identity, central pilot list and Wellnexa staff identity
  requests returned 401; the central pilot route on the clinic host returned 404.

`./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/2026-11-01-practitioner-pilot-admin`
again passed **239 checks / 7 archives / 2,174 entries / 2,091 text signature scans**,
including the existing pinned public README key example. Source remains
`73bfd14d96e8038e9b3a40a9c4b17f5ab59be545`; no dependency, permission, consent,
external endpoint or data-flow change was made in this follow-up.

Remote private-file hashes and the owner's actual transfer transport remain unverified.
Anonymous smoke checks cannot prove matching private code, authenticated authorization,
database lock privileges or the complete practitioner flow. Next: copy the two entry pages,
verify hosted hashes/routes, then coordinate the already reviewed private settings separately.
Preserve `.env`, historical assets and all database records. Full acceptance and rollback
rehearsal remain pending; this checkpoint supersedes older deployment-status notes below.

### Earlier verified preparation and owner upload handoff

After the owner restored the browser, the prepared read-only query executed successfully.
All three pilot tables are InnoDB, with **12/9/6 columns, 4/2/2 foreign keys and zero rows
each**. The current private export additionally verified exact column names/order, primary
keys, unique indexes and foreign-key columns/targets against migration 058. The phpMyAdmin
aggregate result's no-unique-column warning only disables grid editing; no table mutation
was performed by the agent and no clinical/identity rows were displayed.

Current backup: `D:/Dev/WellnessCentre-backup/database/wellness_centre-2026-11-01-before-pilot-admin.sql`,
**1,983,779 bytes**, SHA-256
`f3b0e17d97199cecdafaa0762c62e7448ba369b713e8edf8be1a3adbab4247bc`.
The HTTPS export has the expected database/header, 152 CREATE TABLE statements, 99 INSERT
statements and COMMIT marker; the private copied bytes match. Only the three empty pilot
objects' DDL was inspected for schema verification; records were not output or used as tests.
This backup includes the owner-applied schema. Actual restoration remains untested.

The earlier interrupted HTTPS configuration download completed as `D:/Downloads/env`
(3,972 bytes). A private before-update copy is saved at
`D:/Dev/WellnessCentre-backup/wellness-api.env.before-practitioner-admin-2026-11-01`.
Semantic comparison against the reviewed private candidate found differences **only** in
BILLING_ADMIN_ENABLED, PRACTITIONER_APPLICATIONS_ENABLED,
PRACTITIONER_APPLICATION_ACTIVATION_ENABLED, STAFF_EXTERNAL_AUTH_ENABLED and
PRACTITIONER_PILOT_ACCESS_ENABLED. Both preflights passed; current pilot mode is true, two
workforce identities remain approved, delivery allowlists are empty, mail/SMS are false.
No private setting/credential value was printed, changed live or added to an archive/Git.

An ignored HTTPS upload stage, `.tmp/pa-https`, contains the 12 private delta files and 65
public members byte-copied from the verified packages; no private configuration is included.
Confirmed HTTPS uploads (all displayed 100% green checks):

- `/wellness-api/src/Service/PractitionerPilotAccessService.php` (14,927 bytes).
- `/wellness-api/src/Service/PractitionerPilotLock.php` (1,401 bytes).
- `/wellness-api/src/Service/AccessAudit.php` (18,728 bytes; refreshed listing shows 18.29 KiB/new timestamp).
- `/wellness-api/src/Config.php` (7,601 bytes).
- `/wellness-api/src/PilotPolicy.php` (6,826 bytes).

Remote byte hashes are not verified. No other source file upload, generated Composer
metadata, public asset, HTML or private-flag change is confirmed. The owner requested taking
over copies before the next file selection. Complete the full private ZIP merge to finish a
consistent source/dependency artifact, then copy public assets and verify their HTTPS hashes
before publishing the two HTML files. Leave private `.env` unchanged until those gates pass.
Preserve `.env`, existing data and older hashed assets; do not mirror/delete directories.

Read-only Beyond Compare profile inspection showed **FTP / port 21**, so the agent did not
transfer through it or change its settings. Use a verified encrypted connection or Netfirms'
HTTPS manager for the handoff. Native accessibility sometimes returned unrelated browser
text; subsequent decisions used the intended window's screenshots. File-dialog long path
input truncated, so the Service batch was selected with short basenames from its exact
staging directory. Those three uploads completed. Competing user input and cross-monitor
modal errors required fresh observation/activation; no action was taken on unrelated browser
content. No authentication dialog, password manager, security setting or terminal UI was used.

This supersedes the earlier pending schema/backup notes immediately below. Application
source/dependencies/grants are unchanged from the tested checkpoint; only the five listed
files are now hosted. Hosted full registration/approval/activation/retirement acceptance,
operator/grant/lock tests, headers, rollback rehearsal and accountable signatures remain open.

1 November 2026. The owner answered the exact 058/prepared-pilot deployment question with
“I updated the database.” Read-only inspection of the existing authenticated phpMyAdmin
window showed the `wellness_centre` result screen and three successful CREATE TABLE results
(0.0047, 0.0032 and 0.0026 seconds) for the supplied access/events/requests migration. No
migration statement was replayed and no clinical/identity rows were selected. This is evidence
of the owner's successful statements, not yet a full inspection of the resulting objects.

A read-only information_schema/count query was prepared at ignored
`.tmp/practitioner-pilot-058-verify.sql`. It selects table engine, column definitions/counts,
foreign-key counts and counts of the three new tables only; it has **not been executed**.
Computer-use input first reported an unavailable cached element, then timed out. Window
selection/state was refreshed; capture was black and activation failed with
`failed to activate captured window`. App input stopped after recovery failed; the owner
was asked to bring phpMyAdmin forward and ensure the desktop is unlocked. No security
dialog/settings or password manager was automated. No hosting upload/configuration change
was attempted. A current pre-058 backup was not found in the previously identified private
backup directory (the last known copy remains 31 October); its existence elsewhere is unknown.

Independent repeat package verification passed **239 checks / 7 archives / 2,174 entries**.
All three prepared transfer ZIP hashes match the preceding table. Repeat HTTPS checks using
Windows trust (no TLS bypass) returned 200 for each clinic's public application offer:
`available=false`, `enabled=false`, `activation_available=false`, no plan/terms. Anonymous
central `/api/v1/application/me` returned 401. An initial Python HTTPS attempt returned
URLError and supplied no hosted evidence; the Windows-trust requests above succeeded.
Application source/dependencies/permissions/external data flows remain unchanged. The generated
release manifest records build-time migration status; this dated follow-up records the later
owner action. Schema, fresh backup, file/configuration deployment and hosted acceptance remain
open. Existing 38-control statuses below are qualified by this follow-up; REL-05 now has the
owner-applied migration evidence but not verified backup/restore or completed operations.

## Result and boundaries

Central administrators can record a verified practitioner's own sign-in reference for one
clinic, review a pending entry, approve/revoke it and inspect the last 50 decision events.
Every decision requires a reason, current version and idempotency key. Approval additionally
requires independent account-owner verification. It grants only the extra private-pilot gate;
it grants no clinic membership, clinical role, patient relationship, application approval,
trial, SMS budget, publication or billing entitlement. Ordinary departure still uses Staff
access → Inactive and the existing offboarding review. No patient records are test fixtures.

The default-off `PRACTITIONER_PILOT_ACCESS_ENABLED` flag selects database authority only for
external practitioners while `PILOT_MODE=true`. Workforce/client environment approval lists
and all delivery guards remain in place. Mixed static external-practitioner approvals are
rejected. Admin endpoints are unavailable outside pilot mode; normal non-pilot authentication
continues using the existing identity/membership checks. The UI clears private state on denied,
missing or conflicting authority and on principal changes. Language switching retains drafts.

Full existing JWT validation precedes the own-account `WPI1` support reference. This encoding
contains adapter/issuer/subject only, no token/email/role/name. It is neither signed ownership
proof nor a credential. A copied reference alone cannot approve an account. Operators must
independently verify the intended owner; do not trust unsolicited references or email matches.
No anonymous/pre-allowlist write endpoint is added. Raw upstream provider tokens remain invalid.

Migration **058**, requiring verified 032/040/049 prerequisites, adds exactly three tables:
`practitioner_pilot_access`, `practitioner_pilot_events`, `practitioner_pilot_requests`.
It does not require the disabled communications updates 056/057. It seeds no grants/flags,
changes no clinical records and charges nobody. **The owner reports applying it; three
successful SQL results, resulting structure/keys and the current private backup were verified.**

New first-party API routes on the existing central host, using the existing application-admin
workforce token and active explicit database grant:

| Route under `/api/v1/application/clinics/{clinic}/pilot-practitioners` | Purpose |
| --- | --- |
| GET root, optional `status` / `before_id` | Up to 50 entries; detailed identity tuple omitted. |
| GET `/{id}` | Exact scoped identity and last 50 reason/actor/time events. |
| POST root | Record reference/label/reason as pending. |
| POST `/{id}/approve` | Versioned, independently verified approval. |
| POST `/{id}/revoke` | Versioned revocation; no deletion. |

These responses use `no-store`. Request capture limits these paths to 65,536 bytes; field
limits are tighter. SQL uses prepared values and fixed/allowlisted identifiers; pagination
is by indexed clinic/id. A clinic/identity/database-scoped advisory lock has a five-second
acquisition bound and serializes decisions with protected API execution. Completed revocation
waits for prior authorized execution to finish; later requests recheck current authority.
Unavailable tables/locks fail closed. Required decision events and retry records commit
atomically with the change. Old approval replay cannot restore revoked access.

No new external hostname, telemetry, provider, sender, API permission, consent grant, OAuth
flow, runtime dependency, lockfile version or toolchain is introduced. This adds private
identity-administration data to the existing database and own-account reference metadata
to a verified pilot-denial response. Its retention/hold schedule needs owner approval.
Reasons/labels must not contain patient information, credentials or bearer tokens.

## Changed files and purpose

| File | Purpose |
| --- | --- |
| `Frontend/src/admin/PractitionerPilotAdmin.tsx` | Clinic-scoped queue, record/review/approve/revoke/history, exact uncertain retry, private-state cleanup, bilingual modal and explicit focus restoration. |
| `Frontend/src/central/main.tsx` | Server-advertised tab; account-bound authority state; preserve forms across language changes. |
| `Frontend/src/i18n/practitionerPilot.ts` | English/French wording. |
| `Frontend/src/i18n/index.ts` | Register feature translations. |
| `Frontend/scripts/validate-i18n.mjs` | Include feature resources in actual validation. |
| `Frontend/tests/application-admin.spec.ts` | Nine managed-pilot browser cases; synthetic review/mobile screenshots. |
| `Frontend/tests/practitioner-applications.spec.ts` | Two English/French own-reference denial and retry cases. |
| `api/src/Service/PractitionerPilotAccessService.php` | Current operator/clinic checks, one approval authority, bounds, pending/approve/revoke, atomic events/retries and execution gate. |
| `api/src/Service/PractitionerPilotLock.php` | Bounded connection-scoped advisory lock, namespaced to database/clinic/exact identity. |
| `api/src/Auth/ExternalStaffAuthenticator.php` | Managed callback only after JWT verification; minimal own reference on pilot denial; missing required authority denies. |
| `api/src/Api.php` | Central route, authenticated feature metadata and server-clinic callback; release execution lock after response audit. |
| `api/src/Config.php` | Strict default-off flag and rejection of mixed external approval authorities. |
| `api/src/PilotPolicy.php` | Detect legacy external approvals without exposing them. |
| `api/src/Http/Request.php` | Stricter body limit for the new administration paths. |
| `api/src/Service/AccessAudit.php` | Canonical redacted pilot resource vocabulary. |
| `api/database/migrations/058_practitioner_pilot_access.sql` | Three nonclinical administration tables; no seed/clinical changes. |
| `api/.env.example` | Placeholder/default-off configuration and prerequisite guidance. |
| `api/bin/pilot-preflight.php` | Validate flag and reject mixed authority, with counts-only output. |
| `api/tests/external-staff-auth.php` | Signed-token/reference/gating, missing authority, invalid identity and non-pilot regressions. |
| `api/tests/pilot-preflight.php` | Valid managed settings, malformed flag and legacy authority rejection. |
| `api/tests/request-body-limits.php` | Actual HTTP exact/oversize capture for root/decision paths. |
| `api/tests/integration/practitioner-pilot-access.php` | Disposable SQL/HTTP, exact JWT, stale/foreign/role/replay/Inactive/lock concurrency and audit failures. |
| `api/tests/integration/practitioner-pilot-http-fixture.php` | Guarded loopback router with synthetic JWKs and the real managed callback. Not deployed. |
| `scripts/test-recurring-sql.ps1` | Explicit new-suite selection for disposable MariaDB. |
| `scripts/test-mysql57-sql.ps1` | Explicit new-suite selection for disposable MySQL 5.7. |
| `scripts/build-current-deployment.ps1` | Include current guide/evidence and accurate 058 migration policy in generated release metadata. |
| `documentation/PRACTITIONER_PILOT_ADMIN.md` | Operator workflow, migration/settings/deployment boundaries and rollback. |
| `documentation/PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md` | This current evidence/38-control ledger. |
| `documentation/PILOT_SAFETY_CONTROLS.md` | Link the additional default-off managed authority without rewriting historical evidence. |
| `documentation/WELLNEXA_PRACTITIONER_SIGN_IN.md` | Link pilot management and remaining hosted prerequisites. |
| `documentation/WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md` | Record actual entry/callback/pilot return and new prerequisite for continued tests. |
| `documentation/README.md` | Index the new guide and ledger. |

## Validation and initial failures

All SQL tests use explicitly created disposable loopback databases and synthetic data, not
hosted credentials. Windows RSA fixtures set process-only
`OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`. No provider sends or real payment calls.
Final broad/browser/artifact results are recorded below; local checks do not establish hosted acceptance.

Already observed: 67 external-token checks, 16 preflight checks, 80 pilot-policy checks;
Final **61 root PHP scripts and 254 syntax files** passed, including the body-limit follow-up.
Request-body suite then passed 55 actual HTTP checks. MySQL 5.7 ran the existing 36-ledger +
119-application SQL/HTTP suite successfully. Final managed-pilot runs on both engines passed **36 ledger + 62 pilot SQL/HTTP/concurrency
checks**, including ordinary Inactive/restore with the same signed bearer. The full central
Admin suite passed **45/45**. A later screenshot-only two-case rerun had one timeout waiting
for the initial Admin tab while other suites were running; the French case passed. That
startup timeout is retained; the affected case subsequently passed (1/1) after the broad
suite, without changing timeouts/retries or application behavior. Desktop and French
390-pixel mobile screenshots were visually inspected after disabling screenshot animations;
labels, wrapped identity/reference text and dialog actions remain readable.

Initial failures retained in tool output/ignored test artifacts:

- Browser expectations used raw error messages; existing safe API handling deliberately
  substitutes translated generic 403/409 errors. Assertions now check that behavior.
- A modal initially had no accessible language switch, and implicit focus restoration raced
  the disabled opener. Added an in-dialog language button and explicit transition-end focus
  restoration to the opener, or the section heading when it is unavailable. Focus test passed.
- New French test text was initially written with the Windows code page, and one assertion
  used English Close after switching language. Corrected the test suffix to UTF-8 and French
  label. No lint suppression, test skip, forced dependency update or weakened control.
- MySQL canonicalizes JSON object-key order, so a strict PHP array-order assertion on the
  retry response failed. Compare the full semantic object; status/id/version/key behavior is
  unchanged. MariaDB/MySQL retry/revocation tests subsequently passed.
- Several discovery/check commands used an incorrect relative path or unsupported Windows
  glob. They did not execute the intended check; corrected commands were run explicitly.

Dependency locks remain unchanged. Runtime: Node 22.22.1, npm 11.19.0, PHP 8.4.14,
Composer 2.9.5; disposable MariaDB 11.4.8 and MySQL 5.7.44. Inspected live runtime previously:
PHP 8.4.27/MySQL 5.7.44. Exact locked versions relevant to this work:
Frontend React 19.2.8, TypeScript 5.7.3, Vite 6.4.5, MSAL browser 5.19.0/react 5.6.0,
MUI 7.3.11, Playwright 1.63.0, ESLint 10.13.0; Wellnexa React 19.3.0, TypeScript 6.0.3,
Vite 8.3.4, Playwright 1.64.1, Oxlint 1.88.0. Composer: dompdf 3.1.6, php-font-lib 1.0.2,
php-svg-lib 1.0.2, firebase/php-jwt 7.1.0, libphonenumber 9.0.40, locale 2.9.0,
masterminds/html5 2.11.0, FastRoute 1.3.1, php-css-parser 9.5.0, polyfill-mbstring 1.43.0.

Current audits: `npm audit --json` Frontend exit 1, **11 moderate build-tree findings**;
`npm audit --omit=dev --json` exit 0, no runtime findings. Wellnexa full audit exit 0,
no findings. `composer validate --strict` and `composer audit --format=json` exit 0,
no advisories/abandoned packages. This does not approve the build exposure; the minimatch
review/remediation owner remains unassigned. Existing oversized frontend chunk warnings and
four Wellnexa lint warnings remain (two App mutation, two Details export warnings). No new
runtime dependency or advisory is introduced by this change.

## Final local checkpoint

| Exact command (working directory) | Result |
| --- | --- |
| `npm run lint` and `npx tsc --noEmit` (Frontend) | Exit 0, zero lint warnings. |
| `npm run build` (Frontend) | Final clean package build passed lint, 4 content entries, 2,566 EN/FR translations, strict types and all three surfaces (public, portal, central). Existing chunk warnings retained. |
| `npm test` (Frontend) | Exit 0; **285 passed, 2 pre-existing optional-fixture skips**, 287 total. |
| `npx playwright test --config playwright.application-admin.config.ts` (Frontend) | Final full suite **45 passed**, including nine new pilot cases. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` (Frontend) | **17 passed**, including two own-reference EN/FR denial/retry cases. |
| `npx playwright test --config playwright.invitations.config.ts --reporter=line` (Frontend) | **7 passed**. |
| Process `BUILD_PRACTITIONER_SIGN_IN=1`; `npx playwright test --config playwright.build.config.ts` (Frontend) | Final pilot artifact **15 passed**. Initial run omitted this test setting, so its default-off expectation failed (14 passed/1 failed); correct explicitly enabled pilot setting then passed all 15 without test changes. Ordinary default-off artifact variant not rerun here. |
| `npm ci` (Frontend) | Exit 0; exact lock restore, 350 packages; 11 known moderate build findings. npm reported esbuild 0.25.12 postinstall is not in allowScripts; no new script approval/setting was added. Final build verifies usability. |
| `npm ci` (Wellnexa) | Exit 0; exact lock restore, 30 packages, no audit findings. |
| `npm run lint`, `npm run build`, `npm run test:browser` (Wellnexa) | Exit 0; four existing lint warnings, build passed and **24 browser cases passed**. |
| `php api/tests/external-staff-auth.php` (root) | **67 passed**. |
| `php api/tests/pilot-preflight.php` (root) | **16 passed**, temporary synthetic settings only. |
| `php api/tests/pilot-policy.php` (root) | **80 passed**. |
| `php api/tests/request-body-limits.php` (root) | **55 passed**, actual loopback HTTP input capture. |
| Each root `api/tests/*.php`; `php -l` each file under `api/src`, `api/bin`, `api/tests` | **61 scripts and 254 syntax files passed**, zero failures. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13343 -IntegrationTest practitioner-pilot-access.php` (root) | Final **36 ledger + 62 managed-pilot checks passed**; disposable server stopped. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13344 -IntegrationTest practitioner-pilot-access.php` (root) | Final **36 + 62 passed** on 5.7.44; disposable server stopped. |
| Same MySQL runner/runtime, `-Port 13345 -IntegrationTest practitioner-applications.php` | Existing **36 ledger + 119 registration/review/activation/retirement SQL/HTTP checks passed**; disposable server stopped. |
| PowerShell parser on `scripts/build-current-deployment.ps1`; `git diff --check` | Zero parse/whitespace errors; expected Git CRLF normalization notices only. |
| High-confidence private-key/token signature scan over all 32 task text files | No candidates. This is not an exhaustive secret/history/PHI audit. |

Default Playwright omits central Admin, practitioner applications, invitations and built-artifact
smoke suites; dedicated configurations are necessary and reported separately. Hosted provider
flows, representative load, full accessibility, restoration and organizational evidence remain
excluded as listed below. No broad SQL sweep of unrelated modules or hosted abuse test was run.

## Final package and transfer verification

Implementation source is `07febd06c49f9ef90995a946ca7d338a725b36d5`; package source is
**`73bfd14d96e8038e9b3a40a9c4b17f5ab59be545`**, which adds only the evidence/source binding.
Application code is identical. Final package command from repository root:

```
./scripts/build-current-deployment.ps1 -ReleaseName 2026-11-01-practitioner-pilot-admin
./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/2026-11-01-practitioner-pilot-admin
```

Process-only build settings match the preceding controlled pilot: invitations false;
practitioner applications, durable external auth and social choices true; the same existing
public external tenant/subdomain/SPA/API identifiers from the previous manifest. These values
are recorded in generated `manifest.json`; no private environment file is packaged. This is
build configuration, not live API/clinic enablement. The builder restored Composer's exact
lock into a clean private staging directory (`--no-dev --prefer-dist --optimize-autoloader
--no-interaction`): ten installs, no updates/removals. Frontend/Wellnexa exact `npm ci`
restores passed; all three lock hashes remain unchanged. No install-script approval was added.

Verifier: **239 checks, seven archives, 2,174 entries, 2,091 text signature scans**, one pinned
public JWT README key example reviewed. No unexpected secret/private/development file or
source-map entries. Manifest SHA-256:
`5f0d476a0f46eb6d6a2e76ca97628aa25ef00e17c4fe27576f31044f89d119b9`.
058 SHA-256: `0e66ab365bbd253bc2f2d1b52050c5109b9eefcf4bfb8b6c5f214d95fd5a371e`.
Private API comparison to the previous package: exactly nine intended runtime files plus
three generated Composer metadata files changed; no removals/dependency version changes.
The Wellnexa website ZIP is byte-identical to the preceding package. Four external staff
public identifiers are present in the final portal. Three `http://localhost` bundle candidates
were inspected: React Router's non-browser base fallback, superseded by the actual window
origin; they are not configured API/callback/delivery endpoints. No TLS check was disabled.

Prepared transfer set (owner reports all three copied; public assets/entry pages verified):
`D:/Dev/WelnessCentre/deployments/2026-11-01-practitioner-pilot-admin-transfer`.

| File | Target / verified contents | SHA-256 |
| --- | --- | --- |
| `00-api-private.zip` | `/wellness-api`, outside public root; 2,059 entries, 8,388,884 bytes; exact private package copy, no .env. | `6e72874ee6a1f54aa578d3182f92ccd81a307c8990486d677f8425f1a7aa91dc` |
| `01-assets.zip` | Merge into `/public_html`, 63 changed JS assets under shared portal/central Admin; 1,170,742 bytes. Preserve old assets. | `0bdd1084e7e35596c1f5c4bc2057c8a60d0383e1c5c4b15ee72a922af756f12f` |
| `02-entry-pages.zip` | Merge into `/public_html` only after HTTPS asset verification; two HTML files, 842 bytes. | `9178e0f70ee7bdf5e4af52756c852dba237f568f5722204e10cb57e6fcaf0830` |

All 65 public transfer members were byte-compared to source archives. The standalone SQL
contains exactly three CREATE TABLE statements. Transfer README/manifest/SQL stay outside
public upload; private settings stay outside all ZIPs. Do not mirror/delete hosting folders.
New package/transfer files remain ignored, not substitutes for versioned source/docs/tests.

Distinct final browser totals: **393 passed, two existing optional-fixture skips** across the
six explicitly listed configurations/surfaces. Failed exploratory/reconfigured runs above are
retained and not counted as passes. Final API evidence is 61 root scripts/254 syntax files,
62 new SQL/HTTP/concurrency checks on each engine plus the existing application flow suite.
None of these results closes hosted/organizational gates.

## Hosted evidence and exclusions

The previous source `6808300` release has been uploaded by the owner with Beyond Compare.
140 HTTPS asset checks matched all 72 distinct JS/CSS files across clinic/central/website
hosts. Nine entry/body routes matched after separately identifying stale Wellnexa website
root cache and rechecking the canonical root. Clinic workforce and independent practitioner
entries render separately. Google initially returned AADSTS50011 for its missing Wellnexa
callback; the callback appeared in the authenticated admin session during inspection (the
agent did not perform Save), and Google then returned to Wellnexa. Personal Microsoft also
returned. Both owner-approved accounts currently stop at the private pilot denial. These
are provider-return observations, not registration/activation/normal retirement acceptance.

The prior approved 053–055 updates succeeded after backup verification. The existing host
has two workforce pilot approvals, no static external approvals and no allowlisted delivery
destinations. Mail/SMS stay disabled; Wellnexa intake/activation settings are still closed
and no plan/terms have been published. The owner reports the new API/assets copied;
public asset and new entry hashes passed. The owner reports private flags uploaded; the
application runtime gate is available with both clinics' intake/activation closed. Schema 058 is verified.

Excluded/unverified: hosted runtime grants/lock privileges, managed allow/revoke, registration →
review → activation → ordinary Inactive for both real providers, MFA/idle/absolute/revocation
policies, actual restoration, independent audit integrity/monitoring, representative load,
full assistive-technology/contrast/theme/AODA assessment, and accountable organizational
approvals. Existing identity-library sessionStorage exceptions remain unresolved. No new
session architecture is approved or implemented. No legal/compliance certification is implied.

## All 38 controls reassessed for this revision

Decision date: 1 November 2026; reviewer Codex for stated local scope. Source binding is the
implementation revision recorded in this report's final checkpoint. Organizational owners
below are roles awaiting named appointments; no human approval is inferred. Historical
[29 October findings](COMPLIANCE_AUDIT_2026-10-29.md) are not treated as current verification.

| Control | Status | Current evidence / next action and owner |
| --- | --- | --- |
| GOV-01 | Unverified | No new custodian/operator/agent appointments or clinic-boundary contracts. Custodian/operator: name owners and incident lead. |
| GOV-02 | Unverified | No current legal applicability/commencement register approved here. Privacy/legal: verify current sources before real-data release. |
| GOV-03 | Unverified | Commercial/cross-border applicability unchanged and unapproved. Privacy/legal: record PIPEDA determination. |
| GOV-04 | Partial | New function is identity administration; no clinical inference. Product/clinical/legal: approve intended use. |
| GOV-05 | Partial | Synthetic fixtures, minimized reference and documented flow; complete signed PIA/TRA absent. Privacy/security: assess database identity-administration flow and residual risk. |
| PRIV-01 | Unverified | This feature does not establish custodian-specific bilingual notices/contacts. Custodian/privacy: verify published practices. |
| PRIV-02 | Partial | Independent operator attestation/reason, no registration consent auto-accepted. Privacy/clinical: approve actual joining terms and collection authority. |
| PRIV-03 | Partial | Current grant/clinic/identity checks and serialized pilot revocation; no consent/clinical bypass. Security/custodian: finish hosted restriction/worker acceptance. |
| PRIV-04 | Unverified | No minor/representative authority added. Clinical/custodian: approve supported populations and safe assisted process. |
| PRIV-05 | Unverified | Pilot history is not patient access/correction service. Privacy operations: verify intake/deadline/protected delivery process. |
| PRIV-06 | Unverified | No deletion; new identity/events/retry records retained. Records/privacy: approve retention, holds, backup/disposal schedule and restoration. |
| PRIV-07 | Unverified | No breach exercise or external notice action. Incident/privacy lead: approve/rehearse runbook. |
| AUTH-01 | Implemented (local changed scope) | Full signed JWT checks before callback/reference; missing authority denies, callback cannot accept raw provider tokens. Identity/security: verify final hosted configuration/MFA. |
| AUTH-02 | Partial | Exact clinic/identity/active grant, case sensitivity, no membership grant, stale replay/Inactive/current bearer tests. Security/custodian: complete hosted application and clinical boundaries. |
| AUTH-03 | Partial | Principal-bound UI cleanup; pilot revocation only additional gate. Identity/security: approve idle/absolute/MFA/lost-device and normal departure procedures. |
| AUTH-04 | Partial | New draft/reference stays in memory; no new token storage. Existing MSAL sessionStorage exception unresolved. Security: approve time-bounded exception or separate architecture remediation. |
| AUTH-05 | Partial | Same existing central admin grant/scope, no Graph/provider permission changes. Identity/operations: inventory actual deployed grants and DB lock/runtime privileges. |
| SEC-01 | Partial | Synthetic tests, private files/PDF/builds excluded; 239 package checks/signature scan passed. Build/security: complete indicated history/PHI review and accountable artifact acceptance. |
| SEC-02 | Partial | No transport relaxation; private DB/lock usage follows existing transport. Infrastructure: verify TLS/encryption/backups and rehearse restoration. |
| SEC-03 | Partial | New admin responses no-store; exact central host restriction. Operations: verify final hosted headers/cache/CORS/session behavior. |
| SEC-04 | Implemented (local changed scope) | Exact reference realm/fields, prepared SQL, bounded scalar validators, case-sensitive identity columns, safe React text. Security: independent review and hosted acceptance. |
| SEC-05 | Partial | 64-KiB bodies, tight fields, 50-row/history bounds, indexed cursor, five-second lock bound, duplicate/retry/concurrent tests. Operations/security: hosted throttling/DoS and volume assessment. |
| SEC-06 | Partial | Atomic decision events/retries, failure rollback, canonical redacted read/denial audit. DB/security/privacy: approve privileges, independent integrity/retention/monitoring. |
| SEC-07 | Partial | Generic errors, redacted logs, minimal own reference, no token bodies. Operations: verify actual proxies/provider/web logs and support handling. |
| SEC-08 | Partial | No new external endpoint/provider; documented existing DB/account metadata boundary. Privacy/vendor owner: complete actual vendor/retention/support/incident approvals. |
| SEC-09 | Partial | No sends, new message template or patient content; delivery gates preserved. Privacy/operations: approve actual sender/recipient/CASL evidence before enabling channels. |
| DEV-01 | Implemented (task scope) | Dedicated feature branch, existing work/PDF preserved, instructions/config/locks/migrations/docs reviewed. Maintainer: review/push/merge only within authority. |
| DEV-02 | Implemented (local scope) | Focused changes, no new any/ts-ignore/skips/dependency or suppressed failure; initial defects recorded. Maintainer/security: independent review. |
| DEV-03 | Partial | Locks unchanged; exact versions/audits above; 11 moderate build findings unresolved. Build/security: exposure decision/remediation; exact frontend/website/private-Composer restores now passed. |
| DEV-04 | Partial | Real lint/types/build/browser/PHP/dual-engine checks recorded; hosted acceptance still separate. QA/release: finish final checkpoint and provider flows. |
| DEV-05 | Partial | EN/FR modal, labels, keyboard/unsaved guards, focus restoration and mobile checks. Accessibility/legal/product: full AT/contrast/zoom and applicability review. |
| DEV-06 | Partial | No polling/dependencies; bounded indexed queries and identity-scoped locks. Build/performance: assess existing chunks, clinic-lock contention and representative scale. |
| REL-01 | Partial | This dated 38-control ledger/file/test matrix; named human owners/decisions absent. Release owner: assign and retain evidence. |
| REL-02 | Partial | Local commands/results and failures recorded; dedicated suites explicitly required. QA: verify final counts and operational omissions. |
| REL-03 | Partial | New assets: 140 hash/length/MIME matches; nine new HTML matches; separate practitioner/workforce rendering; nine earlier anonymous API checks; 239 local artifact checks. Schema/current backup verified, private hashes/authenticated acceptance/restore rehearsal pending. Operations/release: complete configuration rollout and hosted synthetic acceptance/rollback. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; no failed/pending control waived by local tests. Accountable owners: resolve or approve lawful scoped internal exceptions. |
| REL-05 | Partial | Controlled rollout authorized; owner applied 058 and copied API/assets/HTML/settings, structure/current backup/public hashes verified; public application gate available with intake closed. Private hashes/authenticated managed approvals pending. Release/custodian/security/privacy/clinical: complete acceptance and accountable signatures. |
| REL-06 | Implemented (report scope) | File purposes, versions/flows, commands, findings, limits, owners and rollback recorded; final checkpoint to bind exact source. Release owner: confirm report. |

## Private configuration preparation

A separate private candidate at
`D:/Dev/WellnessCentre-backup/practitioner-pilot-admin-settings/.env` preserves the previously
reviewed pilot candidate and adds only `PRACTITIONER_PILOT_ACCESS_ENABLED=true`. It has not
been uploaded at the preparation checkpoint; the owner subsequently confirmed its copy as
recorded above. `php api/bin/pilot-preflight.php --env
D:/Dev/WellnessCentre-backup/practitioner-pilot-admin-settings/.env` passed with pilot mode
on, **two workforce approvals, zero email destinations, zero SMS destinations**. This only
validates settings; it does not verify 058, hosted lock privileges or administrator acceptance.
The previous candidate remains unchanged; the owner reports replacing only the live private
file with the reviewed candidate. No private candidate enters Git
or the generated archives.

## Deployment and rollback implications

The fresh verified private backup includes 053–055 and the owner-applied 058 objects;
058 structure and keys are verified. Do not replay it. Current settings have no static external
approvals to migrate; recheck before enabling. Preserve the two workforce entries, pilot
mode and disabled mail/SMS. Deploy matching reviewed API/central/shared assets, verify hashes
before HTML, then enable only the approved Wellnexa flow. Owner-reviewed joining terms/plan
are still required before the hosted registration/activation test. Keep LivinLively intake closed.

Rollback: pause the managed flow and restore matching private configuration/API/frontend,
preserving new tables, decisions, retries and audit/application/clinical history. Do not drop
tables or overwrite newer records with an old dump. Do not disable PILOT_MODE as a workaround.
The old external static list must not regrant a revoked identity. Database restoration and
live configuration rollback remain untested; release/operations owner must rehearse them.

RELEASE STATUS: NOT READY FOR RELEASE
