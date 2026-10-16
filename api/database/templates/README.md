# Starter form templates

`massage-therapy-intake.json` is an editable English/French `wellness-form` version 1 document with 20 questions in four sections. It contains no client answers. All questions are optional so a client can discuss information privately; the practitioner must review and complete the relevant intake with them. Existing client-profile contact details should be confirmed during intake.

## Add to the global library

1. Install the form-library release and database migration **045** first. Back up the database. Select **wellness_centre** in phpMyAdmin before importing SQL.
2. Open `seed-massage-therapy-intake.sql`. It selects the administrator automatically only when exactly one application administrator is active. If several are active, run `SELECT id, display_name, email FROM application_administrators WHERE status='active';` and replace the first `SET @seed_admin_id = ...;` with `SET @seed_admin_id = 123;` using the correct ID. These are application administrator IDs, not clinic user IDs.
3. Import the SQL file and read the final `result`. **CREATED** means one global template was submitted for approval. **UNCHANGED** means it already exists and was preserved. **STOP** requires correcting the reported issue. Stop on SQL errors; roll back an interrupted transaction in the same session.
4. In **portal.copihue.ca/admin/ → Form library → Awaiting approval**, preview the template in both languages. Review/adapt it for your practice and approve it. The script deliberately does not approve it.
5. In a clinic portal, open **Intake and consent forms → Form library → Global library**, find **Massage Therapy Intake / Bilan initial de massothérapie**, then **Use template**. Edit the independent draft, choose its practitioner and service assignments, and publish through the usual workflow.

The release flags `CLIENT_FORMS_ENABLED`, `FORM_TEMPLATE_DRAFTS_ENABLED` and `FORM_LIBRARY_ENABLED` must be true in the private API configuration. This content-only seed needs no new frontend deployment once the form-library release is installed.

Rerunning the script never overwrites the existing starter, creates another revision, reapproves it or restores a retired entry. It records one submission event on initial creation. It does not create practitioners, patients, assignments or administrator grants. To revise a seeded template, use the library revision workflow. Do not change the seed key to work around an existing entry.

## Import just a private draft instead

Use the form editor's **Import JSON** action, select a practitioner and choose `massage-therapy-intake.json`. This creates a working draft rather than a library entry. SQL is not needed for this route.

## Maintain the starter

After editing the JSON, run from the repository root:

```powershell
php scripts/build-massage-intake-seed.php
php scripts/build-massage-intake-seed.php --check
```

The generator validates against the application's actual question schema and embeds only the normalized `definition` in the SQL. UTF-8 hex literals preserve French text and JSON escaping regardless of the importing SQL client's encoding or backslash mode. Keep the readable JSON alongside the generated SQL.

Validated locally on 16 October 2026: JSON/schema and generated-SQL consistency checks, PHP lint, and 21 starter-specific checks on MariaDB 11.4.8 (plus 198 existing booking/forms/library checks). Acceptance covers direct JSON import, pending approval, UTF-8 round trips, repeat imports, approved draft copying, later revisions, retirement and administrator selection. No hosted database was modified. Run the SQL acceptance with `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-MariaDB-directory> -IntegrationTest massage-intake-seed.php`.

This is a starting intake, not a complete clinical record or a treatment-consent form. The reviewing practitioner should tailor it to their services, privacy process and applicable professional requirements before use. The content uses a focused, optional intake approach informed by CMTO's [health-information collection standard](https://www.cmto.com/rules/standard-of-practice-collecting-personal-health-information-from-patients/) and keeps treatment consent separate as described in the [consent standard](https://www.cmto.com/rules/standard-of-practice-consent/). It is not represented as regulator-approved.
