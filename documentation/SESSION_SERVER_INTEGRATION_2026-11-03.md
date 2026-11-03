# Server sessions: existing routes and browser integration

3 November 2026. Local implementation on `feature/server-session-integration`, based on `b35a12005d26d5addf5f79fa0824e0dc98cc189f`. Exact source revision and final checkpoint results are appended below. This updates the integration boundary in the [earlier core report](SESSION_SERVER_CORE_2026-11-03.md); earlier evidence describes its own revision.

The owner authorized local integration, synthetic testing and documentation. Production, hosted databases, private environment files, cloud registrations/grants and retired live practitioner accounts were unchanged. Nothing was merged or deployed. This report does not authorize enabling authentication or accepting PHI.

## Implemented behavior

Existing workforce, independent practitioner, central Admin and client routes now have an opt-in server session boundary. Verified API bearers remain mandatory. Host-only HttpOnly cookies add independent idle/absolute/revocation enforcement and never grant a role or recover a bearer.

| Setting | Default | Meaning |
| --- | --- | --- |
| API `BROWSER_SESSION_CLINIC_IDS` | `[]` | Positive integer clinic IDs; every mapped alias for an enabled clinic requires the new boundary. |
| API `BROWSER_SESSION_CENTRAL_ENABLED` | `false` | Enable the exact central administration host independently. |
| API `ENTRA_SPA_CLIENT_ID` | empty | Existing public workforce SPA ID, distinct from the API audience; required when workforce/central session policy is used. |
| Frontend `VITE_SERVER_SESSIONS_ENABLED` | `false` | Build capability; discover actual host mode before importing identity clients. |

Discovery failure blocks startup. Explicit server `enabled:false` preserves the legacy path for that unactivated scope. API activation without matching browser assets denies protected access; there is no automatic downgrade. An Origin header on a protected read must also match the current host; an absent Origin on a same-origin GET does not replace bearer/cookie checks. Enabled sessions require the portal's own same-origin API. No dependency, supported toolchain, Graph scope, grant, registration, redirect URI or external provider was added or replaced.

```mermaid
sequenceDiagram
    participant UI as Browser memory / supported MSAL
    participant API as Existing API + session gateway
    participant DB as Session database
    UI->>API: Single-use challenge
    API-->>UI: Nonce + HttpOnly challenge cookie
    UI->>UI: Interactive popup / existing registered callback bridge
    UI->>API: API bearer + fresh SPA ID proof + challenge cookie
    API->>DB: Bind current local authority; persist digest/session/audit
    API-->>UI: HttpOnly session cookie + CSRF/deadlines, no bearer
    UI->>API: Existing protected route + bearer + cookie + write Origin/CSRF
    API->>DB: Hold authority barrier; recheck session, membership and roles
    API->>DB: Existing service savepoints + final disclosure audit
    API->>DB: Commit physical request
    API-->>UI: Bounded private response; discard after generation changes
```

`GET /api/v1/auth/session/options` exposes only a boolean. Session families are `/staff/session/*`, `/practitioner/session/*`, `/application/session/*` and existing `/customer/auth/*`. Actions are challenge, start (session for clients), me, activity, logout, action-challenge, action-proof and revoke-all. Client `auth/me` retains its full onboarding/status contract. Existing anonymous catalog/discovery/health boundaries remain.

The implemented limits are 15-minute idle / 8-hour absolute for workforce, practitioner and central, and 30-minute idle / 8-hour absolute for clients. Only trusted keyboard/pointer activity requests idle extension, at most once per minute; polling and token renewal do not extend it. `/admin/*` and central writes additionally require authentication within five minutes. Security/product must approve these timings and the sensitive-action scope before rollout. MFA assurance is a separate unverified provider-policy gate.

“Sign out all sessions for this portal” requires fresh same-account purpose-bound proof. It revokes the current **host + clinic (where applicable) + identity realm** scope, not other aliases/clinics/realms or the identity provider. Normal logout clears private UI immediately and visibly reports an unconfirmed server result. Challenge warming finishes after cookie clearing. Generation checks prevent old activity/token/response completions from reviving or ending a newer session.

Enabled mode keeps MSAL tokens/accounts and private application intents in page memory. Only the selected staff/central/client bootstrap configures the session identity client; importing another identity module for account-menu links cannot change it. The supported popup bridge runs before application/config loading at existing registered callbacks. Only harmless preferences, including provider/application-return intent, retain storage. Startup removes this application's legacy MSAL token/account indexes and private action/session state without displaying token values. Reload/new-tab requires interactive sign-in despite a shared cookie. Cancel sign-in reloads the document to discard SDK interaction state. A simulated pagehide/persisted-pageshow test clears private UI; it is not native Safari bfcache certification.

## Database and request transactions

Migration **060** requires 059 and the existing documented identity/clinic/pilot foundations. It adds an authority-barrier table, three nullable local reference/FK/index bindings on browser_sessions and authority-change triggers. It invalidates existing unbound/versioned core sessions without deleting clinical data or converting legacy customer sessions. Triggers cover clinic/user authority, role/permission grants, membership/product identity, explicit Admin grants, pilot status and ownership changes, customer links/identity tuples, host mappings and workforce identity links. Client rows with `policy_version IS NULL` remain legacy and cannot bypass an enabled gateway.

A guarded request owns one physical transaction; existing service transaction methods use savepoints inside it. Shared barriers remain held through final required AccessAudit and commit. Authority-changing writers take exclusive barriers through triggers. A concurrent real Staff service test proves that revocation waits for an admitted request, then commits and blocks its next retained-cookie request. Deadlocks, five-second lock waits and lost transactions fail closed without automatic retries.

Rejected business effects roll back to a post-admission checkpoint while authority locks remain through error disclosure/audit. Narrow fixed customer-rate and wrong-code counters are reapplied inside that boundary, preserving denial budgets. A forced final audit failure rolls back a real Staff service's prior savepoint commit. Guarded images/304 also finalize audit/commit and use no-store; public images retain their cache policy.

No database grants were applied. Actual hosting trigger support, migration/runtime privileges, contention and restoration remain unverified. New FKs intentionally prevent deletion of referenced authority records; use normal Inactive/revocation and an approved retention process.

## Remaining gates and limitations

- **Three synchronous email paths fail closed in enabled mode:** staff verification-code sending, client invitations requested with email delivery, and practitioner-enquiry notification retry. They need a commit-safe outbox/claim design before these functions can be enabled. Manual client invitation remains supported. Existing post-response workers retain their checks; this task added no messaging or payment transport.
- The actual API rehearsal covers submission, independent approval, activation, fresh practitioner-only sign-in and retirement, with exactly one six-calendar-month trial. Membership creation ends the applicant session; a new sign-in binds its new membership. The browser suite composes production session modules and real MSAL against actual API handlers, and additionally exercises the actual practitioner and client portal bootstraps. The practitioner dashboard disappears after a real Inactive save and its next protected Refresh; the client workspace requires sign-in after reload. It does not exercise every clinical workspace against an exhaustive database fixture; existing complete portal/application browser regressions use their established mocks separately.
- Real Google/Microsoft/workforce/central/client provider claims, MFA/Conditional Access, account switching and registered callback behavior still require hosted acceptance. No real credentials/tokens were used. Existing four strict Windows WebKit SameSite failures and real Safari acceptance remain open; the owner deferred BrowserStack access. Edge/Firefox synthetic interception does not establish hosted TLS/Safari acceptance.
- Full screen-reader/theme/contrast/zoom audit, representative-volume contention/performance, retention/holds/purge policy, named owners, incident/lost-device rehearsal, actual HTTPS/headers/CSP/database/backup encryption, restoration and hosted rollback remain release gates. Reauthentication can discard unsaved memory state; approve that UX before activation.
- No release ZIP or hosted artifact was created. Local builds/scans do not establish legal compliance, real-data authorization or deployability. No legal commencement/applicability conclusion was reassessed.

## Initial failures retained

Ignored `.tmp/session-integration-*` logs retain initial failures and final reruns. TypeScript caught the optional legacy customer-token path. Wellnexa caught an import inserted above its required leading Google Maps type directive. PHP needed the installed Git OpenSSL configuration; existing reflection fixtures exposed eager config access before a cached connection return. These were corrected without loosening checks.

A synthetic central fixture used the wrong existing status enum, and a Windows browser subprocess resolved npx incorrectly. The fixture uses `revoked` and the installed local Playwright CLI through Node. Early browser tests found a real logout/activity generation race and post-logout challenge ordering; the fixes include a logout/relogin regression. French mobile dialog actions now wrap, and sign-in cancellation provides explicit document-reset recovery.

Synthetic clients initially shared and exhausted the actual challenge limiter. Only the hard-gated loopback test router now assigns independent synthetic loopback addresses; production limits are unchanged. Aborted interception cleanup needed explicit teardown rather than indefinite waiting. Actual portal coverage also caught identity-client selection at module import; selection now occurs only in the chosen bootstrap. Its initial disposable schema lacked discovery tables, correctly causing a protected 500 and UI shutdown; the fixture was completed instead of relaxing the gateway. A toolbar-scoped selector resolved multiple legitimate Refresh buttons. Assertion-time transport failures remain failures. A lint failure on exception causality was fixed by preserving the caught error.

Early broad runs overlapped source edits or existing Vite configurations sharing the same ports. Those attempts are invalidated/infrastructure evidence, not acceptance. The final existing-configuration checkpoint runs sequentially against fixed frontend source. Optional private-workbook tests remain skipped. The fixed-source broad run initially had 289 pass / 2 skip / 2 failure: an intermediate client-finder query and initial enquiry-list rendering. Focused repetitions were 5/6, reproducing the finder timing problem. Installing the test clock alone did not freeze real time while Playwright filled fields; that test now explicitly pauses/resumes time. The enquiry test awaits its initial successful list response before asserting rendering. Search equality and all denial-clearing assertions remain intact. A later full run passed those corrected cases but exposed two cold-start appointment-link assertions before the client portal had mounted. Their setup now waits for the Client portal heading (30-second cold compilation budget); token hiding and wrong-account/confirmation checks retain their normal deadlines. All seven link cases passed three focused repetitions (21/21). The actual-dashboard revocation test also had to tolerate a normal protected poll removing its toolbar before a planned click, and restore the independent synthetic baseline after each case. The last broad run then had 290 pass / 2 skip / 1 failure. Its trace shows repeated Edge `net::ERR_NETWORK_CHANGED` errors during travel-settings reload, followed by the safe startup-error page; 3/3 focused reruns passed without a code change. The full run remains non-green and must be repeated in a stable release environment. No tests or rules were removed or disabled to accommodate product defects.

## Changed files

The inventory below lists every source/test/configuration file and purpose. Documentation changes are this report, documentation/README.md, and later-integration notes in the existing core report and implementation plan. Dependency and lock files are unchanged. The unrelated documentation/REQUIRED_FOR_SITE.md and documentation/blog-topics.pdf remain untouched and unstaged.

| File | Purpose |
| --- | --- |
| `Frontend/.env.example` | Document disabled public build capability. |
| `Frontend/playwright.server-session.config.ts` | Isolated Edge/Firefox real-route session suite; no hosted identity or database. |
| `Frontend/src/admin/BusinessSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/CatalogueSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/DashboardWidgetAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/LocationAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/NotificationStatusAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/PractitionerAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/PrivacyAuditReview.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/RoomAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/RoomCapabilities.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/ServiceAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/ServiceAssignments.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/StaffAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/admin/TeamAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/auth/AuthProvider.tsx` | Integrate memory-mode workforce/practitioner/central sign-in and remove authenticated rendering when the lease ends. |
| `Frontend/src/auth/PractitionerApplicationPage.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/auth/ServerSessionNotice.tsx` | Bilingual expiry, retry/cancel, sign-out warning and keyboard-accessible fresh-confirmation dialog. |
| `Frontend/src/auth/StaffInvitationPage.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/auth/StaffSignIn.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/auth/UserAccountMenu.tsx` | Use guarded authenticated transport and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/auth/privateSessionStorage.ts` | Memory-only private intent/session state when activated; retain harmless preferences and legacy behavior. |
| `Frontend/src/auth/serverSession.ts` | Memory bearer/lease manager; real MSAL popup, generation checks, bounded transport, idle/activity, scoped revoke-all, immediate UI end and confirmed logout recovery. |
| `Frontend/src/auth/serverSessionMode.ts` | Fail-closed same-origin capability discovery, early supported popup bridge and scoped legacy credential cache removal. |
| `Frontend/src/booking/StaffAppointments.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/central/bootstrap.tsx` | Move the existing central app into an explicit bootstrap; initialize the selected session mode. |
| `Frontend/src/central/main.tsx` | Handle the popup bridge before loading the central application. |
| `Frontend/src/clients/ClientManagement.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/config/ClinicConfigProvider.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/customer/ClientApp.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/customer/CustomerWorkspace.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/customer/appointmentLink.ts` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/customer/auth.ts` | Integrate session lease/expiry state; preserve disabled-mode behavior. |
| `Frontend/src/customer/bookingIntent.ts` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/customer/bootstrap.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/customer/session.ts` | Use guarded authenticated transport and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/dashboard/Dashboard.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/dashboard/PractitionerToday.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/dashboard/PractitionerTravelCard.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/dashboard/PractitionerVisitSummary.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/dashboard/QualificationReminder.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/discovery/ClientDiscovery.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/discovery/LocationSearch.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/discovery/travelIntent.ts` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/forms/api.ts` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/guest/GuestPortal.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/i18n/index.ts` | Register the additional bilingual messages. |
| `Frontend/src/i18n/serverSessions.ts` | English/French session notices and safe API error messages. |
| `Frontend/src/main.tsx` | Handle popup responses before runtime configuration or application bootstrap. |
| `Frontend/src/portal/PortalApp.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/portal/PractitionerClients.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/portal/PractitionerNotifications.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/portal/StaffPortal.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/portal/bootstrap.tsx` | Integrate session lease/expiry state and memory-only private state in activated mode; preserve disabled-mode behavior. |
| `Frontend/src/portal/staffBootstrap.tsx` | Integrate session lease/expiry state; preserve disabled-mode behavior. |
| `Frontend/src/profile/PractitionerQualifications.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/profile/PractitionerTravelSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/profile/PractitionerWorkLocation.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/profile/ProfileSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/profile/PublicCardSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/profile/StaffNotificationSettings.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/scheduling/AvailabilityAdmin.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/scheduling/PractitionerCalendar.tsx` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/shared/api.ts` | Use guarded authenticated transport; preserve disabled-mode behavior. |
| `Frontend/src/shared/runtimeConfig.ts` | Discover server-session mode before importing identity clients; retain legacy runtime config. |
| `Frontend/src/vite-env.d.ts` | Declare the opt-in build flag. |
| `Frontend/tests/helpers/serverSessionBrowser.ts` | Generated signed synthetic IdP, real PKCE, exact network allowlist, native browser cookie forwarding and guarded teardown. |
| `Frontend/tests/appointment-action-links.spec.ts` | Explicit client-portal startup readiness before retaining all existing token-hiding, account-denial and confirmation assertions. |
| `Frontend/tests/portal.spec.ts` | Freeze the debounce clock and wait for the initial enquiry list response; retain all search and post-denial privacy assertions. |
| `Frontend/tests/server-session-integration.spec.ts` | Four realms, both practitioner profiles, storage, cancellation, logout/relogin, idle, revocation, delayed responses, dialog, mobile/French and page restoration. |
| `Frontend/tests/server-session/index.html` | Test-only integration document. |
| `Frontend/tests/server-session/main.tsx` | Run the production popup bridge before the test composition. |
| `Frontend/tests/server-session/workspace.tsx` | Compose real production session code, real MSAL and private-state UI against existing API handlers. |
| `Frontend/tsconfig.server-session.json` | Strictly typecheck the integration composition, production imports and browser helper. |
| `Frontend/vite.server-session.config.ts` | Separate loopback-only synthetic composition and Vite cache; not a release entry point. |
| `api/.env.example` | Document disabled private activation and the public workforce SPA identifier. |
| `api/database/migrations/060_browser_session_integration.sql` | Add authority barriers, nullable local session bindings/FKs and authority-change revocation triggers; invalidate existing versioned unbound sessions. |
| `api/src/Api.php` | Register realm-specific session endpoints and guard existing protected routes with current authority and final disclosure audit. |
| `api/src/Auth/CustomerAuthenticator.php` | Expose the existing verified signing-key loader for session proof validation; retain the identity realm and provider endpoints. |
| `api/src/Auth/EntraAuthenticator.php` | Expose the existing verified signing-key loader for session proof validation; retain the identity realm and provider endpoints. |
| `api/src/Auth/ExternalStaffAuthenticator.php` | Expose the existing verified signing-key loader for session proof validation; retain the identity realm and provider endpoints. |
| `api/src/Config.php` | Strict default-off clinic/central activation and distinct public workforce SPA identifier. |
| `api/src/Database.php` | Select the managed PDO connection only for configured session scopes; preserve injected/legacy connections. |
| `api/src/Http/BrowserSessionEndpoint.php` | Reject foreign-Origin metadata reads and retain explicit opt-in transport. |
| `api/src/Http/Response.php` | Run required audit/commit before JSON, image or 304 disclosure; private image responses are no-store. |
| `api/src/Service/AccessAudit.php` | Recognize the new session route words in redacted audit route classification. |
| `api/src/Service/BrowserSessionAuthority.php` | Server-only current authority binding DTO; it grants no membership or role. |
| `api/src/Service/BrowserSessionGateway.php` | Compose verified bearer, cookie, Origin/CSRF, current local binding, barriers, recent administrative proof and request finalization. |
| `api/src/Service/BrowserSessionService.php` | Bind server-resolved local authority on admission while retaining existing core callbacks. |
| `api/src/Service/BrowserSessionStore.php` | Store optional local authority references; retain migration-059-only core fixture compatibility. |
| `api/src/Service/CustomerOnboarding.php` | Preserve abuse counters on guarded denials; block synchronous email invitation delivery in the new mode. |
| `api/src/Service/PractitionerEnquiryService.php` | Block synchronous notification retry while a guarded request owns the transaction. |
| `api/src/Service/StaffNotificationPreferences.php` | Preserve wrong-code attempts; block synchronous verification email in guarded requests. |
| `api/src/SessionConnection.php` | Physical guarded-request transaction, nested service savepoints, bounded lock waits and retained denial counters. |
| `api/tests/helpers/server-session-fixture.php` | Disposable synthetic schema, identities, memberships, plans and separate realm configuration; no environment file. |
| `api/tests/helpers/server-session-http.php` | Owned loopback PHP process, generated test signing keys, HTTP cookie/header assertions and named-key cleanup. |
| `api/tests/helpers/server-session-revoke-worker.php` | Private-pipe disposable concurrent real Staff service revocation worker. |
| `api/tests/helpers/server-session-router.php` | Hard-gated real Api router with loopback/disposable database constraints, synthetic public keys, test clock and isolated abuse buckets. |
| `api/tests/integration/server-session-integration.php` | Actual route, service, audit rollback, abuse, registration/approval/activation, binding, recent-proof and concurrency acceptance. |
| `scripts/test-mysql57-sql.ps1` | Allowlist the new disposable suite; preserve owned-process/port/database safeguards. |
| `scripts/test-recurring-sql.ps1` | Allowlist the new disposable suite; preserve owned-process/port/database safeguards. |

## All 38 controls

Reviewed 3 November 2026 by the coding agent against the integration revision recorded below. Implemented means the stated local scope only. Role owners are **not yet named or signed off**, an unmet release requirement. Historical audits remain intact. No legal applicability/commencement decision is inferred from engineering results.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custody/operator/contracts unchanged; custodian identify named clinic/platform owners. |
| GOV-02 | Unverified | No legal commencement reassessment; privacy/legal maintain actual applicability decisions. |
| GOV-03 | Unverified | No new commercial flow; privacy/legal assess actual PIPEDA scope. |
| GOV-04 | Partial | Administrative identity/session core only; clinical/product retain intended-use assessment. |
| GOV-05 | Partial | Synthetic disposable schemas, signing keys and identities only; privacy/security must update the actual data inventory, PIA/TRA and residual-risk decisions. |
| PRIV-01 | Unverified | Clinic notices/contact unchanged; custodian verify actual bilingual practices. |
| PRIV-02 | Partial | No patient/consent collection; metadata limited to session authority/digests. Privacy approve actual retention/purpose/integration. |
| PRIV-03 | Partial | Current authority/role/pilot/client binding, denial rollback and real Staff concurrency tested. Privacy/security/clinical must complete hosted record/consent/worker boundaries. |
| PRIV-04 | Unverified | No new representative/minor authority; clinical/custodian retain scoped assisted process. |
| PRIV-05 | Unverified | Patient rights workflows unchanged; privacy operations retain actual evidence. |
| PRIV-06 | Partial | No record deletion/purge; migration preserves legacy history. Records/privacy approve class-specific session/audit/backup retention and holds. |
| PRIV-07 | Partial | Own logout/revoke-all/departure primitives tested; incident/privacy operations rehearse actual lost-device/breach procedures. |
| AUTH-01 | Partial | Actual API composition uses supported MSAL and existing JWT verifiers; signed synthetic realm/proof negatives. Identity/security must verify real providers, MFA and Conditional Access. |
| AUTH-02 | Partial | Protected existing routes gain bearer+cookie authority barriers; real account/pilot/Admin/client/applicant negatives. Security/clinical must complete hosted cross-record and clinic review. |
| AUTH-03 | Partial | Independent limits, trusted activity, recent admin proof, scoped revoke-all and mounted UI removal tested. Security/product must approve policy, sensitive-action scope, MFA and device procedures. |
| AUTH-04 | Partial | Activated mode uses memory tokens/private state, HttpOnly cookies, Origin/CSRF, popup and logout/relogin. Legacy storage exposure and real Safari/hosted rollout remain unresolved; no silent exception. |
| AUTH-05 | Partial | No grants, dependencies or registrations changed. Operations/security must evidence deployed migration/trigger/runtime privileges and the actual scope/role inventory. |
| SEC-01 | Partial | Generated disposable keys, ignored runtime/logs and scoped scans below; no private env/dumps staged. Security must review actual artifacts/history and any confirmed exposure. |
| SEC-02 | Partial | Existing transport not weakened, no TLS bypass; real HTTPS/DB encryption/backup/key custody unverified. Operations own. |
| SEC-03 | Partial | Same-origin mode, exact Origin/CSRF, host cookies, no-store and late-body suppression tested. Operations/browser reviewers must verify actual host headers, native cache/TLS and Safari. |
| SEC-04 | Partial | Prepared fixed SQL and strict scope/body/cookie/proof checks; no HTML sink or sanitizer added. Human security review of broad composition and migration remains required. |
| SEC-05 | Partial | Bounded buffers/body/locks, persistent denial counters and actual service race tested. Operations/security must verify edge rates, volume, expiry cleanup and costly integrations. |
| SEC-06 | Partial | Required final AccessAudit inside physical commit; forced audit failure rolls back an existing service commit. Security/operations must approve immutable retention, grants and non-API/operator/provider logging. |
| SEC-07 | Partial | Safe UI messages and classified correlation diagnostics; existing error policies pass. Operations must verify actual proxy/provider/APM/support logging. |
| SEC-08 | Partial | No new provider call/host/service/application flow; privacy/vendor owners retain actual subprocessor register decisions. |
| SEC-09 | Partial | No new mail/SMS/payment transport; three synchronous paths blocked in activated mode. Communications/security must implement/review commit-safe dispatch before enabling them. |
| DEV-01 | Implemented | Dedicated feature branch, inspected controls/configuration/migrations, preserved unrelated work; source/evidence committed and pushed for review, without merge or deployment. |
| DEV-02 | Partial | Strict checks, meaningful negative/rollback/race regressions and retained initial failures. Human review of broad integration remains required. |
| DEV-03 | Partial | Exact existing locks, Composer install/audits, no dependency addition; 11 dev advisories and clean-release/license/maintenance evidence remain. |
| DEV-04 | Partial | Exact policy, dual-engine SQL/HTTP, build and dedicated browser results below. Skips, omitted contexts and operational/provider acceptance remain explicit. |
| DEV-05 | Partial | Bilingual expiry/recovery dialog, keyboard cancellation/focus restoration and French mobile checks. Accessibility owner must review screen reader, themes, contrast, zoom and statutory applicability. |
| DEV-06 | Partial | Indexed lookups, bounded buffers/lock waits, activity throttling and cleanup. Operations must assess real contention, query/crypto cost, memory and bundle size. |
| REL-01 | Partial | Source-bound 38-control ledger and complete changed-file inventory. Release owner must obtain named accountable reviewer decisions. |
| REL-02 | Partial | Final local checkpoint below uses disposable synthetic databases. No hosted evidence inferred; initial failures/skips/untested contexts retained. |
| REL-03 | Partial | Local artifact inspection/provenance below; no release ZIP. Release/security/operations must complete approved hosted identity/database/header/backup/rollback acceptance. |
| REL-04 | Missing | Required hosted/provider/Safari/operational ownership evidence and communications integration remain incomplete. Release owner must keep release closed. |
| REL-05 | Partial | Authorization covers local implementation only. No production deploy/migration/merge/grants/external-flow changes. Concrete rollout and accountable signatures remain required. |
| REL-06 | Partial | Requested result, files, versions/flows, exact commands, failures/gaps/owners and rollback recorded; source and final checkpoint evidence below. |

## Rollback and next work

There is nothing to undo in production. Current rollback is reverting this unmerged local feature branch. No hosted SQL rollback was executed. A later rollout needs verified backup/restoration, separately approved migration credentials and trigger support, coordinated API/assets/entry settings, closed intake and hosted synthetic acceptance before activating a whole clinic or central realm.

For an activated incident, close affected entry points and invalidate versioned sessions first. Disable coordinated capability/configuration or restore a reviewed artifact only under a recorded release decision; do not silently reopen bearer-only/legacy storage as a security rollback. Retain additive schema and session/audit history pending approved restoration/retention decisions. Restoring authority must never revive old sessions.

Next work is commit-safe integration of the three synchronous email paths, human architecture/security review, actual identity/MFA and Safari acceptance, and a concrete hosted rollout/rollback package. Documentation does not authorize that rollout.

## Final validation and source evidence

Integration source: `562135f935afcd57b38203f4185dc3ce70dad611`. Test-only correction revisions: `ddc5760d5be078fc1ebf5bb5fc2100dec9185063` and `a1db4e7d0f3e800a372d5505e72849417342fd9b`. Runtime source is unchanged by those two commits. Branch: `feature/server-session-integration`. No merge or deployment. Recorded on 3 November 2026; evidence below refers to local source and synthetic fixtures only.

Commands were run from the named surface/root. Browser configurations using shared Vite ports were run sequentially. OpenSSL-dependent PHP commands used the installed `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`; prior environment values were restored. Edge/Firefox used the existing isolated `PLAYWRIGHT_BROWSERS_PATH=D:/Dev/WelnessCentre/.tmp/session-browsers`.

| Check / exact command | Result |
| --- | --- |
| Isolated copies of Frontend and Wellnexa package/lock files: `npm ci --no-audit --no-fund` | Both exit 0. 350 and 30 packages restored respectively. Frontend npm warned that esbuild's postinstall was not covered by allowScripts; no policy override or script approval was performed. This validates lock restoration, not a clean release build. |
| Frontend `npm audit --json`; `npm audit --omit=dev --json` | Full exit 1: 11 moderate development findings; runtime exit 0: zero vulnerabilities. ESLint/typescript-eslint/minimatch tooling chain; no runtime dependency change or forced fix. Exposure/remediation acceptance remains governed by FRONTEND_QUALITY_GATES.md. |
| Wellnexa same two audit commands | Both exit 0, zero vulnerabilities. |
| API `composer install --no-interaction`; `composer validate --strict`; `composer audit --format=json` | Each exit 0; no advisories or abandoned packages. No lock changes. |
| Frontend `npx tsc --noEmit --project tsconfig.server-session.json` | Exit 0, includes production session imports and strict browser composition/helper tests. |
| Frontend `npm run build` | Exit 0: ESLint, content (4 entries), i18n (2569 keys in each language), strict TypeScript, public/portal/central production builds. Large-chunk warning retained. |
| Frontend `npm run lint` after test-only timing changes | Exit 0. |
| Frontend `node node_modules/@playwright/test/cli.js test --config playwright.config.ts --reporter=json` | Exit 1: 290 passed / 2 optional skips / 1 infrastructure failure (9.2m). During travel-settings reload the trace records repeated `net::ERR_NETWORK_CHANGED` resource failures and the safe startup-error screen. The affected case subsequently passed 3/3 focused repetitions with unchanged application/test code. This full run remains non-green; focused recovery does not rewrite it. |
| Frontend `node node_modules/@playwright/test/cli.js test --config playwright.config.ts tests/portal.spec.ts --grep 'appointment client finder filters by exact birthdate\|practitioner enquiry refresh denial' --repeat-each=3 --workers=1 --reporter=json` | Exit 0: 6/6 after deterministic timing correction. Prior 5/6 and broad failures retained above. |
| Frontend same CLI, `tests/appointment-action-links.spec.ts --repeat-each=3 --workers=1` | Exit 0: 21/21; cold-start setup correction retains all seven action-link contracts. |
| Frontend same CLI, `tests/portal.spec.ts --grep 'practitioner travel settings save only the selected assigned service and persist on reload' --repeat-each=3 --workers=1` | Exit 0: 3/3 after the trace-confirmed network interruption, without changing that test or application behavior. |
| Frontend `node node_modules/@playwright/test/cli.js test --config playwright.build.config.ts --reporter=json` | Exit 0: 15/15. |
| Frontend same CLI with `--config playwright.application-admin.config.ts` | Exit 0: 46/46. |
| Frontend same CLI with `--config playwright.invitations.config.ts` | Exit 0: 7/7. |
| Frontend same CLI with `--config playwright.applications.config.ts` | Exit 0: 22/22. These three dedicated configurations are omitted by the default config. |
| Wellnexa `npm run lint`; `npm run build`; `npm run test:browser` | Each exit 0; browser 24/24. |
| Root `Get-ChildItem api/tests -File -Filter '*.php'` followed by `php <each returned file>` | 63 scripts, every exit 0. Forced audit-failure diagnostics are expected assertions, not hosted incidents. |
| Root `php -l <each api/src PHP file, server-session-* helper and integration file>` | 128 files, every exit 0. |
| Root `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <installed mysql-5.7.44-winx64> -Port 13407 -IntegrationTest browser-sessions.php` | Exit 0: 188 core SQL/transaction/endpoint checks, including repeated races. |
| Root `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13408 -IntegrationTest browser-sessions.php` | Exit 0: same 188 core checks. |
| Root MySQL runner with `-Port 13404 -IntegrationTest server-session-integration.php`, process `RUN_SERVER_SESSION_BROWSER=true` | Exit 0: 130 integration checks, including 28/28 Edge/Firefox browser cases through `playwright.server-session.config.ts`. This uses real Api/services/PDO/JWT verification, real MSAL/PKCE and actual practitioner/client portal bootstraps against generated identities. |
| Root MariaDB runner with `-Port 13409 -IntegrationTest server-session-integration.php` | Exit 0: 129 integration checks. The one additional MySQL check is successful completion of the browser subprocess; MariaDB did not repeat that same browser matrix. |
| `git diff --check`; staged path/credential and build scans | No whitespace error or staged private/generated candidate; scoped artifact findings classified below. |

The two default-suite skips are the existing optional private priced-CSV and Services-Library workbook acceptance. No private workbook was read. Tests not executed here include actual Safari/Windows-WebKit acceptance, actual identity-provider/MFA/Conditional Access, hosted DB migration/trigger/grants and concurrency, real communications/payment transport, backup restoration, release rollback, representative-load and complete accessibility/clinical/privacy acceptance. No hosted SQL/HTTP or live account operation was performed.

Runtimes: Windows PowerShell, Node **22.22.1**, npm **11.19.0**, PHP **8.4.14**, Composer **2.9.5**, MariaDB **11.4.8**, MySQL **5.7.44**. Browser matrix: installed Edge **157.0.4341.30**, Firefox **155.0**. PHP 8.2 remains the declared minimum; this local PHP run does not verify every supported/deployed patch version.

Exact unchanged frontend locks: Frontend React **19.2.8**, TypeScript **5.7.3**, MSAL browser **5.19.0**, MSAL React **5.6.0**, Vite **6.4.5**, ESLint **10.13.0**, Playwright **1.63.0**. Wellnexa React **19.3.0**, TypeScript **6.0.3**, Vite **8.3.4**, Oxlint **1.88.0**, Playwright **1.64.1**. Other existing dependency versions remain exactly those in unchanged lock files.

Exact Composer runtime lock: dompdf/dompdf **3.1.6**, php-font-lib **1.0.2**, php-svg-lib **1.0.2**, firebase/php-jwt **7.1.0**, giggsey/libphonenumber-for-php **9.0.40**, giggsey/locale **2.9.0**, masterminds/html5 **2.11.0**, nikic/fast-route **1.3.1**, sabberworm/php-css-parser **9.5.0**, symfony/polyfill-mbstring **1.43.0**. No package, lock or supported-toolchain update was committed.

| Lock | SHA-256 |
| --- | --- |
| Frontend/package-lock.json | `cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c` |
| Wellnexa/package-lock.json | `17b1d75bce7d40a857dfd16c319f32a116b905e799f339c4443446da3a571f1e` |
| api/composer.lock | `16fab4fc79b83afd34d4200474204bfc55cd7fe40d650a0c39d848dc4f93265f` |

### Artifact scope and scans

Local build capability remains disabled by default; no activation package or ZIP was created. Private environment files, dumps, dependencies, `.tmp` diagnostics/keys, attachments and build outputs are not in the new commits. Local output label: `session-integration-2026-11-03` (report label, not an application release tag). Date/hashes below identify local outputs only, not a controlled clean release artifact. An approved activation artifact must separately record its public configuration, source SHA, clean toolchain/restore, manifest and hosted rollback evidence.

| Output | Files | SHA-256 of ordered relative path/hash pairs |
| --- | --- | --- |
| `Frontend/dist/public` | 8 | `a581e3cf8ba38edb649d9671c006d4ec96073117a4198779a0eabc012f0ea4d2` |
| `Frontend/dist/portal` | 74 | `8f46fdb905e73e559de85b53e284cae43f1c8eb64bbbf7b31244cfeecacb82a1` |
| `Frontend/dist/application-admin` | 7 | `c561c26172d93a7514ad9fd6279679916acb23a849041ba0c25c9f56bae52d66` |
| `Wellnexa/dist` | 10 | `76da3cf36546935437f95f7c6b38f415e2d4767f703be2e0b586616919bc5efc` |

The aggregate is SHA-256 of compact JSON containing sorted `[relative_path, file_sha256]` pairs; individual hashes are retained in ignored `.tmp/session-integration-artifact-final.json`. Build output source corresponds to the integration runtime revision above; subsequent commits change tests only. No activation build was made.

Scoped scans found no literal private key/JWT, integration fixture domain/private intent, private filename or source map in these outputs, and no literal private keys or JWTs in changed source. Static HTTP strings were classified: XML/SVG/MathML namespace identifiers, React Router's localhost URL parsing base, and MSAL common's optional regional IMDS constant. Regional discovery is not configured by these clients; no such HTTP request occurred in the synthetic allowlisted tests. These literals are not configured application API endpoints; artifact inspection must be repeated for a real release. Public identifiers are not secret credentials. Full secret history, dependency licence/maintenance and subprocessor/hosting acceptance remain unverified.

New data flow is first-party session metadata/digests and local authority references in the existing database, plus bearer/proof/lease state in browser memory. Existing identity signing-key transports are reused. Permission/admin-consent/grant changes: **none**. New external hosts/services/subprocessors/PHI transmissions: **none**. Existing signed-provider acceptance is still an operational gate. No messages, charges or clinical records were created outside disposable synthetic fixtures.

RELEASE STATUS: NOT READY FOR RELEASE
