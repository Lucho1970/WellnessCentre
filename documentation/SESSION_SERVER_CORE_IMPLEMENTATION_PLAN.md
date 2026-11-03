# Server application-session core — implementation proposal

**3 November implementation update:** the [local core and disabled adapter](SESSION_SERVER_CORE_2026-11-03.md)
now implement the policy/proof, store/migration and service/transport slices, with
repeated dual-engine SQL/HTTP/concurrency evidence. Existing API/business-route adoption
and browser work remain incomplete; there is no production activation or migration.
The proposal below is retained as the full roadmap, not a claim that all slices shipped.

2 November 2026. Prepared against `b55c9b64c9624f785df40a0feea4fa787a824c87`, with
the separate prototype proof correction described in
[the validation report](SESSION_PROOF_CONTRACT_2026-11-02.md).
This is a concrete WP1 proposal, not an applied migration or production implementation.
It refines the [overall session plan](SESSION_SECURITY_REVIEW_2026-11-02.md).
The owner has authorized the prototype correction and preparation; architecture/policy
approval and later production activation remain separate decisions.

## Intended behavior and policy decisions

Every protected request continues to require the existing verified API bearer and
current clinic/membership/record/consent authority. A server application session adds
independent idle/absolute/revocation enforcement, carried by a host-only HttpOnly cookie.
No password implementation, new Graph scope, token store or Node production service.

| Realm | Proposed idle / absolute | Bootstrap authority / continuing checks |
| --- | --- | --- |
| Workforce | 15 minutes / 8 hours | Existing workforce issuer/API scope; exact clinic host and active staff membership. |
| Practitioner | 15 minutes / 8 hours | Dedicated External ID issuer/API scope; current pilot decision and active membership for workspace. Identity-only application access cannot become workspace authority. |
| Central administration | 15 minutes / 8 hours | Exact central host, workforce identity and current explicit application-administrator grant; no clinical access implied. |
| Client | Existing 30 minutes / 8 hours | Customer verifier and existing customer identity/link/clinic/consent rules. An unlinked identity gains no patient records. |

Proposed challenge lifetime: 10 minutes, single use. Sensitive-action proof: maximum
five minutes, one use for a server-defined purpose/target/version. Freshness and MFA
assurance are different requirements; the identity review must resolve actual provider
claims and policy before enabling a production realm.

Product/security decisions still required: the table's timing; memory-cache/reload/new-tab
UX; precise sensitive actions requiring reauthentication; authority to revoke one/all
sessions; allowed temporary SDK state; named operational/privacy owners. Current memory
prototype requires an interactive popup after reload; the cookie alone cannot recover
access. Actual Safari/provider/MFA evidence remains a release gate, not a passed result.

## Deliverable sequence and exact file responsibilities

All paths below are proposed; none of these new production classes or migrations was
created by the prototype task. Implement in reviewable, separately validated commits.

| Slice | Proposed files | Result / exit check |
| --- | --- | --- |
| A — policy and proof | `api/src/Auth/LoginProofVerifier.php`, `api/src/Service/BrowserSessionPolicy.php`, `api/tests/browser-session-proof.php` | Existing JWT library/JWKS transport, explicit realm binding, exact SPA proof audience, optional-but-validated ID azp; mandatory approved access-token azp and API scope. Unit negative matrix passes. No runtime dependencies. |
| B — store/migration | `api/src/Service/BrowserSessionStore.php`, new `api/src/Service/CustomerSessionStore.php` adapter for the SQL currently in `CustomerOnboarding.php`, next `api/database/migrations/<number>_browser_sessions.sql`, `api/tests/integration/browser-sessions.php` | Additive schema, existing customer-session behavior preserved, locked one-use challenges/epochs/sessions/action proofs; dual-engine disposable SQL acceptance and audit rollback. |
| C — service and cookie transport | `api/src/Service/BrowserSessionService.php`, `api/src/Http/SessionCookie.php`, `api/tests/browser-session-http.php` | Host/realm/principal binding, deadlines, CSRF/origin, rotation, safe errors, no cookie/JWT values in JSON/logs; HTTP negative cases. No clinical authority embedded in session. |
| D — explicit integration | `api/src/Api.php`, `api/src/Http/Request.php`, `api/src/Config.php`, existing authenticator/membership/customer services, privacy/audit services and example config | Default-off/inert route/guard wiring, complete endpoint inventory and raw-request bypass tests. No activation or private .env edit. Authorization/membership mutation paths share revocation locks. |
| E — browser work, separate WP2/WP3 | Existing MSAL providers, customer bootstrap/session, central shell and common requests; typed session boundary and EN/FR warnings | Supported memory/popup flow, Web Storage cleanup/handoff review, removal of private state at denial/expiry/logout, late-response suppression. Server gate is mandatory independently of UI. |

Migration sequence is currently through 058. Recheck before reserving a number; 059 is
only a candidate. No migration should be executed on the hosted database during local
development. Do not install production flags or alter already approved pilot entries.

## Proposed additive schema contract

Use InnoDB, UTC DATETIME, existing BIGINT UNSIGNED ID conventions and prepared SQL.
Digest fields are CHAR(64) CHARACTER SET ascii COLLATE ascii_bin. Session secrets,
pre-auth correlation secrets and action-proof secrets are random 32-byte values;
persist only digests. Never store raw bearer/ID/refresh tokens, session-cookie values,
full claim objects, location/IP fingerprints or clinical bodies.

| Object | Required fields / indexes |
| --- | --- |
| `browser_sessions` | Secret digest PK; realm; normalized host; server-resolved clinic nullable only for central; non-null scope_hash and verified principal_hash; optional existing local authority reference; authenticated_at, created_at, last_activity_at, absolute_expires_at; policy_version, issued_epoch; revoked_at/reason. Index scope/principal/revocation and expiry. |
| `browser_session_epochs` | Composite PK `(scope_hash, principal_hash)`; current generation and updated_at. Store scope host/realm/clinic metadata. Use a non-null scope hash so central NULL clinic values cannot evade uniqueness in MySQL. No roles cached as authority. |
| `browser_auth_challenges` | Correlation-secret digest PK, nonce digest, fixed purpose/realm/host/scope, created/expires/consumed timestamps and minimal correlation. Expiry/scope indexes. Challenge does not create a membership or approval. |
| `browser_action_proofs` | Proof digest PK, bound session/scope/principal, allowlisted purpose, server-derived target digest and expected version, fresh authenticated_at, expires/consumed timestamps. Index session/expiry. No reusable general bypass. |
| Existing `customer_sessions` | Preserve rows, token_hash PK, identity_id, authenticated_at, created_at, last_activity_at, expires_at and revoked_at; migration 039's clinic_id remains. Add nullable host/scope/principal metadata plus policy_version/issued_epoch as needed. Legacy incomplete rows cannot pass an activated new policy. No invented proof freshness or artificial session backfill. |

Migration 005 creates `customer_auth_challenges`/`customer_sessions`; 039 adds clinic
scope. The adapter must preserve `CustomerOnboarding::identityHash`, which uses the
verified **API** issuer/subject. Do not replace existing identities with OID hashes or
merge matching emails. Stable verified oid/tid bind the fresh SPA proof to the API
identity across pairwise subjects; they do not automatically link another local account.
Keep workforce, external staff and customer canonical identities distinct.

Use the generic challenge/action policy for new guarded flows; retain old challenge
history. Do not run two competing customer session clocks or copy active sessions into
a second store. New policy/transport rejects old insufficient proofs when activated.
Existing customer transport continues only under its separately reviewed legacy policy
until that host's migration; its storage exception is not approved by this proposal.

Runtime DB role needs only reviewed SELECT/INSERT/UPDATE on these objects; schema changes
use separate migration credentials. Bounded maintenance/purge needs its own approved
retention/hold rules and role. No universal legal retention period is selected here.

## Transaction and request contracts

1. Resolve realm, host and clinic from server routing/configuration. Validate API JWT
   signature/issuer/tenant/audience/scope/authorized caller; validate fresh SPA proof with
   exact audience, signature, nonce, verified identity and auth_time. Direct Google/MSA
   tokens are not accepted as External ID practitioner tokens. Do not fetch arbitrary JWKS.
2. Consume a challenge exactly once and create/rotate the session in one transaction.
   An invalid/replayed challenge, unavailable authority/store or failed required audit
   cannot commit a session or privilege. Emit Set-Cookie only after successful commit;
   handle an interrupted response as uncertain, without reusing a consumed proof.
3. For protected requests, compare current policy/epoch, host/realm/principal/clinic and
   both deadlines before any activity touch. Background me/polling and silent renewal
   do not extend idle or absolute time. Only explicit approved activity slides idle;
   no request revives an expired session.
4. Inventory existing authority/mutation lock order before integration. Revocation,
   membership/approval changes and sensitive writes must acquire the same authority/
   epoch/session locks and hold them through their commit. An epoch table alone does
   not prevent a stale write; do not claim race protection until those callers are
   wired and competing-transaction tests pass. Deadlock/uncertain-write handling must
   return safe bounded recovery, never blindly replay clinical/payment effects.
5. Keep revocation atomic with required audit; use existing audit services after proving
   their transaction behavior. Events include actor realm, clinic/internal resource,
   outcome/reason, UTC time and correlation, excluding credentials and content. Required
   audit failure blocks the corresponding action. Denial logging needs a reviewed
   persistence path that survives an authorization rollback without leaking data.
6. Cookies use `__Host-`, Secure, HttpOnly, Path=/, SameSite=Lax and no Domain. Return
   CSRF/deadline metadata only; keep CSRF in memory. Every state-changing same-origin
   request requires exact Origin and session CSRF; check real transport/host headers
   using approved proxy trust, never arbitrary X-Forwarded-Host input.
7. Another tab can rotate the shared cookie. Existing same-principal tabs must fetch
   current session metadata/CSRF using the current verified bearer before logout or
   other protected mutations. A different-account bearer cannot pair with that cookie.
   UI cleanup occurs before network logout, and failed server logout remains explicit.
8. Own-session logout can use cookie plus exact Origin/CSRF without a still-valid API
   token, but must never accept another user/session selector or read records. Remote/
   all-session administration requires current explicit authority and fresh action proof.

Proposed routes remain the original plan's `/staff/session/*`, `/practitioner/session/*`,
`/application/session/*`, with the existing `/customer/auth/*` family adapted rather
than duplicated. Only bounded challenge/bootstrap options are anonymous. The new
request parser treats browser cookie input as untrusted, with length/format/duplicate
handling and request-body/abuse limits. An activated host/realm never falls back to a
bearer-only or Web Storage credential path when its session store/config is unavailable.

## Local acceptance and release separation

Add `browser-sessions.php` explicitly to both portable runner allowlists and require a
freshly initialized, explicitly disposable synthetic DB. No connection to an occupied
port, hosted credential fallback or old database dump. Proposed future commands:

```text
php api/tests/browser-session-proof.php
php api/tests/browser-session-http.php
./scripts/test-recurring-sql.ps1 -MariaDbDirectory <disposable-runtime> -IntegrationTest browser-sessions.php
./scripts/test-mysql57-sql.ps1 -MySqlDirectory <disposable-runtime> -IntegrationTest browser-sessions.php
```

These files/suite values do not exist yet; the current runner ValidateSet correctly
rejects the proposed name until deliberately extended. Acceptance must cover successful,
missing/malformed/forged/expired proofs, wrong realms/hosts/accounts/scopes, omitted
versus invalid ID azp, CSRF/origin rejection, replay/duplicate/concurrent login, two-tab
rotation/logout, revoke-one/all and membership departures, idle/absolute boundaries,
silent renewal, audit/store outage, no clinical bypass, migration rerun behavior,
index/query bounds and backup restoration without resurrecting revoked sessions.

After backend tests, require both frontend builds/content/i18n/lint/type checks and
appropriate default plus dedicated admin/application/invitation browser configurations.
Then inspect the exact artifact and execute separately approved hosted synthetic testing,
actual provider/MFA claims and Safari/macOS/iOS trusted-HTTPS acceptance. BrowserStack
remains deferred by owner; no new cloud plan, tunnel or identity exposure is implied.

Rollout is host/realm scoped and default-off until all relevant decisions and deployment
approval exist. No broad rollout merely because a SQL suite passes. On failure, close
the affected guarded entry, retain additive tables and all records/audit, and use the
reviewed prior artifact/configuration with explicit session invalidation. Do not restore
an old database over newer clinical records or enable bearer-only admission as an outage
workaround. After a data restore, invalidate restored sessions/epochs before reopening.

The [38-control evidence ledger](SESSION_PROOF_CONTRACT_2026-11-02.md) applies to this
proposal. Identity/security, custodian/privacy, clinical and release/operations owners
must make named decisions within their responsibilities; no approvals are invented here.

RELEASE STATUS: NOT READY FOR RELEASE
