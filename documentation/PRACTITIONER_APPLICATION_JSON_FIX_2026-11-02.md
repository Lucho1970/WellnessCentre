# Practitioner application review JSON compatibility — 2 November 2026

Branch: `bugs/practitioner-application-json-encoding`, based on `1ffc2b1`.
Reviewer: Codex for the stated source, synthetic-test and hosted-observation scope.
Continues the owner-authorized controlled rehearsal; no merge or general release approval.

## Hosted finding and current account state

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
| Hosted submission / queue / review | Submission and queue passed; detail failed twice before this patch; hosted corrected detail/review still pending. |

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
record the owner's submission but keep review, activation, trial and normal Microsoft
Inactive acceptance pending. No organizational control is upgraded by these local results.
Named custodian/privacy, security/operations, clinical and release-owner decisions remain
required before real-data/general release; previous legal applicability is not reverified here.

Prepare only the committed service file under `/wellness-api/src/Service`, outside
`public_html`. No SQL, .env, Composer dependency, frontend asset or entry-page change is
required. Keep activation held during copy. After the owner copies the patch, reopen the
current application review, confirm exact preserved text/history, then continue the existing
approved synthetic review/activation rehearsal. Do not resubmit/delete the application or
relax guards to force a pass. The applicant must personally accept terms and activate.

Rollback restores only the previous service file; retain the submitted application, consent,
plan/terms, identity and audit records. It restores the prior review defect and is not a
reason to enable activation or remove pilot restrictions. The fresh private database backup
is recorded in the live-test runbook; actual restoration and independent audit integrity,
host headers/session policies and full operational offboarding remain unverified.

RELEASE STATUS: NOT READY FOR RELEASE
