# Wellnexa practitioner live-test rollout and account retirement

1 November 2026. Continuation on `feature/wellnexa-practitioner-signin`. The owner explicitly
approved using **Wellnexa itself** and one named Google and one named personal Microsoft
account for synthetic practitioner testing. Their addresses remain in the private chat rather
than committed fixtures, release files or screenshots. Use labels **Wellnexa Test Google** and
**Wellnexa Test Microsoft** for the dummy professional applications; never claim actual
qualifications, publish the test profiles, book patients or add SMS funding.

This authorizes those test records, not unrelated patient-record access, public launch,
deletion, charging, new identity permissions or a blanket production deployment. The owner
wants the same ordinary account-disable process available for a practitioner who leaves.

## Observed hosted prerequisite

The anonymous `GET https://wellnexa.copihue.ca/api/v1/public/practitioner-applications`
returned HTTP 200 with `available=false`, `enabled=false`, `activation_available=false`.
Both `/staff/apply` and `/staff/external` showed the existing workforce Staff portal rather
than the dedicated practitioner entry. No practitioner application was submitted, approved,
activated or disabled by this check. No private database credentials or patient records were
read. The Entra provider association saved earlier does not rebuild or enable this website.

The current application implementation and earlier tests are described in the
[sign-in guide](WELLNEXA_PRACTITIONER_SIGN_IN.md) and its
[38-control evidence ledger](WELLNEXA_PRACTITIONER_SIGN_IN_VALIDATION_2026-11-01.md).
Full hosted acceptance is still pending, including the exact hosted revision/configuration,
schema history, scoped roles, backup/rollback and approved session/provider controls.

## Reviewable rollout configuration

Prepare an artifact from committed source using the existing full builder and verifier.
These are public build identifiers/settings, not credentials. Set them only in the build
process and restore the previous process values afterward; leave private environment files
unchanged. Preserve the existing workforce and client configuration.

```dotenv
VITE_STAFF_EXTERNAL_AUTH_ENABLED=true
VITE_PRACTITIONER_APPLICATIONS_ENABLED=true
VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED=true
VITE_STAFF_INVITATIONS_ENABLED=false
VITE_STAFF_EXTERNAL_TENANT_ID=0a3841c6-b244-410d-821f-bbd9ccd1b5e2
VITE_STAFF_EXTERNAL_SUBDOMAIN=copihuewellnessclientsdev
VITE_STAFF_EXTERNAL_API_CLIENT_ID=28c7d80a-53fc-45a0-8653-2f5b7d646087
VITE_STAFF_EXTERNAL_SPA_CLIENT_ID=2ccf904c-2b78-4348-9acb-72773d931416
```

```powershell
./scripts/build-current-deployment.ps1 -ReleaseName 2026-11-01-practitioner-pilot
./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/2026-11-01-practitioner-pilot
```

The shared portal's upload target is `/public_html/wellness-common-portal`, which serves
**both clinic hosts**. Central review uses `/public_html/wellness-portal/admin/`; matching
private API source belongs in `/wellness-api`, and the Wellnexa website in
`/public_html/wellnexa.ca`. A full bundle is a review artifact, not an instruction to upload
every archive. Confirm current/previous hosted files before selecting the actual upload set;
do not overwrite unrelated public sites or enable the optional mail bridge for this test.

The operator must verify the private API's current configuration before proposing changes:
`CLINIC_MANAGEMENT_ENABLED`, `APPLICATION_ADMIN_ENABLED`, `PRACTITIONER_APPLICATIONS_ENABLED`,
`STAFF_EXTERNAL_AUTH_ENABLED` and the matching `STAFF_EXTERNAL_*` identifiers. Central billing
configuration may be needed to choose an existing approved plan. Activation additionally
requires `PRACTITIONER_APPLICATION_ACTIVATION_ENABLED` and Wellnexa's own activation setting.
Keep other clinics' intake/activation closed. No new schema migration is introduced by the
sign-in source; check existing migration history rather than replaying SQL or importing a
schema. No Graph/mail permission or new app/secret is needed.

Before any upload or setting change, record the exact proposed scope, source/artifact hashes,
backup, previous settings and rollback; obtain the required deployment/configuration approval
under [AGENTS.md REL-05](../AGENTS.md). Apply the
[pilot controls](PILOT_SAFETY_CONTROLS.md), preserving existing approved operator identities
and normal authorization. Pilot email allowlisting is not an identity or membership grant.
Never turn off a pilot, membership, consent or MFA restriction to make a test pass. Opening
the intake is not automatically restricted to these two email addresses; verify the actual
approved-identity gate first. Application email/SMS dispatch remains separately controlled.

## Live rehearsal after rollout acceptance

1. Recheck `/staff/login` remains workforce-only and `/staff/apply` uses the dedicated external
   authority, staff SPA/API and same-host callback. Confirm Google/MSA/email-code picker.
2. Have the owner sign in using each approved account and complete passwords/MFA themselves.
   Stop on account conflicts or missing claims; never link accounts by matching email or
   invite them into the workforce tenant as a workaround.
3. Confirm no existing application/membership is silently reused. Submit only the labelled
   dummy application after the owner reviews the actual displayed joining terms. Record
   the nonsecret application reference privately; do not commit identifying screenshots.
4. Verify pending/approved applicants receive no workspace. Through the existing authorized
   central operator, review the exact application and verification code, recording that
   credentials are synthetic and the profile must remain unpublished. No test account gains
   central/clinic administrator authority. Obtain action-time access/terms confirmations
   where the UI workflow requires them.
5. Activate only the reviewed synthetic application after the separate activation gate is
   accepted. Verify practitioner-only access and one six-calendar-month trial. Do not browse
   unrelated client lists or attach the account to an actual patient.
6. Verify returning sign-in, logout and denial recovery independently for both providers.
   Complete the normal disable test below and record its result, not just a successful login.

## Normal disable and departure checklist

Use an existing authorized clinic super administrator in **Staff access**. Select the exact
test account, choose **Edit**, set **Status = Inactive**, then **Save changes**. Do this for
each provider's account after the test, retaining application, identity, membership, audit
and trial history. Do not delete accounts or manipulate database rows as test cleanup.

Verify with the same already authenticated practitioner session that the next protected
request is denied. Return login and replayed activation must not restore workspace access.
The application-status page may remain accessible to that identity, but must report no
available practitioner workspace. This is clinic application access removal, not closure
of the person's Google or Microsoft account. Existing downloaded/rendered data requires the
approved logout/shared-device procedure.

Staff Inactive is **not evidence of complete operational offboarding**. Separately review
public profile publication, bookable services/availability, future appointments and safe
handoff, funded SMS membership/phone-number assignment, pending notifications, subscription
renewal/final balances and any clinic-specific access. Preserve outstanding costs, uncertain
provider outcomes, clinical/audit/consent records and retention/legal holds. Do not cancel
real appointments, release a paid number or collect/refund money during this authentication
test. Those require their actual approved departure process and scope.

The synthetic integration suite now exercises the ordinary `AdminService::updateStaff`
method used by the Staff access screen for both brokered provider identities. It checks
normal listing, foreign-clinic and practitioner denials, Inactive-token denial, unavailable
workspace, activation-replay denial, retained application/trial history, audit evidence and
explicit reactivation without a new trial. The existing hard membership-revocation checks
remain. This is local evidence; UI/hosted offboarding is still unverified.

## Rollback and acceptance limits

Before an approved rollout, back up the actual affected files and private configuration;
retain old hashed assets. Restore matching previous files/settings if needed, preserving
new application/audit/trial records. Do not restore an older database over newer records or
drop tables as rollback. Closing intake does not revoke active practitioner access; use
the normal Inactive action for the two test users. Keep workforce recovery and existing
legitimate accounts available. No hosted rollout or test-account mutation has been performed
by this preparation.

Owner roles remain unassigned unless separately recorded: hosting/release operator for
backup and deployment; clinic super administrator for scoped account changes; identity
administrator and account owner for provider/MFA; privacy/security/custodian for the remaining
real-data/session/vendor gates. The existing dependency-tooling and broader release findings
in the 38-control ledger are not waived by the owner's selection of a live test host.

## Prepared artifact and local acceptance

Prepared review directory: `deployments/2026-11-01-practitioner-pilot/`, with a same-named
outer ZIP. Package/application source: **`6808300d02a9ea37a7455c813d6e7d787d05906a`**;
runtime application code is unchanged from `f2e3157`. The new test/runbook commits do not
change the production API, schema, dependency locks, identity grants or provider endpoints.
The manifest explicitly records enabled browser flags and that hosted settings/accounts
remain unchanged; the current root README points to this runbook rather than presenting
the historical 29 October browser flag state as current. All seven archives are cumulative
review material; the actual upload set still needs deployed-revision/preflight review.

The existing full builder passed canonical Frontend lint/content/2,532 translations/strict
TypeScript/all three builds, exact locked production Composer install and Wellnexa build.
The archive verifier passed **237 checks**, **seven ZIPs / 2,172 entries**, and signature-scanned
**2,089 text entries**. Its single pinned public dependency documentation-key fixture remains
the previously reviewed JWT README; no application secret was found by that bounded scan.
No private environment or test-account addresses are included.

Both `npm ci` restores passed with unchanged lock hashes. Frontend's install reported the
existing esbuild postinstall approval warning; no install policy was changed. Current audits:
Frontend full audit exits 1 with **11 moderate build-tree findings**, runtime audit exits 0
with zero findings, Wellnexa audit exits 0 with zero findings. Composer validation/audit and
local non-dev platform checks passed. Large-bundle notices, four existing Wellnexa lint
warnings and terminal color-precedence warnings remain documented, not suppressed.

`Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` passed
**15/15** against the existing default-off output (18.4 s). With process
`BUILD_PRACTITIONER_SIGN_IN=1`, the same command passed **15/15** against this enabled artifact
(22.7 s). The new check verifies workforce separation, both visible provider choices and the
real compiled MSAL authorization request's exact staff SPA/API scope, callback and absence of
rejected hints. Broker metadata/final navigation are intercepted; it authenticates no real
account. Its synthetic screenshot was inspected and is included as a preview.

`Frontend: npx playwright test --config playwright.applications.config.ts --reporter=line`
initially passed 14/15: the first anonymous mobile page lacked its button before the existing
five-second assertion elapsed. The retained review found no page error or failed HTTP status;
the development staff-bootstrap module took about 2.3 seconds. Cold-start loading is a possible
explanation, not a proven root cause. An unchanged complete rerun passed **15/15** (41.4 s),
with no timeout, assertion, retry or product change to accommodate the failure.

`Wellnexa: npm run lint` passed with its four existing warnings;
`npm run test:browser -- --reporter=line` passed **24/24** (33.1 s), and
`npx playwright test --config playwright.applications.config.ts --reporter=line` passed
**1/1** (6.0 s). Final browser coverage here is **55 distinct tests** with an additional
15-case default/enabled build execution; the initial failed run is separately recorded.
The 285-case default Frontend suite, central-admin and invitation suites from the earlier
source checkpoint were not rerun for these test/runbook-only source additions. Hosted
provider/MFA, account approval/activation/disable, DB history/backup/headers and actual
clinical-record boundaries are still untested on Wellnexa.

The normal Inactive SQL/HTTP acceptance, original OpenSSL environment failures and final
**36 + 119 checks on each database engine** are recorded in the linked 38-control ledger.
No passing local test authorizes the live deployment or closes that ledger's remaining gates.

RELEASE STATUS: NOT READY FOR RELEASE
