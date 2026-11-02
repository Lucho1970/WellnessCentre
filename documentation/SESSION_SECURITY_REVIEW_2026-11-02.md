# Session security review and implementation plan — 2 November 2026

**Review and proposal only. No authentication behavior has been changed.**

Source reviewed: `4b7ed03` (completed practitioner UI/revocation checkpoint).
Branch: `feature/session-security-review`, created from that revision, not merged into
main. Reviewer: Codex, source/local synthetic checks and bounded anonymous HTTP evidence.
Accountable security, identity, privacy and release reviewers still need named decisions.
The unrelated untracked `documentation/blog-topics.pdf` is preserved.

## Recommendation and decisions

Retain the existing supported MSAL clients, separate identity realms and backend JWT
validation. Add a server-enforced application session **in addition to a valid bearer
token**, move durable browser tokens to memory, and transport the application session
in a host-only HttpOnly cookie. This is a proposed change to authentication architecture,
not an already approved implementation. No password system or browser-created JWT.

Recommended first implementation is compatible with the current PHP hosting and MSAL
5.19.0: memory token cache plus deliberate, supported popup authentication; PHP owns
application-session expiry/revocation. The API still requires and validates the current
access token on every protected call. The cookie alone never supplies identity or roles.
This avoids introducing a Node service or storing provider refresh tokens on the server.
It does not protect an active page against every XSS action; CSP and injection review
remain necessary.

**Do not just change `cacheLocation`.** Microsoft's current cache table lists memory
storage as incompatible with redirect authentication. The installed SDK also fixes its
temporary transaction store to sessionStorage even when durable cache storage changes.
Temporary state/nonce/PKCE material therefore needs separate inventory, cleanup and a
time-bounded security decision; it cannot be described as zero browser persistence.
[Microsoft cache guidance](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/caching).

If popup compatibility or the narrowly scoped temporary-cache exception is unacceptable,
stop at the feasibility gate and choose a same-origin backend-for-frontend (BFF): a
reviewed confidential OIDC client performs code exchange and keeps provider tokens
server-side. That requires separate library/runtime/hosting and credential-key-management
review; none is selected, installed or authorized here. Do not handcraft OAuth exchanges,
use deprecated temporary-cache overrides, fall back to persistent bearer storage or
silently disable a session guard to make a provider work.

| Decision before implementation | Proposed default / required evidence | Owner |
| --- | --- | --- |
| D1 — architecture | Approve the bearer-plus-cookie design and popup feasibility work; BFF is the alternative if its gates fail. | Product + security + identity |
| D2 — timing and shared devices | Approve the policy table below, including whether particular clinics need a shorter shared-device timeout. These are product/security defaults, not statutory durations. | Security + clinic operations |
| D3 — transient SDK state | Only a documented exception for enumerated single-use SDK transaction keys, bounded to the login transaction and server challenge expiry; proposed initial permission is a **14-day synthetic pilot only**, no reusable token/PHI exception. Name reviewer, expiry, cleanup and XSS compensations. Real-data use needs its own signed decision. If rejected, select BFF. | Security + privacy |
| D4 — actual provider controls | Verify workforce and External ID policies, MFA methods, auth_time/identity-link claims, licensing and both social providers. No inference from an account successfully reaching a picker. | Identity administrator + security |
| D5 — revocation authority | Approve self-session revocation, clinic-scoped staff-session administration and a separately privileged central emergency process. Central billing/support does not imply clinical access. | Custodian + security |
| D6 — release | Approve exact migrations, configuration, identity-registration edits if needed, hosted synthetic scope, backups and rollback after the implementation artifact exists. | Operations + release owner |

The owner requested a review/plan before authentication changes. That authorizes this
document and read-only assessment, not D1–D6 implementation, tenant changes, schema
application, new grants, account reactivation, deployment or merging.

## Verified current design

| Surface | Current implementation / evidence | Limitation |
| --- | --- | --- |
| Workforce staff | `Frontend/src/auth/AuthProvider.tsx:29` uses sessionStorage; loginRedirect, acquireTokenSilent and logoutRedirect. `staffSessionRecovery.ts` bounds repeated recovery redirects to two minutes. | The recovery window is not an idle timeout. No staff application-session table or expiry/revocation gate found. |
| External practitioners | Same client wrapper, dedicated authority/SPA/API and `access_as_staff`; `practitionerIdentity.ts` retains the supported provider picker and prompt=login. | Provider selection remains separate from workforce; no staff idle/absolute gate. Do not revive rejected Google/Microsoft acceleration hints. |
| Central Admin | `central/main.tsx` uses the workforce wrapper, checks `/application/me` on principal change and renders admin sections. `ApplicationAdminAuthenticator` reads the active application-administrator grant per API request. | No independent application session/recent-auth gate; global staff invalidation is portal-only and the central shell does not use it or sessionExpired as a rendering guard. Some sections clear their own denied state. |
| Clients | `customer/auth.ts:22` also uses sessionStorage. `customer/session.ts` persists a reusable session token under `wellness.customer.session.v1`, bound in the UI to homeAccountId. Requests send bearer plus `X-Customer-Session`, with credentials=omit. | Account binding does not remove script access to credentials. Logout currently awaits server logout before local cleanup/provider logout; a network failure can leave local state behind. |
| Client server session | `CustomerOnboarding.php:64–120`: nonce challenge, verified ID proof with auth_time and oid/tid binding, locked single-use challenge, random 32-byte session secret stored hashed, 30-minute idle and eight-hour absolute expiry. Only explicit `/auth/activity` slides idle. Migrations 005/039 provide clinic binding. | Preserve this foundation. No inference that all deployed client settings/claims or hosted customer flows were verified by this review. |
| Client UI expiry | `customer/ClientApp.tsx:70–113` clears private state at the returned deadline; trusted pointer/key events send activity at most once per minute. | Sleep/resume, clock changes, all 403 account denials, cross-tab logout and interrupted logout need dedicated coverage. isTrusted is a UI filter, not server proof of human activity. |
| API authority | `Api::user`, `EntraAuthenticator`, `ExternalStaffAuthenticator`, `StaffMembershipResolver`, `ApplicationAdminAuthenticator` and customer routes enforce identity/clinic/current grants. `Http/Request.php:53` extracts bearer credentials. | There is no common staff/central server session to expire/revoke while otherwise valid tokens remain usable. Existing JWT/membership rules must remain mandatory. |
| Recent hosted test | [UI reliability report](PRACTITIONER_UI_RELIABILITY_2026-11-02.md): retained Microsoft dashboard gone after owner-saved Inactive, Retry denied, final pilot revoked. Both test staff accounts Inactive; intake closed. | It established scoped request-time denial/UI removal, not idle expiry, every raw-fetch path, provider-wide logout or instant push revocation. Those accounts are not reactivated by this review. |

## Findings and priority

| ID | Priority / status | Evidence and required resolution |
| --- | --- | --- |
| SS-01 | High / verified source gap | Staff/practitioner/central application idle and absolute limits, recent-authentication proof and per-session logout/revocation are missing. Add a server gate; UI timers alone are insufficient. |
| SS-02 | High / verified source gap | Both MSAL clients persist credentials; the client also persists its own reusable session secret. Remove durable access/refresh/ID tokens and reusable session secrets from Web Storage. Reassess AUTH-04 explicitly. |
| SS-03 | High / partial | Staff denial handling covers the shared JSON helper and five dashboard paths. A source search finds **53 files** with token/header helpers or Authorization use; this is a review inventory, not 53 proven bypasses. Central and other raw fetch callers need common terminal-state handling. Server denials already apply independently. |
| SS-04 | High / partial | Local logout, upstream IdP logout and application-session revocation are different operations. Staff has no application session; client logout cleanup can be interrupted. Clearing one MSAL cache does not prove every tab, device or external-provider session ended. |
| SS-05 | High / unverified operational evidence | Actual privileged MFA, federation assurance, sign-in frequency, authentication freshness claims and lost-device procedure were not inspected in the tenant. A Google/personal Microsoft login is not evidence of the clinic's workforce MFA policy. |
| SS-06 | High / verified storage exposure | `LocationSearch.tsx` persists latitude/longitude for 30 minutes. Invitation/action-link keys, booking-intent details and travel proof also cross sign-in via sessionStorage. Location/booking context may be personal/health information; do not classify it all as harmless preferences. `LocationDiscoveryService.php` encrypts travel proof with AES-GCM; do not misdescribe that proof as plaintext coordinates. Remove persistence or explicitly approve a narrow, bounded exception/handoff. |
| SS-07 | High / bounded hosted observation | At 22:22 UTC, HEAD on both clinic staff entries returned no CSP, HSTS, nosniff or referrer-policy header; central Admin returned CSP/nosniff/referrer policy, but no HSTS. All three returned no-store. This is evidence for these responses only, not all routes or an exploit demonstration. Complete per-host controls before real-data release. |
| SS-08 | Medium / partial | App realm hints are sessionStorage UI preferences; account selection filters tenant/authority, not authorization. Legacy invite keys have no visible frontend TTL/account binding; action-link capture has a 24-hour bound but remains script-readable. Ensure logout/account switch cleanup, purpose/identity/host binding and non-authoritative routing. |
| SS-09 | High / verified source gap, exploitability untested | Workforce `EntraAuthenticator::verify` checks tenant/issuer/audience/scope but does not explicitly bind azp to the configured SPA; external/client verifiers do. Pin approved client identity for session bootstrap and review the deployed permission/grant inventory before changing acceptance. No current cross-client exploit is claimed from source inspection alone. |

Anonymous responses also set an infrastructure cookie named request_id. Only its name
and attributes were recorded: HttpOnly observed, Secure/SameSite absent on the sampled
responses. Its value was neither displayed nor retained. This is not evidence of an
authenticated application session or proof that it contains PHI; hosting operations must
classify it. Do not reuse it for the proposed session.

## Proposed session policy

These values are reviewable defaults, not changes made or legal requirements.
Server UTC time is authoritative; the UI displays translated countdowns/local times.

| Realm | Idle limit | Absolute limit from fresh authentication | Recent-authentication window | Scope |
| --- | --- | --- | --- | --- |
| Central application admin | 15 minutes | 8 hours | 5 minutes | Exact central host and administrator identity; no clinic authority implied. |
| Clinic workforce, including administrators | 15 minutes | 8 hours | 5 minutes for sensitive administration | Exact clinic host, verified realm and identity; current roles still checked. |
| External practitioner | 15 minutes | 8 hours | 5 minutes for supported sensitive account/access actions | Exact clinic host and external identity; pilot, membership, publication and billing remain separate. |
| Client | 30 minutes | 8 hours | 5 minutes for approved sensitive account/link actions | Preserve current expiry defaults; exact clinic and current own-record/consent authority. |

- Show an accessible two-minute warning, then a 30-second warning; expiry removes
  private UI/drafts, aborts requests and requires deliberate sign-in. No persistent PHI
  draft workaround. Ordinary activity cannot extend the absolute deadline.
- Only bounded explicit activity updates can extend idle time. Polling, silent token
  renewal, hidden-tab status checks, mouse movement and background retries do not.
  Recheck expiry under lock **before** any activity update; an expired session stays dead.
- Idle/absolute expiry, logout, account switch, revoke-all, lost device and denied current
  authority are distinct audited reasons. A usable Entra cookie or renewed access token
  must not silently reopen an expired application session.
- Fresh authentication requires a new server challenge, verified nonce/auth_time and the
  same immutable principal. Prompt parameters are requests to the IdP, not proof by
  themselves. Preserve the existing client workaround that serializes max_age=0 explicitly.
  MFA assurance must come from approved provider policy/evidence, never a UI checkbox.

OWASP requires server-enforced idle/absolute expiry and notes that closing the browser
does not reliably log a user out. These proposed durations are our application choices.
[Session guidance](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
Entra sign-in frequency and the app's own expiry are separate policies.
[Microsoft session policy](https://learn.microsoft.com/en-us/entra/identity/conditional-access/concept-session-lifetime).

## Proposed architecture and data flow

1. Resolve realm from the route and verified identity adapter; resolve clinic from the
   trusted host mapping. Never accept browser-supplied clinic/user/role as authority.
   Workforce, external practitioner, client and central sessions have separate namespaces.
2. A bounded same-origin challenge endpoint creates a short-lived server record and
   host-only pre-auth correlation cookie. Use one-use random nonce, purpose/realm/host,
   optional validated return path, creation/expiry and consumption state. Prepare the
   challenge without losing the browser gesture needed for MSAL popup; feasibility tests
   must prove this behavior rather than introducing an automatic popup/background login.
3. MSAL performs supported authorization-code/PKCE login in a deliberate popup, retaining
   durable tokens in memory. Existing Google/Microsoft picker, separate scopes and account
   selection remain. Popup cancellation/blocking or unavailable storage is an explicit
   recovery state, with no downgrade to persistent credentials or weak login.
4. Session start submits the bearer and fresh ID proof to the same-origin PHP API.
   Existing JWT signature/issuer/audience/tenant/scope checks stay mandatory. A separate
   ID-proof verifier requires the proper SPA audience, nonce, auth_time and verified
   identity correspondence. API and ID token sub can be pairwise: use validated oid/tid
   where supported, never email or an assumed equal sub. Missing required linking/freshness
   claims fail closed until the provider flow is corrected. Fix the staff azp/client
   binding explicitly for session creation; do not broaden accepted applications.
5. Atomically consume the challenge, bind the exact principal and issue a random opaque
   application-session cookie. Store only its SHA-256 digest and minimal session metadata.
   No provider access/refresh token or clinical body belongs in this session store.
6. Every protected request needs **both** a valid JWT and a matching active session.
   Check host/realm/identity, idle/absolute expiry, revocation generation, current clinic,
   current pilot/membership/admin grant and existing record/consent rules. A cookie alone
   is insufficient; a still-valid bearer alone is insufficient once enforcement is on.
   This remains an SPA calling the API, not a claimed full BFF.
7. Own practitioner application/invitation paths need identity-level sessions even when
   membership is not yet active; that session must not grant workspace access. Preserve
   approval/activation and pilot-denial semantics. Client onboarding and representative
   authority remain separate; no auto-link by shared email.
8. Token renewal can replace the bearer in memory while the app session is active; it
   never changes authenticated_at/absolute expiry. Reload loses the memory token cache
   and must recover only through the approved MSAL flow and the still-valid app session,
   or require sign-in. Do not auto-mint a new eight-hour session from a cached token.

### Cookies, requests and storage

- Proposed cookie names: __Host-wc-workforce, __Host-wc-practitioner,
  __Host-wc-client and __Host-wc-central. Secure, HttpOnly, Path=/, no Domain attribute,
  explicit SameSite=Lax; the same properties apply to the pre-auth cookie. Exact host
  binding prevents sharing a session across clinic subdomains. No .copihue.ca cookie.
- SameSite is not enough: sibling clinics can be same-site while still different origins.
  Require exact Origin on state-changing cookie endpoints, a session-bound CSRF header
  for authenticated writes, JSON content type, and appropriate Fetch Metadata handling.
  Reject unsafe methods with absent/unapproved origins. Review GET handlers for side
  effects. Read requests must not gain arbitrary credentialed CORS access. Login start
  uses its own challenge/correlation protection, not an already-authenticated CSRF token.
  [CSRF guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
- Set credentials=same-origin and cache=no-store in all authenticated fetch wrappers.
  Client credentials=omit must change when its cookie is introduced. CSRF values and
  deadline metadata can be in memory; no reusable session secret is returned in JSON.
- Keep non-sensitive language/layout/display preferences. Remove stored precise location
  and booking context, or replace sign-in handoff with a short-lived server record bound
  to purpose/host/nonce and the eventual verified identity. Invitation/action-link handoff
  must preserve one-use/expiry rules and clear on consume, logout, expiry and account
  switch. Do not merely encrypt data in JavaScript and keep the decryption key beside it.
- One account per host/realm cookie session across tabs is the proposed UX. Different
  users in different tabs need separate browser profiles; a mismatched bearer/cookie
  must deny, not silently switch identities. BroadcastChannel sends only logout/lock and
  generation hints, never tokens, names or PHI, and is not an authorization authority.
- MSAL 5.19.0 has no supported temporaryCacheLocation option in CacheOptions; its
  BrowserCacheManager explicitly uses SessionStorage for temporary artifacts. Inventory
  exact remaining keys and lifetime in the feasibility test; no deprecated override.
  Use supported account-scoped clearCache for owned artifacts and verify cleanup;
  do not clear unrelated origins, realms or harmless preferences indiscriminately.

### Revocation, logout and sensitive actions

- Local app logout first locks/clears private UI and aborts in-flight requests. Revoke
  the matching server session and expire its cookie; then request provider logout.
  If networking/provider logout fails, remain locally locked, report the safe limitation
  and offer idempotent retry. Do not claim server revocation succeeded during a DB outage.
  Upstream Google/Microsoft global sign-out is not guaranteed by app logout.
  [Microsoft logout behavior](https://learn.microsoft.com/en-us/entra/msal/javascript/browser/logout).
- Departure, pilot revocation and role/permission changes revoke affected app sessions
  or advance an authorization generation in the same transaction as the authority change
  and required audit. Check current authority on every request regardless of that cache.
  Resolve revocation/write races at the sensitive transaction boundary using a common
  lock order; an invalidated principal cannot commit a later sensitive effect.
- Lost-device response uses local revoke-one/revoke-all and deprovisioning, with bounded
  clinic/central authority and a recorded reason. Provider-wide revocation is an operator
  process, not a new Graph permission requested by this plan. Existing access tokens can
  survive provider revocation, so local enforcement remains necessary.
  [Microsoft revocation guidance](https://learn.microsoft.com/en-us/entra/identity/users/users-revoke-access).
- Require fresh proof for role/pilot/identity-link changes, session administration,
  approved sensitive exports, sender/channel activation and billing/plan administration.
  Issue a short-lived, one-use server authorization bound to actor, host, realm, action,
  target and current version; recheck current permissions at commit. Reauthentication
  supplies no new role, consent, clinical authority or transaction approval. Do not
  automatically replay a write or accept terms when authentication returns.
- Server-expired/revoked/account-wide denials lock all affected workspace surfaces,
  including central Admin and raw fetch modules. Ordinary record-level forbidden and
  service outages keep distinct recovery states. Discard late responses from older
  authentication generations; clear avatars, dialogs, calendars, exports/object URLs,
  cached queries and validation payloads. Recheck on resume/pageshow/BFCache restoration.

## Implementation work packages — after design approval

Each package needs reviewed code and its own evidence. File names below are concrete
proposals, not files created by this review; final migration numbers are reserved only
after checking the then-current migration sequence.

| Order | Changes and principal files | Exit evidence |
| --- | --- | --- |
| WP0 — feasibility and contract | Disposable synthetic prototype of MSAL memory/popup behavior, fresh nonce/auth_time proof, same-origin cookies/CSRF and reload/account switching. Inspect installed SDK storage on success/cancel/error. Record realm-specific MFA/claim requirements and browser matrix. Review `auth/AuthProvider.tsx`, `customer/auth.ts`, `customer/providerRouting.ts`, both bootstrap modules and `central/main.tsx`. | Edge/Chrome/Firefox/Safari/mobile popup compatibility, storage keys/lifetime and fresh-claim availability proven. D1/D3/D4 decision recorded. No production identity/hosting edits by implication. Failure selects BFF review, not a credential-storage fallback. |
| WP1 — server session core | Add `api/src/Service/BrowserSessionService.php`, a storage adapter for existing customer_sessions, `api/src/Auth/LoginProofVerifier.php`, session request/controller helpers, config validation and additive migration(s). Extend `Api.php`, `Http/Request.php` and the existing authenticators without weakening JWT checks. | Disposable unit/SQL/HTTP expiry, binding, CSRF, audit-failure and race tests pass on MariaDB and MySQL 5.7. Every protected route mapped to a server-enforced policy; bearer-only bypass tests fail as intended. |
| WP2 — browser transport and storage | New typed `Frontend/src/auth/applicationSession.ts` and `authenticatedRequest.ts`; update AuthProvider, customer auth/session/bootstrap, `shared/api.ts`, all 53 inventory files as applicable, downloads/avatar/attachment paths, invitations and location/booking handoff. Use the MSAL APIs, not window.fetch monkey patches. | Durable tokens/session secrets absent from Web Storage/URLs/IndexedDB/CacheStorage; popup cancellation/reload and both social identities work. Every write carries expected origin/CSRF protection; wrappers retain bounded requests/errors. |
| WP3 — session UI and lifecycle | New `SessionBoundary.tsx` / `SessionWarning.tsx` (or equivalent shared components); update StaffSignIn, StaffPortal/PortalApp, ClientApp, central Administration, UserAccountMenu, useStaffProfile, access invalidation and all relevant query/draft stores. Add EN/FR strings. | Server deadlines reflected, private UI immediately locks/clears at expiry/logout/denial, old responses cannot restore it, and keyboard/focus/responsive/zoom/themes/AT checks pass. Central Admin receives the same authority-bound protection. |
| WP4 — sensitive actions and departure | Wire versioned recent-auth grants into pilot/staff administration, identity linking, approved export/billing/sender actions and revoke-one/revoke-all administration. Update the actual authority services, not just form buttons. | A valid session without fresh proof cannot commit a sensitive action; proof replay/account/target/version mismatch fails; concurrent revocation blocks later commits. No added role, automatic approval, term acceptance, message or payment. |
| WP5 — operational activation | Per-host policy/headers, provider MFA/claim evidence, migration preflight, source-bound packages, cleanup of owned legacy caches, session reset and hosted synthetic acceptance. Update deployment/pilot/runbooks and control ledger. | Reviewed artifact, backups/restore, monitoring, owner approvals and complete hosted matrix. Prior disabled test accounts stay disabled unless a new exact rehearsal is approved. |

Transport inventory command at the reviewed source:
`rg -l 'getAccessToken|customerToken|Authorization:|Authorization.*Bearer' Frontend/src --glob '*.ts' --glob '*.tsx'`.
Also search every fetch/download/upload and alternate HTTP client during implementation;
this token-helper inventory is not a complete endpoint or clinical-access audit.

### Proposed server data and route contract

New staff/practitioner/central session storage needs: opaque internal row ID; unique
SHA-256 secret digest (compatible CHAR(64)/ascii_bin); realm; exact normalized host;
clinic ID (nullable only for the central realm); immutable verified-principal digest;
appropriate existing user/administrator linkage when present; fresh authenticated_at,
created_at, last_activity_at, idle/absolute expiry; policy/generation version; revocation
time and allowlisted reason. Do not cache roles as authority, copy full claims, store raw
credentials or IP/location fingerprints, or auto-provision a role from a session row.
Index by secret digest, principal/scope/revocation, and expiry for bounded maintenance.

Reuse the existing customer_sessions hashed-token/fresh-proof logic through a common
policy/storage abstraction; extend its host/policy/CSRF metadata with additive migration
if needed. Do not run two competing client session clocks. A session created before a
client link or practitioner activation still cannot reach protected records/workspaces;
activation/permission changes invalidate its old authorization generation and require
the appropriate fresh access check/session rotation.

Add bounded login challenges and sensitive-action proof records with hashed random
secrets, purpose, host/realm, nonce/correlation binding, timestamps and consumed state;
proposed challenge maximum is 10 minutes, recent-action grant maximum five minutes and
single use. Apply row locks, compare-and-swap/version checks and a common lock order.
Bound input/count/rate limits and maintenance batches. Session/decision audit records
contain internal references, realm/clinic, action/outcome/reason and correlation only;
exclude cookie/JWT/CSRF/nonce values, clinical content and complete authorization URLs.
Retention/purge and backup disposal need an approved class schedule; do not delete audit
history or restore revoked sessions through a stale backup.

Proposed route families:

| Realm | Routes / protection |
| --- | --- |
| Workforce | `/api/v1/staff/session/{challenge,start,me,activity,logout,reauth}`; route fixes workforce trust, host fixes clinic. |
| External practitioner | `/api/v1/practitioner/session/{challenge,start,me,activity,logout,reauth}`; dedicated external verifier and pilot/membership rules; identity-only own-application session stays distinct from workspace access. |
| Central | `/api/v1/application/session/{challenge,start,me,activity,logout,reauth}`; exact central host and active application-administrator grant. |
| Client | Preserve `/api/v1/customer/auth/{challenge,session,me,activity,logout}` semantics while changing secret transport; add the reviewed reauth endpoint. Keep fresh ID proof/clinic ownership and existing customer scope. |

Only challenge/options and carefully bounded login bootstrap are anonymous. Session me
returns minimal authority/deadline/CSRF metadata, never a reusable session secret. Normal
API reads/writes require bearer plus cookie; all-session/other-session revocation also
requires current administrative authority and fresh action proof. An **own-session
logout** may accept its cookie plus exact Origin/CSRF without a current bearer so expired
access tokens do not prevent revocation; it can only revoke that matching session and
must never read records or identify/revoke another session from a supplied user ID.
Bootstrap has separate pre-auth correlation and abuse protection. Missing session does
not trigger token-only fallback once that host/realm requires sessions.

### MFA and operational evidence

Inventory actual workforce registrations, central application, external practitioner
SPA/API and customer SPA/API separately: trusted issuer, exact audiences/authorized
clients, existing delegated scopes/roles, redirect/logout URIs, optional freshness claims,
grant owner, conditional-access/MFA coverage and recovery methods. Verify external
Google and personal Microsoft/custom OIDC paths individually with synthetic accounts.
Do not infer trust of an upstream social-provider MFA claim without supported External
ID policy/evidence. An auth_time value alone is not MFA assurance.

Current External ID guidance describes multiple MFA methods, with applicability/cost
differences; actual tenant availability and policy require administrator verification.
Do not enable SMS MFA merely because the app already has an SMS module: identity-provider
MFA traffic/costs and phone collection are outside the application's reminder budget and
MAIL_ENABLED/SMS_ENABLED guards. Approve method, recovery, new personal-data flow and
provider cost before enabling it.
[External ID MFA](https://learn.microsoft.com/en-us/entra/external-id/customers/how-to-multifactor-authentication-customers).

Record tested lost-device/departure steps, incident contact, session-store outage behavior,
current identity-policy export/review date, authorized senders/hosts and monitoring.
No new Graph permissions or automatic tenant-wide session revocation are assumed.

### Headers and public surfaces

Implement reviewed per-host CSP/frame/referrer/nosniff/no-store rules and HTTPS redirects.
Stage HSTS only after checking certificates/routing; do not add includeSubDomains or
preload without the domain inventory. Review COOP/popup/iframe behavior and dedicated
callback/redirect-bridge pages so framing controls do not break supported MSAL flows.
Use maintained policy for the existing Maps and identity endpoints, not arbitrary hosts
or unsafe-eval. Source header files are not proof of deployed headers; check actual GET,
API, error, callback and static responses on both clinic hosts and central Admin.

Public pages must not import private session credentials to display a sign-in hint.
Update GuestPortal's direct session-secret read. The existing marketing iframe bridge
sends initials, but no token/account ID/email/clinical body; initials are still personal
display data, not proof of authority. Prefer an ordinary portal link or a separately
reviewed non-identifying hint. Never widen postMessage origin checks or cookie Domain
to recover cross-site SSO. With blocked third-party cookies or no verified hint, fall
back to the portal link. The marketing site need not become an auth client.

## Required test matrix

All new test identities/records are synthetic; disposable SQL tests use explicit local
credentials/databases, not private hosted environment settings. A trusted local HTTPS
fixture is needed for real Secure/__Host-cookie browser semantics; do not disable TLS
verification to make hosted tests pass.

| Area | Required success and failure evidence |
| --- | --- |
| Identity | Workforce, central, Google practitioner, personal Microsoft practitioner and client; wrong issuer/audience/azp/scope/realm, pairwise-sub mismatch, missing/old/future auth_time, missing/reused/expired nonce; IdP timeout and key rotation failures. |
| Session binding | Correct host/realm/principal; copied cookie to another clinic, another account's JWT, bearer-only and cookie-only requests, stale generations, pre-membership application access without workspace authority. |
| Time | Before/at/after idle and absolute deadlines; continuous clicks versus background polling; silent renewal cannot slide absolute expiry; activity cannot resurrect expiry; skew/sleep/resume/background timer throttling and offline transition. |
| Cookie/CSRF | Secure/HttpOnly/host-only/Path/SameSite attributes, login fixation, sibling-subdomain requests, absent/foreign/null Origin, malformed/expired CSRF, simple form submissions, CORS preflights, GET side effects and logout with expired bearer. |
| Concurrency | Two tabs, simultaneous renewal/logout, reordered requests, session rotation, double nonce consumption, permission change while write is in flight, DB deadlock/audit failure. Required audit failure denies the sensitive commit. |
| UI/data | Private components/drafts/avatars clear; late responses cannot remount; central gets the same protection; ordinary record denial differs from account/session denial; BFCache/back/forward/logout/account switch, download URLs and service-worker/browser caches. |
| Providers/UX | Popup blocked/closed, account picker cancellation, recovery without loops, both social accounts, reload/new tab, private mode/storage denied, mobile/Safari/Firefox/Edge/Chrome, EN/FR keyboard/focus/dialog restoration/zoom/themes/screen-reader warnings. |
| Storage | Assert no durable provider tokens, reusable app-session secret, precise location or PHI in Web Storage/IndexedDB/cache/URLs; inspect actual SDK temporary keys and approved lifetime, cache migration and failed-flow cleanup; harmless preferences remain. |
| Revocation/logout | Revoked DB membership/pilot/admin grant, own/all-session logout, provider logout failure, lost device, restored backup; retained valid JWT does not bypass local revocation. Verify the mounted UI as well as fresh sign-in. |
| Sensitive actions | Fresh correct actor/action/version passes only with existing authority; stale, replayed, other-target or other-account proof denies; no automatic replay or consent/terms acceptance after login. |

Use existing dedicated Playwright configs; the default suite omits central Admin,
practitioner applications, invitations and production-artifact boundaries. Future broad
checkpoint commands include Frontend lint/content/i18n/strict TypeScript/build/default
and dedicated browser suites; Wellnexa lint/build/default/dedicated application-entry
where navigation changes; PHP syntax/policies and dual-engine disposable SQL/HTTP suites.
Record all versions/counts/initial failures and omitted scenarios, not just a green build.

## Rollout and rollback proposal

1. Approve D1–D5, finish feasibility, then implement on dedicated branches. Keep locks,
   PHP/Node support and realm boundaries unchanged unless a separate change is approved.
   The default proposal adds no runtime library or Node server; BFF would need a new
   reviewed dependency/runtime decision. Do not apply SQL from this document.
2. Prepare additive migration/preflight, runtime DB privilege inventory, source-bound
   API/frontend packages, private policy examples and rollback record. Separate migration
   credentials from runtime credentials. Keep session rows/history private and outside
   public roots. Review signing/challenge key purpose/rotation, without reading secrets
   into tickets, logs or agent prompts.
3. Obtain the exact release/migration/identity-setting approval under REL-05 after
   artifacts and rollback tests exist. Back up and prove restoration in a disposable
   environment. Use an approved synthetic canary host/realm. Existing Wellnexa synthetic
   pilot approval is not blanket approval for a new authentication release.
4. Deploy API/schema capability before compatible frontend and server-required policy.
   Activate by trusted host/realm, not a browser feature flag. No bearer-only grace after
   enforcement. A still-legacy realm remains under its existing synthetic-only restriction
   and does not close AUTH-04. All same-origin MSAL clients must be included in cleanup
   planning so one cache purge does not quietly damage or grandfather another client.
5. Revoke old application sessions and clear **owned** old MSAL/token/handoff keys with
   supported account/client-bound APIs; require deliberate fresh login. Do not enumerate
   or print live storage values. Verify reload, cookie and storage behavior on deployed
   artifacts, header coverage, revocation races, IdP failures and representative roles.
6. Monitor redacted session start/end/expiry/denial/reauth failures, abnormal bootstrap
   rates, DB latency, lock conflicts and provider failures. Record release owner, incident
   contact, support instructions and exact previous/current configuration and artifact.
   Keep both existing test identities Inactive/pilot-revoked at the end of any approved
   rehearsal; keep intake/channels closed unless separately approved.
7. Rollback locks affected protected access, revokes the new sessions and restores matching
   reviewed API/frontend/configuration. Preserve additive tables and all application,
   clinical, billing, consent and audit history. Never restore an old database over newer
   records or re-enable legacy bearer-only access to bypass a failed gate. Old session
   cookies are expired explicitly. Returning to Web Storage credentials requires a new
   scoped security decision; a previous ZIP is not such authorization.

## Validation performed for this review

No source, tests, dependency manifests/locks, migration, private environment, identity
registration/policy, grant, cookie setting or hosted account was changed. No private
database, token cache, credential, clinical record or real-provider sign-in was read.
Only anonymous public HEAD responses were requested; cookie values were discarded.
The last hosted cleanup evidence remains the [completed controlled retest](PRACTITIONER_UI_RELIABILITY_2026-11-02.md).

Current local runtime: Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14. Unchanged locks:
MSAL browser 5.19.0 / React 5.6.0, frontend React 19.2.8, TypeScript 5.7.3,
Playwright 1.63.0; PHP firebase/php-jwt 7.1.0 / FastRoute 1.3.1; declared PHP ^8.2.
No supported runtime upgrade or dependency addition is proposed for the primary design.

| Exact check | Result |
| --- | --- |
| `php api/tests/customer-auth.php` | 25 authentication + 1 customer/staff + 6 ID-proof checks passed. |
| `php api/tests/external-staff-auth.php` | 67 checks passed. |
| `php api/tests/customer-onboarding.php` | 25 freshness/expiry/profile policy checks passed. |
| `php api/tests/identity-membership.php` | 29 identity/membership + 3 external permission checks passed. |
| Combined PHP scope | **156 checks**, four scripts, each exit 0. Process OPENSSL_CONF temporarily set to `C:/Program Files/Git/usr/ssl/openssl.cnf` and restored afterward. No SQL/provider calls; key loaders and PDO doubles are synthetic. |
| `npx playwright test tests/portal.spec.ts tests/customer.spec.ts --grep 'staff session recovery\|expired staff session\|silent refresh iframe\|dashboard removes\|access recheck fails\|idle expiry\|real customer MSAL\|callback failure' --reporter=line` (Frontend) | **10 passed**, 19.5 seconds, exit 0. Existing tests, disposable local browser/API/provider fixtures; not hosted identity/DB acceptance. Log `.tmp/session-review-browser.log`. |
| `npm audit --omit=dev --json` (Frontend) | Exit 0, zero runtime advisories. No install/update. Full build-tree audit not repeated; prior build-tool findings retain their separate review. |
| `composer --no-plugins --no-scripts audit --format=json` (api) | Exit 0, no advisories or abandoned packages. Initial attempt from repository root failed because its composer.json is in api; rerun from api passed. No dependency change. |
| `Invoke-WebRequest -Uri $target -Method Head -TimeoutSec 25 -MaximumRedirection 0 -ErrorAction Stop` | Three public entry responses HTTP 200, verified OS TLS. Header observations are in SS-07; two further HEAD requests recorded cookie names/attributes only. No authenticated session inspection. |
| Source/config/lock/script inspection | Auth, transport, expiry, storage/handoff, JWT/clinic boundaries, migrations 005/039, current deployment layout and relevant tests reviewed; no staff session table or cookie-auth middleware found. |
| Documentation-only validation | 38 distinct control rows, 97 relative documentation links, both saved public-evidence hashes and unchanged runtime/dependency paths verified; bounded credential-signature scan found no matches; staged `git diff --check` passed. |

The first staged whitespace check found an extra blank line at EOF in this new plan;
it was removed and the complete documentation check rerun successfully. Normal Git
LF-to-CRLF working-copy notices do not change the reviewed runtime source or lockfiles.

No production builds, full browser suites, SQL integration, Composer/npm restore,
live MFA/policy export, sign-out across devices, comprehensive XSS/accessibility or
backup restore were performed for a documentation-only review. Existing color-environment
warnings appeared in Playwright; no new failure, skipped assertion or weakened test.
Some initial exploratory file paths did not exist; repository file inventories supplied
the actual paths before review. This is not runtime/test failure evidence.

Ignored public-only evidence: `.tmp/session-review-public-headers.json` SHA-256
`e4e7dafa83d5f62a03d1aa541431ef8bbe0a630b4d14125f9494664e53c3a0e0`;
`.tmp/session-review-anonymous-cookie-metadata.json` SHA-256
`51e5e517c0eb6a4a26f8274669f96512c816a7c859981bde787561c6cd84750c`.
Runtime audit JSON and browser logs also remain ignored. This review changes only this
plan and its documentation index. Documentation rollback is a reviewed Git revert;
there is no runtime migration or deployment to undo.

## All 38 controls — review-scope ledger

Review date/source as above. Status is scoped evidence, not certification. Owner roles
require named appointment and sign-off. Historical audits remain intact; this follow-up
does not automatically close their other findings or establish organizational compliance.

| Control | Status | Evidence / owner and next action |
| --- | --- | --- |
| GOV-01 | Unverified | Roles/custody/contracts unchanged; custodian/operator must name reviewers and tenant boundaries. |
| GOV-02 | Unverified | No legal-source/commencement reassessment; privacy/legal maintain applicability register before real-data release. |
| GOV-03 | Unverified | No new commercial/cross-border assessment; privacy/legal record determination. |
| GOV-04 | Partial | Administrative authentication proposal only, no clinical inference; product/clinical approve intended use. |
| GOV-05 | Partial | Synthetic local checks and public headers only; privacy/security update signed PIA/TRA for the proposed cookie/storage flows. |
| PRIV-01 | Unverified | No notice/contact changes; custodian/privacy verify actual bilingual practices. |
| PRIV-02 | Partial | No new collection/consent/provider flow executed; privacy approve new session metadata/MFA collection and retain wording/version where required. |
| PRIV-03 | Partial | Current authority foundations and prior scoped revocation verified; security/custodian require new session/commit-race and worker boundaries. |
| PRIV-04 | Unverified | No representative/minor changes; clinical/custodian retain scoped authority and assisted-process limits. |
| PRIV-05 | Unverified | No rights workflow changed; privacy operations retain access/correction evidence. |
| PRIV-06 | Unverified | No retention/deletion performed; records/privacy approve session/challenge/audit/backup schedules and holds. |
| PRIV-07 | Partial | Lost-device/revoke procedure proposed, no exercise; incident/privacy lead rehearse breach response. |
| AUTH-01 | Partial | Existing MSAL/JWT separation reviewed and synthetic checks pass; identity/security validate proposed fresh proof and every realm. |
| AUTH-02 | Partial | Active membership/central grant/host foundations reviewed; security prove cookie/bearer binding and all negative/race cases. |
| AUTH-03 | Missing (staff/central session limits); Partial (client) | Current client 30-minute/eight-hour server gate verified; security/operations approve and implement staff/central expiry, recent auth and logout. |
| AUTH-04 | Missing (approved persistent-credential design) | Durable browser credential storage remains; security approve D1/D3 and verify migration/cleanup before claiming closure. |
| AUTH-05 | Partial | No grants changed; identity/operations provide actual app/client/MFA grant inventory. No new Graph scope inferred. |
| SEC-01 | Partial | No private files/tokens/PHI read or added; documentation signature/diff review required; security retain full artifact/history assessment. |
| SEC-02 | Partial | Public requests used verified TLS, no new transport; operations verify DB/store encryption, backups and key recovery. |
| SEC-03 | Partial | Bounded real header/cookie metadata recorded; operations/security implement and test every actual host/route, CSP/callback/cache/CSRF behavior. |
| SEC-04 | Partial | Existing prepared SQL/JWT foundations preserved; security review proposed parser/nonce/CSRF/session validators before implementation. |
| SEC-05 | Partial | Existing challenge rates reviewed; engineering/security bound new routes, maintenance and concurrency under representative load. |
| SEC-06 | Partial | Existing audit hooks/session history foundations; DB/security verify fail-closed transactions, independent integrity and retention. |
| SEC-07 | Partial | No credential values retained; engineering/operations redact callback/cookie/body diagnostics across proxies/providers. |
| SEC-08 | Partial | No new external flow now; privacy/vendor owner approve any BFF/identity MFA/host change and actual endpoint contracts. |
| SEC-09 | Not applicable (new message dispatch in this review) | No email/SMS/calendar transport changed or sent; existing communications obligations remain open and identity MFA is separately scoped. |
| DEV-01 | Implemented (review scope) | New feature branch from tested source, instructions/config/tests/deployment inspected, PDF preserved; commit/push docs, no merge inferred. |
| DEV-02 | Implemented (documentation scope) | No generated runtime code or weakened controls; maintainer/security review proposal before changes. |
| DEV-03 | Partial | Unchanged exact versions; read-only runtime/Composer audits pass; build-tool/licence/maintenance and any new BFF dependency need separate evidence. |
| DEV-04 | Partial | 156 PHP checks/10 targeted browser cases pass; full proposed behavior does not exist, so broader implementation/hosted acceptance remains mandatory. |
| DEV-05 | Partial | Accessible bilingual warnings/focus tests specified, no UI changed; accessibility/product approve actual policies and run full checks. |
| DEV-06 | Partial | Explicit activity/no passive renewal and bounded storage/query plan; engineering test actual DB cost, cleanup, timers and network behavior. |
| REL-01 | Partial | This dated evidence/decision ledger; release owner appoint reviewers and retain signatures. |
| REL-02 | Implemented (review checkpoint only) | Relevant existing synthetic checks and read-only audits recorded; unchanged-source broad suites explicitly omitted. |
| REL-03 | Unverified (new session design) | No new artifact/schema deployed; operations/release require reproducible package, approved synthetic host and full matrix/restore. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; a review plan does not waive implementation, privacy or hosted gates. |
| REL-05 | Partial | Review authorized, authentication changes withheld; D1–D6 and exact material changes need owner decisions before execution. |
| REL-06 | Implemented (report scope) | Findings, files, versions, commands/counts, flows, unknowns and rollback described; final doc validation and source binding retained in Git. |

RELEASE STATUS: NOT READY FOR RELEASE
