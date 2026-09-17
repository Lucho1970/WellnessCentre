# R2 client sign-in proof — 17 September 2026

Status: owner approved External ID and reports the customer tenant created using an
existing subscription under their other login. Branch: `codex/client-sign-in`.
Customer tenant ID: `0a3841c6-b244-410d-821f-bbd9ccd1b5e2`.
Primary domain: `copihuewellnessclientsdev.onmicrosoft.com`.
These are owner-provided public configuration identifiers, not independently verified
resource state. Billing configuration and region have not been inspected by the agent.
No customer app credentials, database migration or runtime authentication changes have
been created by this checkpoint. Existing staff sign-in remains unchanged.

Next registration: `Wellness Client Portal Dev`, single-tenant SPA in the customer
tenant. Planned customer-only callback: `https://portal.copihue.ca/client/auth/callback`.
This callback is reserved for implementation; it is not working in the deployed app yet.
Do not create a SPA client secret or enable implicit grants.

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

## Implementation sequence after approval

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

## Approval / configuration required

- Approve Entra External ID as the customer identity provider and choose/create an external
  tenant (separate from the existing staff workforce tenant).
- Confirm available Azure subscription or trial path and deployment region before creation.
- Owner access to Google Cloud OAuth configuration and a personal Microsoft test account.

This is a setup/proof runbook subordinate to MASTER_REQUIREMENTS.md and SYSTEM_DESIGN.md,
not a new master requirements document or a claim that client sign-in is implemented.
