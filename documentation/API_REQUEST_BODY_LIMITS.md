# API request-body limits and MySQL form corrections

29 October 2026. Follow-up for audit finding F-09 / SEC-05, based on `3c8f136` on `bugs/api-request-body-limits`. Scope: bound the bytes read into application memory before JSON parsing on every path handled by `Wellness\Http\Request::capture()`, and correct two MySQL 5.7 form defects exposed by regression testing. This is a backend checkpoint; the earlier pilot controls and frontend quality work are ancestors and retain their separate evidence.

## Behavior

Previously, selected routes used bounded input reads, but ordinary staff/admin, form and unknown routes read the entire body. Every capture now reads at most its limit plus one byte. An extra byte returns `413 request_too_large` before JSON decoding, authentication, route dispatch or database work. The request is not silently truncated into a valid operation. The check uses actual input bytes, including chunked input without `Content-Length`; it does not trust the header to establish size.

| Policy | Bytes | Scope |
| --- | --- | --- |
| Existing strict policy | 65,536 (64 KiB) | Customer/public paths, travel enquiries, discovery preferences, application billing, practitioner applications, provider evidence and clinic communications. Existing path matching is retained. |
| Existing service import policy | 1,048,576 (1 MiB) | Service-import paths. If an unregistered path matches both existing policies, the stricter 64 KiB policy still wins. |
| New fallback | 2,097,152 (2 MiB) | Every other path and HTTP method, including unregistered routes. |

Limits measure the complete encoded JSON body, including keys and JSON escaping. The fallback accommodates existing service validators for branding (up to 1,100,000 encoded bytes), profile images (750,000 encoded bytes), receipt logos and form definitions. It does not raise any service's field, file, pixel, format, role or consent limit. A body fitting capture can still fail the normal business validation. Empty bodies, object/list JSON, valid UTF-8, bearer extraction, correlation IDs, query parsing and routing retain their existing behavior. Malformed/scalar JSON returns `400 invalid_json`; the scalar `0` is no longer accidentally treated as an empty body by PHP's falsy fallback. An unreadable input stream now returns a safe `400 request_read_failed` instead of being treated as an empty body.

No new configuration, schema, dependencies, permissions, identity protocol or external endpoint/data flow. The current clinic database and private environments are unchanged. No hosted access, deployment, merge, provider send or real patient data was used.

## MySQL form corrections found during regression

The assigned practitioner's form-read path used `SELECT 1 WHERE EXISTS(...)` without a table clause. MariaDB accepted it; MySQL 5.7 returned SQL error 1064 and the API safely returned HTTP 500. Adding `FROM DUAL` makes that query compatible while retaining every clinic, practitioner, appointment, creation-audit and non-revoked assignment condition. No access grant or relationship predicate was added or removed.

MySQL also reorders JSON object members when storing answers. The submit retry compared that stored object to the application's sorted answer map with a strict PHP comparison, incorrectly refusing identical multi-field answers as `form_already_submitted`. Stored answer keys are now sorted before the same strict comparison. Values, types, nested/list content and exact field sets still matter; changed submissions remain refused and stored records are not rewritten. The existing form regression covers a successful retry, rejection of changed answers, one submission/consent record, denied foreign/client/practitioner access, pinned versions, merge and Unicode round trips.

## Validation

Environment: Windows, PHP CLI 8.4.14 with existing extensions, disposable MariaDB 11.4.8 and MySQL 5.7.44. PHP RSA tests use process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`; no private environment file is loaded for these checks.

| Command/check | Result |
| --- | --- |
| `php api/tests/request-body-limits.php` | 51 synthetic localhost HTTP checks passed against real `Request::capture()` and `php://input`. Covers exact/one-byte-over limits, every existing strict policy group (including overlap precedence), invalid and UTF-8 JSON, oversize invalid JSON, all six common methods on an unknown path, chunked bodies without a length header, error redaction, preserved metadata and image-envelope compatibility. |
| Each root `api/tests/*.php`, invoked individually with the OpenSSL process setting | 59 scripts passed. |
| `php -l` recursively on api/src, api/public, api/bin, api/tests and Wellnexa/public/api | 252 files passed. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13352 -IntegrationTest consent-services.php` | Final run passed 74 booking + 28 audit + 378 consent/services/import SQL/HTTP checks. Server stopped. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13353 -IntegrationTest consent-services.php` | Passed the same 74 + 28 + 378 checks on MySQL 5.7.44 after corrections. Server stopped. |
| Same MariaDB runner/directory with `-Port 13355 -IntegrationTest client-forms.php` | Final run passed 34 recurrence + 111 form checks. Server stopped. |
| Same MySQL runner/directory with `-Port 13354 -IntegrationTest client-forms.php` | Final run passed 34 recurrence + 111 form checks on MySQL 5.7.44. Server stopped. |
| PowerShell parser on `scripts/test-mysql57-sql.ps1` | Passed. |
| `git diff --check` and changed-file credential signature scan | Passed; no private-key, selected GitHub-token, AWS-access-key or Azure account-key signature matches. This limited scan does not replace final artifact inspection. |

The first MySQL invocation was rejected by the runner's suite allowlist before a server was started. The existing consent/services suite was added to that allowlist, preserving all localhost/disposable-database checks, and the invocation was repeated. The HTTP test uses a temporary loopback PHP server and synthetic bodies; it has no production bypass or DB/provider configuration and stops/removes its temporary server/files on completion.

The first accepted MySQL invocation passed 74 booking checks and then failed an audit assertion. A diagnostic repeat confirmed correct HTTP 404/denial, empty disclosed resources and the expected attempted reference, with MySQL's JSON object keys returned as `id,type` rather than `type,id`. Audit/import assertions now sort object keys before strict comparisons, preserving exact key sets, values, integer/string types and reference list order. Production audit behavior is unchanged. The subsequent form-read failure led to the `FROM DUAL` correction; the expanded form suite exposed the identical-answer retry defect described above. Recurrence replay and Unicode definition assertions also required object-order-independent strict comparisons for MySQL's stored JSON, with array order and values retained. Both engines were rerun; temporary synthetic SQL diagnostics were removed from source.

Frontend code, dependency locks and production output are unchanged; frontend builds/browser suites and dependency restore/audits were not repeated. The [pilot checkpoint](PILOT_SAFETY_CONTROLS.md) records their previous results. Local HTTP tests directly exercise the changed parser, while disposable SQL/HTTP regression checks exercise consent, import and form behavior. They do not prove hosted PHP/runtime, proxy, web-server or database acceptance.

## Remaining gates and rollback

F-09 is partial overall: application input reads are bounded, but this is not a complete abuse or resource-exhaustion control. The hosting/security owner still needs to verify per-host body/header/URI limits, upload buffering, PHP `post_max_size` behavior, request/read timeouts, rate/concurrency limits and representative load. PHP or a reverse proxy may buffer input before application code runs. This implementation does not bound request duration or perform a slow-client/load test. Public website bridges already have their own smaller limits and are unchanged; hosted test utilities and non-API entry points require their own inventory/review.

The release owner must verify the committed API source in approved synthetic hosting before rollout, including imports, clinic branding, avatars, receipt templates, ordinary staff/client operations, assigned practitioner form reads, identical/changed answer retries and 413 behavior. API/host response headers and denied-request logging remain subject to the existing audit findings. No payload/credential values were added to production errors or logs. Other privacy, session, vendor, recovery, accessibility and real-PHI gates remain open; these fixes cannot close them.

Rollback is restoration of the prior reviewed `api/src/Http/Request.php`, `api/src/Service/ClientFormsService.php` and matching release files; no database rollback is needed. Restoring those files also restores the unbounded paths and MySQL form defects, so the security/hosting owner must assess those regressions and enforce effective host limits before rollback. Do not raise limits ad hoc to bypass an invalid request; confirm the actual supported workflow and encoded size first.

## Changed files

| File | Purpose |
| --- | --- |
| `api/src/Http/Request.php` | Bound every input read before decode, preserve stricter policies and distinguish input failure. |
| `api/src/Service/ClientFormsService.php` | Add MySQL-compatible `FROM DUAL` and compare identical answer retries independently of stored object-key ordering. |
| `api/tests/request-body-limits.php` | Exercise real HTTP input boundaries without database/provider access. |
| `api/tests/integration/access-audit.php` | Compare audit reference objects independently of database JSON object-key ordering, retaining strict content/types. |
| `api/tests/integration/consent-services.php` | Apply the same comparison to the saved import-result audit reference. |
| `api/tests/integration/recurring-bookings.php` | Strict recursive object comparison for replay JSON, retaining list order and types. |
| `api/tests/integration/client-forms.php` | Apply that comparison to Unicode definition round trips. |
| `scripts/test-mysql57-sql.ps1` | Permit existing consent/services and client-form regression suites on disposable MySQL 5.7. |
| `api/README.md` | Link the maintained limits and operational scope. |
| `documentation/README.md` | Index this remediation evidence. |
| `documentation/API_REQUEST_BODY_LIMITS.md` | Record implementation, validation, limits, remaining owners/actions and rollback. |

RELEASE STATUS: NOT READY FOR RELEASE
