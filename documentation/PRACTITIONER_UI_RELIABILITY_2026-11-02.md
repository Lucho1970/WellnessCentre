# Practitioner administration and sign-in UI reliability — 2 November 2026

Branch `bugs/practitioner-ui-reliability`, based on `85ab55d`. Reviewer: Codex for the
specified source, synthetic checks and scoped hosted observations only. The owner copied
the controlled frontend pilot update; new assets and entry pages passed the hosted gates below.
No merge, account reactivation, grant or general-release approval is inferred.

Frontend implementation revision: `61202e541f5cbe89969cc805de227afeaf5e63c6`.
The final public-surface isolation correction and package source are
`f35e2b4c203436133257361652010d9c4ab51d69`. The manifest binds that exact committed source
and the reviewed baseline; later evidence/documentation does not change its payload.

## Result and boundaries

This implements the four UI follow-ups from the completed hosted rehearsal:

- Staff access name/email fields start readonly. An explicit bilingual Edit name and email
  action enables them and focuses the name field. Cancel restores their original values.
  Status/role saves use the original contact snapshot unless contact editing was explicitly
  enabled. `autocomplete=off` is only a hint; payload selection is the protection against
  the observed extension autofill. The existing PATCH contract and backend authorization
  are unchanged; a malicious client/extension is outside this UI protection.
- Known account-wide API denials (401, or explicit membership/account/realm/scope/pilot
  denial codes with 403) invalidate the mounted staff workspace. The existing `/auth/me`
  check runs again; cached access is bound to both account and check revision so an old
  result cannot authorize the new revision. `/auth/me` denial does not recursively emit
  the signal. Retry remains deliberate and receives focus on denial. Ordinary resource
  `forbidden`/service errors do not revoke the account. No polling, push revocation,
  credential clearing, sign-in architecture or backend access policy is added.
- The authenticated shared JSON helper and the five dashboard request paths report these
  account failures. Raw fetch callers in unrelated modules are not universally intercepted;
  their API enforcement remains authoritative. Dashboard refresh also clears old summary,
  preferences and layout draft before loading; travel refresh clears prior visit/estimate.
  Unmount removes child widgets. This does not remotely erase previously rendered or
  downloaded records, establish idle expiry or replace full shared-device/session review.
- Staff and central Admin bootstrap remove recognized OAuth code/error response fields
  only after MSAL's redirect promise settles, including failure. URL/history state and
  unrelated query/hash parameters are retained; navigation during processing is not
  overwritten. Hidden renewal iframes retain their existing early return. Existing
  invitation/action-link and client authentication handling are unchanged. The locked
  MSAL 5.19.0 implementation was inspected locally; no provider configuration was changed.
- Pilot clinic/filter controls have flexible widths and wrap before becoming cramped.
  The empty filter explicitly displays All pilot entries rather than a blank selection.
  Existing labels, permissions, clinic selection and decision workflow are retained.

Both clinics' hosted intake/activation remain closed, the two test staff users remain
Inactive and pilot-revoked, and mail/SMS remain disabled as recorded in the
[previous hosted checkpoint](PRACTITIONER_APPLICATION_JSON_FIX_2026-11-02.md). This task makes
no hosted settings/database/account write. The owner-uploaded frontend assets
and entry pages are verified separately below. The existing CAD 0 rehearsal trial/history is
retained. No consent wording, legal authority, external endpoint/data flow, runtime
dependency/version, identity-provider grant or schema changes. The existing MSAL
sessionStorage exception and broader session requirements remain unresolved.

## Changed files and purpose

| File | Purpose |
| --- | --- |
| `Frontend/src/admin/StaffAdmin.tsx` | Explicit contact edit mode, readonly/autocomplete hints, original-value save protection and focus. |
| `Frontend/src/admin/PractitionerPilotAdmin.tsx` | Responsive clinic/filter widths and wrapping. |
| `Frontend/src/auth/staffAccessInvalidation.ts` | In-memory account-denial signal; no payload, bearer, identity or storage. |
| `Frontend/src/auth/StaffSignIn.tsx` | Revision-bound access recheck, workspace removal and Retry focus. |
| `Frontend/src/shared/api.ts` | Report account denials for authenticated JSON requests; exclude the authorization recheck itself. |
| `Frontend/src/dashboard/Dashboard.tsx` | Report terminal access denials and remove stale dashboard summary/preferences/drafts. |
| `Frontend/src/dashboard/PractitionerToday.tsx` | Report account denial from visit operations. |
| `Frontend/src/dashboard/PractitionerTravelCard.tsx` | Report account denial and clear old visit/estimate on refresh. |
| `Frontend/src/dashboard/PractitionerVisitSummary.tsx` | Report account denial from visit summary. |
| `Frontend/src/dashboard/QualificationReminder.tsx` | Report account denial even on the optional reminder path. |
| `Frontend/src/auth/staffRedirectCleanup.ts` | Deferred, conditional OAuth response URL cleanup with navigation protection. |
| `Frontend/src/portal/staffBootstrap.tsx` | Run cleanup after staff redirect processing. |
| `Frontend/src/central/main.tsx` | Run the same cleanup after central Admin redirect processing. |
| `Frontend/src/i18n/en.ts` | Two English contact-edit labels. |
| `Frontend/src/i18n/fr.ts` | Matching French contact-edit labels. |
| `Frontend/tests/portal.spec.ts` | Autofill/contact-edit payload and focus, account denial/Retry, ordinary denial and outage regressions. |
| `Frontend/tests/practitioner-applications.spec.ts` | Deferred real-client callback success/failure cleanup, preserved navigation and explicit cold-start readiness. |
| `Frontend/tests/application-admin.spec.ts` | EN/FR filter widths, overflow and keyboard/focus at desktop, zoom-equivalent and mobile widths. |
| `scripts/build-practitioner-ui-deployment.ps1` | Committed-source frontend build, baseline configuration integrity, assets-first package, public HTML rollback and manifest. |
| `documentation/PRACTITIONER_UI_RELIABILITY_2026-11-02.md` | Scope, validation, release/rollback and 38-control evidence. |
| `documentation/README.md` | Index this follow-up. |
| `documentation/PRACTITIONER_PILOT_ADMIN.md` | Link the prepared fixes without claiming deployment. |

## Validation

All browser fixtures use disposable local contexts, synthetic accounts/profile data and
mock API/provider responses. They do not use hosted credentials, clinical data, actual
Google/Microsoft accounts, payments or mail/SMS. Node 22.22.1 / npm 11.19.0; dependency
locks unchanged. Exact locked restores completed in the existing workspace; a separately
isolated source/dependency build environment has not been established by that restore.

Exact unchanged locked versions: Frontend React/React DOM 19.2.8, TypeScript 5.7.3,
Vite 6.4.5, MSAL browser 5.19.0 / React 5.6.0, MUI 7.3.11, Playwright 1.63.0;
Wellnexa React/React DOM 19.3.0, TypeScript 6.0.3, Vite 8.3.4, Playwright 1.64.1.

| Command (directory) | Current evidence |
| --- | --- |
| `npm ci` (Frontend) | Passed, exit 0; 350-package locked baseline. |
| `npm audit --json` (Frontend) | Exit 1: 11 moderate build/development advisories, zero high/critical; existing exposure/remediation decision remains open. |
| `npm audit --omit=dev --json` (Frontend) | Passed, zero runtime advisories. |
| `npm run lint`, `npm run validate:content`, `npm run validate:i18n`, `npx tsc --noEmit` (Frontend) | Passed individually and in the final canonical production build; 4 bilingual entries / 2,568 UI translations. Zero frontend lint warnings. |
| `npx playwright test --config playwright.application-admin.config.ts --reporter=line` (Frontend) | 46 passed, exit 0. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` (Frontend) | Final 22 passed, exit 0. |
| `npx playwright test --reporter=line` (Frontend) | Corrected full run: **291 passed, 2 existing optional-fixture skips**, exit 0. Initial 289/2/1 result is retained below. |
| `npx playwright test tests/portal.spec.ts --grep 'staff status saves\|dashboard removes\|record-level forbidden\|access recheck fails\|travel badge account denial\|travel badge clears' --reporter=line` (Frontend) | 9 passed, exit 0: includes delayed child response, authority-check outage, explicit restoration and final focus behavior. |
| `npx playwright test --config playwright.invitations.config.ts --reporter=line` (Frontend) | 7 passed, exit 0. |
| `BUILD_PRACTITIONER_SIGN_IN=1` in this test process, then `npx playwright test --config playwright.build.config.ts --reporter=line` (Frontend) | **15 passed**, exit 0, against the final built public/portal output; test flag restored afterward. Real MSAL code-flow/broker boundary uses intercepted synthetic responses. |
| `npm ci`, `npm audit --json`, `npm run lint`, `npm run build`, `npm run test:browser -- --reporter=line` (Wellnexa) | Restore/audit/build passed; zero advisories; lint exit 0 with four pre-existing warnings; 24 browser tests passed. Website source/locks unchanged. |
| PowerShell parser for the new builder; `git diff --check` | Passed at preparation checkpoint. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` (Wellnexa) | Dedicated opt-in joining-entry suite: **1 passed**, exit 0; omitted by its default browser configuration. |
| `./scripts/build-practitioner-ui-deployment.ps1 -ReleaseName 2026-11-02-practitioner-ui-reliability-v3 -BaselineManifest deployments/2026-11-01-practitioner-pilot-admin/manifest.json` (repository) | Passed, exit 0, from committed `f35e2b4`; preserves reviewed build flags/IDs, runs canonical lint/types/content/i18n and all three frontend production builds, packages only clinic/central portals. |

Final browser coverage is **406 distinct passed / 2 existing optional-fixture skips**:
291 default + 46 central Admin + 22 practitioner applications + 7 invitations + 15 built
artifact + 24 Wellnexa browser + 1 Wellnexa application-entry. The nine focused portal cases
were rerun after the final public-surface guard and passed again; they overlap this total.
The full default run preceded that narrow compile-time public guard; the final artifact
suite verifies public isolation and the final focused run verifies the active portal branch.
No unrelated broad suite was repeated after that targeted correction. The two skipped
cases require optional private CSV/workbook fixtures; they are not new skips or acceptance.

Initial evidence is retained rather than hidden: the first new portal run had four failures
and one pass. The status control's accessible name includes its selected value; its selector
now uses that observed semantic name. Cold Vite startup after a locked reinstall exceeded
the initial five-second UI expectation; tests now await the relevant heading explicitly
(15-second maximum), retaining all behavior assertions. A later negative-case assertion
incorrectly requested a navigation role for an actual labelled list; that test now checks
the real list and the visible dashboard/action controls. No production access rule changed
to satisfy those failures. One callback run could not start because the agent overlapped
`npm ci` and Vite in the same dependency directory; it was rerun only after restore finished.
The first full application run had 19 pass/3 fail: one cold-start readiness failure and
two callback failure tests whose fake provider ignored `redirectFailed`. The latter now
use the real AuthProvider/client with only the redirect promise delayed/rejected locally.
Final application/admin results above include the unchanged recovery/privacy assertions.

The committed-source `npm run build` passed lint, content/i18n, strict TypeScript and all
three surfaces at `61202e5`. The first package scan then correctly stopped with no manifest
on a localhost literal in `FormValidation-*.js`. Inspection of locked React Router's
`createBrowserURLImpl` (`react-router/dist/production/chunk-TP6EHSSF.js:376`) and both emitted
copies establishes that this is a `new URL()` parsing base replaced by the actual window
origin, not a fetch/transport endpoint. The builder now excludes only that reviewed exact
assignment/window-origin structure from scan text; other localhost/loopback destinations
remain blocked and packaged bytes are unchanged. The incomplete first package stays local
for diagnosis and must not be uploaded; it was superseded by the final v3 output. An initial
local inspection used Windows' default text decoder on UTF-8 output and failed; the
inspection was rerun with explicit UTF-8, without modifying output bytes.

The first broad run also exposed the deliberate new account recheck in an old travel-badge
401 fixture: it denied one badge request but continued reporting an active account from
`/auth/me`, so reauthorization remounted the badge and made a third request. The replacement
401 case now models actual revoked current authority and verifies count clearing, no polling
or visibility-triggered recovery while revoked, and deliberate Retry after current authority
is restored. The original 403/404 count/visibility assertions remain unchanged. No case was
skipped or removed; the new stronger 401 scenario and all eight other focused checks pass.

No PHP/API source or SQL changed. PHP/disposable database suites, Composer restore/audit,
real provider sign-in, hosted upload, real extensions/password-manager behavior, complete
AT/contrast/theme/zoom audit, load/penetration and backup restore were not repeated here.
Mocked browser success is not hosted acceptance. Test logs and screenshots remain ignored
under `.tmp/` and browser test-results directories. The unrelated PDF is preserved.

The initial production-artifact browser command passed 13/15. One failure was invocation
configuration: the explicitly enabled pilot artifact requires `BUILD_PRACTITIONER_SIGN_IN=1`
for its existing boundary test. The other caught a real public-build isolation regression:
the new shared helper's `/auth/me` exclusion literal was included in marketing output.
The signal is now explicitly gated to the portal surface so public builds tree-shake that
staff branch. The existing no-staff-authentication/private-module assertion is retained,
not relaxed. A new committed artifact replaced the otherwise verified v2 package;
final production-artifact retest passed all 15 cases. These initial failures are historical;
the final v3 package and current validation table supersede their pending state.

## Package, approval and rollback

The versioned builder accepts a release name and the reviewed previous private/local
build manifest. It checks that previous portal ZIP's SHA-256, preserves its public identity
IDs/build gates and verifies those IDs in the new portal bundle. It checks that the current
public portal HTML matches that baseline before treating it as rollback evidence. The
baseline manifest's historical operational statuses are not copied as current acceptance.
The normal canonical build runs lint/content/i18n/strict TypeScript and all three surfaces.
Only built JS/CSS/HTML are packaged; private API/configuration, SQL, tests and dependencies
are excluded. Credential/development-endpoint/source-map signatures block packaging pending
review. This bounded scan is not an exhaustive credential/PHI or vulnerability assessment.

The final review package is
`D:/Dev/WelnessCentre/deployments/2026-11-02-practitioner-ui-reliability-v3`.
Do not upload the incomplete first directory or superseded v2 output. Final source is
`f35e2b4c203436133257361652010d9c4ab51d69`, branch as above. The manifest records UTC build
time, Node/npm versions, all three lockfile hashes, public build settings and baseline
manifest hash, member hashes/lengths and rollback URLs/hashes. No private settings file is
included. The transfer layout is `/public_html`, existing directories:

| Archive | Entries | Bytes | SHA-256 |
| --- | --- | --- | --- |
| `01-assets.zip` | 70 | 1,249,885 | `895ecd0633063057000f548d2918cdd5fad1c357d05109de77ef6f4541e81492` |
| `02-entry-pages.zip` | 2 | 843 | `815157df49108961d81ca6ec16517b52452116fedd653cea44a4b9074d7aadd9` |
| `ROLLBACK-entry-pages.zip` | 2 | 848 | `92ec6f60c8894f79c2e2ad5aab602e6b2875a75e332cefaa40aaf254b10afab5` |

Independent Python standard-library ZIP/JSON/SHA-256 inspection passed for all three
archives: CRC/readback, unique safe paths, only JS/CSS/HTML, archive and member hashes,
all **72 payload members** byte-identical to final build output, all rollback bytes matching
fresh baseline hashes, and every entry-page module/stylesheet reference present in the
asset archive. Public output's existing no-workforce/no-`/auth/me`/no-CIAM assertions pass.
New portal assets total 2,612,824 bytes versus baseline 2,610,228 (+2,596); central assets
1,550,157 versus 1,549,100 (+1,057). Existing >500-kB chunk warnings remain; limits were
not raised. The small increase does not establish representative-volume performance.

Both rollback HTML files also matched their earlier verified archive entries. Five fresh
anonymous verified-TLS checks confirmed their four primary JS/CSS assets have exact prior
hashes/lengths and LivinLively's portal HTML matches the shared Wellnexa baseline. These
are static rollback checks, not a restore rehearsal or all transitive-asset verification.
Read-only public application offers still report intake/activation false for both clinics.
At package preparation, no hosted file, setting, account, database, grant, worker or
provider change was made. The subsequent assets-only transfer is recorded below.

1. Merge `01-assets.zip` contents into existing directories; preserve every old asset.
2. Verify actual hosted bytes before replacing only the two `02-entry-pages.zip` files:
   `wellness-common-portal/index.html` and `wellness-portal/admin/index.html`.
3. Keep `ROLLBACK-entry-pages.zip`, manifest and README local. The rollback contains fresh
   verified-TLS anonymous HTML baselines; previous assets must remain available. Restore
   those entries only if needed; retain all schema, application, consent, trial and audit
   records. No .env, API, SQL, marketing website or hosting security-file upload.

The shared portal affects both clinics; it and central Admin require coordinated review.
Following review of this v3 package, the owner proceeded with the controlled frontend
copy and confirmed assets uploaded. That authorization covers this staged file update;
it does not approve reopening access, settings/schema changes or general release.
Before real tester access,
finish restricted hosted smoke checks (including actual autofill and both provider returns),
headers/current boundaries/rollback and accountable privacy/security/clinical/release-owner
decisions. Do not enable mail/SMS, intake, activation or test users to force a pass.
The existing staff PATCH still submits its required contact fields and has no new server
version/concurrent-edit guard; resolving concurrent administrator edits is a separate
backend review. Actual extension behavior, hosted revocation and both provider returns
must be retested on the reviewed artifact. Keep the source branches for human review;
this controlled file-copy authorization does not authorize merging them.

## Hosted assets gate — 2 November 2026

The owner reported `01-assets.zip` uploaded. Against package source
`f35e2b4c203436133257361652010d9c4ab51d69` and evidence revision
`87db885159396c58b1cce0cba97bc798c1f82a3a`, anonymous HTTPS checks passed
**138/138 asset URLs**: all 68 shared portal assets on each of Wellnexa and LivinLively,
plus both central Admin assets. Every response was HTTP 200 with the expected JS/CSS
MIME type, exact byte length and exact manifest SHA-256. An HTML fallback would fail
these assertions. No redirect or TLS verification bypass was used.

The current Wellnexa root, LivinLively root and central `/admin/` entry pages also
passed **3/3** exact hash/length/HTML-type checks against the saved previous entries.
Thus the new asset upload has not switched any entry page to the new build. At
19:27 UTC, both public practitioner-application offers passed **2/2** checks with
`data.enabled=false` and `data.activation_available=false`. No account, database,
private configuration, provider, consent, dependency or external data-flow changes
were made by these checks. Test-user and channel states were not re-read privately;
the preceding restrictive checkpoint remains the last evidence for those states.

Exact HTTP command, run in PowerShell 7.6.5 per public manifest-derived URL with
four concurrent requests maximum:

```powershell
Invoke-WebRequest -Uri $target.url -TimeoutSec 25 -MaximumRedirection 0 -ErrorAction Stop
```

Response bytes came from `RawContentStream.ToArray()` and were compared using
`[Security.Cryptography.SHA256]::HashData($payload)` and `$payload.Length`.
The two public offer URLs were requested with the same options and explicitly checked
for both false booleans. Both command batches exited 0; **143 checks passed, zero failed**.
Public-only inventories/results remain local and ignored:

| Local evidence under `.tmp/` | SHA-256 |
| --- | --- |
| `ui-hosted-pre-entry-targets.json` | `b992515511155138a8fab4894887e36b2fbf7f3e3c579e0727531649fbefcc43` |
| `ui-hosted-pre-entry-results.json` | `93c2b4f2dc0313e53148e1ed6848c85d8b5e246774a80a373702deb6bc5ed771` |
| `ui-hosted-pre-entry-offers.json` | `8b3ef1fe40b4ca4b77999b6ff06f5a189882c8b0c3a520383acc48bba51ea42d` |

At that checkpoint, the next step was to replace only `wellness-common-portal/index.html` and
`wellness-portal/admin/index.html` from this v3 `02-entry-pages.zip` under
`/public_html`, preserving all previous assets. Entry-page hash checks and hosted
render/behavior acceptance follow that copy. Static asset integrity does not establish
provider, revocation, accessibility, private API, rollback-restoration or general-release
acceptance. No builds/browser/PHP/SQL suites were repeated for this documentation-only
checkpoint; the scoped local evidence above remains applicable. The subsequent entry
upload and bounded hosted checks are recorded next.

## Hosted entry pages and staff checks — 2 November 2026

The owner subsequently confirmed the two entry files uploaded. At 20:04 UTC, the same
verified-TLS PowerShell HTTP command and byte/hash/MIME assertions passed **141/141**
responses: **138 assets plus all three new entry URLs** (Wellnexa root, LivinLively root,
central `/admin/`). The shared portal's two host responses match the new shared entry;
central matches its new entry. Both public application offers again passed **2/2**
checks with intake and activation false. This is **143 passed, zero failed** after entry
replacement, separate from the earlier 143-check assets-only gate. No unexpected redirect
or HTML fallback was accepted. Source remains `f35e2b4c203436133257361652010d9c4ab51d69`;
the documentation checkpoint being verified was `497a6ac949fbab10d83d73d4b2f3a9534b735ffc`.

| Local evidence under `.tmp/` | SHA-256 |
| --- | --- |
| `ui-hosted-post-entry-targets.json` | `06e9ac6a9e4d5ba7c2fe17c38c12f102b404aead4920c73214ebbc7241853f09` |
| `ui-hosted-post-entry-results.json` | `05d2521383d5d383384fabe456e41eb698683f684bf8d231306707b87ca5238c` |
| `ui-hosted-post-entry-offers.json` | `c138dd6e1dbdf255376cc39170200975de51dfcdd03417a794c8dd25bcc22150` |

Scoped observations through the existing Windows browser sessions:

- Central Admin was reloaded from its new entry and loaded Application administration.
  Practitioner pilot access showed readable English clinic/filter labels and explicitly
  All pilot entries. The Wellnexa view showed both synthetic pilot entries revoked.
  Switching to French displayed the full clinic/filter labels and revoked states; the
  original English language was restored. This is a desktop visual/semantic smoke check,
  not full accessibility or responsive hosted acceptance.
- The existing Microsoft application session was reloaded and showed pilot denial,
  without a practitioner dashboard. The Google practitioner session expired and returned
  to the dedicated provider picker, which displayed both Google and Microsoft account
  choices. A fresh provider return and callback cleanup were not completed on this build.
  Do not treat the expired session as proof of post-login denial.
- After the owner brought Staff access forward, its footer identified
  `2026-11-02-practitioner-ui-reliability-v3`. Both designated test practitioners were
  listed Inactive with their original email addresses. Only the synthetic Microsoft
  entry was opened for editing. Its name/email initially lacked the accessibility
  `settable` property, its status was Inactive, and only practitioner was selected among
  the local roles. Explicit Edit name and email made both contact fields settable.
  Changing only its unsaved synthetic display name, then Cancel name and email changes,
  restored the original name/email and readonly fields; status remained Inactive.
  No Save changes action was issued. The real password manager was not exercised.
  Accessibility focus reporting was inconsistent, so hosted focus transfer is unverified;
  the existing local focus assertions remain the evidence for that behavior.

The Windows tool stopped the first session because it could not determine the current
browser URL reliably. The owner explicitly resumed the check with Staff access foregrounded.
It stopped again when dismissing the drawer with Cancel; that final drawer-close result
is unknown. Both stops were obeyed immediately, without alternative automation to bypass
the URL safety check. No account activation, role/status save, application, terms,
subscription, provider configuration, database or messaging change was submitted by
these checks. The prior CAD 0 trial and all histories remain retained. Mail/SMS settings
were not freshly read; the preceding disabled-channel checkpoint remains their evidence.

The evidence files were re-read with explicit count/pass assertions (141 HTTP results,
two closed offers), all three local ZIP hashes/lengths were rechecked, and
`git diff --check` was run for this documentation checkpoint. These checks passed;
no source, runtime dependency/version, lockfile, permission, consent wording or endpoint
changed. No build, local browser, PHP or SQL suites were rerun for documentation-only
edits. The completed provider registration/approval/offboarding rehearsal predates this
UI build and remains historical acceptance, not a new callback/revocation test.

### Owner-completed provider returns — 2 November 2026

After the documented deployment, the owner reported signing in with both designated
Google and personal Microsoft test accounts and receiving the same pilot-denial message.
The supplied screenshot shows Wellnexa's staff denial screen, Retry, application link
and Sign out, with no practitioner workspace. This supports owner-reported successful
provider returns followed by the expected application pilot restriction for both accounts.
It is separate from agent-observed provider selection, token validation or server-log
inspection. Pilot denial does not independently prove the Inactive membership check,
because the pilot gate can reject access first. The previously observed Inactive states
remain the evidence for membership status.

The screenshot has no address bar. In a subsequent explicit check of both returned
Wellnexa tabs, the owner confirmed neither address bar contained `code=` or `error=`.
This completes the owner-observed successful-return URL check for both providers on
this build. It does not establish the hosted error-return path, every other callback
parameter, or an independent browser/network inspection. No parameter values were
requested or recorded.
No identifying pilot-reference payload or screenshot was copied into the repository.
No access was reapproved and no account, setting, billing, consent or messaging change
was made for this evidence update. The documentation-only follow-up passed
`git diff --check`; source/dependencies are unchanged and builds/browser/PHP/SQL suites
were not repeated.

Remaining acceptance is explicit: manually confirm the drawer is closed without saving;
verify hosted error-return cleanup separately from the owner-checked successful returns;
and test live mounted-workspace removal after authority revocation in a separately
approved synthetic scenario. Do not reactivate the departed test accounts or reopen
intake to force those checks. Actual autofill/status-save protection, hosted keyboard
focus, broader role/clinic negatives, headers, restore rehearsal and accountable
privacy/security/clinical/release decisions remain open. No further package upload is
needed for the already verified frontend update. Rollback still replaces only the two
saved previous HTML entries while retaining all old assets and database/history records.

### Mounted-dashboard revocation retest — observed result; pilot cleanup pending

The owner explicitly requested this hosted test after confirming both provider returns.
The prior statement against reactivation was a resting-state safeguard; the new request
allows preparation of a narrowly scoped rehearsal. Proposed fixture is the existing
synthetic Microsoft practitioner, using its existing membership and retained CAD 0 trial.
Do not use Google's older returning profile or grant additional roles, permissions,
patient relationships, publication or messaging. Intake/activation must remain closed.
The exact access changes must be reviewed before they are saved; no reactivation is
claimed by this preparation record.

1. Establish separate current organization-administrator and Microsoft practitioner
   browser sessions. Verify the test identity, original email, existing practitioner-only
   membership and synthetic assignment scope. The owner subsequently confirmed the
   blocked practitioner tab uses the Microsoft test account and opened a separate
   organization-administrator browser. The owner completed central Admin's separate
   sign-in, and the agent observed authenticated central and clinic administration.
2. Temporarily allow only this existing pilot entry and set only this existing test staff
   account Active through normal administration. Preserve contacts, role/permission
   selections, application, membership, billing, trial dates and all history. These are
   access changes, not another registration, trial activation or subscription change.
3. Use the practitioner's deliberate Retry to obtain its existing dashboard. Confirm
   synthetic-only content and the expected deployed build; stop if the fixture or visible
   records differ. Do not book, publish, open patient records or send communications.
4. Leave the dashboard mounted. In the separate administrator session set this staff
   account Inactive, leaving pilot approval temporarily allowed so that membership
   denial is tested independently of the pilot gate.
5. Return to the same dashboard and use its in-app Refresh, without browser reload,
   navigation or sign-out. Expected: the denied protected request triggers access
   revalidation, removes the practitioner workspace/widgets/navigation and shows the
   account-denial recovery screen. This is removal on the next observed protected
   denial, not server push or erasure of previously downloaded information. Do not
   claim that an untouched idle screen disappears immediately.
6. Deliberate Retry must stay denied while Inactive. Confirm no dashboard restoration
   or stale widget reappearance. Finally revoke the test pilot entry again and verify
   the blocked session. Leave Google Inactive/revoked, both clinics closed and channels
   disabled. Retain the trial and all application/audit history; no database restore or
   record deletion is part of cleanup. Record any incomplete cleanup explicitly.

Before any save, the agent inspected the existing Microsoft test user's drawer:
original contact values, Inactive status, only practitioner selected, and all additional
scheduling/invitation/service-area and verified-consent permissions unchecked. The
drawer was only inspected/scrolled; no status, role, contact or permission change was
submitted. Central Admin initially required separate owner-handled sign-in. After the
owner completed it, both existing Wellnexa test entries were observed revoked. The
Microsoft entry's issuer/subject and prior decision history matched its existing synthetic
rehearsal record; no new reference was created. Its review defaulted to Approve pilot
access. The agent filled only the unsaved reason below and left the owner-confirmation
checkbox unchecked; the save remained disabled. The separate Staff access drawer still
contained the original Inactive/practitioner-only values. The owner was asked to perform
the two temporary access saves and leave the practitioner tab untouched.

Prepared audit reason:

> Owner-authorized mounted-dashboard revocation retest. Temporarily allow only this existing synthetic Microsoft pilot entry and restore its existing practitioner access. Keep intake/activation closed; no publication, bookings, charges or messages. Return Staff access to Inactive, verify removal on in-app Refresh and denial on Retry, then revoke pilot access again. Preserve contacts, roles, permissions and all history.

Access-setting saves are owner-handled under the Windows computer-use security-setting
restriction. This is a tool operating boundary, not a new request for broad implementation
authorization under REL-05. At that prepared checkpoint no access save had been observed,
and the live dashboard revocation test remained pending. Its initial Active/allowed state
and final Inactive/revoked state must both be verified; a prepared form is not an effective
access decision or a hosted revocation pass.
Preparation makes no hosted access/configuration or database write. Local recheck:
`npx playwright test tests/portal.spec.ts --grep 'staff status saves|dashboard removes|record-level forbidden|access recheck fails|travel badge account denial|travel badge clears' --reporter=line`
from Frontend passed **9/9**, exit 0, in 21.2 seconds at source/evidence checkpoint
`7dee7c7`, using only disposable synthetic browser/API fixtures. The ignored log is
`.tmp/ui-revocation-retest-local.log`. These overlap the earlier nine focused cases;
they are not nine additional distinct tests and cannot substitute for the live result.
Existing Node color-environment warnings were emitted; no assertion or suite was skipped.
No builds/PHP/SQL/dependency restore were repeated because their inputs did not change.
At 20:43 UTC, fresh anonymous verified-TLS checks of both public practitioner offers
passed with intake/activation false (2/2), using the same bounded `Invoke-WebRequest`
command above; redacted boolean results are in `.tmp/ui-revocation-retest-offers.json`.
No source, dependency, scope/grant, endpoint, consent wording, schema or package change
is proposed. The existing 38-control ledger remains applicable with this hosted case open.

**Subsequent owner-save checkpoint:** The owner supplied a screenshot showing the
existing Microsoft entry Allowed for pilot and an approval decision at
`2026-11-02 21:25:23 UTC`, with the prepared controlled-retest reason. This is evidence
of the owner-saved temporary pilot allowance, not staff activation. The owner then
reported sign-in still denied. Read-only browser inspection observed the practitioner
denial and the clinic Staff access list/drawer still showing this Microsoft test account
Inactive, original contact values and practitioner role. Its status dropdown was open
but still selected Inactive. Pilot approval does not override that independent inactive
staff boundary; this does not establish a defect in dashboard revocation behavior.
The owner was asked to select only Active and save the existing staff account, leaving
the practitioner tab untouched for agent-observed Retry. No dashboard has yet been
observed and no revocation pass is claimed. Microsoft pilot allowance is now temporarily
effective according to the supplied saved-decision evidence; final Inactive/revoked
cleanup remains required even if the live test cannot be completed. Google access,
intake/activation, clinical relationships, trial/history and messaging are outside this
temporary allowance and must remain at the prior restrictive checkpoint.

**Live dashboard baseline observed:** Following the owner's staff-status save, the
administrator list showed Staff access updated and the existing Microsoft practitioner
Active. At `2026-11-02 21:53:59 UTC`, the agent observed its retained practitioner session
at `/practitioner` with Dashboard, Your day at a glance, dashboard Refresh/customization,
travel/visit widgets, zero visible visit metrics and No appointments scheduled today.
User interaction was detected immediately before that observation, so the initial
Retry/navigation is owner-performed or otherwise unverified; it is not claimed as an
agent action. No patient record or appointment was opened. This is the required active
mounted-workspace baseline, not yet the revocation result.

The same test user's admin drawer was reopened and observed with original name/email,
Active status and practitioner-only role. A geometry-unavailable click was recovered by
fresh window selection/screenshot before the drawer was opened; no status save was
issued by the agent. The owner was asked to save only Inactive, keep pilot allowance
temporarily effective and leave the practitioner dashboard untouched. The agent will
then trigger its in-app Refresh and verify workspace removal without full reload,
followed by Retry denial and final pilot revocation. Until that sequence is observed,
the Microsoft account's latest verified staff status is Active and cleanup is pending.

**Live revocation result:** The owner confirmed both the Active save and the subsequent
Inactive save, with pilot allowance left in effect and the practitioner tab to remain
untouched. The agent independently observed Staff access updated and the same Microsoft
test user listed Inactive. Returning to the existing `/practitioner` tab showed the
access-denied recovery screen: Dashboard, day summary, booking action, customization
and travel/visit widgets were absent. This was already true before any agent in-app
Refresh, browser reload, navigation or sign-out. The observation was recorded at
`2026-11-02 21:58:24 UTC`, after the active baseline at 21:53:59 UTC.

The agent clicked the visible Retry action. Verifying staff access appeared, then at
21:58:32 UTC denial and Retry remained visible, with no dashboard, day summary or
travel/visit widgets restored. This passes the scoped live workspace-removal and
denied-Retry checks for the retained Microsoft session after owner-saved Inactive.
It is not merely a fresh-sign-in test. The account remained pilot-allowed for this
membership-denial check, as requested; the final cleanup is separate.

Evidence limits: removal occurred before the intended manual dashboard Refresh could
be clicked. An existing background/visibility-triggered request may have prompted it,
but no network trace or direct server response inspection identifies the exact trigger,
HTTP status or error code. The owner was instructed not to touch the practitioner tab;
the agent used no full reload/navigation/sign-out during this phase. These observations
do not establish an instant push deadline, behavior of a completely idle browser, remote
erasure of downloaded data or the isolated manual-Refresh scenario. No reactivation is
needed merely to repeat an already successful workspace-removal observation. Local
tests above separately cover controlled request triggers and late-widget races.

**Pilot cleanup handoff:** The owner requested revoking the temporary Microsoft pilot
allowance. The agent opened its existing central Admin review and observed Allowed for
pilot with Revoke pilot access selected; Google was shown revoked behind the dialog.
Only the unsaved audit reason was prepared, recording Inactive, workspace disappearance,
denied Retry and the untraced automatic trigger. The owner was asked to make the final
Save pilot access decision under the computer-use access-setting restriction. At this
checkpoint the Microsoft staff account is verified Inactive, its session is denied, and
the pilot revocation save is still pending. No final pilot-revoked state is claimed until
the saved queue/history and blocked session are checked.

Only the owner performed access saves; the agent opened/scrolled forms, prepared audit
text, observed the test and used Retry. No patient record, appointment, publication,
terms, trial activation, charge, subscription change, message, schema, grant, dependency
or application code was changed by this retest. No new package is needed. Existing
trial/application/membership/audit history is retained; rollback remains the saved HTML
entries with old assets, not a database restore. Remaining general-release controls
below are unchanged. This documentation-only checkpoint runs `git diff --check`; broad
build, PHP, SQL and dependency checks were not repeated for unchanged source/locks.
At 22:02 UTC, the bounded anonymous verified-TLS public-offer checks passed again for
both clinics (2/2): intake and activation false. Exact command remains
`Invoke-WebRequest -Uri $url -TimeoutSec 25 -MaximumRedirection 0 -ErrorAction Stop`,
followed by the explicit response-envelope/false-boolean assertions. Redacted results
are local in `.tmp/ui-revocation-cleanup-offers.json`, SHA-256
`7b3b152ea132e339fa037419ba5fc5d40dbd8f288dac1ebe0714b8053d5572da`.
Mail/SMS settings and database billing/history counts were not freshly queried; no
agent or owner action in this retest requested changes to those retained states.

## All 38 controls — scoped reassessment

Evidence applies to this branch's stated changes and the source revision later recorded
in its package manifest. Reviewer/date: Codex, 2 November 2026. Human owner roles below
await named appointments and dated decisions; no organizational approval is inferred.
Historical audits remain intact. This is a follow-up to the
[existing ledger](PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md).

| Control | Status | Evidence, owner and next action |
| --- | --- | --- |
| GOV-01 | Unverified | No new custodian/agent appointments or contracts. Custodian/operator: name owners and boundaries. |
| GOV-02 | Unverified | No legal commencement/applicability reassessment here. Privacy/legal: dated current-source register before real-data release. |
| GOV-03 | Unverified | Non-health/cross-border applicability unchanged. Privacy/legal: record determination. |
| GOV-04 | Partial | Administrative UI/auth cleanup, no clinical inference. Product/clinical/legal: approve intended use. |
| GOV-05 | Partial | Synthetic local fixtures, no hosted patient queries. Privacy/security: complete signed PIA/TRA and residual-risk decisions. |
| PRIV-01 | Unverified | Notices/contact authority unchanged. Custodian/privacy: verify actual bilingual practices. |
| PRIV-02 | Partial | No terms/consent change or auto-acceptance; original contact values preserved on status save. Privacy: commercial collection authority still required. |
| PRIV-03 | Partial | Local account-denial/recheck tests plus hosted Microsoft workspace disappearance and denied Retry after Inactive; backend unchanged. Security/custodian: broader record/worker/concurrent restriction boundaries remain. |
| PRIV-04 | Unverified | No representative/minor workflow added. Clinical/custodian: approve supported populations/assisted route. |
| PRIV-05 | Unverified | No patient-rights workflow changes. Privacy operations: evidence access/correction process. |
| PRIV-06 | Unverified | No deletion/retention change. Records/privacy: approve holds/backup/disposal and restore. |
| PRIV-07 | Unverified | No incident exercise. Incident/privacy lead: rehearse runbook. |
| AUTH-01 | Partial | Supported locked MSAL/JWT flow retained; owner reports both real provider returns followed by pilot denial and neither returned URL contains code=/error=. Identity/security: hosted error-return cleanup and independent provider/security acceptance remain. |
| AUTH-02 | Partial | Revision-bound UI access and account-vs-resource denial tests; API role/clinic rules unchanged. Security/custodian: hosted cross-clinic/record negatives. |
| AUTH-03 | Partial | Hosted retained Microsoft workspace gone after Inactive and Retry stays denied; exact initial request trigger untraced. Identity/security: hosted focus, idle/absolute/MFA/logout/lost-device and broader raw-fetch coverage remain. |
| AUTH-04 | Partial | Signal/drafts in memory, no new token persistence. Security: resolve existing MSAL sessionStorage exception/design before real-data release. |
| AUTH-05 | Partial | No new scopes/grants/DB privileges. Identity/operations: verify actual deployed inventory. |
| SEC-01 | Partial | Private files/PDF/tests excluded, bounded artifact scan required. Build/security: independent final secret/PHI/history review. |
| SEC-02 | Partial | Public baseline uses verified OS TLS; no transport relaxation. Operations: encryption/key/backup/restore evidence remains open. |
| SEC-03 | Partial | Callback URL cleanup improves exposure; no host headers changed. Operations: all-host cache/CSP/CORS/header acceptance. |
| SEC-04 | Partial | React text, existing API validators unchanged; explicit contact edit/save intent. Security: independent review and hosted validation. |
| SEC-05 | Partial | No new endpoint/polling; bounded dashboard failures trigger access recheck. QA/security: interrupted/concurrent/volume and broader abuse acceptance. |
| SEC-06 | Partial | No API audit changes; local signal contains no token/payload/identity. DB/security/privacy: independent integrity/monitoring/retention remains open. |
| SEC-07 | Partial | Generic provider recovery retained; raw OAuth error fields cleaned after processing. Operations: web/proxy/provider-log redaction review. |
| SEC-08 | Partial | No new endpoint/vendor/data flow. Privacy/vendor owner: existing support/retention/incident approvals. |
| SEC-09 | Partial | No messages/channel activation. Privacy/operations: actual sender/recipient/CASL and delivery evidence before enabling. |
| DEV-01 | Implemented (task scope) | Dedicated bugs branch, instructions/config/locks/migrations/docs reviewed; source/artifact committed and final branch pushed; PDF preserved. Maintainer: human review, no merge inferred. |
| DEV-02 | Partial | No new any/ts-ignore/skips, narrow changes, initial failures retained; local diff reviewed. Maintainer/security: independent review remains. |
| DEV-03 | Partial | Exact restores/unchanged locks; zero runtime/11 moderate frontend build findings. Build/security: outstanding build advisory exposure decision. |
| DEV-04 | Partial | Real lint/types/content/i18n/builds and 406 passing distinct browser cases; 2 existing optional-fixture skips documented. QA: hosted checks and broader omitted scenarios remain. |
| DEV-05 | Partial | Local bilingual/focus/mobile/zoom checks; hosted EN/FR pilot filter layout and explicit contact edit/cancel observed. Accessibility/product: hosted focus, real AT/contrast/themes/full zoom and actual extension handling remain. |
| DEV-06 | Partial | No runtime dependency or polling; source bounds unchanged. Build/performance: compare final chunks and representative access-recheck concurrency. |
| REL-01 | Partial | This dated ledger and file matrix; named owner decisions absent. Release owner: assign and retain approvals. |
| REL-02 | Implemented (local changed scope) | Exact frontend/website builds and dedicated/default browser configs pass; unchanged API/SQL exclusions explicit. QA: local success is not hosted acceptance. |
| REL-03 | Partial | Final v3 provenance/static integrity, scoped central/staff checks, owner provider/URL checks, retained Microsoft workspace removal and denied Retry recorded. Operations/release: final pilot cleanup, isolated request/error-return checks, broader boundaries and actual restore remain. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; no local result waives open gates. Accountable owners: resolve/sign scoped decisions. |
| REL-05 | Partial | Owner proceeded with reviewed controlled frontend copy; assets verified before entry handoff, both public offers closed. No permission/settings expansion. Release/privacy/security/clinical owners: remaining scoped operational and general-release decisions. |
| REL-06 | Implemented (report scope) | Files/commands/flows/limits/rollback and final source/hash binding recorded. Release owner: confirm report and accountable decisions. |

RELEASE STATUS: NOT READY FOR RELEASE
