# Form libraries, approvals and editable copies

This release adds a global reusable form library and a private library for each clinic. Practitioners and clinic administrators submit complete form definitions for approval. A clinic administrator (`clinic_admin` or `super_admin` in that clinic) reviews clinic-library submissions. Only a separately granted application administrator on the central host reviews global-library submissions; a clinic super administrator does not acquire global approval access.

There are no preloaded clinical forms in this release. Library authors create the content through the editor, or submit a copy of a form they are already authoring. Library content contains form questions only, not client answers, contacts or clinical records. Preview answers exist only in browser component memory and are never submitted.

## Database update and upload order

1. Back up `wellness_centre`, `/wellness-api`, the shared clinic portal and central landing files. Arrange a quiet deployment window. Keep `FORM_LIBRARY_ENABLED=false` in the private API `.env` until SQL and code are installed.
2. In phpMyAdmin explicitly select **wellness_centre**. Run `sql/form-library-preflight.sql`. All six prerequisite tables must show **OK**, and `travel_columns_present` must be **6** for migration 044. The library-table count must be **0** before the first migration. A count of **3** means already installed: skip migration and run postflight. A count of **1 or 2** means a partial update: stop and inspect; do not rerun CREATE statements blindly.
3. Run **`sql/045_form_library.sql` once**. Prerequisites are the existing intake forms/drafts (036/037), application administrator registration (040) and the already installed 044 baseline. This creates three new tables and a foreign key; it changes no existing clinic, form, assignment or submitted answer. Do not import `schema.sql`, seeds or earlier migrations again.
4. Run `sql/form-library-postflight.sql`. Expect **three OK rows**, each using InnoDB and utf8mb4 collation.
5. Upload the following archive **contents** directly into their destinations, including hidden `.htaccess` files. Avoid extra enclosing directories:

| Archive | Destination |
| --- | --- |
| `wellness-api-private.zip` | `/wellness-api/`; replace `src`, `bin`, `vendor` and Composer files; preserve private `.env`, runtime data and uploads |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal/` once for all clinic hosts |
| `copihue-portal-landing.zip` | `/public_html/wellness-portal/`; includes the landing, `admin/` build and thin API entries |

6. In private `/wellness-api/.env`, retain the working authentication, routing and other feature settings and enable:

```dotenv
CLIENT_FORMS_ENABLED=true
FORM_TEMPLATE_DRAFTS_ENABLED=true
FORM_LIBRARY_ENABLED=true
```

Central administration also requires the already configured `APPLICATION_ADMIN_ENABLED=true`, `CLINIC_MANAGEMENT_ENABLED=true`, `APPLICATION_ADMIN_HOST=portal.copihue.ca` and an active application administrator grant. No new identity-provider callback, Composer dependency, DNS mapping or marketing website upload is needed. Keep the established workforce `/admin/` callback. Refresh all portal tabs after upload and clear PHP OPcache through hosting if it retains old code.

See [Deployment layout](DEPLOYMENT_LAYOUT.md) for the confirmed folder map. The full API package also contains preceding receipt, distance-fee, clinic-specific time-off and practitioner travel-setting code; keep their already installed migrations and configuration.

## Author and approve

- Open **Intake and consent forms → Form library**. Choose **Global library** or **Clinic library**. Search names/descriptions and preview approved templates. English/French preview controls affect only the preview, so they do not discard an open editor. Preview answers are not saved.
- **Create library template** opens an editor with name, description, type, bilingual instructions, sections and questions. **Submit for approval** stores an immutable pending version. Nothing becomes available for copying until approved, including administrator-authored submissions.
- To contribute a form already being edited, select **Submit to library** in that form's editor. Choose a target library and add a description. This copies the definition into a separate library submission; it does not publish or assign the practitioner's working form.
- Practitioners track their own proposals using **Submissions**. Rejection notes are visible to the submitter and authorized reviewers. Clinic admins choose **Clinic library → Awaiting approval → Preview template** to approve or reject. Rejection requires a note. Approvals do not require an extra confirmation dialog.
- Platform administrators use **`portal.copihue.ca/admin/ → Form library → Awaiting approval`** for global submissions from every clinic, or create their own global templates in that tab. They cannot use this feature to inspect private clinic-library content or client answers.
- **Revise library template** proposes a new immutable version. An existing approved version remains usable while a revision is pending or rejected. Review a pending revision before submitting another revision for the same entry. Stale reviews/revisions fail with a reload message.
- **Retire template** requires deletion confirmation and removes the entry from browsing and new copies. It preserves library history, audit events and all forms already created from it.

## Create and edit a practitioner form

Select the assigned practitioner, then **Use template**. The server creates a private, independently editable draft in the current clinic. It copies only the approved definition and title. The new draft has no inherited service rules, client answers, appointments or version-family link. Save it as a draft or publish it using the existing workflow, selecting service bindings deliberately.

Use **Move section up/down**, **Insert section after**, **Move question up/down** and **Insert question before/after**. The question's section selector moves it between sections. Removing a question requires confirmation. **Remove section** confirms removal of the heading while preserving its questions; **Delete section and questions** confirms removing both. The last question cannot be deleted, preserving the existing form-definition minimum. There is no drag-and-drop. Changes to a draft or new published form version never rewrite earlier assignments/submissions or the library source.

## Hosted acceptance

1. Submit a clinic template as a practitioner. Another practitioner must not see the pending content. The author must not have approval controls.
2. Approve it as the clinic admin. Confirm it becomes available only in that clinic. Reject another submission with a reason; verify its author can read the note and revise it.
3. Submit globally. Confirm the clinic admin cannot approve it globally. Approve through application administration; then verify both clinics can preview and create independent copies for their own practitioners.
4. Edit, reorder, insert and delete sections/questions in a copy. Save/reload the draft and publish it with deliberately selected services. Confirm the library source and an existing client's assigned form remain unchanged.
5. Propose and approve a library revision; old copies retain their original questions. Retire the source; new browsing/copying must stop while existing drafts and assigned forms remain usable.
6. Check English/French and mobile previews, rejection notes, stale two-tab edits, cross-clinic IDs and invalid owner selection. These hosted checks are separate from synthetic local tests; do not confirm unintended appointments or send real clinical data while testing.

## Build and rollback

From clean committed source, run `./scripts/build-form-library-deployment.ps1 -ReleaseName YOUR-UNIQUE-RELEASE`. It produces the three required code ZIPs, SQL, README and a manifest recording source revision, destinations and hashes. Generated packages stay ignored under `deployments/`.

To disable the library, set `FORM_LIBRARY_ENABLED=false`. Preserve its tables, approved versions and audit events. The existing private draft/published-form workflow remains available. If rolling back files, restore the matching API, shared portal and central administration builds together. Do not drop library tables or existing form history to roll back.

## Local validation

The full public/portal/central frontend build and 1952 English/French translation checks passed. Forty form/editor/library browser scenarios and six central-administration scenarios passed, including independent copying, bilingual preview, French mobile layout, section/question controls, clinic review and global author/approval flows. The final run of the existing form suite passed after one earlier startup failure was rechecked. Screenshot capture disables transitions for visual inspection.

Real MariaDB checks passed: 53 library checks, 111 existing intake-form checks and 34 recurring-booking checks. They cover cross-clinic isolation, pending visibility, independent copies, immutable content, private reviewer notes, role-specific approval, idempotent creation/copying, stale revisions, rejection, retirement, published-form preservation, auditing and feature disablement. The exact migration and pre/postflight SQL were rehearsed on the disposable database. PHP syntax, 115 form-definition checks, 15 response/preflight checks and PowerShell parser checks passed. Hosted acceptance remains pending; no hosted database or provider settings were changed.
