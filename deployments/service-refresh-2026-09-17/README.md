# Service assignment list refresh

Back up the portal frontend, then upload the contents of `wellness-portal.zip`
to `/public_html/wellness-portal/`. Upload assets before index.html and include
the root .htaccess (preserving hosting-required directives).

This is a frontend-only update. Preserve the existing portal `api/` directory.
No private API, vendor, environment, Entra or database changes are required.
The production portal still calls its same-origin `/api/v1` entry point.

Hard-refresh once after deployment. Create a test service, then select it in
Service assignments without another refresh and assign a practitioner/location.
An existing unsaved assignment selection is preserved when a service is saved.

Verified: TypeScript, production portal build, and a browser regression test
covering creation, refreshed choices, draft preservation and assignment payload.
Browser test uses mocked API/auth; hosted acceptance remains required.
Rollback by restoring the prior frontend files, leaving api/ unchanged.
