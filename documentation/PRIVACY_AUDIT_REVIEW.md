# Restricted clinic privacy-audit review

The clinic portal now provides **Operations → Privacy audit** at `/admin/privacy-audit`. The API reads the existing response-boundary `access_audit_events` history. This is a privacy oversight workflow, separate from access to the underlying patient record.

## Explicit authority

Both a clinic `super_admin` or `clinic_admin` role and the explicit `review_privacy_audit` permission are required. Global/application administration, practitioner, reception and accountant roles grant no review access. The service rechecks the active clinic, active local staff account, current administrator role and persisted permission on every search, including direct service calls with an old principal. A grant in another clinic grants nothing here.

An authorized clinic Super Admin can open **Staff access → select staff member → Details → Grant privacy-review permission**. The target must be an active administrator in that same clinic. Self-granting is refused; designate another eligible reviewer or have another clinic Super Admin authorize your access. **Revoke privacy-review permission** removes the capability, including from inactive or no-longer-eligible staff. A Super Admin may revoke their own grant. Clinic Admin reviewers cannot grant or revoke permissions.

Dedicated `PUT` and `DELETE /api/v1/admin/privacy-audit/reviewers/{id}` handlers change only this capability. They recheck the grantor's active local Super Admin role, hold clinic consent/transaction locks, and commit the permission write with its business audit. They do not require the grantor to hold review permission. The Super Admin staff directory is explicitly available as patient-free configuration so the grantor can find the target. Broader staff edits retain their existing conservative consent boundary; the existing edit form also validates the new capability against administrator roles.

The narrow grant/revoke handlers remain available when care-related consent restrictions apply, so restrictions cannot prevent revocation. They cannot change roles, patient instructions or care relationships. There are no automatic grants in migration 051.

## Search and disclosed information

- Searches use UTC dates: **From** is inclusive; **Before** is exclusive. The default covers seven calendar days, including today. Each request is limited to 31 days; older history can be reviewed using another date window.
- Optional filters cover actor type/ID, exact resource type/ID, and success/denied/failure outcome. Actor IDs require their namespace; resource searches require both type and ID. No name, address, message, raw SQL, clinic override or unrestricted free-text search is accepted.
- The server applies clinic, date and filter predicates before pagination. Pages contain at most 50 audit rows, ordered by descending numeric ID, with a `before` cursor. There is no total-count query. A multipart event may span pages; the group and part numbers identify its pieces.
- Each row exposes its audit ID, event group/part, UTC time, actor type/ID, standardized action/canonical route, HTTP method/status, outcome and standardized numeric references. Disclosed, requested and attempted references are displayed separately. An attempted reference is not proof of the target's existence or of disclosure.
- Raw metadata, clinical answers, messages, addresses, contact details, IP addresses, correlation strings, user agents and tokens are not returned. Legacy unrecognized route segments/actions are redacted. Only known numeric reference types are projected, up to the existing 200-reference part size per category.

Resource searches match references actually recorded in disclosed, requested or attempted metadata; they do not infer a patient identity from current appointment ownership or produce a complete patient access report. Appointment-only historical events may require searching the appointment ID. Events before audit collection began cannot be reconstructed by this screen. Older `audit_logs` business history, global/null-clinic events and other clinics' events are not exposed.

## Oversight and audit safeguards

The specifically authorized reviewer can investigate minimal audit references relating to a restricted patient. Ordinary care consent filtering does not hide that oversight trail. This permission never authorizes opening the patient's record, addresses, forms or other protected endpoints; their existing restrictions continue to apply. The custodian must approve the reviewer's identity, oversight purpose, scope and handling procedures before assigning the capability. A role label or this implementation does not establish legal authority by itself.

Each successful search writes `privacy_audit.search` to the business audit with its normalized filters and returned audit IDs before returning results, including empty searches and direct service calls. Nested caller transactions are refused so callers cannot later roll back that audit after reading data. HTTP responses additionally record the disclosed **audit-event IDs** in the central access trail; underlying reference arrays are not recursively copied. Denied/failing HTTP attempts are centrally audited; valid numeric resource-search targets are retained as attempts. Failed business or response auditing prevents successful disclosure.

The portal clears old rows when loading another page/search and on access failures, and aborts superseded requests. Revocation takes effect on the next server authorization check; it does not erase information already viewed or revoke an already-authorized in-flight response. Cached navigation may require refreshing the session to reflect a changed capability. Already displayed/downloaded information and database copies remain subject to staff handling controls.

This screen is read-only: it provides no audit editing, deletion or export. It does not implement automated anomaly alerts, investigation case disposition, external tamper protection, retention/hold enforcement or a restricted database support workflow. Those remain operational/application follow-ups for P0-3, alongside hosting database privileges, backups and review procedures.

## Rollout and acceptance

Back up and rehearse the database. Apply `api/database/migrations/051_privacy_audit_review.sql` **once**, after migrations through 050; it inserts the permission and a clinic/ID history index. Do not reapply it to a fresh schema already containing this definition. Deploy the matching API and portal together; retain private environment files. No new environment variable is required. Assign only reviewed grants after rollout, and drain old API versions before acceptance.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest privacy-audit-review.php` for disposable local SQL/HTTP acceptance. It reuses the synthetic booking/access-audit fixtures, with no real patient data, external provider request or production authentication bypass.

Local validation on 19 October 2026:

- 69 privacy-review SQL/HTTP checks passed on MariaDB 11.4.8, with 74 booking-contact and 28 central access-audit checks in the same run. Coverage includes no automatic grants, permission assignment/revocation, role/clinic boundaries, stale principals, restricted-patient oversight without clinical access, invalid filters, pre-limit filtering, cursor pages, redaction and failed audit/storage paths.
- All 53 root PHP scripts passed, including 26 audit filter checks. Syntax checks passed for 94 source/public files and the new integration source.
- Seven selected browser scenarios passed: six new cases covering denied roles, search/pagination, clearing rows after revocation and explicit grant/revoke commands, plus the existing staff details/edit/create regression.
- Full public, portal and application-admin production build passed, including TypeScript, content and 2,108 English/French translations. Existing bundle-size warnings remain. The full browser suite was not rerun.

Hosted identity/membership, MySQL/MariaDB compatibility, grant/revocation, concurrent operations, representative-volume query performance and storage/backup privileges require acceptance on the intended host. The [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open. This review branch does not authorize real-data use, deployment or a compliance certification.
