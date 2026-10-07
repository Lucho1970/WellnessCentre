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

Keep `STAFF_INVITATIONS_ENABLED=false` in the shared private `/wellness-api/.env` during setup. Set `STAFF_EXTERNAL_TENANT_ID`, `STAFF_EXTERNAL_SUBDOMAIN`, `STAFF_EXTERNAL_API_CLIENT_ID` and `STAFF_EXTERNAL_SPA_CLIENT_ID` to the verified staff configuration. Preserve unrelated private settings.

Build the portal with the corresponding `VITE_STAFF_EXTERNAL_*` values and `VITE_STAFF_INVITATIONS_ENABLED=true`. These are public build-time settings; changing the hosted private `.env` alone does not update the browser. Confirm migration 033 is present; do not rerun the full schema. Upload matching private API and portal packages, then enable `STAFF_INVITATIONS_ENABLED=true` for hosted acceptance. Record the source revision and configuration in the release manifest. No enabled release should be prepared using guessed staff application IDs.

## Esther's new account

1. As Super Admin, open **Staff access → Practitioner invitations**. Enter Esther's intended Gmail address, name, discipline and clinic location. Leave **existing user ID** empty. Save the one-time invitation link for manual delivery; it expires in 48 hours.
2. Esther opens that link, selects Google, uses her own account and submits the claim. Creating or signing into the provider account alone does not grant practitioner access.
3. Verify her claim code through a known channel. Approve the claim normally in the admin screen. This creates a new local user and practitioner membership; it does not retain user 2.
4. Configure her new services, availability and public profile. Check her own appointments and client access; confirm the new account has no elevated administrator permissions.
5. After the new login works, deactivate her old local account through the normal admin flow. Preserve identity, membership and audit history. Keep a working workforce recovery administrator.

No invitation has been delivered, old account deactivated, hosted data deleted or cloud provider configuration changed by preparing this guide.

## Acceptance and rollback

Run the synthetic local database rehearsal with:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest staff-invitations.php
```

The runner starts a separate loopback-only database, reads no application `.env`, restores process environment variables and stops the server after completion. It tests actual SQL constraints and transactional behavior; it does not establish real Google/Entra sign-in or simultaneous claim/approval races.

Hosted acceptance must cover Google sign-in and MFA, pending access denial, verified approval, approval retry, expired/revoked invitations, client-token denial, wrong-clinic denial and administrator recovery. Real provider tokens must satisfy the configured issuer, tenant, API audience, dedicated SPA authorized party and `access_as_staff` scope.

To stop the pilot, disable both invitation flags, revoke unused invitations and deactivate newly onboarded local users whose access must stop. Keep audit and membership rows. Do not remove memberships or enable legacy fallback to bypass revocation.

Local checkpoint, 7 October 2026: 20 real SQL invitation checks passed on portable MariaDB 11.4.8, all 48 top-level PHP test scripts passed, and all four invitation browser tests passed using test authentication/network substitutes. The existing CLI session cannot access the External ID tenant; administrator sign-in is required before creating the staff applications. No enabled deployment package has been generated.
