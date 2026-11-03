# Prototype ID-proof contract correction — 2 November 2026

**Isolated local prototype only. Production unchanged. NOT READY FOR RELEASE.**

Baseline: `b55c9b64c9624f785df40a0feea4fa787a824c87`.
Implementation source: `e25dfdb8813c85acf4173fdb18716cdc450eaf60`; branch `bugs/session-proof-audience`.
Reviewer/date: Codex, 2 November 2026 client date. Owner authorized correcting and
testing this prototype and preparing the next server-session core plan. No production
authentication, database, registration, grant, cloud subscription or account change.
BrowserStack remains deferred; actual Safari/provider/MFA acceptance is not claimed.

## Result and security boundary

The prototype incorrectly required the access-token caller claim `azp` in the SPA ID
proof too. A valid signed, fresh proof addressed to the exact SPA was rejected when
that optional claim was absent. Four new realm tests reproduced HTTP 401 instead of
200 before the fix. The local verifier now distinguishes the contracts:

| Token | Mandatory audience | Caller / proof requirement |
| --- | --- | --- |
| API bearer | Exact configured API app ID | Exact approved SPA azp and delegated scope remain mandatory. Missing/null/wrong azp still fails. |
| Fresh ID proof | Exact configured SPA app ID | azp may be absent. If present, it must be the exact SPA string; null, blank, number, array or another client is rejected. |

Both retain JWT signature/algorithm, issuer, tenant, version, lifetime and identity
validation. Bootstrap still requires its bound pre-auth cookie, one-use nonce, recent
auth_time and matching verified oid/tid. Different pairwise sub values between API and
SPA do not permit another principal. A valid ID token never becomes an API bearer.
No credential-store, scope, role, membership or clinic-boundary fallback was added.

The synthetic token issuer now omits azp from ID tokens by default while still including
it in API tokens. Ordinary real-MSAL popup, renewal, reload, denial and logout tests
therefore exercise the corrected shape end to end. Direct HTTP cases also cover a
matching explicit ID azp and rejection of malformed/mismatched claims, bad signatures,
wrong audiences/issuers/tenants/nonces/accounts/freshness and proof replay in all four
realms. The first full run completed with the prior fixture shape; a final run with the
more realistic ID-token fixture is separately recorded below.

This follows the distinction in Microsoft's
[ID-token claims reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference)
(audience is the receiving app) and
[access-token reference](https://learn.microsoft.com/en-us/entra/identity-platform/access-token-claims-reference)
(v2 azp identifies the caller). Existing production `CustomerAuthenticator` already
allows an absent ID-proof azp while requiring access-token azp. Its existing `isset`
check also treats null as absent; the prototype deliberately rejects a present null.
No production verifier was changed, and this does not establish actual provider claims
or effective MFA. That separate contract still needs real identity/tenant evidence.

The fixture-default run also exposed an existing boundary-test race: its synthetic
offset was added to the still-advancing wall clock. A check intended for idle expiry
minus one second could reach the deadline and correctly receive 401. The fixture now
provides an authenticated local-only `freeze` control used by the two exact idle tests.
They assert the issued deadline, request time one second before expiry, and exact
expiry before checking denial. Other tests retain the live clock. No timeout, expiry
comparison or assertion was relaxed, and no production clock was changed.

## Validation

Final dedicated matrix: **164 passed / 4 failed**, exit 1, 183.053s. Edge 56/56 and
Firefox 56/56 pass. Windows WebKit passes 52/56; all four failures remain the strict
SameSite=Lax assertions in the four realm lifecycle cases (native jar reports None).
No tests were skipped, retried, marked expected failures or weakened. These do not
establish Safari compatibility. The corrected ID-proof groups and exact idle tests
pass in every engine. The clock-corrected run started 3 November 2026 04:48:55 UTC
(2 November local date). Ignored JSON: `.tmp/session-proof-clock-final-report.json`.
Browser annotations: Edge/Chromium 157.0.4341.30, Firefox 155.0, WebKit 26.6. WebKit's
first popup case uses two explicit fixture cookie-transport bridges; the others use
none. This is synthetic intercepted HTTPS, not real TLS or provider acceptance.

| Exact check / directory | Result |
| --- | --- |
| `npm run typecheck:session-prototype` — Frontend | Exit 0; strict TS unchanged. |
| `npm run lint` — Frontend | Exit 0, no warnings. |
| Before fix: `npx playwright test --config playwright.session-prototype.config.ts --project=edge --grep 'ID proof accepts'` — Frontend | All 4 new positive cases failed at omitted azp, HTTP 401 versus required 200. Expected defect reproduction, no skipped assertions; `.tmp/session-proof-before.log`. |
| `npm run test:session-prototype` — Frontend | 164 passed / 4 existing WebKit failures, exit 1, 183.053s. Uses existing isolated PLAYWRIGHT_BROWSERS_PATH cache; `.tmp/session-proof-clock-final-matrix.log`. |
| First full run, before fixture-default refinement | 164 passed / 4 existing WebKit SameSite failures, exit 1, 3.3m; `.tmp/session-proof-full-matrix.log`. Refinement prompted a fresh full run rather than assuming complete SDK compatibility. |
| Fixture-default run, before deterministic idle clock | 163 passed / 5 failed, exit 1, 188.169s: the same four WebKit failures plus Edge practitioner idle-minus-one-second reaching expiry. Preserved `.tmp/session-proof-fixture-refinement-report.json` and `.tmp/session-proof-final-matrix.log`; the final clock-corrected run is separate. |
| `npx playwright test --config playwright.session-prototype.config.ts --project=edge --grep 'idle boundary' --repeat-each=10` — Frontend | 20 passed, exit 0, 25.0s; `.tmp/session-proof-clock-repeat.log` and ignored JSON. No retries or skipped assertions. |
| `npm run build` — Frontend | Exit 0: lint, 4 bilingual content entries, 2,568 translations, strict TS and public/portal/central builds. Existing >500kB chunk warnings retained; `.tmp/session-proof-frontend-build.log`. |
| `npx playwright test --config playwright.build.config.ts` — Frontend | 15 passed, exit 0, 21.8s; `.tmp/session-proof-build-browser.log`. |
| `npm run lint`; `npm run build`; `npm run test:browser` — Wellnexa | Each exit 0; 24 browser cases passed, 35.5s. Four existing App.tsx/catalogue Details.tsx lint warnings remain; `.tmp/session-proof-wellnexa-browser.log`. |
| `php api/tests/customer-auth.php` | Exit 0: 25 auth + 1 staff boundary + 6 ID-proof checks. |
| `php api/tests/external-staff-auth.php` | Exit 0: 67 checks. |
| `php api/tests/customer-onboarding.php` | Exit 0: 25 checks. |
| `php api/tests/identity-membership.php` | Exit 0: 29 membership + 3 external permission checks. |
| PHP total / syntax | 156 existing synthetic checks passed. OPENSSL_CONF set process-only to `C:/Program Files/Git/usr/ssl/openssl.cnf` and restored. `php -l` on both prototype PHP files passed. |
| `composer validate --strict`; `composer --no-plugins --no-scripts audit --format=json` — api | Both exit 0; zero advisories/abandoned packages; ignored JSON. |
| `npm audit --json`; `npm audit --omit=dev --json` — Frontend | Full exit 1: 11 existing moderate development lint/minimatch-chain findings, no high/critical. Runtime exit 0: zero advisories. No dependency updates or forced fixes. |

No hosted DB/schema/SQL suite, real provider authentication, MFA, Safari/iOS/BrowserStack,
private environment/cache inspection, full assistive-technology/theme/load/restore or
reproducible clean release installation was performed. No migration exists for the
proposed server core. The default application suite and dedicated admin/application/
invitation configurations were not repeated because their production sources/configs
are unchanged; their previous findings remain in the dated reports. A successful local
identity shape test does not prove authorization, privacy or hosted release readiness.

## Next server-session implementation prepared for review

The [concrete WP1 plan](SESSION_SERVER_CORE_IMPLEMENTATION_PLAN.md) specifies proposed
classes/files, route boundaries, additive session/challenge/epoch/action-proof fields,
customer_sessions reuse, exact identity preservation, single-use/transaction rules,
same-principal cross-tab CSRF rotation, dual-engine disposable tests and fail-closed
rollout/rollback. It reserves no migration number and creates no production class,
route, flag, secret or schema. Architecture/timing/UX/authority decisions, real browser
and identity evidence and exact deployment approval remain outstanding.

## Files, versions, data flow and rollback

| Changed file | Purpose |
| --- | --- |
| prototypes/session-security/SessionFixture.php | Separate ID-proof audience/optional azp from mandatory API-caller binding; explicit frozen test clock for exact idle boundaries. |
| Frontend/tests/helpers/sessionPrototype.ts | Model ID tokens without an API-only caller claim by default; API azp remains. |
| Frontend/tests/session-security-prototype.spec.ts | Add 12 meaningful HTTP cases/groups across four realms, including both positive shapes and negative boundary matrices; make existing idle-deadline assertions deterministic. |
| documentation/SESSION_SERVER_CORE_IMPLEMENTATION_PLAN.md | Concrete next implementation/migration/integration/acceptance proposal; no executable migration. |
| documentation/SESSION_PROOF_CONTRACT_2026-11-02.md | This result/source/control report. |
| documentation/README.md | Index latest proof correction and next core plan. |
| prototypes/session-security/README.md | Current proof contract/matrix and report links. |

Unchanged versions: Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14 (declared ^8.2),
and Firebase PHP-JWT 7.1.0. Frontend: MSAL browser 5.19.0 / React adapter 5.6.0,
React 19.2.8, TypeScript 5.7.3, Playwright 1.63.0, Vite 6.4.5 and ESLint 10.13.0.
Wellnexa: React 19.3.0, TypeScript 6.0.3, Playwright 1.64.1 and Vite 8.3.4.
All manifests/locks and supported runtimes unchanged;
no runtime/test dependency added or replaced.

No production code/config, permissions, consent, grants, endpoint, external application
data flow, hosted account, clinical/billing record, mail/SMS or private .env changed.
The same allowlisted fake origins and loopback fixtures are used; only signed synthetic
claim shape changes. No TLS bypass, new cloud service, public package, ZIP or deployment.
The untracked PDF and REQUIRED_FOR_SITE.md remain untouched.

Rollback is a reviewed revert of the prototype/fixture/tests and documentation; no
database restoration or production auth change is involved. Reverting revives the
documented synthetic false rejection, so retain the new regression tests/evidence in
review. Disposable keys/reports remain ignored; normal teardown removes the two private
files. Existing real-data and temporary-storage exceptions are not approved here.

## All 38 controls — corrected prototype and plan scope

Date/source/reviewer above. Owner roles require named appointment/sign-off. Implemented
means verified local scope; previous organizational/release findings remain separate.

| Control | Status | Evidence / owner / next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/operator roles/contracts unchanged; custodian identify accountable clinic/platform owners. |
| GOV-02 | Unverified | No legal/commencement reassessment; privacy/legal maintain applicability register. |
| GOV-03 | Unverified | No PIPEDA determination changed; privacy/legal assess actual flows. |
| GOV-04 | Partial | Administrative synthetic identity checks only; product/clinical confirm actual intended use. |
| GOV-05 | Partial | Synthetic local proof fixtures, no real PHI; security/privacy update actual PIA/TRA for later session integration. |
| PRIV-01 | Unverified | Clinic practices/contact unchanged; custodian verify real bilingual notices. |
| PRIV-02 | Partial | No real credentials/consent collected; privacy approve later session metadata/authority and any cloud use. |
| PRIV-03 | Partial | Local denial/expiry/revocation regression retained; production request/worker/commit authority still needs implementation evidence. |
| PRIV-04 | Unverified | Minor/representative authority unchanged; clinical/custodian retain scoped assisted process. |
| PRIV-05 | Unverified | Rights workflow unchanged; privacy operations retain actual access/correction evidence. |
| PRIV-06 | Unverified | Disposable keys only; records/privacy approve real retention/holds/backups for new session objects. |
| PRIV-07 | Partial | Local logout/denial and future departure plan; incident/privacy lead rehearse actual breach/lost-device response. |
| AUTH-01 | Partial | Corrected synthetic proof audience/azp contract; signed positive/negative matrix passes locally. Identity/security verify actual provider claims/MFA. |
| AUTH-02 | Partial | API caller/host/realm/principal boundaries retained; no production role/record integration or new clinical authority. |
| AUTH-03 | Partial | Local lifecycle tests and concrete policy proposal; production limits/recent auth/MFA remain missing or unverified. |
| AUTH-04 | Partial | Prototype memory/cookie design unchanged; WebKit failures and actual production storage remain open. Security approve full design/handoffs. |
| AUTH-05 | Partial | No grants broadened; prior workforce read evidence retained, External ID/runtime permission inventory still incomplete. |
| SEC-01 | Partial | Keys/output ignored and changed-file/artifact scans scoped below; security retain full history/exposure review. |
| SEC-02 | Partial | No new transport or TLS bypass; actual Safari HTTPS/DB/backup/key custody unverified. Operations own. |
| SEC-03 | Partial | Strict cookie/origin/CSRF checks retained; four known Windows WebKit failures remain a gate. QA/security require target-platform acceptance. |
| SEC-04 | Partial | Optional means omitted only; present invalid/null claims fail. Existing JWT signature/issuer/audience/nonce checks retained; review production implementation separately. |
| SEC-05 | Partial | Existing body/token/store/time limits and replay/concurrency checks retained; production SQL abuse/load/race tests still required. |
| SEC-06 | Partial | Fixture audit is not real disclosure/transaction audit; security/DB verify integration, denial persistence, integrity and retention. |
| SEC-07 | Partial | No token/cookie values in new diagnostics; safe statuses/assertions. Operations verify real proxy/provider logs. |
| SEC-08 | Partial | No new external flow or service; cloud/real-provider work deferred or unverified, privacy/vendor owner approve later flows. |
| SEC-09 | Not applicable | No new mail/SMS/calendar transport or dispatch. |
| DEV-01 | Implemented (local task scope) | Dedicated bugs branch, instructions/configs/schema inspected, untracked work preserved; commits/push without merge/deploy. |
| DEV-02 | Partial | Focused prototype fix, red/green regression and strict checks; human security review and actual-provider contract remain. |
| DEV-03 | Partial | Same locks/libraries, clean runtime/Composer audits; 11 dev advisories and clean release/licence/maintenance evidence remain open. |
| DEV-04 | Partial | Full dedicated matrix, builds/artifact/site/PHP regressions; failed WebKit and omitted live/SQL/config suites explicit. |
| DEV-05 | Partial | No new UI; existing bilingual keyboard/mobile cases retained. Actual AT/contrast/theme/Safari assessment still unverified. |
| DEV-06 | Partial | No production bundles/polling added; bounded fixture cleanup retained, future SQL indexing/load/lock-order acceptance required. |
| REL-01 | Partial | Source-bound dated ledger plus concrete plan; release owner obtain named decisions and missing evidence. |
| REL-02 | Partial | Applicable local checks recorded including four known failures; no hosted/SQL or clean release restore. |
| REL-03 | Unverified | Production artifacts exclude prototype; no new deployable package/hosted identity/restore acceptance. Operations/release own. |
| REL-04 | Implemented (decision only) | NOT READY FOR RELEASE; proof correction closes no other privacy/operational/browser gate. |
| REL-05 | Implemented (authorized local scope) | Prototype correction/plan authorized; no production architecture, schema, grant, cloud-plan or access change. Later concrete rollout requires approval. |
| REL-06 | Implemented (report scope) | Scope/files/versions/commands, initial failure, remaining gates, owners/rollback and source checks recorded. |

Final repository checks: `python .tmp/validate-session-proof.py` validates all seven
changed files, 108 relative documentation links and all 38 distinct control rows. Its
bounded private-key/literal-JWT signature scan finds zero matches in those files;
all 102 current Frontend/Wellnexa build files exclude the five prototype markers.
This is a scoped exclusion/signature check, not a full artifact/history certification.
Normal final teardown removed private.pem and control-secret. Production source,
schema, manifests and locks are unchanged against the baseline. `git diff --check`
and `git diff --cached --check` pass; normal LF-to-CRLF notices are not failures.
Only the two named unrelated untracked documents remain outside this task's commits.
Completed source and documentation are committed and pushed on the dedicated branch
for review; no merge, package or deployment.

Unchanged lockfile SHA-256:

| Lock | SHA-256 |
| --- | --- |
| Frontend/package-lock.json | cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c |
| Wellnexa/package-lock.json | 17b1d75bce7d40a857dfd16c319f32a116b905e799f339c4443446da3a571f1e |
| api/composer.lock | 16fab4fc79b83afd34d4200474204bfc55cd7fe40d650a0c39d848dc4f93265f |

RELEASE STATUS: NOT READY FOR RELEASE
