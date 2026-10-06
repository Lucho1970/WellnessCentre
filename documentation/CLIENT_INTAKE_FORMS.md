# Client intake and consent forms

First workflow implemented on `feature/client-intake-forms`. Not merged or deployed. It remains disabled by default through the private runtime setting `CLIENT_FORMS_ENABLED=false`. This is the first Phase 7 forms slice, not completion of all clinical records or privacy workflows.

## Where to find it

- Practitioners and clinic administrators: **Intake and consent forms** in their portal sidebar. Create a named template with an assigned practitioner, English/French instructions and up to 30 text, yes/no or required consent-checkbox questions. Publish immediately or create a new version of a current template; published definitions cannot be edited in place. Version history shows the latest 100 stored versions.
- Administration/reception: **Clients → Details → Client forms**, or the Forms tab beside appointment history and practitioner access. Assign a current template, inspect pending/submitted/reviewed/revoked status, or revoke a pending assignment. This view contains metadata, not answer content.
- Practitioners: select a client in **My clients → Client forms**. Eligible clients are those with their appointment history, those they created, or those with an explicit current assignment approved for that practitioner. A form assignment is an explicit grant to its assigned practitioner; broad scheduling permission does not grant access to another practitioner's answers.
- Linked clients: **My forms** in their client account. Read instructions, answer the assigned questions, acknowledge each consent checkbox where present, then review and explicitly submit. Answers remain visible afterward with the original version. No answer data is stored in browser local/session storage.

Administrators may publish practitioner-owned templates and assign them to a clinic client. Reception may assign existing templates but cannot author them. Answers can be read only by the owning client or the currently active assigned practitioner with the practitioner role. Clinic administration, reception, accounting and scheduling delegation alone do not grant answer access. An administrator who separately has the practitioner role may read their own assigned answers.

## Versioning and transactions

New publications append a `form_templates` row in the same clinic-local family and retire the previous row from new assignments. Existing tasks/submissions keep their original template ID and definition. Publication, direct assignment and submission retries do not create duplicate rows. Publishing/assigning with a reused key for a different request fails; completed assignment retries still work after a new template version is published.

Selected service rules assign the latest active template automatically when **new** appointments are committed with that template's practitioner. Other practitioners' bookings do not inherit the template. Rules require services actually offered by the owner. This is not a retroactive backfill of existing appointments. Automatic tasks join the ordinary/recurring booking transaction, so previews and conflicts leave no tasks. Canceling a booking while forms are enabled revokes its pending automatic tasks; already submitted answers and other occurrences remain intact. Reassignment does not silently transfer access to existing form answers or change the assigned template owner.

Submission locks its task, validates only the published question IDs/types, requires all mandatory answers and explicit final confirmation, then creates one immutable response and updates the task. Required yes/no questions accept **No**. Consent checkboxes require an affirmative value; a consent form must contain at least one required consent question. Consent evidence records the client, exact template version, task/submission IDs and timestamp. This records an acknowledgment; it does not implement drawn signatures, withdrawal or adjudication of consent policies.

The assigned practitioner may mark a submitted form reviewed. Answers cannot be overwritten in place; reassign a new form/version to request corrected or additional information. Super Admin client merges move tasks, submissions and consent records to the reviewed surviving client, even when the forms pilot is disabled. The old client identity cannot continue reading moved answers.

Form access and changes are audited with entity IDs and minimal metadata; answers, questions, consent text and contact details are excluded from audit metadata and normal notifications. API responses use `Cache-Control: no-store`. Status lists use pages of 25 and templates pages of 20. Form answers are not included in the existing booking-contact or client overview projections.

## Storage and API

Migration **036_client_intake_forms.sql** converts template text to UTF-8, adds nullable family/publication/author fields to `form_templates`, and creates `client_form_tasks`. It preserves existing foundation rows, which are excluded from this new versioned workflow until deliberately migrated. Existing `form_assignments`, `form_submissions` and `consent_records` are reused. The maintained full schema includes these changes.

| API | Behavior |
|---|---|
| `GET/POST /api/v1/forms/templates` | List current templates/options; publish an initial version |
| `POST /api/v1/forms/templates/{id}/versions` | Publish a new version with `expected_version` and `idempotency_key` |
| `GET /api/v1/forms/templates/{id}/history` | Authorized template version history |
| `GET/POST /api/v1/clients/{id}/forms` | Paginated metadata; explicit direct assignment with `template_id` and `idempotency_key` |
| `GET/PATCH /api/v1/forms/tasks/{id}` | Authorized answers; versioned review or pending revocation |
| `GET /api/v1/customer/forms` | Linked client's own paginated tasks |
| `GET /api/v1/customer/forms/{id}` | Linked client's own exact-version form/answers |
| `POST /api/v1/customer/forms/{id}/submit` | Validate `answers`, task `version` and `confirmed=true`; preserve a single submission |

Requests always use the authenticated clinic/client context. Client input cannot choose a different client ID. Publication, direct assignment and client submission confirmation ambiguity preserves the same request and locks edits/navigation until retried. Review/revocation use task versions; reload status after a conflicting retry. Service template changes do not bypass booking availability or change scheduling permissions.

## Deployment and synthetic acceptance

1. Back up the database and current matching private API/Willow portal files. Keep `CLIENT_FORMS_ENABLED=false` in private `/wellness-api/.env` while uploading.
2. Confirm previous release migrations through 035 and onboarding tables are present. Apply **036** once to the API's development database before enabling forms; do not import the entire migration directory or full schema. Inspect existing columns/table and the migration if it was partly applied; MySQL DDL is not an atomic rollback.
3. Upload the matching private API and Willow portal builds from a reviewed revision. No public website, neutral landing, authentication callbacks, storage provider or new browser build flag is needed. This implementation task does not produce deployment ZIPs.
4. Set `CLIENT_FORMS_ENABLED=true` only for the planned synthetic acceptance run. Create an intake/consent template, assign directly, book a matching service, submit as a synthetic client and review as its practitioner. Publish a new version and verify old assignments retain their original questions.
5. Test another client, another clinic and another practitioner; deny answer access to admin/reception/accounting. Check administrator-approved assignments, merged clients, revoked tasks, canceled appointments, recurring previews/conflicts, duplicate/uncertain requests, stale versions and French/mobile navigation.
6. Record the exact hosted database version and acceptance results. Complete the plan's privacy/clinical-content review before real client answers are collected. If acceptance fails, disable the pilot. Keep task/submission/template/consent history when rolling back matching application files.

## Local verification

`api/tests/client-form-definition.php` tests question limits, duplicate/unknown IDs, text bounds, mandatory/optional fields, yes/no false values and affirmative consent. `api/tests/integration/client-forms.php` runs the disposable recurrence fixture first, rehearses migration 036 with a legacy template, and exercises real writers and denials, version/retry/consent behavior, automatic booking tasks, rollback/cancellation and supervised merges.

Run the local SQL rehearsal with the portable runtime already used for recurrence:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest client-forms.php
```

This starts a fresh localhost-only synthetic database, never reads the application `.env`, stops its server and retains ignored data/logs for inspection. Local MariaDB results do not establish the exact hosted MySQL configuration. Browser scenarios use synthetic API responses; they verify user flows separately from database permissions. Hosted acceptance remains outstanding.

Validation completed on 6 October 2026:

- Both frontend builds, TypeScript, four content checks and English/French parity (1,623 keys) passed. Existing bundle-size warnings remain.
- All 47 top-level PHP test files passed, including 21 form-definition checks; changed PHP files passed syntax checks.
- A disposable MariaDB 11.4.8 database passed 41 forms integration checks and 34 recurrence checks. The server stopped after the run; no hosted database was changed.
- All 130 browser regression scenarios passed, including seven forms scenarios; all 13 production-build smoke tests passed.
- The French mobile forms screenshot at 390 pixels was inspected, and Git whitespace checks passed.

These are local results. Matching hosted deployment, migration 036, runtime enablement and synthetic acceptance have not been performed.

## Subsequent forms work

Draft saving, append-only amendments, configurable due dates/reminders, historical assignment backfills, template archival, consent withdrawal, uploads/private storage/malware scanning, practitioner notes, exports and retention/deletion policies remain separate development steps. Intake completion does not yet block appointment booking or invoice a client.
