# Wellnexa practitioner Google and personal Microsoft sign-in

1 November 2026. Implementation branch: `feature/wellnexa-practitioner-signin`, based on
`0aec293` so the existing quality gates, pilot safeguards, request limits and deployment fixes
are retained. This branch does not merge or deploy itself. The separate blog-document task and
its PDF are unrelated and preserved. See the dated
[validation and control ledger](WELLNEXA_PRACTITIONER_SIGN_IN_VALIDATION_2026-11-01.md)
for the exact application revision, tests, limitations and all 38 control decisions.
The [live-test rollout and retirement runbook](WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md)
records the owner's subsequent approval of Wellnexa and two specific provider accounts,
the observed disabled hosted feature and the ordinary Inactive account test.

## User experience

The clinic portal presents separate practitioner and clinic workforce entry points when
external practitioner authentication is enabled. Wellnexa's joining page also links directly
to practitioner sign-in, independently of its application form.

| Entry | Purpose | Identity application |
| --- | --- | --- |
| `/staff/login` | Clinic employees and administrators | Existing workforce Microsoft application |
| `/staff/external` | Approved independent practitioners | Dedicated external practitioner SPA/API |
| `/staff/apply` | New application, review status and approved activation | Same dedicated practitioner SPA/API |
| `/client` | Client registration and booking | Existing separate client application |
| Central host `/admin/` | Explicitly authorized platform administrators | Existing workforce application and central grant |

With the social sign-in build gate enabled, the practitioner screen and anonymous application
screen offer **Continue with Google**, **Continue with personal Microsoft account**, and
**Choose another sign-in method**. A clinic-issued account is not required.
All three buttons open the configured practitioner provider picker without a provider hint.
The bilingual guidance asks users to choose Google or Microsoft account on the next screen;
the other-method option also supports an existing approved email-code account.
The application does not switch its authority or API trust on a provider error.

Use the same sign-in account and method after registration. Matching email addresses do not
link identities or migrate an existing workforce/client account. Clinic staff sign-in remains
Microsoft workforce login; selecting it explicitly clears practitioner/application intent.

Failed provider callbacks render a generic recovery message and the sign-in choices instead
of leaving an unhandled bootstrap error. A failed callback cannot select a cached account in
the rendered authentication bridge. Raw provider diagnostics are not displayed. An authenticated
applicant denied workspace access can open their application or sign out. Application and
invitation callbacks continue to return to their original workflow.

## Registration through approval

1. Sign in using an enabled provider on `/staff/apply`. This authenticates an account; it does
   not create a staff membership, grant records access or start a trial.
2. Read the clinic's published joining terms, provide the requested professional/contact
   details, confirm their accuracy and explicitly accept that terms version. Patient records,
   identity documents and payment details do not belong in the application.
3. Submission records the exact broker-issued issuer/subject and consent version. The applicant
   sees their verification code and status, with no clinical workspace. The operator reviews
   the application on the central host and verifies the code through an independent channel.
4. Central approval checks the current explicit operator grant, clinic/location and application
   version. Approval itself still grants no workspace access.
5. When the independently gated activation is ready, the applicant accepts the current terms
   and activates. Existing trial/history rules remain enforced. Eligible new activation starts
   six calendar months free; it does not collect money or assign SMS funding.
6. The active exact identity/membership/local account receives only practitioner access. Each
   request still checks the clinic and current authority. Revocation denies the same otherwise
   valid token, including a replay of the old activation request.

Existing accounts are not silently rebound. A second identity with the same contact email
cannot inherit an approved membership. Use the established reviewed account-link/migration
process if one is separately approved. Keep workforce administrator recovery available.

## Identity boundary and routing

Both providers federate through the existing dedicated Entra External ID practitioner flow.
The PHP API continues to accept only an Entra-signed staff access token with its exact
configured issuer, tenant, staff API audience, authorized staff SPA and `access_as_staff` scope.
Direct Google/MSA tokens, client tokens and workforce tokens do not become practitioner
application credentials. Provider hints and upstream `idp` claims grant no privilege.

Live checks after the provider save still rejected the documented `domain_hint=google`
(`AADSTS90023`) and `domain_hint=login.live.com` (`AADSTS90072`) shortcuts. The application
therefore sends **no provider hint** for either button and uses the working provider picker.
Request parameters cannot supply another hint, callback or authority. Practitioner
interactive requests use `prompt=login` and clear the active-account
hint before starting, avoiding the workforce remembered-account picker. A failed navigation
start restores the previous active account. Workforce requests retain `prompt=select_account`.
The existing MSAL code/PKCE/nonce/state handling is retained; this is not a new token verifier.

Microsoft documents [Google federation](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-google-federation-customers),
[personal Microsoft account OIDC federation](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-microsoft-accounts-federation-customers)
and [issuer acceleration](https://learn.microsoft.com/en-us/entra/external-id/customers/concept-authentication-methods-customers#issuer-acceleration).
These describe provider capabilities, not evidence that this tenant is configured or that a
particular account has passed live sign-in.

## Live provider configuration and remaining verification

The [7 October rollout record](GOOGLE_PRACTITIONER_ROLLOUT.md) identifies the external tenant
and dedicated staff registrations. Treat these as recorded identifiers to inspect, not current
acceptance. The cached CLI authorization for this tenant failed Conditional Access during this
task. No policy was weakened and no device-code workaround was attempted.

A public entry check on 1 November reached the dedicated staff user flow with the Wellnexa
callback and displayed Google. The documented personal Microsoft issuer-hint request returned
`AADSTS90072`, rather than the intended independent account federation. This confirms a live
entry failure; it does not by itself identify every missing/misassociated cloud setting.
No credentials were entered or application membership approved during that initial check.

After the user authenticated the admin browser and explicitly approved saving the change,
the existing **Microsoft account** OIDC provider was added to
**WellnessPractitionersSignUpSignIn** in tenant
`0a3841c6-b244-410d-821f-bbd9ccd1b5e2`. Before this change it had zero user-flow associations;
the flow selected only Google and email one-time codes. After Save, Google and Microsoft
account were selected and Save/Discard were disabled. A fresh public authorization request
for the dedicated staff SPA displayed both provider buttons. Email codes were retained.
The flow's Applications view contained only the dedicated staff SPA
`2ccf904c-2b78-4348-9acb-72773d931416`; the client and workforce applications were not changed.

The existing MSA provider uses broker client `0615ffd8-79b2-43b5-9d1c-162b8823904c`, consumer
discovery, `https://login.live.com`, code response and `openid profile email`. Its subject and
email mappings use the upstream claims; no verified-email constant was fabricated. No new
registration, secret, API grant or credential rotation was needed. Both public provider
buttons reached Entra's phone MFA enrollment screen with a cached session.
No phone number/code was supplied or enrollment completed. This
is partial routing evidence, not a completed login, policy review or hosted approval test.
Do not substitute B2B guest invitations for self-service practitioner registration.

In the correct external tenant, inspect the dedicated practitioner SPA, API and associated
user flow before changing anything. Do not alter the client flow or workforce recovery app.

1. Verify both staff registration IDs and the delegated `access_as_staff` permission. Add or
   verify the exact SPA callback `https://wellnexa.copihue.ca/staff/external` and the portal root
   logout return. Keep previously approved portal callbacks needed by other clinics. A SPA
   callback is different from the broker-to-provider Web callback below.
2. Verify the existing Google provider's credentials/callbacks with the official instructions,
   and select Google in the **practitioner** user flow. Reuse a verified provider instead of
   creating a duplicate; do not expose its secret in browser configuration or documents.
3. For personal Microsoft accounts, inspect for an existing suitable registration/provider.
   Reuse the inspected existing provider. If replacement is separately authorized, Microsoft's
   current MSA guide specifies a broker Web application supporting organizational and personal
   Microsoft accounts, with the exact federation callbacks described in its instructions.
   Keep consumer discovery for this personal-account federation; this does not widen the
   clinic workforce application's authority or the PHP API's trusted issuers.
   Use its client ID and confidential credential only inside Entra's provider configuration.
   Record the credential owner, expiry, secure custody and rotation procedure privately.
4. Configure the custom OIDC provider according to Microsoft's MSA guide: consumer discovery
   `https://login.microsoftonline.com/consumers/v2.0/.well-known/openid-configuration`, configured
   issuer `https://login.live.com`, authorization code response and `openid profile email`
   scopes. Verify subject/profile mappings and missing-email behavior; do not manufacture
   verified-email claims or use email to link application records. Select the provider in
   the practitioner user flow and associate the dedicated staff SPA with that flow.
5. Preserve the approved MFA and Conditional Access requirements. Independently verify actual
   enforcement for both provider types and administrator recovery. Do not disable a policy to
   make a test pass. A local `mfa_required` field is not evidence of provider enforcement.
6. Verify the provider picker and both selected-provider journeys on the actual browser/tenant.
   Keep hints omitted while acceleration fails. Any future shortcut needs a new live check
   before changing the application; do not substitute another authentication realm.

Upstream federation registration supplies identity authentication only. Do not grant Graph,
mail, clinical API or directory administrator permissions to that application. A new provider
does not approve a practitioner, authorize PHI processing or enable real-data use.

## Configuration and rollout

New public build setting in `Frontend/.env.example`:

```dotenv
VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED=false
```

Keep it false until both providers and the dedicated practitioner application are ready. It
controls the explicit provider buttons; it does not configure the cloud providers or authorize
API access. The existing unhinted external login remains available under its existing gates.
The flag has no effect when all external practitioner feature flags are off.

For the intended portal build, configure the verified `VITE_STAFF_EXTERNAL_*` identifiers and
enable `VITE_STAFF_EXTERNAL_AUTH_ENABLED`. Enable `VITE_PRACTITIONER_APPLICATIONS_ENABLED` for
the application page and, after provider verification, the new social flag for both buttons.
The private API needs matching `STAFF_EXTERNAL_*`, `STAFF_EXTERNAL_AUTH_ENABLED` and the
existing application/activation flags. Publish intake/terms in central administration and
enable activation separately only after its privacy/operational acceptance. Do not enable
invitations merely to expose a practitioner sign-in link.

The separate Wellnexa website build accepts `VITE_STAFF_EXTERNAL_AUTH_ENABLED=true` to show
the returning-practitioner link when intake is closed. Its existing application flag continues
to control the application link. Browser build settings are public; changing only the API's
private `.env` does not rebuild either website. Build gates are shared portal capabilities;
the trusted host and per-clinic membership/settings still enforce clinic scope.

No new schema migration, runtime package, API permission or backend token trust is introduced
by the source changes. Existing application migrations through 055 and the current maintained
baseline remain prerequisites. No package, hosted migration, production deployment, real
application approval or provider credential was created by the local implementation.

Before live tester delivery, follow [pilot controls](PILOT_SAFETY_CONTROLS.md). Use explicitly
approved synthetic identities and records, private allowlists and blocked outbound messages
unless deliberately accepted. Never infer that an existing database is synthetic. Identity
provider registration/MFA emails are outside the application's outbound-message guard.

## Hosted acceptance and rollback

Use separate approved Google and personal Microsoft test identities. Exercise new registration,
return login, cancellation/rejection, MFA, missing claims, closed intake, pending staff denial,
independent central approval, explicit activation, six-month trial, portal access, wrong-clinic
denial, same-email/different-identity denial, revocation and administrator recovery. Record the
actual tenant/apps/flow, artifact SHA/configuration, results, owners and approval. Local signed
token fixtures do not authenticate a real Google/MSA user.

Rollback requires matching reviewed source and frontend rebuilds. Turning off the new social
flag removes the explicit provider shortcuts while retaining the existing provider picker.
Do not disable durable external authentication for active practitioners as a convenience:
that can lock them out. Preserve memberships, trials, consent and audit history. Restore
approved provider/callback settings through a deliberate operator change; do not delete or
recreate identities to bypass an application conflict. No data/schema downgrade is needed.
For the 1 November cloud change specifically, an approved rollback would deselect only
Microsoft account in the practitioner flow and Save, retaining Google/email codes and all
identities. That removes future MSA authentication through this flow; it does not revoke
already issued tokens. Apply the approved session/membership revocation process separately
if required. No cloud rollback was executed or tested.

RELEASE STATUS: NOT READY FOR RELEASE
