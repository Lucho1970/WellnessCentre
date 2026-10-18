# Sensitive read, search and denied-access auditing

18 October 2026. Builds on [booking contact authorization](PRACTITIONER_BOOKING_CONTACT_ACCESS.md) and addresses the application logging gaps in the [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md). This does not close hosting, retention, consent, monitoring or organizational PHIPA gates.

## Coverage and record meaning

`Api::handle` installs one response audit callback before request capture/dispatch. All successful JSON responses with a verified staff, linked client, customer identity or application administrator are audited before any response bytes are emitted. This includes protected read results returned by POST previews/lookups and sensitive information returned by write responses. Anonymous published catalogue/health responses and OPTIONS are excluded. The only binary response helpers currently used by the API serve published team/brand images; private receipt PDFs and calendar exports use the audited JSON path.

| Surface | Recorded references |
| --- | --- |
| Client administration search, practitioner directory and booking-contact search | Every disclosed client ID, including empty-result events; search criteria and DOB omitted |
| Client profile, saved address, duplicate/merge review, invitations and configured access | Requested path IDs, disclosed clients, invitations, claim/identity IDs and related practitioner IDs; authenticated own-profile requests get an explicit own-record reference |
| Appointment lists/details, practitioner calendar, day-of views, history and logistics notes | Disclosed appointment/client IDs, typed calendar time-off IDs and note IDs; list coverage replaces the old destination page event with an unrelated random correlation |
| Forms, submissions, amendments, templates and drafts | Task/template/submission/amendment/draft IDs; answers, definitions and draft payloads are never traversed |
| Receipt lists/context/PDF and client calendar export | Receipt and appointment IDs, with download classification for PDF/ICS responses; document bytes and filenames omitted |
| Travel enquiry lists and threads | Enquiry and message IDs; message bodies and search labels omitted |
| Notification lists and dashboards | Notification/appointment IDs; fixed next-appointment projections and time-off follow-ups; recipient contacts, errors and widget definitions omitted |
| Staff profiles, qualifications, private locations and application administration | Verified actor, route and relevant user/practitioner/qualification/global-record IDs; addresses and credentials omitted |
| Denied and failed requests | Original status, bounded application error code, actor when already verified, canonical route and attempted numeric targets; no claim that a requested record exists |

The new `access_audit_events` table is the response disclosure/security trail. Existing `audit_logs` module events and write histories remain; in particular, client-creation events used by the booking relationship policy are unchanged. Existing module view events may represent service access before serialization, whereas the new successful access event is committed only after JSON serialization succeeds and before output. A successful event records an attempted authorized disclosure, not proof that a remote browser received it.

One event group may have multiple rows. `event_group`, `part_number` and `part_count` link chunks of up to 200 unique typed references. Large calendars are not silently truncated. Row timestamps, clinic, actor realm/ID, correlation, method, canonical route, HTTP status, action/outcome and validated IP support investigation. Application administrators and unknown-host events can have a null clinic; actor realms distinguish their IDs from clinic users.

Metadata separates:

- `resources`: identifiers from returned structural records and authorized path targets. A known duplicate-review validation response also records the client candidate IDs it discloses.
- `requested_resources`: allowlisted numeric targets submitted in top-level body/query ID fields on a successful request; these are not evidence that every target was disclosed.
- `attempted_resources`: numeric path/body/query targets on a denied/failed request, without checking or confirming their existence.

`401`, `403`, concealed `404`, and the booking writer's `invalid_client` rejection are denied outcomes. Other errors are failure outcomes. Before valid local attribution exists, rejected credentials are logged as anonymous; unverified JWT claims or browser-provided user IDs never become actor identity. Verified customer sessions can be attributed to a customer identity before a local client grant exists. Linked profile/appointment requests bind the local client actor.

The collector walks fixed structural containers and identifier fields, with explicit handling for dashboard projections. It never serializes complete responses, request bodies/queries, raw URLs, arbitrary path fragments, JWTs, session/invitation tokens, clinical answers, addresses, contacts, message bodies, PDF/calendar contents, or exception messages. Canonical routes replace numeric IDs with `:id` and unknown strings with `:value`; numeric targets are stored separately. New response shapes must extend the structural mapping and tests when introducing additional sensitive record containers. Future protected JSON routes still receive a response event even before such a mapping is added.

## Failure behavior

Audit chunks commit atomically before a protected response. An insertion/commit failure produces `503 access_audit_unavailable` and withholds the successful payload. If a handler leaves an open transaction, it is rolled back and the successful response is refused. Denial handling rolls back any abandoned writer transaction first, then records the denial independently.

If the audit sink itself is unavailable during a denial/failure, the original rejection remains a rejection. PHP's operational error channel receives only an audit event-group ID, outcome and SHA-256 hash of the request correlation. It receives no raw exception text, request data or credentials. Operators must monitor these alerts: a failed persistent audit write cannot be represented as a durable database event. A response correlation can be hashed to match the fallback alert.

A write may already have committed before its response audit fails. Existing idempotent retry/reconciliation behavior remains necessary; do not interpret this 503 as proof that the business operation never happened, or retry an uncertain booking with a new request key. Durable notification jobs remain separate from response auditing.

## Deployment and hosted acceptance

1. Back up and apply [migration 049](../api/database/migrations/049_access_audit_events.sql) to the shared API database before deploying the updated API. The fresh-schema definition also includes this table. No existing patient records are copied or changed by the migration.
2. Confirm runtime INSERT privileges on this table and the ability to use transactions. Prefer a runtime account without UPDATE/DELETE privileges on audit rows, with a separately authorized read-only investigation account. The application does not expose an audit-table API or grant new staff access to logs. Hosting privilege enforcement, independent log protection, access reviews, backups and approved retention still require operational evidence.
3. Deploy `AccessAudit.php`, `Api.php`, `Response.php` and the booking-list adjustment together. There is no default-off switch for the new response audit. Missing migration/privileges intentionally prevent successful protected disclosures. No frontend change is required.
4. With synthetic records, verify client search, practitioner search, saved-address denial, appointments/calendar, forms and denied administrator answer access, own client profile, PDF/ICS, notifications and travel threads. Verify resolved clinic and actor realm/ID, record references, pagination/chunk completeness and absence of clinical/credential contents. Exercise revoked sessions, foreign clients, invalid/unknown hosts and an audit-sink failure in an isolated environment. Do not disable the production audit table to test an outage.
5. Retain source revision, migration/configuration evidence and named acceptance results. Audit viewers, routine review ownership, suspicious-access alerting, retention and tamper protection remain launch work. Backend rollback must preserve accumulated audit records and record the resulting coverage change; do not drop the table as a rollback shortcut.

No deployment or hosted patient-data acceptance was performed in this task. Private operator tools, direct database access, host/web-server logs and outbound provider processing need their own operational controls; this change covers the application's API disclosure boundary.

## Validation

- All 51 root PHP policy/test scripts passed. The new collector/response tests cover typed references, payload exclusion, empty searches, concealed denials, duplicate candidates, verified/anonymous/customer/global actors, 1,001 calendar records, atomic chunk failure, unfinished transaction refusal, denial persistence and pre-output/serialization failure behavior. Final count: 50 new checks.
- Disposable MariaDB 11.4.8 plus a loopback PHP HTTP server passed 28 acceptance checks against the real API, signed synthetic workforce/customer tokens, customer sessions, SQL-backed reads/authorization and the migration. The inherited booking-access fixture also passed its 74 checks.
- All frontend public/portal/application-admin builds passed, including bilingual content and 2,051 translations; existing bundle-size warnings remain.
- 38 browser regressions passed across customer sessions, client overview, appointment action links, receipt/navigation flows and access-denied recovery. These use browser fixtures; the separate HTTP suite validates real backend enforcement, including form answers.
- PHP source/public syntax: 87 files passed. All three new PHP test/router sources passed syntax checks, the PowerShell SQL runner parsed successfully, and Git whitespace checks passed.

Run the SQL/HTTP suite on an explicitly disposable local database server:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13329 -IntegrationTest access-audit.php
```

The integration router lives under private test sources, refuses ordinary CLI execution and requires the synthetic test environment. It uses in-memory synthetic verification keys via private dependencies; production exposes no test identity header or authentication bypass. Test databases/logs stay ignored under `.tmp`. The existing broad browser travel-navigation assertion mismatch is outside this change; the full browser suite was not rerun.

Final SQL/HTTP acceptance: 28 checks passed; no hosted database or real patient records were accessed.
