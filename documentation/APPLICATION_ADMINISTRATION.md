# Central application administration

The public landing page stays at `portal.copihue.ca`. Its Administration sign-in link opens `/admin/`. The central application is a separate frontend build, uses only workforce Microsoft authentication and never loads a clinic catalogue or clinical record. Clinic portals retain their own business settings, locations and other operational screens. Clinic creation and portal hostname registration now belong to central administration; old clinic `/admin/clinics` APIs deny access.

## Authorization

Migration **040_application_administration.sql**, applied once after 039, creates `application_administrators`. Access requires an active explicit grant for the verified workforce issuer and immutable subject. Existing clinic Super Admin roles, matching emails and supplied clinic IDs do not grant central access. Provider signature, expiry, tenant, API audience and delegated scope are checked before the grant. A revoked grant is rejected on the next request. There is no public signup or automatic grant.

The central host is globally configured by `APPLICATION_ADMIN_HOST` (default `portal.copihue.ca`), and enabled with `APPLICATION_ADMIN_ENABLED=true` plus `CLINIC_MANAGEMENT_ENABLED=true`. `/api/v1/application/*` is accepted only at that exact host; clinic APIs are rejected there. Do not put the central host in `CLINIC_HOST_MAP` or `clinic_hosts`.

Central administrators may list clinics, create a new clinic and configure clinic name, public website and portal host. They cannot read clinical records through central routes. Creating a new clinic explicitly provisions a separate Super Admin membership for the creating workforce identity in that NEW clinic; it grants no access to existing clinics. Clinic login additionally requires the existing workforce `Wellness.SuperAdmin` app role. Central access alone does not satisfy clinic login authorization. Grant management currently uses controlled SQL; there is no web UI to invite additional application administrators.

Changes are transactional and audit records identify the application administrator ID separately from a clinic-local user. Duplicate or reserved hosts fail without partial records. Changing a portal registration does not migrate records or alter DNS, certificates, identity-provider callbacks or global legacy aliases. Remove obsolete `.env` aliases deliberately when changing a host, otherwise a legacy alias may still resolve to its original clinic.

## Initial grant

First inspect the intended administrator's existing Microsoft identity link and independently confirm its workforce tenant and object ID. Do not select a person by email. Replace the placeholders below with the explicit local staff user ID and trusted workforce tenant ID; do not use Esther's test user ID by assumption.

```sql
SELECT u.id, u.display_name, i.tenant_id, i.provider_subject
FROM users u JOIN identity_links i ON i.user_id=u.id
WHERE u.id=YOUR_ADMIN_USER_ID AND i.provider='microsoft';

INSERT INTO application_administrators (issuer,subject,display_name,email)
SELECT CONCAT('https://login.microsoftonline.com/',i.tenant_id,'/v2.0'),
       i.provider_subject,u.display_name,u.email
FROM users u JOIN identity_links i ON i.user_id=u.id
WHERE u.id=YOUR_ADMIN_USER_ID AND u.user_type='staff' AND u.status='active'
  AND i.provider='microsoft' AND i.tenant_id='YOUR_WORKFORCE_TENANT_ID'
  AND i.provider_subject<>'';
```

Review that exactly one row is inserted. There is deliberately no duplicate-key reactivation. To revoke access use the explicit application administrator ID:

```sql
UPDATE application_administrators SET status='revoked' WHERE id=EXPLICIT_APPLICATION_ADMIN_ID;
```

## Deployment

1. Apply 040 once; do not reapply 039. Keep application administration disabled until files and the reviewed initial grant are in place.
2. Deploy matching private API and clinic portal builds. The clinic portal update removes the former Clinics navigation.
3. Deploy the expanded `copihue-portal-landing.zip` at the existing `portal.copihue.ca` document root. It includes the public landing files, `admin/index.html`, administration assets and thin `api` entries. Its document root must be at the same level as clinic portal roots so the entries resolve the shared `/wellness-api`. Replace the landing `.htaccess`, which previously redirected `/admin` to Willow and rejected APIs.
4. Add `https://portal.copihue.ca/admin/` as a SPA redirect URI in the existing workforce portal registration (application ID `d5c68a6f-cde5-40f5-843f-eac5a3160065`). The central build returns and logs out to `/admin/`; it ignores the clinic redirect setting and never uses the invited practitioner provider.
5. Set `APPLICATION_ADMIN_ENABLED=true`, `APPLICATION_ADMIN_HOST=portal.copihue.ca` and `CLINIC_MANAGEMENT_ENABLED=true` in the shared private `.env`.
6. Sign in centrally, register existing clinic 1 as Livin' Lively at `livinlively.copihue.ca`, remove any obsolete Willow-to-1 fallback alias, and create Willow Wellness Virtual Clinic at `willowwellness.copihue.ca`. Records remain in their existing clinics. Existing clinic hosts and callbacks remain separate configuration.

The shared `.env` remains infrastructure configuration. Clinic business settings remain clinic-scoped database records. This slice provides central clinic registration, not an editor exposing global secrets or every future clinic override.

## Validation and acceptance

Run `npm run build`, normal browser regressions, `npx playwright test --config playwright.application-admin.config.ts` and applicable PHP checks. The synthetic localhost rehearsal is `api/tests/integration/application-administration.php` with `CLINIC_TEST_ALLOW_CREATE=true`, default MariaDB port 13319; it retains a random database and does not load private `.env`.

Hosted acceptance must confirm the real Entra callback, direct `/admin` and `/admin/` access, anonymous/unauthorized denial, successful registration, duplicate rollback, revoked grant denial and separation of clinic APIs. Local development does not deploy files, apply hosted SQL or configure provider callbacks.
