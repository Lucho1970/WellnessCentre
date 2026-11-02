# Practitioner administration and sign-in UI reliability — 2 November 2026

Branch `bugs/practitioner-ui-reliability`, based on `85ab55d`. Reviewer: Codex for the
specified source, local synthetic tests and artifact checks only. No merge, hosted file
replacement, account reactivation, grant or general-release approval is inferred.

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
no hosted settings/database/account write. The existing CAD 0 rehearsal trial/history is
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
No hosted file, setting, account, database, grant, worker or provider change was made.

1. Merge `01-assets.zip` contents into existing directories; preserve every old asset.
2. Verify actual hosted bytes before replacing only the two `02-entry-pages.zip` files:
   `wellness-common-portal/index.html` and `wellness-portal/admin/index.html`.
3. Keep `ROLLBACK-entry-pages.zip`, manifest and README local. The rollback contains fresh
   verified-TLS anonymous HTML baselines; previous assets must remain available. Restore
   those entries only if needed; retain all schema, application, consent, trial and audit
   records. No .env, API, SQL, marketing website or hosting security-file upload.

The shared portal affects both clinics; it and central Admin require coordinated review.
Production copy needs explicit authorization under REL-05 after the actual package and
checks are ready; this development request does not authorize it. Before real tester access,
finish restricted hosted smoke checks (including actual autofill and both provider returns),
headers/current boundaries/rollback and accountable privacy/security/clinical/release-owner
decisions. Do not enable mail/SMS, intake, activation or test users to force a pass.
The existing staff PATCH still submits its required contact fields and has no new server
version/concurrent-edit guard; resolving concurrent administrator edits is a separate
backend review. Actual extension behavior, hosted revocation and both provider returns
must be retested on the reviewed artifact. Keep the source branches for human review;
implementation authorization does not authorize merging or deploying them.

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
| PRIV-03 | Partial | Known account denial removes workspace/rechecks current authority; backend unchanged. Security/custodian: complete hosted restriction/worker boundaries. |
| PRIV-04 | Unverified | No representative/minor workflow added. Clinical/custodian: approve supported populations/assisted route. |
| PRIV-05 | Unverified | No patient-rights workflow changes. Privacy operations: evidence access/correction process. |
| PRIV-06 | Unverified | No deletion/retention change. Records/privacy: approve holds/backup/disposal and restore. |
| PRIV-07 | Unverified | No incident exercise. Incident/privacy lead: rehearse runbook. |
| AUTH-01 | Partial | Supported locked MSAL/JWT flow retained; callback fields removed after processing. Identity/security: real-provider hosted retest. |
| AUTH-02 | Partial | Revision-bound UI access and account-vs-resource denial tests; API role/clinic rules unchanged. Security/custodian: hosted cross-clinic/record negatives. |
| AUTH-03 | Partial | Workspace clears on observed account denial; deliberate Retry/focus. Identity/security: idle/absolute/MFA/logout/lost-device and broader raw-fetch coverage. |
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
| DEV-05 | Partial | Bilingual controls, explicit focus, filter keyboard/mobile/zoom-equivalent checks. Accessibility/product: real AT/contrast/themes/full zoom and actual extension handling. |
| DEV-06 | Partial | No runtime dependency or polling; source bounds unchanged. Build/performance: compare final chunks and representative access-recheck concurrency. |
| REL-01 | Partial | This dated ledger and file matrix; named owner decisions absent. Release owner: assign and retain approvals. |
| REL-02 | Implemented (local changed scope) | Exact frontend/website builds and dedicated/default browser configs pass; unchanged API/SQL exclusions explicit. QA: local success is not hosted acceptance. |
| REL-03 | Partial | Final v3 source/lock/runtime/member hashes, 74 ZIP entries, independent readback and static rollback checks recorded; no hosted replacement. Operations/release: restricted hosted acceptance and actual restore. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; no local result waives open gates. Accountable owners: resolve/sign scoped decisions. |
| REL-05 | Partial | Development authorized; current hosted restrictions preserved. Release/privacy/security/clinical owners: review actual package before production authorization. |
| REL-06 | Implemented (report scope) | Files/commands/flows/limits/rollback and final source/hash binding recorded. Release owner: confirm report and accountable decisions. |

RELEASE STATUS: NOT READY FOR RELEASE
