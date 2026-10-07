# Client intake and consent forms

First workflow implemented on `feature/client-intake-forms`. Not merged or deployed. It remains disabled by default through the private runtime setting `CLIENT_FORMS_ENABLED=false`. This is the first Phase 7 forms slice, not completion of all clinical records or privacy workflows.

## Where to find it

- Practitioners and clinic administrators: **Intake and consent forms** in their portal sidebar. Create a named template with an assigned practitioner, English/French instructions and up to 30 text, yes/no, date, phone number, email or required consent-checkbox questions. Publish immediately or create a new version of a current template; published definitions cannot be edited in place. Version history shows the latest 100 stored versions.
- Administration/reception: **Clients → Details → Client forms**, or the Forms tab beside appointment history and practitioner access. Assign a current template, inspect pending/submitted/reviewed/revoked status, or revoke a pending assignment. This view contains metadata, not answer content.
- Practitioners: select a client in **My clients → Client forms**. Eligible clients are those with their appointment history, those they created, or those with an explicit current assignment approved for that practitioner. A form assignment is an explicit grant to its assigned practitioner; broad scheduling permission does not grant access to another practitioner's answers.
- Linked clients: **My forms** in their client account. Read instructions, answer the assigned questions, acknowledge each consent checkbox where present, then review and explicitly submit. Answers remain visible afterward with the original version. No answer data is stored in browser local/session storage.

Administrators may publish practitioner-owned templates and assign them to a clinic client. Reception may assign existing templates but cannot author them. Answers can be read only by the owning client or the currently active assigned practitioner with the practitioner role. Clinic administration, reception, accounting and scheduling delegation alone do not grant answer access. An administrator who separately has the practitioner role may read their own assigned answers.

## Versioning and transactions

### Question sections

The `feature/form-question-sections` follow-up adds up to 15 named sections with optional descriptions. Titles and descriptions have English/French fields; clients use French text where available, otherwise English. Sections are displayed in their configured order with each section's questions together. Unassigned questions remain under **Questions without a section** when sections exist; older flat forms keep their existing presentation.

In the template editor, use **Add section**, enter its title and description, then **Add question to [section]**. Existing questions can move using their Section selector. **Move section up/down** changes section order. Removing a section requires confirmation and moves its questions to the unassigned group; it does not delete questions or answers. Question order within each group follows the template's existing question order. The existing limit of 30 questions per form remains.

Section IDs are stable within a versioned form, unique, and referenced by each question's optional `section_id`. API validation rejects unknown references, duplicate IDs, more than 15 sections, blank titles, titles over 190 bytes and descriptions over 2,000 bytes. Sections are plain text and remain within the existing total definition-size bound. The definitions store `sections` alongside the existing flat `questions` array; answer keys remain question IDs, and permissions/answer validation are unchanged.

Published title, description, order or membership changes require **Create new version**. Existing assignments and submissions retain their original section structure, including descriptions, and version history displays it. No SQL migration or new runtime flag is required. Matching API/portal code must be uploaded; previously generated ZIPs do not contain this follow-up.

### Radio buttons and checkboxes

`feature/form-choice-fields` adds **Single choice (radio buttons)** and **Multiple choice (checkboxes)**. Enter the question label, then 2–20 options with English labels and optional French labels. Options may be added or removed while editing an unpublished version. For example, **Average stress level** can offer Low, Moderate and High as a single-choice question. Use multiple choice when several answers are allowed.

Required single-choice questions need one selected option; required multiple-choice questions need at least one. Optional selections may be left blank, optional radio selections can be cleared, and unknown or duplicate option IDs are rejected. Answers store stable option IDs (one string or an array), while the assigned definition retains the original labels. Checkbox answers are sorted before storage/retry comparison so selection order cannot create a second submission. Saved selections are read-only and display labels from the assigned version, including French fallback behavior.

Changing a question type or its options requires a new published version. Older assignments retain their original choices and answers. Choice options appear in version history. Answer-access permissions, audit exclusions and private response storage are unchanged. No additional SQL or runtime flag is required; matching private API and portal files must be deployed together. Earlier sections-only packages do not contain these types.

### Date, phone number and email fields

The `feature/form-date-phone-fields` follow-up adds three answer types without another SQL migration. A published Text question can become one of these types in a new version; previous assignments and answers retain their original types. Revoke a pending old assignment and assign the new version if the client should receive the updated fields.

- **Date** uses a native date picker and stores a calendar date as `YYYY-MM-DD`, without a timezone conversion. The optional **No future dates** setting is intended for birth dates; its cutoff uses the Toronto calendar day consistently in browser and API. Other date questions may accept future dates. Invalid calendar dates and year zero are rejected.
- **Phone number** provides a country selector, defaulting to Canada. It accepts national formatting or an explicit international `+` number, validates numbering patterns and stores international E.164 format. Extensions are not supported. Number validation uses bundled metadata locally, without sending answers to an external provider; it does not verify ownership or reachability.
- **Email address** uses email input and server validation, trims surrounding spaces and preserves the entered address. ASCII email addresses are supported; mailbox ownership or delivery is not verified.

Blank optional typed fields are omitted. Client-side feedback supplements authoritative API checks. Answers remain private form responses; birth date, phone and email answers do not automatically update the client profile or public contact card. Both matching API dependencies (`vendor` and Composer files) and portal code must be deployed before authors publish these types. Older API code rejects the new types, and older portal code does not render their controls correctly. The existing `CLIENT_FORMS_ENABLED` setting and migration 036 are reused.

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

The Date/Phone/Email follow-up passed both frontend builds, TypeScript/content/translation checks (1,632 keys), all 47 PHP fixture files (including 51 definition checks), PHP syntax and Composer validation, 48 forms SQL checks plus 34 recurrence checks on disposable MariaDB 11.4.8, 11 focused forms browser scenarios and 13 production smoke tests. The French mobile typed-field screenshot was inspected at 390 pixels. Existing bundle-size warnings remain. Dependency audit reported an existing development-only `source-map-js` advisory; the new phone dependencies introduced no reported advisory. No hosted database or deployment was changed by this follow-up.

The sections follow-up passed both frontend builds, TypeScript/content/translation checks (1,646 keys), all 47 PHP fixture files (including 71 definition checks), changed-file PHP syntax, 53 forms SQL checks plus 34 recurrence checks on disposable MariaDB 11.4.8, all 15 focused forms browser scenarios, and 13 production-build smoke tests. The French section-title/description layout was inspected at 390 pixels. Existing bundle-size warnings remain; exact hosted acceptance and deployment are still outstanding.

## Subsequent forms work

Choice-field validation on 6 October 2026: both frontend builds, TypeScript/content/translation checks (1,657 keys), all 47 PHP fixture files (including 94 definition checks), changed-file PHP syntax, 59 forms SQL checks plus 34 recurrence checks on disposable MariaDB 11.4.8, and 19 forms browser scenarios passed. The read-only French radio/checkbox mobile layout was inspected at 390 pixels. Existing bundle-size warnings remain. Hosted acceptance is separate; the existing local English translation edit is excluded from the feature commit and package.

Draft saving, append-only amendments, configurable due dates/reminders, historical assignment backfills, template archival, consent withdrawal, uploads/private storage/malware scanning, practitioner notes, exports and retention/deletion policies remain separate development steps. Intake completion does not yet block appointment booking or invoice a client.


## Form-author drafts and editable JSON source

On `feature/form-drafts-json-import`, **Save draft** stores an unpublished form in the API database, including unfinished names, labels, sections and options. **My form drafts → Resume draft** restores it after a reload or a later sign-in. Drafts are private to the creating practitioner/admin in that clinic; administrators do not automatically see another author's work. Drafts are not assignable, do not create booking rules, and do not expose client answers. This is form-author draft saving, not client-answer draft saving.

Saving is explicit. Wait for **Draft saved. You can return after signing in again.** before leaving. Unsaved changes show a warning; keeping a tab open does not guarantee preservation through authentication expiry, a refresh or browser closure. A failed or uncertain request is not a confirmed save. For an uncertain save/import, retry the identical request; the editor locks modifications until confirmation. Conflicting draft revisions return an error rather than overwriting newer work. Preserve edits with a JSON download before reloading a conflicted editor.

**View source** opens an editable JSON panel from the form list or editor. It includes the current name, form type, instructions, questions, answer types/options and section titles/descriptions. **Apply source** validates the document on the server and updates the unpublished editor without saving or publishing. Invalid JSON/schema leaves the original editor unchanged. **Download JSON** exports the displayed text as a local file. Source edits to a published form start a new version; existing templates and client assignments stay immutable. When saving a resumed draft, its draft revision protects against concurrent changes; publishing marks that draft published in the same transaction as the new template.

**Import form JSON** accepts pasted JSON or a `.json` file up to 64 KiB and imports it as a new private draft for an explicitly selected authorized practitioner. The portable format is:

```json
{
  "format": "wellness-form",
  "format_version": 1,
  "name": "Client intake",
  "form_type": "intake",
  "definition": {
    "instructions": "Please complete these questions.",
    "instructions_fr": "",
    "sections": [{ "id": "info", "title": "Client Information", "description": "" }],
    "questions": [{ "id": "birth", "label": "Birth date", "type": "date", "required": true, "no_future": true, "section_id": "info" }]
  }
}
```

Exports intentionally omit clinic, practitioner, booking service, template family/version and client/response identifiers. Imports reject unknown document fields and do not restore booking bindings; review the selected practitioner and services in the editor before publishing. Existing schema limits still apply (including 30 questions, 15 sections and 2–20 options per choice question). Incomplete English labels are allowed in drafts only; publication requires complete valid labels and consent structure. Downloaded JSON must remain valid to be imported later. Form definitions are stored as JSON payload text in `form_template_drafts`; publication continues using immutable `form_templates.definition`.

| API | Behavior |
|---|---|
| `GET/POST /api/v1/forms/drafts` | List the author's private drafts in pages of 20; create a draft with an idempotency key |
| `GET/PATCH /api/v1/forms/drafts/{id}` | Resume or save with `draft_version` and a fresh idempotency key |
| `POST /api/v1/forms/drafts/import` | Validate a portable `document` and import as a private draft |
| `POST /api/v1/forms/source/validate` | Author-only portable JSON validation without database writes |

Apply **037_form_template_drafts.sql** once after 036, then deploy the matching API/portal and enable `FORM_TEMPLATE_DRAFTS_ENABLED=true` in private `/wellness-api/.env`. This separate flag defaults to false: existing templates and View source do not query the new table while disabled. `CLIENT_FORMS_ENABLED` must also be true. Turning draft storage off hides save/resume/import controls but preserves saved rows. Do not modify published JSON directly in the database.

A form-list service error, such as correlation `660c6767-4a43-4501-973f-662e6aec07be`, does not demonstrate deletion. Inspect the matching private PHP/server log and retry loading before assessing stored templates. This change cannot recover a form that was never successfully saved or published.


Draft/source local validation on 6 October 2026: both frontend builds, TypeScript, four content checks and 1,683 English/French translation keys passed; all 47 PHP fixture files passed (including 101 definition checks), with syntax checks for changed services/routes. Migration 037 and draft/source flows passed 99 forms SQL checks plus 34 recurrence checks on disposable MariaDB 11.4.8. All 28 focused forms browser scenarios passed across the regression run and targeted reruns, including unfinished save/reload/resume, source download/edit/validation, import, uncertain retries, disabled storage, isolated draft-load failure and French mobile layout. An existing administrator startup scenario timed out before loading its page once and passed an isolated rerun. All 13 production smoke tests passed. The 390-pixel French source-panel screenshot was inspected. Existing bundle-size warnings remain. Git whitespace checks passed; the pre-existing English working-file edits were preserved outside this feature commit. No hosted migration, configuration, deployment or data recovery has been performed, and no deployment ZIP was generated by this implementation.


## Hosted JSON failure diagnostic overlay (7 October 2026)

The owner reported a signed-in templates-list 500 with `API failure JsonException`. A read-only hosted report showed template 1/version 1 still present with `JSON_VALID(definition)=1` and 9,751 bytes; malformed JSON in that stored row was not confirmed. Valid database JSON does not establish that PHP decoding and final response encoding both succeed. The underlying hosted cause remains unresolved pending the classified log.

`bugs/form-json-diagnostics` adds private error logging of the numeric JSON error code and safe source basename/line. It excludes exception messages, stack traces, form definitions, answers, credentials and absolute server paths. The HTTP error response and database remain unchanged. Error code 1 means maximum depth; 4 means syntax; 5 means invalid UTF-8. The source distinguishes template decoding in `ClientFormsService.php` from response encoding in `Response.php`, or another operation.

For the owner already running the `form-drafts-source-2026-10-07` release (base `cd7b086`), upload only the diagnostic overlay's `src/Api.php` into private `/wellness-api/src/Api.php`, replacing that one file. Keep private `.env`, `var`, dependencies and public portal files. No SQL or frontend deployment is required. Refresh the failing signed-in request once and provide the new private log line containing `json_error_code`, `source` and `correlation_id`. This overlay is instrumentation, not a claimed repair or recovery of form content. Restore the previous matching `Api.php` to undo the logging change.


## UTF-8 JSON read hotfix (7 October 2026)

The classified hosted log reported `json_error_code=5 source=ClientFormsService.php:53`, identifying invalid UTF-8 while decoding the template definition. This is different from invalid JSON syntax; the owner's read-only report still showed valid JSON for template 1/version 1. A disposable SQL reproduction with a legacy `character_set_results=latin1` generated the identical exception at the same decode line before this fix. The exact hosted column/result settings remain to be confirmed separately.

`bugs/form-json-utf8` fetches the UTF-8 JSON definitions and responses with `CAST(... AS BINARY)` before PHP decoding. This preserves the JSON bytes instead of allowing the result charset to transcode them. It covers template listing/history, assigned definitions, submitted responses and submission retries. PHP decoding remains strict. No application write, migration, replacement characters, guessed legacy-to-UTF-8 conversion or content repair is introduced. Native MySQL JSON string serialization uses UTF-8; see the [MySQL JSON conversion rules](https://dev.mysql.com/doc/refman/8.0/en/json.html) and [binary cast documentation](https://dev.mysql.com/doc/refman/8.4/en/cast-functions.html).

For the already deployed draft/source release and diagnostic overlay, upload only `src/Service/ClientFormsService.php` from `wellness-api-json-utf8-fix.zip` into private `/wellness-api/src/Service/ClientFormsService.php`. Back up that file first. The overlay does not contain `.env`, `var`, dependencies, portal files or SQL. Keep the diagnostic `Api.php` so any remaining error includes its code and source. Refresh Intake and consent forms, open View source, and verify the original questions/accents before continuing. If the same error remains, provide the new log and the read-only column report `SHOW FULL COLUMNS FROM form_templates WHERE Field = 'definition';`. A different error/source is a separate failure to investigate. Hosted acceptance is pending; this patch does not claim the owner's data was changed or recovered.


UTF-8 hotfix validation: the new real SQL fixture reproduced `JSON_ERROR_UTF8` at the original template decode line before the patch; after the patch, 108 forms checks and 34 recurrence checks passed on disposable MariaDB 11.4.8. It verifies exact accented, curly-apostrophe, Chinese and emoji text through list/history/assigned definitions/submitted answers/retry; JSON response encoding succeeds; stored definition hex bytes remain unchanged. All 48 PHP fixtures and changed PHP syntax/whitespace checks passed. This PHP read-only change does not alter frontend bundles; hosted MySQL/column settings and post-upload acceptance remain separate.


## Draft original-template version hotfix (7 October 2026)

The owner supplied a valid exported definition with 30 questions and 12 sections. The failed draft response was `invalid_form` / `Check the original template.`. The editor forwards the listed template version as `expected_version`; PDO can return that numeric column as a string, while the draft validator correctly requires an integer. These expected validation rejections do not enter the server exception log. No question correction is indicated by this response.

`bugs/form-draft-template-version` explicitly serializes list/history versions as integers. Original-template authorization and optimistic version checks remain strict; no database writes or schema changes are introduced by the read fix. A real SQL regression forces `PDO::ATTR_STRINGIFY_FETCHES=true`, verifies integer versions in list/history, then saves a draft using the listed version. It failed on the original list response before the fix. The earlier UTF-8 read fix is retained; the owner confirmed that it resolved form loading.

Deploy the single service overlay into private `/wellness-api/src/Service/ClientFormsService.php`. Keep the exported JSON backup before refreshing the editor: a template already loaded before this patch still holds its old string version. After refresh, reopen Create new version, use View source to apply the exported form JSON, then Save draft. Import form JSON is also available when a new independent form is intended. No frontend or SQL deployment is required. Hosted draft-save acceptance remains pending.

Validation: the regression failed before the patch; after the patch, 111 forms checks and 34 recurrence checks passed against disposable MariaDB 11.4.8, including draft creation from the listed version under stringifying PDO. All 48 PHP fixtures, changed PHP syntax and whitespace checks passed. No frontend code changed; frontend builds/browser tests were not repeated. The owner's uncommitted English resource edits remain preserved and excluded from this API overlay.

## Configurable question limit (7 October 2026)

`FORM_TEMPLATE_MAX_QUESTIONS` in private `/wellness-api/.env` controls the maximum questions per form. The default is 100; supported integer values are 1-200. Unset or invalid values fall back to 100. The templates endpoint returns `max_questions`, and both Add question buttons use it; older API responses without that field retain the previous 30-question editor fallback. Publishing, draft saving and JSON import/source validation use the same server limit. Raising the limit does not require SQL or alter existing published definitions/assignments. The existing 15-section, 20-choice-option and 55,000-byte definition limits still apply.

Deploy the private API and rebuilt wellness portal together, then reload the editor after backing up unsaved source. Set `FORM_TEMPLATE_MAX_QUESTIONS=100` if an explicit value is desired; it is also the default without a new setting. This change is on `feature/configurable-form-question-limit`, stacked on the draft-version repair. Local browser tests use synthetic forms; hosted acceptance remains separate.

Validation: both production frontend builds, bilingual content/translation validation, all 29 forms browser scenarios, and all 48 PHP fixtures passed. Definition validation includes 100-question published/draft acceptance, rejection above capacity, custom limits and invalid-setting fallback (110 checks). The browser regression adds through section/global controls beyond 30, verifies both stop at the API-reported 32, then saves all 32 as a draft. No SQL schema changes or hosted writes were performed. Existing uncommitted English resource edits are preserved and excluded from this task's commit.
