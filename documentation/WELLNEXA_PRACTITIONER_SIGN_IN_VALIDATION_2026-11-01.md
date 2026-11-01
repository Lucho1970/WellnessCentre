# Practitioner sign-in implementation and release evidence

Review date: **1 November 2026** (America/Toronto). Reviewer: **Codex, local source/test review**;
this is not a custodian, legal, privacy, security or clinical approval. Branch:
`feature/wellnexa-practitioner-signin`. Baseline: `0aec293`, retaining the prior safety/quality
work rather than branching from the older main implementation. Application source revision:
**`6a4ba6ab83f92bf8ac0791d104c32e436ff1850d`**. This report is committed separately after
that tested source revision. No merge or
deployment is authorized or performed by this implementation report.

The requested independent practitioner entry is implemented using the existing external staff
identity application. Google and personal Microsoft choices have fixed issuer hints; workforce
sign-in remains separate. Registration, central review, explicit activation and revocation are
tested with synthetic identities through real HTTP/SQL dispatch. No code accepts a raw Google
or personal Microsoft token as a staff credential. See the
[operating/setup guide](WELLNEXA_PRACTITIONER_SIGN_IN.md).

## Live acceptance blocker and scope

The cached Azure CLI authorization for the recorded external tenant returned **AADSTS530036**
(Conditional Access rejected the cached authentication flow). Only expiration metadata was
requested; no bearer token or secret was printed. No policy was disabled, no device-code
workaround was attempted, and no provider permission or credential was changed. Inspection
of the existing Azure browser window did not successfully select its Entra tab, so it supplied
no current provider-setting evidence. A request for an authenticated admin session was left
with the user while local work continued.

A later read-only public check fetched the tenant OIDC discovery metadata and verified the
expected broker issuer/authorization host. A fresh authorization-entry inspection using the
recorded dedicated staff SPA/API and Wellnexa callback displayed Google on the user-flow page.
The personal Microsoft issuer-hint request returned **AADSTS90072** instead of completing
independent federation. No credentials were entered, account created, token exchanged or
membership approved, and the check tab was closed. The diagnostic's account identifiers are
not retained in this report. Provider settings/flow association require authenticated inspection
and correction; adding an account as a workforce/B2B guest is not the requested design.

Live Google/MSA federation, configured callbacks, MFA, current granted scopes and the hosted
registration/approval journey remain **unverified**. There was no hosted database access or
assumption that existing records are synthetic. No real user was registered, approved or
messaged. Implementation authorization is not release or real-PHI acceptance.

## File inventory and purpose

| Changed file | Purpose |
| --- | --- |
| `Frontend/src/auth/practitionerIdentity.ts` | Centralize external feature gates and the two fixed provider-routing choices. |
| `Frontend/src/auth/PractitionerSignInOptions.tsx` | Shared accessible Google/MSA/picker controls and approval/account guidance. |
| `Frontend/src/auth/AuthProvider.tsx` | External-only provider parameters, fresh interactive hint handling, safe callback failure context and realm-specific recovery text. |
| `Frontend/src/auth/StaffSignIn.tsx` | Separate practitioner screen; preserve workforce login; provide application/sign-out recovery after access denial. |
| `Frontend/src/auth/PractitionerApplicationPage.tsx` | Offer the same providers before application submission and display safe callback recovery. |
| `Frontend/src/auth/UserAccountMenu.tsx` | Keep returning practitioners on the external login route and allow the unauthenticated link to reflow. |
| `Frontend/src/guest/GuestPortal.tsx` | Display distinct practitioner/workforce entry links with durable external authentication, without requiring invitations. |
| `Frontend/src/portal/PortalApp.tsx` | Let header controls shrink/reflow at narrow widths and zoom. |
| `Frontend/src/portal/staffBootstrap.tsx` | Catch provider callback rejection, render recovery and prevent cached-account selection on that failed return. |
| `Frontend/src/i18n/practitionerApplications.ts` | English/French provider, guidance and recovery labels. |
| `Frontend/.env.example` | Default-off public social-button rollout gate; no credential. |
| `Frontend/playwright.applications.config.ts` | Exercise both provider choices with synthetic external staff configuration. |
| `Frontend/tests/practitioner-applications.spec.ts` | Preserve registration tests and cover entry separation, actual bridge request construction, malicious URL hints, denial recovery, callback failure and French zoom/keyboard behavior. |
| `Wellnexa/src/Join.tsx` | Add the returning-practitioner link separately from intake. |
| `Wellnexa/.env.example` | Document the existing durable external-authentication gate for the website entry link. |
| `Wellnexa/tests/applications/entry.spec.ts` | Verify both joining and sign-in destinations use the configured clinic portal. |
| `api/tests/integration/practitioner-applications.php` | Extend signed-token HTTP/SQL checks with both synthetic brokered provider journeys, same-email isolation, raw-upstream rejection and revocation. |
| `api/tests/dst-availability.php` | Correct a transition-day fixture failure with a 400-day synthetic horizon; retain all DST assertions and production booking limits. |
| `documentation/WELLNEXA_PRACTITIONER_SIGN_IN.md` | User flow, identity boundary, exact provider setup, gates, hosted rehearsal and rollback. |
| `documentation/WELLNEXA_PRACTITIONER_SIGN_IN_VALIDATION_2026-11-01.md` | Revision-bound results, limitations, dependency/flow inventory and 38-control ledger. |
| `documentation/README.md` | Index the current sign-in guide/evidence. |
| `documentation/PRACTITIONER_APPLICATIONS.md` | Point to the current provider entry and live acceptance boundary. |
| `documentation/GOOGLE_PRACTITIONER_ROLLOUT.md` | Preserve historical setup and distinguish the new two-provider implementation from hosted acceptance. |
| `documentation/FRONTEND_QUALITY_GATES.md` | Record the new dated moderate tooling advisory without rewriting historical audit results. |

## Dependencies, permission/consent and endpoint changes

No dependency manifest or lockfile changed. No runtime dependency, supported toolchain or
backend JWT verifier was added/replaced. No app/API/Graph/mail permission, membership grant,
pilot approval or consent wording was changed. Existing joining terms must still be published
and accepted at their exact version. Choosing an authentication provider is not treatment,
marketing or record-disclosure consent.

Local runtimes: Node **22.22.1**, npm **11.19.0**, PHP **8.4.14**, Composer **2.9.5**, Edge;
disposable engines MySQL **5.7.44**, MariaDB **11.4.8**. Relevant Frontend locked packages:
`@azure/msal-browser 5.19.0`, `@azure/msal-react 5.6.0`, `react 19.2.8`, `typescript 5.7.3`,
`vite 6.4.5`, `@playwright/test 1.63.0`. Existing lint tree includes `eslint 10.13.0`,
`@eslint/js 10.0.1`, `typescript-eslint 8.72.1` and `minimatch 10.2.6`.

Relevant Wellnexa locks: `react 19.3.0`, `typescript 6.0.3`, `vite 8.3.4`,
`@playwright/test 1.64.1`, `oxlint 1.88.0`. Composer runtime locks: `dompdf/dompdf 3.1.6`,
`dompdf/php-font-lib 1.0.2`, `dompdf/php-svg-lib 1.0.2`, `firebase/php-jwt 7.1.0`,
`giggsey/libphonenumber-for-php 9.0.40`, `giggsey/locale 2.9.0`, `masterminds/html5 2.11.0`,
`nikic/fast-route 1.3.1`, `sabberworm/php-css-parser 9.5.0`,
`symfony/polyfill-mbstring 1.43.0`. Unchanged lockfile SHA-256:

```text
Frontend/package-lock.json cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c
Wellnexa/package-lock.json 17b1d75bce7d40a857dfd16c319f32a116b905e799f339c4443446da3a571f1e
api/composer.lock 16fab4fc79b83afd34d4200474204bfc55cd7fe40d650a0c39d848dc4f93265f
```

| Endpoint/flow | Fields, trust, purpose and verified scope |
| --- | --- |
| Configured dedicated `*.ciamlogin.com` staff authority | Existing staff SPA, `access_as_staff`, code/PKCE redirect to `/staff/external`; fixed `google` or `login.live.com` hint, no caller-supplied authority. Local request construction checked; live grant/flow/MFA unverified. |
| `accounts.google.com` through the broker | Existing own-account authentication/profile federation; no appointment or clinical bodies supplied by these changes. Provider credential/callback, retention, geography and support/contract acceptance require the assigned owner. |
| `login.microsoftonline.com/consumers` and configured `login.live.com` issuer | Personal Microsoft OIDC federation to configure/verify in the broker. Official setup uses `openid profile email`; only Entra's broker credential belongs in its cloud configuration. No direct MSA API trust or new runtime service. Actual cloud change not performed. |
| Existing workforce `login.microsoftonline.com/{tenant}` | Clinic staff/admin path unchanged, `access_as_user`; no widening to arbitrary/personal tenants. |
| Existing `/public/practitioner-applications`, `/practitioner-applications/*`, central review and `/auth/me` | Existing application/contact/terms and minimum authorization projections. New tests cover exact subject, no membership before activation, clinic boundaries, role cap, replay and revoked access. No new endpoint or PHI transmission. |

Actual deployed endpoint/grant inventory, credential custody/rotation, subprocessor contracts,
support access, retention and incident terms remain for the accountable security/privacy owners.
The provider guides are implementation references, not privacy approval.

## Validation commands and results

The following are final local checkpoint results. Commands use repository
root unless a directory prefix is shown. SQL runners require the explicit disposable runtime
paths; they never load the application private `.env` or attach to a hosted database.

| Command | Result |
| --- | --- |
| `Frontend: npm run lint` | Passed, zero warnings. Also executed by the final canonical build. |
| `Frontend: npm run build` | Passed: lint, four bilingual content entries, 2,531 English/French strings, strict TypeScript and public/portal/central builds. Existing >500 kB notices remain. |
| `Frontend: npx playwright test --config playwright.applications.config.ts --reporter=line` | 15 passed. Real request construction is exercised with only the final redirect effect replaced; provider authentication itself is simulated. |
| `Frontend: npx playwright test --config playwright.invitations.config.ts --reporter=line` | Seven passed. Existing independent invitation path retained. |
| `Frontend: npm test -- --reporter=line` | 285 passed, two existing optional private input cases skipped; no failures (9.9 minutes). |
| `Frontend: npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 36 passed, including independent application review and central workforce trust. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | 14 passed against current production output. |
| `Frontend: node --test tests/cache-config.test.mjs` | Two passed. |
| `Wellnexa: npm run build`; `npm run lint` | Passed; lint retains four existing warnings in `App.tsx` and `catalogue/Details.tsx`. No rule disabled. |
| `Wellnexa: npm run test:browser -- --reporter=line` | 24 passed. |
| `Wellnexa: npx playwright test --config playwright.applications.config.ts --reporter=line` | One passed with both portal entry destinations. |
| `Frontend: npm audit --json` | Exit 1: 11 moderate package findings in the ESLint/typescript-eslint/minimatch build tree, no high/critical. See advisory classification below. |
| `Frontend: npm audit --omit=dev --json` | Exit 0: no runtime advisories. |
| `Wellnexa: npm audit --json` | Exit 0: no advisories. |
| `api: composer validate --strict`; `composer audit --format=json` | Passed; no advisories or abandoned packages reported. |
| Each `php api/tests/<file>.php` with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | Final rerun: all 61 standalone scripts passed. No provider requests. |
| `php -l` for every PHP file under `api/src`, `api/public`, `api/tests`, `Wellnexa/public/api` | 251 passed. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <portable-5.7.44> -Port 13341 -IntegrationTest practitioner-applications.php` | 36 ledger + 99 application SQL/HTTP checks passed; disposable server stopped. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13340 -IntegrationTest practitioner-applications.php` | 36 ledger + 99 application SQL/HTTP checks passed; disposable server stopped. |

Browser total: **382 passed, two optional skips** across the seven explicitly listed browser
suites. Limited selected credential-pattern scans found no candidates in tracked first-party
text or 82 current built text assets; no current source maps were present. This was not an
exhaustive secret/history scan, artifact provenance review or PHI-content assessment. The
six changed documentation files resolved 102 relative links, all 38 control rows were counted,
and the staged diff passed whitespace checks. Private/untracked files remain excluded.

The first application browser run passed 13/14: its new French 200%-zoom check exposed the
header's nonshrinking staff link. After reflow changes, 14/14 passed; the final 15/15 run also
adds rejected-callback recovery. Its synthetic French screenshot was visually inspected.
The first broad PHP run stopped on `dst-availability.php`: on transition day the next fall
transition is outside a 365-day future horizon. Only its fake service horizon/date-selection
window changed to 400 days (within supported settings); DST offset/nonexistent-hour/order
assertions and production behavior remain intact. The full 61-script rerun then passed.

The current audit is not the older zero-advisory result. npm propagates one
[minimatch glob-pattern amplification advisory](https://github.com/advisories/GHSA-g3hv-75wg-m249)
through 11 lint-tool package records. The vulnerable path is build-only here; exploitation
requires a malicious glob pattern, and this app's public sign-in does not submit globs to the
lint process. This is a scoped source/dependency inference, not a security acceptance. Do not
run lint on unreviewed attacker-controlled build configurations. npm's offered fixes include
major downgrades of the toolchain; no forced fix or unreviewed major replacement was applied.
Owner: security/build maintainer (unassigned); next action: review a compatible patched parent
dependency/tooling change or record an approved time-bounded build exposure decision. The
advisory identifies minimatch 11.0.1 as patched; that is not permission to force it into every
older parent dependency.

Not performed: live Google/MSA account creation or sign-in, cloud provider/permission changes,
hosted DB/identity/MFA/headers/pilot acceptance, release ZIP or deployment, real-provider mail/
SMS, clean release-environment dependency restoration, exhaustive historical secret scan,
full assistive-technology/contrast/theme/load/penetration/licence or backup-restoration review.
No dependency change was introduced; clean restores remain a release-artifact gate. Main browser
APIs are mocked; signed SQL/HTTP fixtures use only local keys and synthetic people. These do
not establish cloud provider acceptance or organizational compliance.

## All 38 controls: dated evidence and next action

All decisions below are **1 November 2026, Codex**, against the application source revision
recorded above and this report's files/tests. Human owner names/signatures have not been
supplied: role labels below are **unassigned accountable roles**, not claimed appointments.
Implemented means only the stated locally verified scope. Historical audit findings are
reassessed here where this change supplies evidence; missing organizational evidence is not
an assertion of organizational noncompliance. Every unresolved item remains a release gate
for its applicable scope.

| Control | Status | Current evidence / limitation | Owner and next action |
| --- | --- | --- | --- |
| GOV-01 | Unverified | No new signed custodian/operator/agent/clinic responsibility evidence. | Custodian/operator: name roles, custody boundaries and incident lead. |
| GOV-02 | Unverified | Provider technical docs checked; legal applicability/commencement register not approved by this task. | Privacy/legal: approve dated current-source register. |
| GOV-03 | Unverified | No new PIPEDA/flow applicability determination. | Privacy/legal: decide commercial/interprovincial/international scope. |
| GOV-04 | Partial | Change concerns administrative sign-in and professional applications; no diagnosis/treatment recommendation added. | Product/clinical/legal: approve intended use; reassess any later clinical functions. |
| GOV-05 | Partial | Synthetic fixtures and flow inventory above; complete approved PIA/TRA and actual vendor inventory unverified. | Privacy/security: sign and maintain assessments with residual risks. |
| PRIV-01 | Missing | This change does not publish the actual custodian's bilingual information-practices notice/contact. | Custodian/privacy: publish and verify per-clinic notices. |
| PRIV-02 | Partial | Existing explicit versioned joining terms retained; HTTP tests exercise independent approval and activation acceptance. No marketing/PHI/treatment authority inferred. | Privacy/product: approve purpose-specific wording and legal authority. |
| PRIV-03 | Partial | Exact identity, current membership and wrong-clinic/revocation tested; no bypass added. Broader clinical restriction acceptance unchanged. | Privacy/security: complete hosted and clinical-boundary acceptance. |
| PRIV-04 | Unverified | Professional own-account workflow; minors/representative health-record authority not implemented here. | Custodian/clinical: approve supported populations and assisted process. |
| PRIV-05 | Unverified | Registration status is not a complete patient access/correction process. | Privacy operations: evidence intake, verification, deadlines and protected delivery. |
| PRIV-06 | Unverified | No automatic record deletion or retention changes; actual record/identity/provider-log schedules/holds unapproved here. | Custodian/records: approve profession/class schedules and disposal/restore tests. |
| PRIV-07 | Unverified | No operational breach rehearsal/sign-off performed. | Incident/privacy lead: approve and rehearse applicable notification runbook. |
| AUTH-01 | Implemented (local scope) | Existing supported MSAL and exact backend JWT checks retained; synthetic raw-upstream/client/workforce denial and brokered flows pass. Live provider trust unverified. | Identity/security: verify actual issuer/apps/scopes, callback and MFA in hosting. |
| AUTH-02 | Partial | Current central grant, practitioner-only role, clinic membership, same-email denial and revocation pass locally; no clinical support reach added. | Security/custodian: complete hosted role/record/consent tests. |
| AUTH-03 | Partial | Safe callback/recovery UI added; no approved workforce idle/absolute/MFA/departure evidence supplied. | Identity/security: approve session design and verify provider policies. |
| AUTH-04 | Partial | New choice/error state does not store tokens; existing MSAL sessionStorage and existing invitation/session exceptions remain unresolved. | Security: approve a time-bounded exception or separately implement approved session architecture before real data. |
| AUTH-05 | Partial | Source scope remains `access_as_staff`; no API/Graph/mail grants changed. Deployed permission inventory unavailable. | Identity/operations: inventory exact runtime grants and provider credential access. |
| SEC-01 | Partial | Private files/attachments excluded; limited source/build credential-pattern checks recorded at final checkpoint. Not an exhaustive Git-history scan. | Security/build: complete artifact and indicated history review/rotation if needed. |
| SEC-02 | Unverified | No TLS checks disabled or new database connection design; hosted encryption, keys, DB transport and restores unverified. | Infrastructure/security: supply encryption/patching/restore evidence. |
| SEC-03 | Partial | Same-origin configured callbacks and fixed provider choices; hosted per-host headers/cache/session acceptance not performed. | Operations/security: verify every portal/API host and shared-device behavior. |
| SEC-04 | Implemented (changed scope) | Closed provider enumeration/fixed hints; malicious hint/callback query ignored in actual bridge tests; existing prepared SQL verifier flow unchanged. | Security: review cloud OIDC/profile configuration and missing claims. |
| SEC-05 | Partial | Existing application request/rate/batch bounds retained and exercised; identity-provider abuse controls and representative load unverified. | Security/identity: verify hosted abuse/rate limits and failure handling. |
| SEC-06 | Partial | Existing application/audit atomicity, reads and denied/current authority covered by inherited HTTP/SQL tests; independent integrity/monitoring not proven. | Security/DB/privacy: verify runtime privileges and operational audit review. |
| SEC-07 | Partial | Rejected callback shows generic recovery without raw diagnostic; broader provider/proxy/web logging remains unverified. | Operations/security: inspect/redact external and infrastructure logs. |
| SEC-08 | Partial | Endpoint/field/scope inventory above; Google/MSA configuration/retention/contracts/support approval not supplied. | Privacy/security/vendor owner: approve actual subprocessor flows and credential lifecycle. |
| SEC-09 | Partial | No clinical email/SMS/calendar content or new app messages; provider registration/MFA communications remain outside app guard. | Privacy/operations: approve recipient/CASL classification and vendor handling. |
| DEV-01 | Implemented (task scope) | Instructions, preserved Git state, dedicated branch, package/lock/config/migration/deployment/docs inspected; no merge/deploy. | Maintainer: review and integrate under authorized workflow. |
| DEV-02 | Implemented (local scope) | Focused source review; no new any/ts-ignore/dependency or weakened lint/tests; callback catch maps to visible safe recovery. | Maintainer/security: independent human code review. |
| DEV-03 | Partial | Locks unchanged, current audits and exact versions recorded; 11 moderate build records remain; clean release restore not performed. | Build/security: review compatible minimatch remediation and release restore. |
| DEV-04 | Partial | Listed lint/types/build/local browser/PHP/SQL checks; initial fixture/layout failures recorded and corrected. Omitted hosted scenarios explicit. | QA/release: finish hosted/provider acceptance and retain final results. |
| DEV-05 | Partial | Bilingual provider labels, semantic buttons, keyboard, French mobile and 200%-zoom screenshot checked; full WCAG/themes/AT and AODA applicability unverified. | Accessibility/product/legal: complete applicable assessment and review. |
| DEV-06 | Partial | No new runtime dependencies, polling or SQL queries; fixed-size provider choices. Existing chunk notices and volume performance remain. | Performance/build: review bundle/performance budgets and representative load. |
| REL-01 | Partial | This 38-control source/test ledger is supplied; named accountable owners and human decisions remain absent. | Release owner: assign names and collect dated decisions. |
| REL-02 | Partial | Exact local commands/results and synthetic boundary above; defaults omit dedicated suites, which are run explicitly. Hosted acceptance remains separate. | QA/release: verify final checkpoint and required operational scenarios. |
| REL-03 | Missing | No new release artifact/provenance/hosted rehearsal/backup rollback proof created by this implementation. | Release/operations: produce approved artifact and hosted synthetic acceptance. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; flags are default off and unresolved controls are not waived by tests. | Accountable owners: resolve or lawfully approve applicable internal exceptions. |
| REL-05 | Unverified | User authorized implementation; no production deployment/schema/permission change or operational sign-off performed. | Release/custodian/privacy/security/clinical: record appropriate approvals and rollback ownership. |
| REL-06 | Implemented (report scope) | Requested/result scope, file purposes, exact versions/commands, flows, findings, omissions, owners/actions and rollback recorded. | Release owner: verify final source SHA and completion report. |

## Rollback and remaining action owners

No schema/data migration is introduced. Preserve identities, memberships, trial/terms/audit
history. Restore matching approved code/builds; disabling only the new social-button flag
retains the unhinted external login. Keep durable practitioner authentication and workforce
administrator recovery available. Cloud provider/callback changes need their own reviewed
before/after record and credential owner; none was performed here.

The identity administrator must supply authenticated tenant access and complete actual Google/
MSA/callback/MFA acceptance. QA/release must run the same journey with approved synthetic
hosted accounts and the exact proposed artifact. Privacy/security/custodian/clinical owners
must address the applicable missing controls before real-PHI access. No passing local test or
historical tenant-setup document substitutes for those decisions.

RELEASE STATUS: NOT READY FOR RELEASE
