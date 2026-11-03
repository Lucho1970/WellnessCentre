# Safari and identity verification preflight — 2 November 2026

**Read-only checkpoint. Production unchanged. BrowserStack testing deferred by owner.**

Source reviewed: `6a97b9a74aaa08663a763c05fcb2e1b9bf663d5d`.
Branch: `feature/session-hosted-verification`; reviewer: Codex, 2 November 2026 Eastern.
The owner authorized the next feasibility checks, proposed BrowserStack because no Mac
is available, signed in personally, and then deferred resolving BrowserStack access
with “not now.” No remote browser test session, tunnel, purchase, trial, upload, deployment, account
reactivation, permission change or authentication architecture change was performed.
Named identity/security/privacy/release sign-offs remain outstanding.

## Results and exact limits

| Check | Observed result | Meaning / next action |
| --- | --- | --- |
| Local/connected host availability | This task has the Windows workspace; no connected Mac host was found. Owner reports no Mac. | Actual Safari/macOS/iOS tests were not run. Windows WebKit remains a different platform. |
| BrowserStack Live dashboard | Signed in by owner. Dashboard displayed “Please buy a plan to continue testing” and an email-verification notice. Owner deferred access work. | Access to a runnable Safari session is unverified. No purchase or cloud browser session started; do not infer a subscription or completed verification. |
| Workforce tenant | Existing Azure CLI context identifies `dcbc99f5-04a8-40d6-8a2e-69633e3673ff`. Selected Graph reads succeeded. | Configuration evidence only; no interactive staff authentication or token-freshness/MFA ceremony tested. |
| Workforce SPA | `d5c68a6f-cde5-40f5-843f-eac5a3160065`, “Wellness Centre Staff Portal (Dev)”. optionalClaims is null. | Null configuration does not prove that a particular claim will be absent from an issued token. |
| Workforce API | `923bdf31-87c8-44c7-9396-3d3860b7e678`, “Wellness Centre API (Dev)”; requestedAccessTokenVersion=2. | Matches the documented API audience/version configuration, not proof that every deployed request validates it. |
| Delegated scope | Enabled `access_as_user`, scope ID `e47831e2-5d08-42e5-b9b4-c9f8f37c46b2`, type User. SPA requiredResourceAccess references this exact API/scope. | Required permission configuration verified; no new permission requested. |
| Actual consent grant | SPA service principal has one returned OAuth2 permission grant, scope `access_as_user`, consentType AllPrincipals, no next page. Its resource service principal resolves to the expected API app ID above. | Actual tenant-wide delegated consent verified for this pair. It grants neither local clinic membership nor central administrator authority. API preAuthorizedApplications is empty; this does not negate the separately verified consent grant. |
| Workforce Conditional Access | Graph GET returned an empty value array, with no nextLink. | No Conditional Access policy evidence was obtained from this tenant. This alone does not prove that MFA is disabled: security defaults, per-user enforcement and effective sign-in evidence remain separate. |
| Security defaults | Graph GET returned HTTP 403. | State unverified; no broader Graph permission requested to resolve it. Identity/security reviewer must inspect it through authorized administration. |
| External ID | Existing CLI authorization for tenant `0a3841c6-b244-410d-821f-bbd9ccd1b5e2` was rejected with AADSTS530036. | Practitioner/client registrations, actual provider claims and effective MFA remain unverified in this checkpoint. No policy bypass, tenant switch or alternate authentication workaround. |

The workforce SPA currently registers these exact SPA return URIs:

```text
https://wellnexa.copihue.ca
https://portal.copihue.ca/admin/
https://livinlively.copihue.ca/
https://willowwellness.copihue.ca
https://portal.copihue.ca
https://wellness.copihue.ca
http://localhost:5173
```

These are observed settings, not proposed additions or permission to deploy a bridge
at those locations. The isolated prototype's `/prototypes/session-security/popup.html`
is not among them. A registered localhost root does not itself make the current
synthetic harness a valid real-provider callback. No redirect URI was added/removed.

## Review before any real-provider experiment

The existing [session design](SESSION_SECURITY_REVIEW_2026-11-02.md) still needs D1–D6
decisions and appropriate human approvals before production integration. In particular:

- Verify the exact practitioner and client app/flow/callback configuration using a
  fresh authorized External ID admin session; retain separate workforce and social realms.
- Obtain issuer/audience/SPA binding, single-use nonce, auth_time and silent-renewal
  evidence using only the approved test identities and an exact reviewed callback.
  No application submission, membership activation or clinical API call is needed
  merely to assess identity proof. Existing test-account retirement is not undone.
- Establish effective workforce/privileged and External ID MFA from policy plus real
  sign-in evidence. A successful picker, optionalClaims setting, auth_time or a generic
  amr claim alone is insufficient. No extra Graph scope, SMS MFA or provider cost approved.
- Reconcile the prototype's ID-proof claim contract before trying real tokens: its
  `SessionFixture::claims` currently requires azp in both token types, whereas existing
  `CustomerAuthenticator::claims` permits a missing ID-proof azp and binds the ID proof
  to the SPA audience, while still strictly binding access-token azp. Microsoft's
  [ID-token reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference)
  defines aud as the receiving app; its
  [access-token reference](https://learn.microsoft.com/en-us/entra/identity-platform/access-token-claims-reference)
  describes v2 azp as the calling client. Actual claim shapes remain unverified here;
  no verifier was changed and no validation requirement waived by this review.

## BrowserStack resume plan — synthetic data only

BrowserStack supports real desktop/mobile browser testing and
[Local Testing](https://www.browserstack.com/docs/live/local-testing). When the owner
chooses to resume, first verify existing Live or Automate access and email verification.
Any new paid plan requires a specific product/cost decision; no budget or subscription
has been authorized. Use Live for a supervised manual Safari session if already included;
automated sessions require the corresponding actual entitlement. Starting a trial,
accepting a new agreement or upgrading is not inferred from signing in.

Prepare a separately reviewed synthetic test endpoint and narrow local connection
before any cloud session. The current full harness depends on Playwright interception
of fake origins and therefore cannot simply be opened in a real Safari Live session.
Do not expose the repository root, API private directory, local attachments, .env,
database, Azure credentials or the production portal through a tunnel.

The first cloud test should reproduce cookie behavior with fixed dummy values and
an isolated popup fixture; no Google/Microsoft credentials, real token, practitioner
identity, PHI or production data may enter the cloud browser in that step. Record
BrowserStack OS/browser/device versions, real versus simulated device, session settings,
data/recording retention, cleanup and exact source. Cloud-browser provider terms and
operational safeguards remain unreviewed for real identity/health data.

Retain Secure/HttpOnly/host-only/SameSite, exact Origin/CSRF and bearer requirements.
Use properly trusted HTTPS; BrowserStack's
[acceptInsecureCerts capability](https://www.browserstack.com/docs/automate/selenium/accept-insecure-certificates)
and its Local `--disable-ssl-validation` flag must stay off. Its
[Local flags](https://www.browserstack.com/docs/local-testing/binary-params) include
host/port scoping; approve a bounded allowlist before starting a connection rather
than broad network forwarding. iOS
[localhost rewriting and port restrictions](https://www.browserstack.com/docs/live/local-testing/ios-troubleshooting-guide)
must be accounted for without falsely treating two same-site aliases as cross-site.
No tunnel binary, runtime dependency or new cloud endpoint was installed/configured here.

Actual Safari/iOS trusted-HTTPS evidence remains open. The
[last local matrix](SESSION_SECURITY_BROWSER_FOLLOWUP_2026-11-02.md) is still historical:
128/132 passed, four strict Windows WebKit failures. No cloud acceptance is claimed.

## Exact read operations and retained evidence

Azure CLI was used with its existing context. Graph access tokens stayed only in
process variables/headers and were cleared afterward; no token was printed or written
to the evidence files. No auth-cache file was directly inspected/exported; the CLI used
its standard credential handling. No private env, database or patient record was read.
Only these narrowly selected Graph GET resources were inspected:

```text
/applications(appId='d5c68a6f-cde5-40f5-843f-eac5a3160065')
  select: id,appId,displayName,signInAudience,spa,web,optionalClaims,requiredResourceAccess,api
/applications(appId='923bdf31-87c8-44c7-9396-3d3860b7e678')
  select: appId,displayName,api,optionalClaims
/servicePrincipals?filter=appId eq '<workforce-SPA-app-id>'
  select: id,appId
/oauth2PermissionGrants?filter=clientId eq '<resolved-SPA-service-principal-id>'
  select: clientId,resourceId,scope,consentType
/servicePrincipals/<resolved-grant-resource-id>
  select: appId,displayName
/identity/conditionalAccess/policies
  select: id,displayName,state,conditions,grantControls,sessionControls
/policies/identitySecurityDefaultsEnforcementPolicy
```

Commands included `az account show --query '{tenantId:tenantId,state:state}' --output json`,
`az account get-access-token --resource-type ms-graph --output json` and the equivalent
external-tenant token request, captured only in process variables. Actual selected GETs
used `Invoke-RestMethod -Method Get` with those in-memory headers. The external-tenant
request failed; security defaults returned 403. An initial CLI Graph URL query was
malformed; the corrected direct GETs above succeeded. It is not counted as an authority
denial. BrowserStack dashboard lookup was read-only after user-completed sign-in.
No raw error text, service access key or private account URL is included in this document.

Ignored JSON SHA-256:

| File under `.tmp/` | SHA-256 |
| --- | --- |
| session-workforce-portal-readonly.json | `40a202a668fbf6b6e64043e06c15b475d0682a67b43bf541bbd6ec6466709692` |
| session-workforce-api-readonly.json | `c4601a5ddea885d99914c9c40c44dbfc116b3d9bc948ab1d5f55a20e2df5fbc2` |
| session-workforce-conditional-access-readonly.json | `626699d6dbc9e15d53daa5c5d61b746a035239c5460c01d98c7fe27ff9b9a5fa` |
| session-workforce-grants-readonly.json | `486512d39c4538c825ba603bc23c8aa2989716477b45c84c10d0b961b91e5149` |
| session-workforce-grant-resource-readonly.json | `4fbffae16bc4d37ac930769758a2483b6e162b91acc4b01a280c25107fb0a642` |

## Change scope, validation and rollback

This task changes only this new report and `documentation/README.md` (index link). No code, tests,
manifest, dependency, supported runtime, lockfile, public configuration, registration,
grant, consent, permission, application endpoint or production data flow changed.
Existing versions remain Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14, MSAL browser 5.19.0,
Playwright 1.63.0 and Firebase PHP-JWT 7.1.0. BrowserStack account access is not approval
for an application subprocessor handling identity credentials or PHI.

Cloud/API findings above and documentation/source checks below are this checkpoint's
validation. Builds, browser, PHP, SQL, dependency restores/audits and hosted acceptance
were not rerun for two documentation files. The previous tests/advisories/failures remain
in their dated reports; no failed gate is closed by this preflight. No artifact/package,
backup restoration, live provider ceremony or clinical/operational acceptance occurred.
Rollback is a reviewed documentation revert; nothing was deployed or mutated in Entra
or BrowserStack, and no production data restoration is needed.

## All 38 controls — read-only preflight scope

Date/revision/reviewer above. Owners below need named appointment/sign-off; statuses
cover only verified scope and do not certify organizational compliance.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/operator roles/contracts unchanged; custodian identify accountable tenant/platform owners. |
| GOV-02 | Unverified | No legal/version/commencement reassessment; privacy/legal maintain applicability register. |
| GOV-03 | Unverified | No PIPEDA determination changed; privacy/legal assess actual commercial/cross-border contexts. |
| GOV-04 | Partial | Administrative identity/browser review only; product/clinical confirm real intended use. |
| GOV-05 | Partial | No real patient data; new cloud-testing proposal bounded to dummy data. Privacy/security approve real flow/PIA/TRA. |
| PRIV-01 | Unverified | Notices/contact unchanged; custodian verify actual bilingual practices. |
| PRIV-02 | Partial | No real practitioner ceremony or consent collected; privacy approve any later cloud credential/identity flow. |
| PRIV-03 | Partial | Prior revocation evidence remains bounded; no current server/worker authority or clinical transaction revalidation. Security own. |
| PRIV-04 | Unverified | Representative/minor authority unchanged; clinical/custodian retain safe scope/process. |
| PRIV-05 | Unverified | Rights workflow unchanged; privacy operations retain access/correction evidence. |
| PRIV-06 | Unverified | No real record disposal; records/privacy approve session/audit/backup/provider retention and holds. |
| PRIV-07 | Partial | Recovery/revocation proposal unchanged; incident/privacy lead rehearse real breach/lost-device response. |
| AUTH-01 | Partial | Workforce SPA/API metadata verified; actual proof claims, External ID and real browser/provider execution still open. Identity/security own. |
| AUTH-02 | Partial | Configured consent is not a clinic/central membership grant; production boundaries unchanged, no new acceptance. Security own. |
| AUTH-03 | Partial | Empty returned CA inventory, denied defaults read; MFA/effective session policies remain unverified. Identity/security provide real evidence. |
| AUTH-04 | Partial | Existing persistence and failed browser gates unchanged; real cloud credentials excluded, actual design/handoffs need security approval. |
| AUTH-05 | Partial | Exact workforce scope/resource/AllPrincipals grant verified read-only; External ID/client/runtime DB inventory still incomplete. Identity/operations own. |
| SEC-01 | Partial | No credentials/dumps/private files committed; Graph bearer held only in process. Security retain full source/artifact/history assessment. |
| SEC-02 | Partial | Verified HTTPS Graph/web reads, no bypass; actual Safari HTTPS, DB/backup encryption and key custody unverified. Operations own. |
| SEC-03 | Partial | BrowserStack trust/alias constraints identified; four prior WebKit failures remain. QA/security require trusted target-platform acceptance. |
| SEC-04 | Partial | No new parser/HTML/auth code; prototype ID-proof claim assumptions require separate reconciliation before live test. Engineering/security own. |
| SEC-05 | Partial | Bounded selected metadata reads, no cloud session/expensive integration started; engineering need actual session abuse/load/race tests. |
| SEC-06 | Partial | Read-only cloud metadata review is not production audit implementation; security/DB retain actor/correlation/denial/integrity gates. |
| SEC-07 | Partial | Error classifications only in report; token values and private dashboard URLs excluded. Operations verify actual proxy/provider redaction. |
| SEC-08 | Partial | BrowserStack reviewed as proposed dummy-data service only; no tunnel/cloud test/PHI flow. Privacy/vendor owner approve actual use and retention. |
| SEC-09 | Not applicable | No new email/SMS/calendar transport or message dispatch. |
| DEV-01 | Implemented (review scope) | Dedicated feature branch, instructions/config/source inspected, PDF preserved, commit/push without merge/deploy. |
| DEV-02 | Implemented (documentation scope) | No runtime change or weakened gate; current facts distinguished from historical and denied/unverified evidence. |
| DEV-03 | Partial | Dependencies/locks/toolchain unchanged; prior 11 moderate dev findings and clean release evidence remain open. |
| DEV-04 | Partial | Documentation/read-only operational validation only; prior suites/failures preserved, omitted fresh runtime/SQL/cloud tests explicit. |
| DEV-05 | Unverified | No UI changed; actual Safari/iOS/accessibility/assistive-technology evidence still required. |
| DEV-06 | Partial | No runtime/network polling change; no actual cloud session/load/query/index/browser timing acceptance. Engineering/QA own. |
| REL-01 | Partial | Dated source-bound ledger and selected cloud evidence; release owner obtain named decisions/signatures. |
| REL-02 | Implemented (documentation/read-only checkpoint only) | No runtime/schema change; checks/omissions and previous failed gates explicit. |
| REL-03 | Unverified | No new artifact/cloud-browser or hosted identity acceptance, restore or rollback exercise. Operations/release own. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; service access, claims/MFA, browser, design and operational gates remain. |
| REL-05 | Implemented (authorized read-only scope) | No production/material/auth/grant/cloud-plan changes. Owner deferred BrowserStack access; later concrete changes need approval. |
| REL-06 | Implemented (report scope) | Requested/result scope, exact reads/limits, files/versions/flows, owners, omissions and rollback recorded; final source checks below. |

Final documentation validation: `node .tmp/validate-session-provider-preflight.mjs`
passed for two changed files, 100 relative links and 38 distinct control rows. A
bounded private-key/JWT-signature scan found zero matches in the changed documents.
`git diff --cached --check` passed; normal LF-to-CRLF notices are not failures.
The runtime/source/config/lock directories are unchanged against the reviewed source.
Untracked `documentation/blog-topics.pdf` and the later-appearing
`documentation/REQUIRED_FOR_SITE.md` were preserved without inspection or staging.
The report/index are committed and pushed on the dedicated branch for review;
no merge, deploy, cloud session or production activation.

RELEASE STATUS: NOT READY FOR RELEASE
