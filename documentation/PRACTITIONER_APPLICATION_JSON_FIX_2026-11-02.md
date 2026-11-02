# Practitioner application review JSON compatibility — 2 November 2026

Branch: `bugs/practitioner-application-json-encoding`, based on `1ffc2b1`.
Implementation/package source: `d61a94cc628c485164db5483c3f292909d6dadbe`.
Reviewer: Codex for the stated source, synthetic-test and hosted-observation scope.
Continues the owner-authorized controlled rehearsal; no merge or general release approval.

## Hosted finding and current account state

**Owner activation observed, 2 November:** The owner personally accepted activation terms
and reported activated. The retained Microsoft session reached the practitioner dashboard.
Its own application page reports Account activated and a trial end displayed as 2 May 2027,
10:56:42 AM in the browser's locale. Services, availability, verified credentials and public
profile publication all remain Pending. Revisiting the page retained activated status and
offered Open practitioner portal rather than a second activation control. This is not a
forged/replayed activation-request test. Direct clinic `/admin/users` navigation in that
session displayed permission denial; the authorized clinic Staff access form independently
shows only practitioner selected, with administrator/reception/accountant roles unchecked.
This verifies the UI boundary and displayed role assignment, not a new direct privileged-API
negative test. No clinical lists were manually opened or bookings/profile publication made.

The ordinary Inactive draft was opened for the synthetic Microsoft staff user. Browser
autofill replaced the email before save. The agent did not save that altered address;
the owner was asked to restore the original approved address, retain only practitioner,
and personally save Inactive because a password-manager popup is present. This save and
same-session denial remain pending observation. Netfirms has also expired; the owner was
asked to reopen phpMyAdmin for a minimized read-only membership/trial count/date check.
No exact hosted membership/subscription count, UTC trial interval or final disabling is
claimed from the activation screen alone. Google remains Inactive/pilot-revoked; Microsoft
pilot-allowed. Wellnexa gates will be closed and Microsoft pilot revoked after the normal
Inactive test. No additional source/dependency/permission/endpoint/consent-text change.

**Subsequent hosted acceptance, 2 November:** After the owner confirmed the one-file
patch upload, Admin detail opened successfully. The original synthetic credential/service
text, punctuation, immutable submission version 1 and terms-confirmation event rendered.
The owner had added an optional contact phone before personally submitting; that value,
account addresses, identity subjects and verification codes are excluded from this record.
The private remote file hash was not independently read; this is behavioral acceptance.

The retained Microsoft session was denied the practitioner workspace while submitted.
Central Admin then approved the intended synthetic applicant using the independently
matched code, the existing Virtual Clinic location and the CAD 0 rehearsal plan. Existing
membership reuse remained unchecked. The review explicitly verified eligibility for this
owner-authorized synthetic rehearsal only, not any real professional qualification. The
applicant-facing decision states that publication, patient bookings, charges and messages
are excluded and the account will be made Inactive afterward. Save succeeded. Retrying
the practitioner workspace in that same preserved session was still denied after approval;
the own-application page now reports Application approved and that the trial has not started.
No bearer bytes were inspected, so this is not byte-identical token replay evidence.

At this checkpoint activation remains held; no Microsoft membership/trial has been created
by the rehearsal. Google remains Wellnexa Inactive and pilot-revoked; Microsoft pilot access
is allowed. LivinLively remains closed and mail/SMS remain disabled. Activation, six-calendar-
month trial creation, normal Microsoft Inactive and final pilot revocation remain pending.
The failure and pre-upload state below are historical, superseded by this hosted checkpoint.

**Activation handoff:** Under the owner's existing explicit rehearsal authorization, Admin
subsequently enabled Wellnexa activation, preserving intake, the CAD 0 plan, timezone and
terms v1. The readiness reason expressly limits the acknowledgement to this controlled
synthetic test, not real PHI, professional qualification or general release. Save succeeded.
Anonymous verified-TLS `Invoke-RestMethod` reads of each clinic's
`/api/v1/public/practitioner-applications` returned Wellnexa intake/activation true and
LivinLively intake/activation false with no plan/terms. Wellnexa terms SHA-256 remained
`6097467fc2c0e74a3c6bd215d673a5d66189f6166f9f932a1fa5e371125f2416`.
Refresh application in the retained Microsoft session now displays activation terms v1,
an unchecked acceptance box and a disabled Activate my approved account button. The owner
was asked to personally accept and activate; neither action was performed by the agent.
Membership/trial creation and Inactive checks remain pending that action. Rollback of this
temporary setting closes Wellnexa intake/activation through Admin while preserving terms,
application, consent and audit history. It does not require restoring the database.

The owner submitted the labelled Microsoft application after personally confirming the
information and accepting terms v1. Its preserved practitioner session reports Application
submitted; the exact synthetic record appears in Wellnexa's central Admin queue. Opening
Review application failed twice with a generic service error. No approval mutation was
attempted, and activation remains held. Microsoft remains pilot-allowed with no membership
created by this rehearsal; Google remains Wellnexa Inactive and pilot-revoked. The approved
CAD 0 plan/terms remain selected and intake remains restricted by the existing pilot gate.
LivinLively remains closed; no mail/SMS, charges, publication or patient bookings occurred.

The owner provided a private server log at `error_logs/error_log.log`. Only the two matching
request classifications were reported: `JsonException`, JSON error code 5, source
`PractitionerApplicationService.php:99`. This identifies invalid UTF-8 during decoding of
submission-history JSON. No log payload, account subject, email, verification code, token
or clinical data is included here or in the patch. The private log directory is excluded
through local `.git/info/exclude`; it and the unrelated untracked PDF are preserved.

The verified pre-rehearsal private backup's affected tables have a latin1 default, with a
native JSON details column. This alone did not reproduce the failure locally: the first
localized fixture run passed 36 ledger + 120 application checks on MySQL 5.7.44. A real-driver
fixture with `character_set_results=latin1` then reproduced the same UTF-8 exception at the
same decode line (exit 1, after 36 ledger passes). The host's actual result-character-set
session value/driver behavior has not been read; this test demonstrates the failure mechanism,
not a claim that its exact connection setting is proven. MySQL's documented
[JSON charset metadata issue](https://bugs.mysql.com/bug.php?id=95698) is background, not
proof of this hosted cause.

## Change and evidence preservation

The application detail query now selects `CAST(details AS BINARY) AS details`, retrieving
the stored JSON bytes without a text result-character-set conversion. Strict
`json_decode(..., JSON_THROW_ON_ERROR)` remains. No guessed source encoding, replacement
characters, swallowed exception, schema conversion or historical record rewrite is used.
The existing prepared query, clinic/administrator checks, read transaction and disclosure
audit are retained. No request/response fields or error-detail disclosure are added.

| Changed file | Purpose |
| --- | --- |
| `api/src/Service/PractitionerApplicationService.php` | Retrieve submission JSON bytes for the existing authorized detail read. |
| `api/tests/integration/practitioner-applications.php` | Legacy table defaults, exact accented/punctuation history round trip, real lossy-result regression with charset restoration, and successful authorized HTTP detail check. |
| `documentation/PRACTITIONER_APPLICATION_JSON_FIX_2026-11-02.md` | Finding, reproduction, validation, artifact/rollback and open acceptance. |
| `documentation/PRACTITIONER_PILOT_ADMIN.md` | Current operator checkpoint and review failure. |
| `documentation/PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md` | Qualify the existing 38-control ledger for this narrow correction. |
| `documentation/WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md` | Owner submission and incomplete review/activation state. |
| `documentation/README.md` | Index this dated follow-up. |

No dependency/lockfile/runtime version, permission/grant, consent wording, identity protocol,
external endpoint or external data-flow changes. The existing dependency versions and
organizational findings remain in the [pilot ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md).
This patch does not change the already owner-approved plan/terms/private flags.

## Validation

All database tests use explicit disposable loopback databases, synthetic identities/profile
text and fake identity loaders, never hosted credentials or message/payment transports.
PHP CLI 8.4.14; MySQL 5.7.44; MariaDB 11.4.8. Crypto-dependent child processes use only
process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`.

| Command | Result |
| --- | --- |
| `php -l api/src/Service/PractitionerApplicationService.php` | Passed. |
| `php -l api/tests/integration/practitioner-applications.php` | Passed. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13344 -IntegrationTest practitioner-applications.php` | Initial localized/default-only run passed 36 + 120; expanded regression failed as above; final fixed run **36 ledger + 122 application SQL/HTTP/concurrency checks passed**, exit 0; server stopped. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13343 -IntegrationTest practitioner-applications.php` | **36 + 122 passed**, exit 0; server stopped. |
| Each `php api/tests/<root-file>.php` with the process OpenSSL setting | All **61 standalone scripts passed**, zero failures; ignored per-file report `.tmp/application-json-php-results.json`. |
| `git diff --check` | Passed; normal Git line-ending notices only. |
| Hosted submission / queue / review | Owner submission and queue passed; detail failed twice before the patch, then original history/detail and synthetic approval passed after owner upload. Same-session workspace denials passed both before and after approval; activation/trial/Inactive remain pending. |

No frontend source changed. Frontend/website builds, browser suites, clean dependency restores,
dependency audits, broad SQL suites for unrelated modules, load/accessibility/penetration and
restore were not rerun for this focused PHP read fix. Earlier results are separately dated,
not new acceptance. Existing chunk/lint/build-advisory and session-storage findings remain.

## Controls and deployment

All 38 statuses/owners/next actions in the linked pilot ledger remain applicable to this
revision with these qualifications: AUTH-01/02 authorization/realm/clinic boundaries unchanged;
SEC-04 preserves exact JSON evidence and strict decode; SEC-06/07 retain audit and generic
errors with private redacted diagnostics; DEV-03 has no dependency change; DEV-04/REL-02 add
the failing regression, successful HTTP detail and dual-engine results. PRIV-02/REL-03/05
record the owner's submission, successful hosted detail/approval and same-session workspace
denials; activation, trial and normal Microsoft Inactive acceptance remain pending. No
organizational control is upgraded by these local or narrowly scoped hosted results.
Named custodian/privacy, security/operations, clinical and release-owner decisions remain
required before real-data/general release; previous legal applicability is not reverified here.

Prepare only the committed service file under `/wellness-api/src/Service`, outside
`public_html`. No SQL, .env, Composer dependency, frontend asset or entry-page change is
required. Keep activation held during copy. After the owner copies the patch, reopen the
current application review, confirm exact preserved text/history, then continue the existing
approved synthetic review/activation rehearsal. Do not resubmit/delete the application or
relax guards to force a pass. The applicant must personally accept terms and activate.

The one-file patch is prepared at
`D:/Dev/WelnessCentre/deployments/2026-11-02-practitioner-application-json-fix`.
Its payload comes from `git show` at the exact source above. Rollback uses the original
verified private API archive's bytes. The initial builder correctly stopped on a byte
comparison because that earlier package uses CRLF and committed source uses LF; normalized
comparison confirmed identical prior source, and the final rollback retains the actual
earlier archive bytes. No released file was overwritten during packaging recovery.

| Artifact | Bytes | SHA-256 |
| --- | --- | --- |
| `01-api-application-json-fix.zip` | 7,778 | `4347b8e3f6be19376afa2c6c22599408b8a4a4dfc500c09216513c23eb20ae0e` |
| New `src/Service/PractitionerApplicationService.php` | 32,432 | `4588f14583030147f634f7298622b38cfe1ceebc21d4b61abcc3697262d0c04d` |
| `ROLLBACK-api-application-before.zip` | 7,682 | `e2f6c35c4d59e28c20d51f0b8029abc0e7c84b22166374b2f2c3d9bd2226bdc8` |
| Previous service member | 32,501 | `f9177b862298fa7e0204762f274171eb05ca679d4548cdd06da834bd966cd0a0` |

Both archives were reopened, checked for exactly the one expected member, CRC-checked and
byte-compared with their source payloads. Narrow private-key/JWT and actual-test-email
signature scans found no candidates; this is not an exhaustive security/privacy audit.
No .env, logs, dumps, tests, dependencies, frontend files or source maps are included.
Manifest records exact source/branch/build time, runtime/counts and all three lockfile hashes.
Upload only the patch contents to `/wellness-api`; retain README/manifest/rollback locally.
Preserve every other API file, including the previously deployed pilot-response fix.
The owner confirmed copying the patch and corrected hosted detail/review passed as recorded
above. Remote private-file hash remains unverified.

Rollback restores only the previous service file; retain the submitted application, consent,
plan/terms, identity and audit records. It restores the prior review defect and is not a
reason to enable activation or remove pilot restrictions. The fresh private database backup
is recorded in the live-test runbook; actual restoration and independent audit integrity,
host headers/session policies and full operational offboarding remain unverified.

RELEASE STATUS: NOT READY FOR RELEASE
