# Practitioner invitation pilot

**Current rollout decision (7 October 2026):** Esther will create a new account using her own Google identity; no existing-user binding or migration SQL is needed. Follow [Google practitioner rollout](GOOGLE_PRACTITIONER_ROLLOUT.md). Keep her old account available until the new account is accepted and tested. Earlier migration instructions below remain an optional maintenance path, not the chosen pilot.

This implements the next step after the selected staff membership pilot. It is disabled by default and has not been deployed. The owner confirmed on 5 October 2026 that the recovery administrator and Esther (local user 2) can sign in and that Esther's existing workflow appears unchanged. That confirms the basic hosted pilot; wrong-host and revocation acceptance still need explicit checks.

The invitation pilot prepares separate **Entra External ID staff API and SPA registrations**, provisionally in the existing external tenant. It lets a new practitioner register through a staff user flow without first creating a workforce account. The provider setup is still required. Existing workforce login, including administration and Esther, remains available at `/staff/login`. Existing client registrations cannot be reused for staff authentication.

## Workflow

When enabled, a Super Admin opens **Staff access → Practitioner invitations**, enters the intended recipient's email, name, discipline and a bookable clinic location, then creates an invitation. The link is displayed once for manual delivery and expires in 48 hours. The application does not send an email in this slice. Only the SHA-256 token hash is stored; the browser captures the secret URL fragment into session storage and removes it from the address bar before signing in.

The recipient follows `/staff/invitation#token=…`, signs in or registers through the dedicated staff app, and submits their name. A validated staff API access token binds one pending claim to its exact adapter, issuer and subject. No user, role or membership is created yet. Repeated submission by that same identity returns the same pending result; a different claimant is denied.

The recipient receives a verification code. The administrator contacts the intended recipient through a known channel, confirms that code, then reviews and approves the claim. A matching email or self-entered name is never identity proof. Approval atomically creates the active product identity, local staff account, practitioner record, location association, practitioner role and active membership. The public profile remains unpublished, no services or availability are assigned, and no elevated permissions are granted. Configure those separately before offering bookings.

An optional explicit existing local staff user ID can bind an active, practitioner-only account that has no membership. It is never chosen by matching email. Accounts with elevated roles, inactive accounts and any existing membership require a separate reviewed migration. **Esther already has a workforce membership and cannot be rebound by this invitation flow.** Her identity migration is a later step that must preserve user 2 and test rollback.

## Trust and authorization

- Invitations can grant practitioner access only, and only a current Super Admin in the selected clinic can create, revoke or approve them. Approval rechecks the inviter, location and local account.
- Staff tokens must be signed RS256 access tokens with the configured External ID issuer, tenant, staff API audience, dedicated staff SPA authorized party, version 2.0, expiry and `access_as_staff` scope. Client tokens, ID tokens, other apps and unknown signatures are rejected.
- Authentication selects a handler using an untrusted audience hint, then fully validates the selected token. It never trusts the hint as authorization.
- Every authenticated staff request requires an active exact identity, active clinic membership, active local staff account and matching clinic. External staff roles are limited to the local practitioner role. Optional local practitioner permissions are limited to scheduling for others, adding clients and approving On-Site service areas; new invitations grant none.
- Revoked or pending memberships and inactive identities are never reactivated by approval. Duplicate bindings and concurrent uniqueness conflicts fail and roll back. Disable the local account to revoke both legacy and membership access during transition.
- Audit events record invitation/entity IDs and approval user/membership IDs, without raw tokens, provider subjects or recipient details in metadata. The admin list contains recipient details needed for review; access is restricted and responses are not cached.

## Configuration and provider setup

Apply migration `api/database/migrations/033_staff_invitations.sql` after a backup and disposable-database rehearsal. Migrations 031 and 032 and the matching previous source are prerequisites. Do not rerun the full schema on an existing database.

Copy the settings from `api/staff-invitations.env.example` into the **shared private API `.env`**, outside the public document roots. Keep `STAFF_INVITATIONS_ENABLED=false` during upload and setup. Configure the tenant GUID, tenant subdomain and two new app IDs. Do not reuse the customer or workforce app registrations. These backend settings are read at runtime; no frontend secret is needed.

In the External ID tenant, register a dedicated staff API exposing `api://STAFF_API_ID/access_as_staff`, configure version 2 access tokens, and grant that delegated permission to a separate staff SPA. Register each exact SPA callback, such as `https://willowwellness.copihue.ca/staff/external`, plus the portal root logout return. Associate that SPA with its staff sign-up/sign-in user flow, configure the intended sign-in providers, grant consent as required, and enforce the approved staff MFA policy in Entra. The local `mfa_required` field does not enforce provider MFA. Test issuer, audience, authorized party and scope with the actual provider before enabling the pilot. Do not change the customer app's permissions or user flow as a shortcut.

Frontend settings are in `Frontend/.env.example`: `VITE_STAFF_INVITATIONS_ENABLED`, `VITE_STAFF_EXTERNAL_TENANT_ID`, `VITE_STAFF_EXTERNAL_SUBDOMAIN`, `VITE_STAFF_EXTERNAL_API_CLIENT_ID` and `VITE_STAFF_EXTERNAL_SPA_CLIENT_ID`. They are public build-time configuration and require a rebuild. Keep the frontend flag false until setup is complete. The guest landing offers a separate **Invited practitioner sign-in** link when enabled. `/staff/login` explicitly selects workforce authentication; `/staff/external` selects the dedicated invited-staff app. Customer sign-in remains under `/client`.

Microsoft references: [associate an application with a user flow](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-user-flow-add-application) and [External ID external-tenant overview](https://learn.microsoft.com/en-us/entra/external-id/customers/overview-customers-ciam).

## API contract

| Method and path, under `/api/v1` | Body / result |
|---|---|
| `GET /admin/staff-invitations` | Super Admin; latest 50 clinic invitations, review status and claim verification code. No raw invitation token or identity subject. |
| `POST /admin/staff-invitations` | `recipient_email`, `given_name`, `family_name`, `discipline`, positive `location_id`, optional `existing_user_id`; returns ID, one-time token and expiry. Only practitioner role accepted. |
| `POST /staff-invitations/claim` | Dedicated external staff bearer token plus `token`, `claimant_name`; returns pending status and verification code. Does not grant staff access. |
| `POST /admin/staff-invitations/{id}/approve` | Super Admin plus `recipient_verified: true`, exact `verification_code`; returns local user ID and membership ID. |
| `POST /admin/staff-invitations/{id}/revoke` | Super Admin; revokes an unused invitation. Accepted invitations require account deactivation instead. |

## Validation and rollout acceptance

Local checks cover signed-token isolation, role restriction, disabled gates, claim idempotency, expired/revoked/accepted denial, wrong clinic, duplicate membership, inactive identity, manual identity verification and whole-transaction rollback. PDO-double checks do not establish MySQL locking or constraint behavior. Browser checks use test-only network substitutes and do not establish real provider behavior.

Implementation checkpoint: both frontend surfaces build and bilingual/type checks pass; all 43 top-level PHP test scripts pass; 4 invitation browser checks, 19 focused staff/client authentication regressions and 13 release-bundle smoke checks pass. New signed-token tests contain 29 checks, invitation policy/transaction tests contain 29 checks, and the resolver adds 3 external-role/permission checks. The MySQL rehearsal was attempted and could not connect to the unavailable local service.

The real database rehearsal is `api/tests/integration/staff-invitations.php`. It connects only to `127.0.0.1` (default port 13317), loads no `.env`, creates a random synthetic database and retains it for inspection. Set `STAFF_INVITATION_TEST_ALLOW_CREATE=true`; optional local credentials use `STAFF_INVITATION_TEST_USER`, `STAFF_INVITATION_TEST_PASSWORD` and `STAFF_INVITATION_TEST_PORT`. Run `php api/tests/integration/staff-invitations.php`. On 7 October 2026, the portable MariaDB 11.4.8 rehearsal passed all 20 real SQL checks after updating the fixture to reuse the schema-seeded roles. Use `scripts/test-recurring-sql.ps1` with `-IntegrationTest staff-invitations.php` to repeat it. Simultaneous claim/approval race acceptance and hosted database/provider acceptance remain outstanding.

Before enabling on the hosted test clinic, complete the database rehearsal, real staff user-flow registration and MFA checks, client-token denial, wrong-host denial, inactive/revoked account denial, invitation expiry/revocation, approval retry and recovery administrator/Esther regression. Start with a synthetic new practitioner; do not migrate Esther automatically. Upload the matching private API source and rebuilt portal only after these prerequisites are reviewed. No hosted deployment or invitation delivery was performed during development.

Rollback: revoke unused invitations, deactivate newly onboarded local accounts if their access must stop, and disable both pilot flags. `STAFF_INVITATIONS_ENABLED=false` disables external staff authentication as well as invitation operations. Keep identity/membership/audit rows for review and retain workforce recovery login. Never enable legacy fallback or delete memberships to bypass revocation.

Next slices: supervised Esther identity migration, automatic invitation email delivery with retry/reissue handling, stronger recovery and provider acceptance, then broader staff roles and multi-clinic memberships. The planned **View appointments** button in admin client details remains a separate backlog item.

Esther's existing workforce membership can be switched through the separate [SQL identity migration](ESTHER_IDENTITY_MIGRATION.md), retaining local user 2. This does not enable the external staff provider or bypass claim verification. Use a pending new invitation without an existing user ID, then the reviewed maintenance SQL instead of normal approval. Provider setup/rebuild and hosted acceptance remain required.


## Approval conflict diagnostics

The UI previously collapsed specific approval failures into a generic HTTP 409 message. On `bugs/practitioner-approval-errors`, English/French messages now preserve the API error code for existing-email, membership, binding, role, invitation-state and related approval conflicts. No account-linking rule is relaxed and a failed approval remains pending. Both frontend builds and seven invitation browser checks passed, including specific conflict messages, retained verification input and the normal approval flow. This frontend fix has not been deployed.

To diagnose a hosted failure, open browser Developer Tools **before** retrying approval. In Network, enable recording, choose All or Fetch/XHR, clear text filters and click Approve practitioner again. Select the failed request ending in `/approve`, then copy only its Response JSON (`error.code`, `error.message`, `error.correlation_id`). Do not share Authorization headers, bearer tokens or an unredacted HAR. A screenshot of the generic message alone cannot distinguish an existing email from other conflicts. Do not change account IDs, delete records or bypass verification based solely on that screenshot.
