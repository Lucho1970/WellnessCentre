# R2 client sign-in proof — 17 September 2026

Status: owner approved External ID and reports the customer tenant created using an
existing subscription under their other login. Branch: `codex/client-sign-in`.
Customer tenant ID: `0a3841c6-b244-410d-821f-bbd9ccd1b5e2`.
Primary domain: `copihuewellnessclientsdev.onmicrosoft.com`.
These are owner-provided public configuration identifiers, not independently verified
resource state. Billing configuration and region have not been inspected by the agent.
Customer identity proof is now implemented in source, with no SQL migration or clinic
record writes. Existing staff sign-in remains separate. Hosted end-to-end acceptance
is still pending; this checkpoint does not enable client booking or clinical access.

Owner reports registration created: `Wellness Client Portal Dev`, single-tenant SPA
in the customer tenant. Application (client) ID: `7a522317-d74f-4ccb-9805-8e4b912c02ab`.
Planned customer-only callback: `https://portal.copihue.ca/client/auth/callback`.
This callback is implemented in this release; deploy before end-to-end testing.
Do not create a SPA client secret or enable implicit grants.

Owner reports registration created: `Wellness Client API Dev` in the customer tenant,
single tenant, without a redirect URI or secret. Application (client) ID:
`08542bbb-09cc-4737-979b-ca61c7eec70d`.

Owner reports API scope saved using Application ID URI
`api://08542bbb-09cc-4737-979b-ca61c7eec70d` and an enabled delegated scope
`access_as_client` (Admins only consent). Owner reports portal delegated permission/admin
consent completed and API requestedAccessTokenVersion already 2. These settings do not
grant staff access or replace API ownership checks.

Owner reports `WellnessClientsSignUpSignIn` associated with the portal SPA, with Google,
personal Microsoft and email one-time passcode visible on the hosted sign-in screen.
Google project `Wellness Clients Dev`, OAuth client `Wellness Entra Dev`, is configured
in testing mode; credentials live only in Google/Entra and the owner's password manager.
Personal Microsoft federation uses a separate workforce-tenant registration named
`Wellness Client Login Dev`, client ID `0615ffd8-79b2-43b5-9d1c-162b8823904c`.
Its secret is owner-managed; record its expiry and rotate before expiry. This is NOT
the customer SPA ID or the customer API ID and must never replace either below.

Live discovery was read successfully on 17 September 2026:
- Metadata: `https://copihuewellnessclientsdev.ciamlogin.com/0a3841c6-b244-410d-821f-bbd9ccd1b5e2/v2.0/.well-known/openid-configuration`
- Issuer: `https://0a3841c6-b244-410d-821f-bbd9ccd1b5e2.ciamlogin.com/0a3841c6-b244-410d-821f-bbd9ccd1b5e2/v2.0`
- JWKS: `https://copihuewellnessclientsdev.ciamlogin.com/0a3841c6-b244-410d-821f-bbd9ccd1b5e2/discovery/v2.0/keys`

## Implementation and deployment checkpoint

`/client` and `/client/auth/callback` bootstrap customer MSAL independently, before
the general router. Workforce MSAL is not initialized on those paths. Client-to-staff
navigation uses a full page load to switch authentication contexts. Authorization code
with PKCE and sessionStorage are handled by MSAL; there is no browser client secret.
Silent-refresh iframe callbacks use the SDK redirect bridge. Callback response data is
removed before application UI loads. Failed authorization requires deliberate retry,
not an automatic redirect loop. No provider access/ID tokens are accepted by PHP.

`GET /api/v1/customer/auth/me` validates RS256 signature, immutable issuer/subject,
tenant, v2 format, lifetime, API audience, SPA authorized party and `access_as_client`.
The JWKS URL derives only from operator configuration. Separate per-tenant key caching
supports bounded unknown-key refresh. The endpoint returns only identity-verification
status, `onboarding_status: not_linked` and empty capabilities; no patient data, roles,
email matching, local user creation or identity persistence. See
[`customer-auth.openapi.yaml`](../api/customer-auth.openapi.yaml).

Add these PUBLIC configuration identifiers to the existing private `/wellness-api/.env`:

```dotenv
CUSTOMER_ENTRA_TENANT_ID=0a3841c6-b244-410d-821f-bbd9ccd1b5e2
CUSTOMER_ENTRA_SUBDOMAIN=copihuewellnessclientsdev
CUSTOMER_ENTRA_API_CLIENT_ID=08542bbb-09cc-4737-979b-ca61c7eec70d
CUSTOMER_ENTRA_SPA_CLIENT_ID=7a522317-d74f-4ccb-9805-8e4b912c02ab
```

Do not replace existing staff ENTRA variables, database settings, APP_KEY or other secrets.
For frontend builds supply the same four names prefixed with `VITE_`. All four are
required; without them the customer UI shows an unconfigured state. Release packages
must be built with these values and the existing public/portal URL settings. No federation
secret goes in the frontend or API environment. Both API pointers still share one backend.

Deploy the release's private API code and complete matching vendor bundle, preserving
the server `.env` and `var` runtime directory; deploy the portal bundle to
`/public_html/wellness-portal`. Do not mix Composer generated files between builds.
No SQL update is required for this identity-only release. The historical SQL scripts
bundled with full deployments are not instructions to rerun migrations already applied.
Ensure PHP can write `/wellness-api/var/cache`. Rollback: restore the previous portal and
private API packages; no data migration reversal is needed.

### Hosted acceptance (not yet completed)

1. Verify the customer SPA and API really reside in the new external tenant; compare their
   Directory tenant IDs if registration fails. Keep all workforce registrations unchanged.
2. Customer SPA redirect must be exactly `https://portal.copihue.ca/client/auth/callback`.
   Register `https://portal.copihue.ca/client` as an additional SPA redirect for the logout
   return, if not already registered. Do not enable implicit grants. For local testing add
   matching localhost callbacks separately; local Vite still needs a running/reachable PHP API.
3. In the separate personal-Microsoft federation registration's Web platform, retain the
   configured GUID callback and also add the documented domain-form callback:
   `https://copihuewellnessclientsdev.ciamlogin.com/copihuewellnessclientsdev.onmicrosoft.com/federation/oauth2`.
   Keep its credential only in the customer tenant's custom identity provider.
4. Keep Google OAuth in Testing; add intended test Google accounts under Audience/Test users
   if required. Do not publish or request Gmail/Calendar permissions for sign-in.
5. Open `https://portal.copihue.ca/client` in a private browser window. Test Google, personal
   Microsoft and email-code sign-up/sign-in separately. Each must return to the portal and
   show **Customer sign-in verified** and **not linked**. The hosted provider screen alone
   is not success. No booking, profile or clinical data should be accessible.
6. Test cancelled login, reload, sign out and sign in with another test account. Verify the
   customer request targets `/api/v1/customer/auth/me` on the portal host, not the public host.
7. Confirm existing superadmin/practitioner login, client management and mobile booking still
   work. Customer tokens must be rejected by `/api/v1/auth/me` and staff endpoints; staff
   tokens must be rejected by `/api/v1/customer/auth/me`. Never paste tokens or secrets in chat.
8. On errors share safe error code/correlation ID, not callback URLs carrying authorization
   codes. Provider federation (including issuer/claims mapping), consent and hosted PHP key
   retrieval need actual testing. Do not weaken validation to make a failing test pass.

Local checks: `php api/tests/customer-auth.php`, existing PHP tests, `npm test` and
`npm run test:build` inside Frontend. Windows PHP may need OPENSSL_CONF pointing to an
installed OpenSSL configuration for ephemeral test RSA keys (e.g. Git's usr/ssl/openssl.cnf).
No real identity tokens, user credentials, clinic fixtures or private keys are committed.

Remaining gates: provider end-to-end proof; approved record-claiming/recovery and dual-persona
rules; additive identity mapping migration; server-tracked session/revocation controls;
client profile/booking and reliable notifications. The proposed inactivity limits are NOT
enforced by this bearer-token proof and no real clinical access is enabled.

## Recommended provider

Use a separate Microsoft Entra External ID external tenant for customers. Microsoft
currently documents both Google federation and personal Microsoft account federation:

- [Google federation](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-google-federation-customers)
- [Personal Microsoft account federation](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-microsoft-accounts-federation-customers)
- [Create an external tenant](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-create-external-tenant-portal)
- [Billing and free-tier overview](https://learn.microsoft.com/en-us/entra/external-id/external-identities-pricing)

Google needs a Google Cloud OAuth application. Personal Microsoft accounts need a separate
Microsoft application configured as an external identity provider. Neither requires
customers to become staff or have Microsoft 365 licences. Provider support is documented,
but successful federation and API tokens must still be demonstrated in the chosen tenant.
Do not change the existing workforce application's account types or token trust settings.

The owner must approve tenant creation/selection, its region and any subscription linkage.
Check current billing limits and enabled features before linking a paid subscription;
do not assume the Microsoft 365 subscription covers this service. No paid add-ons are
authorized. Keep customer identity data minimal; clinical/appointment data stays in MySQL.

## Overall implementation sequence

1. Create/select the customer external tenant and record its tenant ID and verified
   authority. Create dedicated customer SPA and API registrations and a sign-up/sign-in
   user flow. Configure Google and personal Microsoft providers with owner-managed
   credentials. Keep secrets in provider configuration, never React, Git or chat.
2. Prove both providers using test accounts and authorization code with PKCE. Use a
   separate customer callback on the portal; register its final exact URI before testing.
   Request an access token for the customer API scope, not Google or Microsoft Graph.
3. Add isolated client routes and an authentication adapter that validates the configured
   issuer, audience, algorithm, signature, lifetime and scope. A customer token must never
   authenticate against staff endpoints or produce staff roles.
4. Store immutable issuer/subject identities separately from contact email. New/unlinked
   sign-ins initially receive no existing client-record access. Implement reviewed,
   single-use invitation claiming before exposing appointment/profile data. Resolve
   shared-family-email/dependent scope before changing user email uniqueness.
5. Test sign-in, rejection/denial, logout, refresh, expiry, wrong issuer/audience/scope,
   cross-role denial and record-ownership isolation. Define approved server-enforced session
   controls before clinical rollout. Keep real client booking disabled until onboarding and
   notification prerequisites are fulfilled.

## Configuration ownership

- The owner has selected and created the separate customer external tenant described above.
- Subscription linkage and region remain owner-managed; this release creates no Azure resources.
- Keep owner access to Google Cloud OAuth configuration and test accounts for hosted acceptance.

## Local verification for this checkpoint

On September 17, 2026, all 112 PHP checks and 22 browser checks passed, including
customer/staff token separation, invalid tokens, readable API failures, and the real MSAL
authorization-code request with PKCE. Browser identity responses are mocked; these tests
do not establish successful hosted provider federation.

This is a setup/proof runbook subordinate to MASTER_REQUIREMENTS.md and SYSTEM_DESIGN.md,
not a new master requirements document or a claim of production acceptance.
