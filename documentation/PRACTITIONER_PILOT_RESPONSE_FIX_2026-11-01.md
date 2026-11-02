# Practitioner pilot approval response compatibility — 1 November 2026

Branch: `bugs/practitioner-pilot-response-types`, based on pilot checkpoint `f4d3e4c`.
Implementation/package source: `18e9b595ebb88bab55c25fdbd524ef9a1db5106d`.
Reviewer: Codex for the stated code, synthetic-test and hosted-observation scope.
This continues the authorized controlled pilot, not a general release or merge approval.

## Hosted trigger and result

The owner completed the requested Google sign-in. Wellnexa returned the expected pilot
denial and own-account WPI1 reference. The reference was copied through the UI into central
Admin for the explicitly selected Wellnexa clinic, with operator label **Wellnexa Test Google**
and a synthetic-test reason. Recording succeeded; review shows the recorded history.
Retrying the same practitioner session still denies access while its entry is pending.

The subsequent approval attempt returned `validation_failed` for `version`. The entry
remains pending; no successful approval, clinic membership, practitioner application,
activation, payment, publication or trial was created by this attempt. No bearer token,
provider credential, actual account subject or WPI1 value is included in this document.

The service returned database rows directly, although its frontend contract requires numeric
IDs and versions. A PDO driver returning numeric fields as strings consequently produced a
string decision version that the strict integer request validator correctly rejected.
The disposable regression reproduces that response-contract failure by explicitly enabling
`PDO::ATTR_STRINGIFY_FETCHES`; the host's actual PDO attribute has not been inspected.

The fix serializes the known numeric ID, clinic ID and version fields as integers in pilot
list, detail and decision-history responses. Strict incoming version validation, current
grant/clinic/identity checks, locks, idempotency and atomic history remain in place. No global
database-driver option, schema, identity protocol, provider grant or frontend cache is changed.

## Changed files and validation

| File | Purpose |
| --- | --- |
| `api/src/Service/PractitionerPilotAccessService.php` | Normalize only the pilot response's numeric fields, including history; retain strict request validation. |
| `api/tests/integration/practitioner-pilot-access.php` | Force string-returning PDO, assert numeric list/detail/history/HTTP responses and pass the returned version into real approval/revocation requests. |
| `api/tests/integration/practitioner-pilot-http-fixture.php` | Exercise the same driver behaviour in the disposable signed-token HTTP fixture; never deployed. |
| `documentation/PRACTITIONER_PILOT_RESPONSE_FIX_2026-11-01.md` | Current source, hosted failure, regression, patch/rollback and remaining acceptance. |
| `documentation/PRACTITIONER_PILOT_ADMIN.md` | Operator checkpoint: pending Google entry, failed decision and Microsoft return. |
| `documentation/PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md` | Qualify the existing 38-control ledger for the hosted failure and local correction. |
| `documentation/WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md` | Current provider-return, pending entry and incomplete end-to-end flow. |
| `documentation/README.md` | Link the response-fix evidence and rollout instructions. |

Initial regression command, before the fix:

```text
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13343 -IntegrationTest practitioner-pilot-access.php
```

The 36 subscription/SMS ledger checks passed, then the new numeric review-contract assertion
failed as intended (exit 1). Both initial post-fix engine attempts reached RSA fixture
generation but failed because this new shell lacked the already documented local OpenSSL
configuration. The standalone pilot-policy test also initially failed for that reason.
No validation was disabled. Subsequent crypto-dependent checks use process-only
`OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`, inherited by child fixtures.

| Command/check | Result |
| --- | --- |
| `php -l` on each of the three changed PHP files | All passed. |
| `php api/tests/pilot-policy.php` with the OpenSSL setting above | 80 passed, exit 0. |
| `php api/tests/pilot-preflight.php` | 16 passed, exit 0. |
| Each `php api/tests/<file>.php` with that OpenSSL setting | All 61 standalone scripts passed; ignored per-file exit report `.tmp/practitioner-pilot-response-php-results.json`. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13344 -IntegrationTest practitioner-pilot-access.php` with that OpenSSL setting | 36 ledger + 68 pilot SQL/HTTP/concurrency checks passed, exit 0; disposable server stopped. |
| Corrected MariaDB command above with that OpenSSL setting | 36 ledger + 68 pilot SQL/HTTP/concurrency checks passed, exit 0; disposable server stopped. |
| `git diff --check` | Passed; existing Git LF/CRLF normalization notices remain. |

PHP CLI remains **8.4.14**; engines are MySQL **5.7.44** and MariaDB **11.4.8**.
Exact dependency versions and the unchanged lockfile/runtime inventory remain in the
[pilot validation ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md).
There are **no dependency/version, permission/consent, external endpoint or data-flow changes**.
The reviewed five private flags were already copied by the owner; this fix changes none.

This is a focused PHP response fix, not a broad frontend checkpoint. Frontend builds,
browser suites, clean dependency restores/audits and full package generation were not rerun;
the existing pilot's recorded results do not establish acceptance of a new patch. Both
engines use explicit disposable loopback databases, synthetic signed JWTs and fake provider
loaders, never hosted credentials or message/payment transports.

## Controls, deployment and remaining work

All 38 controls were reassessed against this narrow change. The statuses, named-owner gaps
and next actions in the linked pilot ledger remain applicable to this branch, qualified by
this dated evidence: AUTH-01/02 retain the pilot/membership boundaries; SEC-04 now includes
stable numeric serialization without relaxing input validation; SEC-06 retains atomic
history; DEV-04 includes the reproduced hosted-contract defect and corrected driver fixture;
REL-03/05 require patch provenance, hosted retest and the existing controlled rollout gates.
No control is upgraded to organizational or full hosted acceptance by these local results.

The owner also completed the personal Microsoft sign-in in a separate third Guest tab.
It returned to Wellnexa's pilot denial and displayed a distinct WPI1 reference. That identity
has not been recorded or approved. The Google tab remains open and pending. Provider returns
are verified independently; complete practitioner registration is not yet accepted.

The one-file private patch is prepared at
`D:/Dev/WelnessCentre/deployments/2026-11-01-practitioner-pilot-response-fix` and handed to the
owner for copying; upload is **not confirmed yet**. There is no additional migration,
frontend upload or private configuration change. Both generated archives were reopened,
verified as exactly one expected member, CRC-checked and byte-compared with their sources.
The new payload comes from `git show` at the exact source above, not uncommitted content.
Rollback bytes come from the prior verified private release. A narrow private-key/JWT
signature scan found zero candidates; this is not an exhaustive secret/privacy audit.
Manifest includes source/branch/build time, existing lock hashes and runtime/test counts.

| Artifact | Bytes | SHA-256 |
| --- | --- | --- |
| `01-api-response-fix.zip` | 4,457 | `8e5d9f2765362ca9f61040bdda2cff25d015fa8297084c4ecff9b229369b968b` |
| New `src/Service/PractitionerPilotAccessService.php` | 15,349 | `1c1dd260b579fc83d415fd240ac7cef6041cabd32e392783d01daafaa5a05ec8` |
| `ROLLBACK-api-response-before.zip` | 4,335 | `10ea9ea8947afa2043cde5dc466a7850f209969150c7cdc9f4f58d8e3c70429e` |
| Previous service member | 14,927 | `83c7fe129b024243a4aefe895cb8953327be82bcbd8a13efc1498b320d2d635a` |

Upload only the patch ZIP's contents into `/wellness-api`, outside `public_html`; retain
rollback/README/manifest locally. Preserve
the previous API artifact and configuration. After copying the exact replacement to
`/wellness-api/src/Service/PractitionerPilotAccessService.php`, close the failed review,
reload the entry and review its current version before retrying. Do not edit the database
or weaken the request validator to force an approval. Roll back by restoring that one file;
retain all pending entries, events, requests, identities and application/clinical records.

Open acceptance: Google approve/revoke and membership separation; personal Microsoft
callback/reference and equivalent pilot decisions; owner-reviewed joining terms/plan;
registration/review/activation/six-month trial and ordinary Inactive for both accounts;
headers/session controls, actual restore and accountable release/privacy/security/clinical
signatures. The narrow Admin status-selector label needs readability correction/retest.
The Google callback also retained an OAuth response fragment in the address bar; cleanup
needs investigation without copying that fragment into diagnostics or artifacts.

RELEASE STATUS: NOT READY FOR RELEASE
