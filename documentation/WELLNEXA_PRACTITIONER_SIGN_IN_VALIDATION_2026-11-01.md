# Practitioner sign-in implementation and release evidence

Review date: **1 November 2026** (America/Toronto). Reviewer: **Codex, local source/test review**;
this is not a custodian, legal, privacy, security or clinical approval. Branch:
`feature/wellnexa-practitioner-signin`. Baseline: `0aec293`, retaining the prior safety/quality
work rather than branching from the older main implementation. Initial broad checkpoint:
**`6a4ba6ab83f92bf8ac0791d104c32e436ff1850d`**. Current application source revision after
the live-routing correction: **`f2e3157195aae67a5351c2f48427e3833e3a95d2`**. The focused
follow-up validation is recorded separately below; unchanged backend/website evidence comes
from the initial checkpoint. This report is committed after the tested source. No merge or
deployment is authorized or performed by this implementation report.

The requested independent practitioner entry is implemented using the existing external staff
identity application. Google and personal Microsoft choices open its provider picker; workforce
sign-in remains separate. Registration, central review, explicit activation and revocation are
tested with synthetic identities through real HTTP/SQL dispatch. No code accepts a raw Google
or personal Microsoft token as a staff credential. See the
[operating/setup guide](WELLNEXA_PRACTITIONER_SIGN_IN.md).

## Live configuration, routing checks and remaining acceptance

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
not retained in this report. This initial result preceded the authenticated inspection below;
adding an account as a workforce/B2B guest is not the requested design.

### User-approved provider association saved on 1 November

The user supplied an authenticated admin browser in **Wellness Centre Clients Dev**
(`0a3841c6-b244-410d-821f-bbd9ccd1b5e2`). The existing **Microsoft account** custom OIDC provider
had **zero user-flow associations**. The practitioner flow **WellnessPractitionersSignUpSignIn**
selected Google and email OTP only. Its Applications view contained only the dedicated
**Wellness Invited Staff Portal Dev**, SPA `2ccf904c-2b78-4348-9acb-72773d931416`.

The existing provider's visible configuration was inspected: client
`0615ffd8-79b2-43b5-9d1c-162b8823904c`, consumer discovery
`https://login.microsoftonline.com/consumers/v2.0/.well-known/openid-configuration`, issuer
`https://login.live.com`, code response and `openid profile email`. Subject/name/email mappings
used upstream claims including `sub`, `name`, `email` and `email_verified`; no verified-email
constant was added. Secret values were not revealed or copied. Credential validity, owner,
expiry/rotation and full upstream claim behavior remain unverified.

The user explicitly answered **"Save the provider change"** to the exact proposed association.
Save was clicked once. The resulting flow selected Google and Microsoft account with
Save/Discard disabled, retaining email OTP. A fresh public authorization request for the
dedicated staff SPA/API and Wellnexa callback then displayed **both provider buttons**,
confirming the saved association took effect. No new app, secret, API/Graph permission,
workforce/client-flow association, clinic membership or application approval was changed.
No Conditional Access/MFA protection was weakened. The existing broker consumes authentication
and profile claims only; no clinical/booking content was transmitted by these probes.

Each public picker button subsequently reached Entra's **Keep your account secure** phone
MFA enrollment screen with a cached session. No phone number/code was supplied, no enrollment
or application token exchange completed, and no practitioner application was submitted or
approved. Cached sessions mean this does not prove new upstream registration or fresh
Google/MSA credential validation. Automatic directory side effects were not audited. Account
identifiers from the probe are omitted; the temporary tab was closed.

After Save, direct `domain_hint=google` still returned **AADSTS90023** and
`domain_hint=login.live.com` still returned **AADSTS90072**. Microsoft's general
[issuer acceleration guidance](https://learn.microsoft.com/en-us/entra/external-id/customers/concept-authentication-methods-customers#issuer-acceleration)
does not establish that either works for this tenant. The follow-up source removes both
hints and adds English/French guidance to select the provider on the next screen. Both
buttons keep the exact external staff authority/scope; hostile URL hints remain ignored.
This deliberately uses the observed working picker rather than inventing another issuer,
inviting a workforce guest or changing the cloud provider's global settings.

Full live Google/MSA federation, completed MFA, callback/token validation, current granted
scopes and the hosted registration/approval journey remain **unverified**. There was no
hosted database access or assumption that existing records are synthetic. Approved synthetic
identities and a named non-production environment were requested for the remaining rehearsal.
Implementation/provider-save authorization is not release or real-PHI acceptance.

## File inventory and purpose

| Changed file | Purpose |
| --- | --- |
| `Frontend/src/auth/practitionerIdentity.ts` | Centralize external feature gates and closed provider enumeration; omit issuer hints rejected by the live tenant. |
| `Frontend/src/auth/PractitionerSignInOptions.tsx` | Shared accessible Google/MSA/picker controls and next-screen/approval/account guidance. |
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
| `Frontend/tests/build.smoke.spec.ts` | Verify default-off and explicitly enabled production artifacts, workforce separation and the compiled MSAL staff-broker request without authenticating a live account. |
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
| `documentation/WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md` | Approved live host/account scope, observed disabled feature, exact artifact settings, ordinary disable/departure procedure and package/test evidence. |

## Dependencies, permission/consent and endpoint changes

No dependency manifest or lockfile changed. No runtime dependency, supported toolchain or
backend JWT verifier was added/replaced. No app/API/Graph/mail permission, membership grant,
pilot approval or consent wording was changed. Existing joining terms must still be published
and accepted at their exact version. Choosing an authentication provider is not treatment,
marketing or record-disclosure consent.
The separate authorized cloud change enables the existing MSA provider in the practitioner
flow only, as recorded above. No delegated/application scope or clinic role was broadened.

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
| Configured dedicated `copihuewellnessclientsdev.ciamlogin.com` staff authority | Existing staff SPA, `access_as_staff`, code/PKCE redirect to `/staff/external`; no provider hint or caller-supplied authority. Saved two-provider picker and cached-session MFA entry observed; final grant/callback/MFA unverified. |
| `accounts.google.com` through the broker | Existing own-account authentication/profile federation; no appointment or clinical bodies supplied by these changes. Provider credential/callback, retention, geography and support/contract acceptance require the assigned owner. |
| `login.microsoftonline.com/consumers` and configured `login.live.com` issuer | Existing MSA OIDC provider inspected and associated with the practitioner flow after explicit Save approval. `openid profile email`; credential stays in Entra. No direct MSA API trust or new runtime service. Full fresh-account federation remains unverified. |
| Existing workforce `login.microsoftonline.com/{tenant}` | Clinic staff/admin path unchanged, `access_as_user`; no widening to arbitrary/personal tenants. |
| Existing `/public/practitioner-applications`, `/practitioner-applications/*`, central review and `/auth/me` | Existing application/contact/terms and minimum authorization projections. New tests cover exact subject, no membership before activation, clinic boundaries, role cap, replay and revoked access. No new endpoint or PHI transmission. |

Actual deployed endpoint/grant inventory, credential custody/rotation, subprocessor contracts,
support access, retention and incident terms remain for the accountable security/privacy owners.
The provider guides are implementation references, not privacy approval.

## Validation commands and results

The following are the initial broad local checkpoint results at `6a4ba6a`. Commands use repository
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

Initial browser total: **382 passed, two optional skips** across the seven explicitly listed browser
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

Not performed: completed fresh Google/MSA sign-in or intentional account creation, new API grants,
hosted DB/identity/MFA/headers/pilot acceptance, release ZIP or deployment, real-provider mail/
SMS, clean release-environment dependency restoration, exhaustive historical secret scan,
full assistive-technology/contrast/theme/load/penetration/licence or backup-restoration review.
No dependency change was introduced; clean restores remain a release-artifact gate. Main browser
APIs are mocked; signed SQL/HTTP fixtures use only local keys and synthetic people. These do
not establish cloud provider acceptance or organizational compliance.

### Follow-up after the approved cloud save: `f2e3157`

Only four Frontend source/test files changed after the broad checkpoint: provider request
parameters now omit both rejected hints; shared buttons show next-screen guidance; that
guidance has a French translation; actual bridge tests require the unchanged staff scope,
`prompt=login` and no hint even when the page URL contains hostile values. The changed
French mobile/200%-zoom screenshot was visually inspected and remains free of horizontal
overflow. Other browser/SQL/PHP/Wellnexa results above are **not claimed as rerun** at this
revision; those sources and all three lockfile hashes are unchanged.

| Exact follow-up command (from `Frontend`) | Result |
| --- | --- |
| `npm run build` | Exit 0: zero-warning ESLint, four content entries, **2,532** EN/FR translations, strict TypeScript and public/portal/central builds. Existing >500 kB notices remain; no limits changed. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` | Exit 0: **15 passed** (33.8 s), including both real request constructions without hints, separation, callback recovery and French keyboard/zoom. |
| `npx playwright test --config playwright.build.config.ts --reporter=line` | Exit 0: **14 passed** (17.7 s), current production output. |
| `npx playwright test --config playwright.invitations.config.ts --reporter=line` | Exit 0: **seven passed** (25.0 s); existing invitation path retained. |

Follow-up browser total: **36 passed**, no skips or failures. Node/Playwright emitted existing
`NO_COLOR`/`FORCE_COLOR` precedence warnings; they affect terminal coloring, not test behavior.
No package/toolchain/scopes/schema/backend verifier changes. The earlier moderate build-tool
advisory remains unresolved; no new audit acceptance is inferred from the successful build.
An inline `node --input-type=module` documentation check passed for all four follow-up docs,
**94 local links**, **38 control rows**, the exact source SHA/release decision and absence of
private-key markers. `git diff --check` passed; Git's LF-to-CRLF notices reflect the existing
Windows checkout policy. Runtime versions and lock hashes above still apply. No release artifact or deployment
was produced, and the social-button build flag remains default off pending rollout acceptance.

## All 38 controls: dated evidence and next action

Subsequent live-test scope: the owner explicitly approved Wellnexa itself and two named
Google/MSA accounts, with dummy professional details and ordinary account disable afterward.
Addresses remain private. This resolves the host/account-selection question, not the hosted
deployment, pilot identity, record-boundary or terms/access approvals. Anonymous live checks
returned HTTP 200 with all three public application/activation availability fields false;
both dedicated paths still showed workforce entry. No live account mutation occurred.
See the [live test/retirement runbook](WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md).

Normal retirement regression source **`04475b5223483d9440948f419237ce2045365ea7`** adds
20 checks to the existing application integration suite. The normal `AdminService::updateStaff`
Inactive action is exercised for both providers, including scoped administration denial,
same-token workspace denial, activation replay refusal, preserved history/audit and explicit
reactivation without a new trial. There is no production API/schema/dependency change.

Commands from the repository root (final reruns):

- `php -l api/tests/integration/practitioner-applications.php`: exit 0.
- `php api/tests/staff-access.php`: exit 0, staff creation authorization checks passed.
- `php api/tests/external-staff-auth.php`: exit 0, 35 token checks passed.
- With process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`,
  `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13340 -IntegrationTest practitioner-applications.php`:
  exit 0, **36 ledger + 119 application checks** on MariaDB 11.4.8.
- With the same process OpenSSL setting,
  `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13341 -IntegrationTest practitioner-applications.php`:
  exit 0, **36 ledger + 119 application checks** on MySQL 5.7.44.

Both initial SQL attempts failed at RSA fixture generation because the local OpenSSL setting
was omitted. Both disposable servers stopped. Supplying the established configuration and
rerunning fixed the environment without changing assertions, production code or JWT security.
All final disposable servers stopped; only synthetic logs/data are retained locally. This
does not establish hosted UI offboarding or complete operational departure (publication,
future bookings, SMS funding, pending communications and billing require separate review).

Prepared artifact source is **`6808300d02a9ea37a7455c813d6e7d787d05906a`**. The live-test
runbook records exact additional restore/audit/build/browser/archive commands and results,
including the failed first development-browser case and unchanged full confirmation. Both
frontends were restored from locks; their hashes and runtime versions above remain unchanged.
The package enables only the documented browser capabilities; no hosted configuration/account
was modified and full hosted acceptance remains pending. The old 382-case checkpoint is not
claimed to have been repeated. No new runtime dependency, schema, API permission, legal terms
or external endpoint/data flow was added by this follow-up. Generated packages stay ignored.

The subsequent authorized hosted checkpoint applied existing migrations **053, 054 and 055**
once, in order, after verifying the owner's private SQL export structure. Hosted imports
completed **9 + 10 + 8 statements**; a read-only query confirmed **18 tables**, the supporting
unique index, three subscription columns and practitioner audit actor, with no application
settings, applications or billing accounts seeded. MySQL is **5.7.44** and the live API reports
PHP **8.4.27**. The initial read-only verification query's database-name resolution error and
corrected results, source hashes, backup limits, screenshots and pending file-copy job are
recorded in the [live-test runbook](WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md#authorized-hosted-database-checkpoint).
No live application files/settings or test accounts have changed at this checkpoint. The
prepared artifact remains immutable; no new runtime dependency, grant or endpoint was added.

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
| AUTH-01 | Implemented (local scope) | Existing supported MSAL and exact backend JWT checks retained; synthetic raw-upstream/client/workforce denial and brokered flows pass. Saved picker and partial MFA entry observed; full live trust unverified. | Identity/security: verify actual issuer/apps/scopes, callback and MFA in hosting. |
| AUTH-02 | Partial | Current central grant, practitioner-only role, clinic membership, same-email denial, revocation and ordinary Inactive/restore checks pass locally on both engines; no clinical support reach added. | Security/custodian: complete hosted role/record/consent and normal disable tests. |
| AUTH-03 | Partial | Safe callback/recovery UI; cached-session phone MFA enrollment screen observed, not completed. No approved idle/absolute/MFA/departure evidence supplied. | Identity/security: approve session design and verify both providers' policies. |
| AUTH-04 | Partial | New choice/error state does not store tokens; existing MSAL sessionStorage and existing invitation/session exceptions remain unresolved. | Security: approve a time-bounded exception or separately implement approved session architecture before real data. |
| AUTH-05 | Partial | Source scope remains `access_as_staff`; no API/Graph/mail grants changed. Dedicated staff-only flow association observed and existing MSA enabled with user approval. Full deployed grant inventory unavailable. | Identity/operations: inventory exact runtime grants and provider credential access. |
| SEC-01 | Partial | Private files/attachments excluded; limited source/build credential-pattern checks recorded at final checkpoint. Not an exhaustive Git-history scan. | Security/build: complete artifact and indicated history review/rotation if needed. |
| SEC-02 | Partial | Private SQL export structure verified before approved DDL; no TLS checks disabled. Actual restoration, hosted encryption, keys and DB transport remain unverified. | Infrastructure/security: supply encryption/patching/restore evidence. |
| SEC-03 | Partial | Same-origin configured callbacks and fixed provider choices; hosted per-host headers/cache/session acceptance not performed. | Operations/security: verify every portal/API host and shared-device behavior. |
| SEC-04 | Implemented (changed scope) | Closed provider enumeration; rejected issuer hints removed; malicious hint/callback query ignored in actual bridge tests; existing prepared SQL verifier flow unchanged. | Security: complete cloud OIDC/profile and missing-claim acceptance. |
| SEC-05 | Partial | Existing application request/rate/batch bounds retained and exercised; identity-provider abuse controls and representative load unverified. | Security/identity: verify hosted abuse/rate limits and failure handling. |
| SEC-06 | Partial | Existing application/audit atomicity, reads and denied/current authority covered by inherited HTTP/SQL tests; independent integrity/monitoring not proven. | Security/DB/privacy: verify runtime privileges and operational audit review. |
| SEC-07 | Partial | Rejected callback shows generic recovery without raw diagnostic; broader provider/proxy/web logging remains unverified. | Operations/security: inspect/redact external and infrastructure logs. |
| SEC-08 | Partial | Endpoint/field/scope inventory and MSA provider association/configuration recorded; full provider credential/retention/contracts/support approval not supplied. | Privacy/security/vendor owner: approve actual subprocessor flows and credential lifecycle. |
| SEC-09 | Partial | No clinical email/SMS/calendar content or new app messages; provider registration/MFA communications remain outside app guard. | Privacy/operations: approve recipient/CASL classification and vendor handling. |
| DEV-01 | Implemented (task scope) | Instructions, preserved Git state, dedicated branch, package/lock/config/migration/deployment/docs inspected; no merge. Explicitly authorized existing 053–055 migrations applied after backup check; application-file rollout pending. | Maintainer: review and integrate under authorized workflow. |
| DEV-02 | Implemented (local scope) | Focused source review; no new any/ts-ignore/dependency or weakened lint/tests; callback catch maps to visible safe recovery. | Maintainer/security: independent human code review. |
| DEV-03 | Partial | Locks unchanged, current audits and exact versions recorded; 11 moderate build records remain; clean release restore not performed. | Build/security: review compatible minimatch remediation and release restore. |
| DEV-04 | Partial | Listed lint/types/build/local browser/PHP/SQL checks; initial fixture/layout failures recorded and corrected. Omitted hosted scenarios explicit. | QA/release: finish hosted/provider acceptance and retain final results. |
| DEV-05 | Partial | Bilingual provider labels, semantic buttons, keyboard, French mobile and 200%-zoom screenshot checked; full WCAG/themes/AT and AODA applicability unverified. | Accessibility/product/legal: complete applicable assessment and review. |
| DEV-06 | Partial | No new runtime dependencies, polling or SQL queries; fixed-size provider choices. Existing chunk notices and volume performance remain. | Performance/build: review bundle/performance budgets and representative load. |
| REL-01 | Partial | This 38-control source/test ledger is supplied; named accountable owners and human decisions remain absent. | Release owner: assign names and collect dated decisions. |
| REL-02 | Partial | Exact local commands/results and synthetic boundary above; defaults omit dedicated suites, which are run explicitly. Hosted acceptance remains separate. | QA/release: verify final checkpoint and required operational scenarios. |
| REL-03 | Partial | Pilot artifact at source 6808300 has manifest/lock hashes and 237 local archive checks; approved hosted 053–055 imports and schema checks passed. Export structure verified; full restoration, application-file rollout and hosted identities remain unverified. | Release/operations: verify file backups and complete hosted synthetic acceptance/rollback. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; flags are default off and unresolved controls are not waived by tests. | Accountable owners: resolve or lawfully approve applicable internal exceptions. |
| REL-05 | Partial | User authorized implementation, exact MSA association, two-account synthetic testing, controlled pilot deployment and existing 053–055 DDL after backup verification. Those migrations succeeded; file/configuration rollout and accountable operational sign-offs remain pending. No new API grant. | Release/custodian/privacy/security/clinical: retain scoped approval, file-backup/restore and hosted acceptance evidence. |
| REL-06 | Implemented (report scope) | Requested/result scope, file purposes, exact versions/commands, flows, findings, omissions, owners/actions and rollback recorded. | Release owner: verify final source SHA and completion report. |

## Rollback and remaining action owners

No schema/data migration is introduced. Preserve identities, memberships, trial/terms/audit
history. Restore matching approved code/builds; disabling only the new social-button flag
retains the unhinted external login. Keep durable practitioner authentication and workforce
administrator recovery available. The cloud before/after record is above. An approved cloud
rollback would deselect only Microsoft account in the practitioner flow and Save, preserving
Google/email OTP and directory identities. This stops future MSA flow authentication but
does not itself revoke existing tokens; use the approved session/membership revocation
process if necessary. No cloud rollback or data-restoration rehearsal was performed.

The identity administrator supplied authenticated tenant access; they must complete fresh
Google/MSA/callback/MFA acceptance and credential lifecycle evidence. QA/release must run the
same journey with approved synthetic
hosted accounts and the exact proposed artifact. Privacy/security/custodian/clinical owners
must address the applicable missing controls before real-PHI access. No passing local test or
historical tenant-setup document substitutes for those decisions.

RELEASE STATUS: NOT READY FOR RELEASE
