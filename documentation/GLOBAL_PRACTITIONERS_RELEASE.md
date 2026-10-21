# Global practitioner code deployment — October 14, 2026

This release combines global practitioner management, practitioner-owned credential sharing, the earlier client-form answer drafts/amendments, and Wellnexa branding. The central landing page now links to the active `wellnexa.copihue.ca` portal. The standalone `wellnexa.ca` marketing website is deployed separately and needs no upload for this release.

The database update was confirmed complete by the operator. This is a code-only upload set; do not rerun SQL migrations from earlier deployment folders. Keep the current private `.env`, clinic host bindings, identity-provider configuration and feature flags.

Extract each archive and upload **its contents**, without adding another enclosing directory:

| ZIP | Destination |
| --- | --- |
| `wellness-api-private.zip` | The existing private `wellness-api` directory. Overwrite `src`, `bin`, `vendor` and Composer files; preserve `.env` and private runtime data. |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal`, shared by both clinic hosts. |
| `copihue-portal-landing.zip` | The existing document root serving `portal.copihue.ca`. This includes `admin/index.html`, its `assets`, the neutral landing files, `.htaccess` and the thin `api` entry point. |

The clinic hosts serve the same compiled application from one directory. Clinic records and branding come from the API's hostname resolution. Include hidden `.htaccess` files and retain old hashed assets through rollout. Upload the shared API first, then the common portal once and central administration if included. Refresh and verify both clinic portals. New manifests record the source revision, destinations and ZIP hashes; old release archives remain unchanged. See [Deployment layout](DEPLOYMENT_LAYOUT.md).

Acceptance after upload:

1. Open `https://portal.copihue.ca/admin/`, sign in and select **Global practitioners**.
2. Import the existing practitioner from the original clinic, then assign the Wellnexa clinic and its location.
3. Have the practitioner sign into each clinic with the same existing account. Under their account profile, **My global credentials** lets them store credentials privately and choose what to share with that clinic.
4. Verify RMT and nutrition selections independently. Each shared credential enters the receiving clinic's pending review. Unselected credentials remain private.

Builds, targeted integration/browser regressions, and ZIP structure/integrity checks are local release validation. Actual hosted sign-in, importing, assigning and sharing must still be accepted on Netfirms. See [GLOBAL_PRACTITIONERS.md](GLOBAL_PRACTITIONERS.md) for the full workflow and access rules.
