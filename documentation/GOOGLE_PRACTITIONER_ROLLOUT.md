# Google practitioner pilot: a new Esther account

The owner chose a fresh practitioner account on 7 October 2026. Existing clients are test records; no identity or client-data migration is required. Do not run the Esther identity migration SQL for this rollout. Do not delete test records as part of authentication setup.

## Provider configuration

The existing client External ID tenant is **Wellness Centre Clients Dev**, tenant ID `0a3841c6-b244-410d-821f-bbd9ccd1b5e2`, subdomain `copihuewellnessclientsdev`. Confirm this directory in Entra before creating or changing applications. Workforce registrations belong to another tenant and must remain available for administrator recovery.

1. Inspect existing registrations before creating duplicates. Register a dedicated **Wellness Invited Staff API** with version 2 access tokens and a delegated `access_as_staff` scope under `api://<staff-api-app-id>`.
2. Register a separate **Wellness Invited Staff Portal** as a SPA. Add the exact callback `https://willowwellness.copihue.ca/staff/external`; configure the portal root as the logout return. Grant only the delegated staff API scope to this SPA and configure consent/preauthorization as required. No client secret is needed for these two applications.
3. Create a separate practitioner sign-up/sign-in user flow. Inspect the existing tenant Google identity provider, reuse it if configured, and enable Google in this flow. Associate only the dedicated staff SPA with this flow. Do not alter the client application's association or permissions.
4. Configure and test the approved MFA policy for the staff applications, preserving administrator recovery access. A local `mfa_required` database field does not enforce provider MFA. Check supported methods and subscription requirements before choosing a method.

Microsoft instructions: [Google federation](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-google-federation-customers), [user-flow application association](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-user-flow-add-application), and [External ID MFA](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-multifactor-authentication-customers).

## Matching release configuration

Dedicated apps configured in Entra on 7 October 2026:

| Setting | Value |
|---|---|
| Staff API app ID | `28c7d80a-53fc-45a0-8653-2f5b7d646087` |
| Staff SPA app ID | `2ccf904c-2b78-4348-9acb-72773d931416` |
| API scope | `api://28c7d80a-53fc-45a0-8653-2f5b7d646087/access_as_staff` |
| User flow | `WellnessPractitionersSignUpSignIn` |

The API manifest requests version 2 access tokens. The SPA has only the delegated staff scope, with tenant admin consent granted. Its registered SPA return URIs are the staff callback and portal root. Its separate user flow has Google and email one-time passcode authentication; it is associated only with the dedicated staff SPA. No new client secret was created. **SMS and the four Conditional Access policies below are enabled. Actual Google-and-SMS sign-in, provider token acceptance and a fresh administrator recovery sign-in remain pending. Upload the matching code before supervised hosted acceptance.**

Merge these values into the shared private API `.env`, keeping the flag false until the matching code is uploaded and supervised hosted acceptance is ready:

```dotenv
STAFF_INVITATIONS_ENABLED=false
STAFF_EXTERNAL_TENANT_ID=0a3841c6-b244-410d-821f-bbd9ccd1b5e2
STAFF_EXTERNAL_SUBDOMAIN=copihuewellnessclientsdev
STAFF_EXTERNAL_API_CLIENT_ID=28c7d80a-53fc-45a0-8653-2f5b7d646087
STAFF_EXTERNAL_SPA_CLIENT_ID=2ccf904c-2b78-4348-9acb-72773d931416
```

Keep `STAFF_INVITATIONS_ENABLED=false` in the shared private `/wellness-api/.env` during setup. Set `STAFF_EXTERNAL_TENANT_ID`, `STAFF_EXTERNAL_SUBDOMAIN`, `STAFF_EXTERNAL_API_CLIENT_ID` and `STAFF_EXTERNAL_SPA_CLIENT_ID` to the verified staff configuration. Preserve unrelated private settings.

Build the portal with the corresponding `VITE_STAFF_EXTERNAL_*` values and `VITE_STAFF_INVITATIONS_ENABLED=true`. These are public build-time settings; changing the hosted private `.env` alone does not update the browser. Confirm migration 033 is present; do not rerun the full schema. Upload matching private API and portal packages, then enable `STAFF_INVITATIONS_ENABLED=true` for hosted acceptance. Record the source revision and configuration in the release manifest. No enabled release should be prepared using guessed staff application IDs.

## Esther's new account

1. As Super Admin, open **Staff access → Practitioner invitations**. Enter Esther's intended Gmail address, name, discipline and clinic location. Leave **existing user ID** empty. Save the one-time invitation link for manual delivery; it expires in 48 hours.
2. Esther opens that link, selects Google, uses her own account and submits the claim. Creating or signing into the provider account alone does not grant practitioner access.
3. Verify her claim code through a known channel. Approve the claim normally in the admin screen. This creates a new local user and practitioner membership; it does not retain user 2.
4. Configure her new services, availability and public profile. Check her own appointments and client access; confirm the new account has no elevated administrator permissions.
5. After the new login works, deactivate her old local account through the normal admin flow. Preserve identity, membership and audit history. Keep a working workforce recovery administrator.

No invitation has been delivered, old account deactivated or hosted data deleted. The dedicated cloud applications, delegated scope, practitioner user flow and MFA policies have been configured; hosted activation and actual sign-in acceptance remain pending.

## Acceptance and rollback

Run the synthetic local database rehearsal with:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest staff-invitations.php
```

The runner starts a separate loopback-only database, reads no application `.env`, restores process environment variables and stops the server after completion. It tests actual SQL constraints and transactional behavior; it does not establish real Google/Entra sign-in or simultaneous claim/approval races.

Hosted acceptance must cover Google sign-in and MFA, pending access denial, verified approval, approval retry, expired/revoked invitations, client-token denial, wrong-clinic denial and administrator recovery. Real provider tokens must satisfy the configured issuer, tenant, API audience, dedicated SPA authorized party and `access_as_staff` scope.

To stop the pilot, disable both invitation flags, revoke unused invitations and deactivate newly onboarded local users whose access must stop. Keep audit and membership rows. Do not remove memberships or enable legacy fallback to bypass revocation.

Local checkpoint, 7 October 2026: 20 real SQL invitation checks passed on portable MariaDB 11.4.8, all 48 top-level PHP test scripts passed, and all four invitation browser tests passed using test authentication/network substitutes. Both frontend release builds and the built-site browser suite passed. All six release archive hashes and ZIP contents were verified; the portal contains the dedicated staff settings and scope, and private environment files are excluded.

The matching package is `deployments/google-practitioner-pilot-2026-10-07`, built from source commit `9905ca6ae8d9f894f79b80893446dea0e9c6aad4`. Its portal invitation flag is enabled at build time. The private hosted flag must remain false until provider MFA and supervised hosted acceptance are ready. No code has been uploaded to Netfirms.

## Provider MFA checkpoint

On 7 October 2026, the owner approved paid SMS, staff-app MFA and replacing security defaults with explicit Conditional Access protections. SMS was enabled for all users as an optional registered method for MFA/password reset, not primary sign-in. Entra confirmed the method was saved. Replacement policies were prepared before disabling security defaults, then activated in the order management, administrator, device code and staff MFA. Entra confirmed each save; a fresh policy inventory showed all four **On**.

| Policy | ID | Enabled scope and control |
|---|---|---|
| Wellness external tenant - management MFA | `7017dcb3-c9dd-4155-8ab3-982d282b5245` | All users, no exclusions; Microsoft Admin Portals and Azure Resource Manager (`797f4846-ba00-4fd7-ba43-dac1f8f63013`); require MFA. |
| Wellness external tenant - administrator MFA | `f244682d-fef3-4a0e-a51e-1ec80e0b669b` | The 16 administrator roles listed below, no exclusions; all resources; require MFA. |
| Wellness external tenant - block device code | `b81bc6c1-99d5-4816-8859-f787e3b60b0d` | All users, no exclusions; all resources; block device code authentication flow. Authentication transfer is not selected. |
| Wellness invited practitioners - require MFA | `ce35e6c4-63c9-4d60-af9e-dc52229d30c7` | All users except external-tenant administrator object `cc1aca34-47f5-4436-b0e8-0c43f2fb3a38`; only the dedicated staff API and SPA above; require MFA. |

The administrator roles are Global Administrator, Application Administrator, Authentication Administrator, Billing Administrator, Cloud Application Administrator, Conditional Access Administrator, Exchange Administrator, Helpdesk Administrator, Password Administrator, Privileged Authentication Administrator, Privileged Role Administrator, Security Administrator, SharePoint Administrator, User Administrator, Authentication Policy Administrator and Identity Governance Administrator. These follow the roles in Microsoft's [security defaults documentation](https://learn.microsoft.com/en-us/entra/fundamentals/security-defaults). The portal accepted the role policy; verify its real sign-in enforcement during hosted acceptance. Management MFA targets all users independently of role assignment.

Security defaults are now **Disabled**. This is not a complete reproduction of their baseline: the external tenant disables the Client apps condition, so no blanket legacy-client authentication block was configured. All-user MFA registration and adaptive prompting were not recreated. Normal client application sign-ins are not targeted by the dedicated staff MFA policy. The device code block does not block the browser authorization-code flow with PKCE. No emergency administrator accounts were created. Before the transition, the current administrator's successful Azure Resource Manager sign-in showed an MFA requirement satisfied by a claim in the home-tenant token. A fresh administrator sign-in after activation remains an acceptance requirement.

Entra displays an additional charge per SMS and a Microsoft-provided SMS/voice retirement notice dated 1 February 2027. Confirm the notice's applicability to this external tenant and plan a supported replacement before relying on SMS long term. Microsoft's [MFA concept documentation](https://learn.microsoft.com/en-us/entra/external-id/customers/concept-multifactor-authentication-customers) lists email OTP and SMS for external identity providers; [passkey registration](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-sign-in-with-passkey) is restricted to local password accounts and requires a custom domain and a credential-management implementation. These restrictions prevent substituting a passkey for Esther's Google login without changing the authentication design. Actual Google sign-in and MFA have not yet been verified. No SMS has been sent as part of this configuration.
