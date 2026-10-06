# Intake and consent forms release

This package contains the matching intake forms API and portal implementation. Packaging does not merge the branch or deploy it. See `manifest.json` for the exact source revision, build time and archive hashes, and `CLIENT_INTAKE_FORMS.md` for permissions and synthetic acceptance checks.

## Required uploads

1. Back up the existing private API and Willow portal files. Keep `CLIENT_FORMS_ENABLED=false` in private `/wellness-api/.env` during upload. Preserve that file and existing authentication, clinic routing and other rollout settings.
2. The owner reports migration **036_client_intake_forms.sql** already applied. Do not run it again or import the bundled migration directory. Previous migrations through 035 and onboarding tables must also be present; reported SQL completion is not a hosted acceptance test.
3. Extract **wellness-api-private.zip** into private `/wellness-api`, replacing the matching application files and dependencies. Do not upload it to a public document root.
4. Extract **wellness-portal.zip** into the document root serving **willowwellness.copihue.ca**, replacing its application files, assets and included public API entry point. ZIP contents belong directly in that document root, without an extra wrapper directory.
5. After both uploads, set `CLIENT_FORMS_ENABLED=true` in private `/wellness-api/.env`. Refresh the portal and test using synthetic client answers before collecting real information.

Only these two archives are required for this feature. The public website, neutral `portal.copihue.ca` landing and mail bridge archives are included by the general builder and do not need uploading for intake forms. The public website archive retains the canonical **livinlively.ca** redirect fix.

## Acceptance and rollback

- Practitioner/admin sidebar: **Intake and consent forms**. Publish a template and a new version; old assignments must retain their original questions.
- Client details: **Client forms**. Assign directly and inspect status; admin/reception must not see answers.
- Client account: **My forms**. Submit explicitly, including a required No answer and consent acknowledgment where applicable.
- Assigned practitioner: open the submitted form and mark it reviewed. Another practitioner/client must not read it.
- Book a matching service with the template owner and confirm an automatic assignment; canceled pending assignments are revoked. Verify recurring previews/conflicts leave no tasks.

Use the fuller acceptance checklist in `CLIENT_INTAKE_FORMS.md`, including merged clients, retry behavior and French/mobile navigation. Exact hosted database/provider behavior and clinical/privacy review remain outstanding.

If acceptance fails, set `CLIENT_FORMS_ENABLED=false` and restore the previously matching API/portal files together. Preserve the migration and form/template/submission/consent history; do not drop tables to roll back application files.
