# Wellnexa practitioner live-test rollout and account retirement

## Latest checkpoint: entry pages live, both provider returns at pilot gate

The owner uploaded the prepared assets and three entry pages using Beyond Compare.
140 HTTPS asset checks matched 72 distinct files; nine entry/body checks matched after
separately diagnosing stale website-root cache and rechecking the normal canonical root.
Rendered workforce Microsoft entry remains separate from the independent provider picker.
Google initially reported AADSTS50011 for the missing Wellnexa staff SPA callback. The callback
appeared during authenticated admin inspection (the agent did not perform Save); a fresh
Google attempt and the owner-completed personal Microsoft attempt both returned to Wellnexa
and the existing pilot denial. Account addresses/subjects/tokens are not retained here.

The owner then requested allowed-practitioner management in central Admin. Work continues on
`feature/practitioner-pilot-admin`; see the [operator guide](PRACTITIONER_PILOT_ADMIN.md) and
[38-control/evidence ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md). New migration
058 is not approved/applied, the new feature/flag is not hosted, and neither account has
submitted/accepted terms or been approved/activated/retired. The host still has disabled
mail/SMS and closed application settings. Prior steps below are retained as their dated
observations; they do not supersede this current checkpoint.

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

## Authorized hosted database checkpoint

1 November 2026, continuation from source `686d071d9ccf6ea257c6364d8df691076e898c6e`.
The owner explicitly authorized the controlled pilot deployment and subsequently authorized
applying existing migrations **053, 054 and 055 in order after backup verification**. These
are separate approvals under REL-05; neither is approval for real-PHI acceptance, unrelated
schema changes, broader identity permissions or merging this branch.

The owner identified a private local SQL backup dated 31 October. A bounded local check
confirmed a nonempty **1,734,146-byte** phpMyAdmin export naming `wellness_centre`, containing
**120 CREATE TABLE statements**, 95 INSERT statements and a final COMMIT marker. No record
contents were displayed, copied into fixtures, uploaded or committed. This verifies export
structure, not successful restoration, completeness of every row or a current RPO/RTO.

Authenticated phpMyAdmin metadata checks found none of the 18 feature tables and no
`uq_billing_membership_clinic` index before execution. Hosted database version was
**MySQL 5.7.44**; the anonymous API reported **PHP 8.4.27**. Each exact versioned source script
was selected separately through the database-level importer with foreign-key checks enabled,
zero skipped queries, UTF-8 and SQL compatibility NONE:

| Existing source migration | Hosted result | Source SHA-256 |
| --- | --- | --- |
| `api/database/migrations/053_subscription_foundation.sql` | Success, 9 statements | `8c4086e3f8e77d9d60a6c372c6f0c003ebfd71e5bb81b136cb6a997446a5a6ed` |
| `api/database/migrations/054_billing_administration.sql` | Success, 10 statements | `07f57aa044148aeb1ad63171e1782cb23b4d8014b1734c9a020d68330b50a231` |
| `api/database/migrations/055_practitioner_applications.sql` | Success, 8 statements | `18bd46e69edaf59ecf7ff6ece13a65311e64571f6be1ddc78ca0ff7d026ffffd` |

Post-execution read-only checks confirmed **18/18 tables**, the unique membership index,
all three 054 subscription columns, and the new practitioner-applicant audit actor. Application
settings, applications and billing accounts each contained **zero rows**. No joining terms,
plans, prices, account enrollment, payment collection or SMS activation were seeded. No
migration 056/057, patient deletion or schema downgrade was performed.

The first post-execution verification SELECT failed with MySQL 1109 because its UNION
resolved unqualified application-table names in `information_schema`. Explicitly qualifying
the three count targets with `wellness_centre` corrected this read-only query; all seven
result checks then passed. The migrations themselves succeeded on their first execution
and were not replayed. phpMyAdmin also displayed its existing SQL-debug-info warning;
the separate import success banners and final SELECT results are the acceptance evidence.

Local ignored screenshots `hosted-migration-053.png`, `hosted-migration-054.png`,
`hosted-migration-055.png` and `hosted-migration-verification.png` under `.tmp/` record the
non-identifying schema results. They are not packaged or committed. The immutable prepared
artifact remains at application source `6808300`; no application source, dependency lock,
runtime grant or new endpoint changed during this database checkpoint.

The private server-side API rollback copy was submitted earlier at
`/wellness-rollbacks-2026-11-01-practitioner/wellness-api`. Netfirms still reports the copy
in progress; 12 immediate items are visible, but recursive completeness is not yet verified.
No live application archive has been uploaded or private configuration changed. Verify that
copy and back up the affected public directories before replacing files. Provider sign-in,
application submission/review/activation and ordinary Inactive account testing remain pending.

Rollback preserves the additive schema and any subsequent application/audit/trial rows;
do not drop the new tables or restore the older database over newer operational records.
Restore matching previous files/settings only after a verified file backup. The release
operator still owns backup restoration evidence and file rollout; the existing identity,
clinic, privacy/security/custodian and clinical acceptance responsibilities remain open.

## Direct file transfer after hosting-tool failure

Further 1 November 2026 operational evidence, following documentation commit `4c27518`:

- The owner supplied the browser download location. The current private `.env` download is
  **3,972 bytes**, with the same parsed settings as the project copy despite formatting
  differences. A separate copy was preserved outside the repository. Only approved public
  identifiers, flags and allowlist counts were printed; no credentials or identity subjects
  were displayed. Current configuration has pilot mode enabled, **two workforce identities**,
  empty email/SMS allowlists, and mail/SMS sending disabled.
- `php api/bin/pilot-preflight.php --env <private-downloaded-env>` passed, exit 0. A separate
  private candidate adds only `BILLING_ADMIN_ENABLED`, `PRACTITIONER_APPLICATIONS_ENABLED`,
  `PRACTITIONER_APPLICATION_ACTIVATION_ENABLED` and `STAFF_EXTERNAL_AUTH_ENABLED` as true;
  its local preflight also passed. **That candidate has not been uploaded.** Clinic settings,
  legal terms and exact external-practitioner pilot identities remain separate gates.
- The owner's private backup directory contains the API (2,109 files), shared portal
  (80 files), central portal (14 files) and Wellnexa website (11 files). Anonymous HTTPS
  reads of the three current HTML entry pages matched their backup copies. Initial Python
  HTTPS attempts returned URL errors; PowerShell used normal verified OS TLS successfully.
  No certificate validation was disabled. Restoration remains untested.
- The approved API ZIP uploaded to private `/wellness-api/wellness-api-private.zip` at
  100%. Archive Gateway selected that exact path and output `/wellness-api`, then reported
  **`Error unable to make temp directory`**. No successful extraction is claimed and it was
  not replayed. API health still returned HTTP 200; both clinics' public application metadata
  remained disabled/unavailable. No application account or private configuration changed.
- To assess the necessary transfer, all 2,057 candidate runtime/dependency files were compared
  against the private API backup: **2,049 byte-identical**, five identical after newline
  normalization, and three differing only in generated Composer classmaps/root revision.
  The classmaps add the already present `DiscoveryMapsClient`; the root reference changes
  from `f738e22` to `6808300`. No dependency version differs. This is backup comparison,
  not proof of the exact current hosted files. No API feature-source change is required by
  the practitioner sign-in patch; preserve the existing API and verify its behavior.
- A native bulk upload selected 62 changed shared-portal assets, but HTTP SHA-256 checks
  verified only **two**: `AddressEntry-DMuFyRIZ.js` and `api-CAzSqCHG.js`. The other 60 requests
  returned HTTP 200 with nonmatching bodies (SPA fallback is not file acceptance). The
  old entry page was preserved, so those two new assets are unreferenced. Do not switch
  HTML until all required assets pass integrity checks. Old hashed assets were not deleted.

The owner uses **Beyond Compare** to merge a ZIP's contents into the existing hosting
connection. Prepared ignored directory:
`deployments/2026-11-01-practitioner-direct-upload/`. Both ZIPs target `/public_html` and
contain the site's existing directory names, without another enclosing release directory:

| Transfer ZIP | Exact contents | SHA-256 |
| --- | --- | --- |
| `01-assets.zip` | 64 changed JS assets across `wellness-common-portal/assets`, `wellness-portal/admin/assets` and `wellnexa.ca/assets`; two may already compare equal | `b718bb55f558614ac222667fcdc48cf068bf647e9e8705292d81614492768f48` |
| `02-entry-pages.zip` | Only the three changed HTML entry pages at their existing paths | `6a6725cb64da6aca9b21ad8ac46a85f13b6f600977bb68540bbd0aa8cf1a3277` |

All **67 members** were checked byte-for-byte against the corresponding original verified
archives at application source `6808300d02a9ea37a7455c813d6e7d787d05906a`. ZIP integrity,
member-path/type allowlists and bounded private-key/token-signature checks passed. The
adjacent manifest records member hashes, original archive and destination; neither it nor
the adjacent README belongs in the public website. No private `.env`, PHP backend, SQL,
patient records, test identities or dependencies are in these two transfer ZIPs.

Use the existing verified encrypted FTPS/SFTP connection. Merge **assets first**, preserving
every existing remote file; do not mirror deletions. Verify all hosted asset bytes/hashes,
then apply the three HTML entry pages and verify both clinic hosts, central administration,
Wellnexa entry and separate workforce/practitioner paths. These transfer files do not enable
registration or grant access. Google/MSA authentication, application/terms acceptance,
review, activation, trial and ordinary Inactive tests remain pending. No additional broad
build/test rerun was needed for this documentation/byte-copy packaging step; the original
artifact's recorded test limits still apply.

Rollback restores the three previous HTML entry files from their private backups, retains
old assets and preserves schema/new audit/application records. Private configuration would
require its separately preserved current copy if later changed. The host's pending server
copy, restore rehearsal, full hosted acceptance and accountable sign-offs remain unresolved.

## Hosted asset gate passed before entry-page replacement

1 November 2026, **18:23 UTC** (America/Toronto date: 1 November), documentation baseline
`05e9e5ef73849e266c36c8a5c1c65cdcf616a188`. The owner reported completing the asset merge in
Beyond Compare and requested verification before applying entry pages. No entry-page or
configuration upload was performed by this check.

Expected bytes and SHA-256 values were read directly from **all asset members** of the
three original verified archives at application source `6808300`, rather than trusting
an HTTP 200 or the transfer ZIP's changed-file subset. This covers **72 distinct assets**:
68 shared-portal assets, two central-administration assets and two Wellnexa website assets.
The 64 changed JS files in `01-assets.zip` are included, along with the unchanged assets
required by the complete builds.

| Anonymous HTTPS target | Checks | Result |
| --- | --- | --- |
| `https://wellnexa.copihue.ca/assets/...` | 68 | All HTTP 200, exact expected bytes/SHA-256, valid JS/CSS MIME. |
| `https://livinlively.copihue.ca/assets/...` | 68 | All HTTP 200, exact expected bytes/SHA-256, valid JS/CSS MIME. |
| `https://portal.copihue.ca/admin/assets/...` | 2 | Both HTTP 200, exact expected bytes/SHA-256, valid JS/CSS MIME. |
| `https://wellnexa.ca/assets/...` | 2 | Both HTTP 200, exact expected bytes/SHA-256, valid JS/CSS MIME. |
| Both clinic roots, central `/admin/`, Wellnexa website root | 4 | All HTTP 200 and byte-identical to the previous private HTML backups. Entry pages have not switched. |

**140/140 asset URL checks and 4/4 previous-entry-page checks passed, zero failures.**
Canonical URLs were requested with normal verified TLS, no cache-busting parameter and
no certificate override. Requests used PowerShell `Invoke-WebRequest -Uri $target.url
-TimeoutSec 25` in `ForEach-Object -Parallel ... -ThrottleLimit 4`; response bytes came from
`$response.RawContentStream.ToArray()`, with SHA-256 calculated using
`[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()`.
JS MIME types were checked against browser-supported JavaScript types, and CSS against
`text/css`. The Python standard-library `ZipFile` target inventory and PowerShell results
remain ignored under `.tmp/`; neither contains authentication or patient data:

- `practitioner-hosted-pre-entry-targets.json`, SHA-256
  `9bea52907ded7cfd0779a1d691c3a938c21ce2844f8f7923e1810549ca5c9db3`.
- `practitioner-hosted-pre-entry-results.json`, SHA-256
  `5fffcc7d84074e9d7c318be1ce22544847bb2a0cd3093588a24ccf0a93456bab`.

The **asset integrity gate is passed** for applying `02-entry-pages.zip` to `/public_html`
under the existing deployment approval. Replace only its three HTML entry pages and retain
all old and new assets. Recheck the deployed entry bytes, compiled routes, both clinic
intakes and separate workforce/practitioner authentication afterward. This is static hosted
asset acceptance, not successful provider/MFA, application approval/activation, trial,
normal Inactive testing, database restore or real-PHI release acceptance.

No source/runtime dependency, permission, identity approval, API endpoint/data flow, private
configuration, account or additional schema change was made. The two maintained documents
record this checkpoint; broad local builds/suites were not repeated for a read-only hosted
integrity check. Rollback still restores the three prior HTML files while retaining assets
and all database/application/audit records. Existing accountable-owner and operational gates
remain open.

RELEASE STATUS: NOT READY FOR RELEASE
