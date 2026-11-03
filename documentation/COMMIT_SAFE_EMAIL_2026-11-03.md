# Commit-safe account email delivery

**Follow-up:** [Account email scheduler preparation](ACCOUNT_EMAIL_SCHEDULER_2026-11-03.md) adds a separate default-off authenticated one-minute trigger and disabled Azure template. This report's unscheduled-worker status describes revision `766fffcd` before that later branch. Neither branch has been merged or deployed.

3 November 2026. Branch `feature/commit-safe-email`, based on `1025b355593033feec83c92217f2d76962ec3e1a`. Local implementation, disposable synthetic testing and documentation were authorized. Production files, databases, private configuration, identity providers, permissions, accounts, mail delivery and schedulers are unchanged. No merge or deployment is authorized by this report. Implementation revision: `5e279864ca3c211637f847495068c2e7783afbc2` (21 source/test/config files), comprising core `fecf1cf60e952ed8b3d0c9ab44b3e69669acd9b2` and a CLI-only HTTP boundary follow-up. The documentation follow-up adds this report and index/history links; it does not change executable behavior.

## Behavior and boundaries

This resolves the three blocked paths identified in the [server-session integration report](SESSION_SERVER_INTEGRATION_2026-11-03.md), behind a separate default-off switch:

| Path | Atomic database effects | Successful request response |
| --- | --- | --- |
| Staff personal notification email verification | New code hash, expiry, throttle state, encrypted outbox item and audit | `queued:true, sent:false` |
| Client invitation with email delivery | Invitation/revocation history, encrypted outbox item and audit | `delivery:email_queued`; no reusable invitation token in this response |
| Practitioner enquiry notification retry, clinic or central administrator | Attributed enqueue, enquiry notification state and audit | `notification_status:queued` |

Manual invitations retain their existing one-time token response and independent identity-review requirement. A queued invitation grants no record link. The existing anonymous enquiry submission/post-response delivery, appointment notices, billing notices and SMS workers are outside this change. Existing protected Azure/Netfirms notification triggers still drain their existing notification worker, not this outbox. Enabling the queue requires a verified dedicated CLI schedule or a separately reviewed trigger integration; do not assume the existing five-minute trigger will pick these messages up. A malformed queue switch fails closed instead of falling back to synchronous sending. Default-off legacy behavior is retained; with server sessions enabled and this queue disabled, the three paths still fail closed rather than sending inside the request transaction.

The request performs no provider call and registers no post-response callback. Service commits are savepoints inside the optional server-session request: only the final required access audit and physical commit make an outbox item visible to an independent worker. The new dedicated worker is the sole sender for these queued items. An originating PDO transaction, including the hidden `SessionConnection` request transaction, cannot run it.

```mermaid
sequenceDiagram
    participant UI as Existing portal
    participant API as Authenticated existing route
    participant DB as Database
    participant W as Dedicated account-email worker
    participant G as Existing Graph mail transport
    UI->>API: Existing action, bearer/session/CSRF where enabled
    API->>DB: Domain effects + encrypted outbox + required audits
    API->>DB: Final request audit and physical commit
    API-->>UI: Queued, delivery unconfirmed
    W->>DB: Current authority/source checks + durable claim/audit
    W->>DB: Recheck under dispatch locks
    W->>G: One submission through existing guarded transport
    W->>DB: Accepted / failed / canceled / needs_review + audit; erase ciphertext
```

The worker captures both global and clinic authority barrier versions from migration 060. It cancels pending requests after a change in either scope, including a revoke followed by restoration. This deliberately also cancels requests after unrelated authority changes in that scope. It favors suppression over stale disclosure; a future narrower generation design needs its own review and concurrency evidence. It rechecks the original effective roles against persisted roles, exact identity mapping, active clinic/staff or explicit central grant, current pilot identity approval, source ownership and current destination. Ambiguous/missing identity mappings fail closed; matching email addresses never link identities. The worker does not query live directory-role or MFA policy; departure procedures must revoke the persisted identity/membership/grant as well as provider access. A browser logout alone does not undo an already committed email request.

Verification dispatch requires the same saved address/code hash, an unverified address, fewer than five failed attempts and an unexpired code. Invitation dispatch requires an active, unlinked client, an unconsumed/unrevoked unexpired invitation, matching token hash/address, current invitation authority, and no applicable staff-access or patient-communication restriction. Enquiry dispatch requires the original reference/revision and current enabled recipient/settings revision. Sender and Graph tenant/application routing must still match. Existing pilot recipient/sender guards remain mandatory and run before transport, including OAuth.

Migration 061 adds one private outbox table and extends the enquiry notification enum. A short-lived encrypted envelope holds the necessary destination, message and original identity binding; verification codes and invitation links are never stored in plaintext in the outbox. AES-256-GCM uses a private random 32-byte key, random IV/tag and authenticated immutable job metadata. It uses the already-required OpenSSL extension; there is no new dependency. Treat client invitation contact/clinic association as potentially PHI under repository controls. Terminal handling clears the active row’s ciphertext and preserves minimal operational/audit history; it does not erase backups, binlogs or provider Sent Items. It does not delete clinical records or replace an approved retention schedule. Expired envelopes left by a stopped/disabled worker remain encrypted until processing; monitoring and approved retention/backup handling are release prerequisites.

## Submission, uncertainty and concurrency

The worker commits `sending` and its required submission-intent audit **before** the provider call. It then takes a separate worker-owned transaction and rechecks source/authority while holding the clinic consent lock, shared authority barriers and source/outbox row locks across the single submission. Authority/restriction changes must serialize with that effect. This transaction is separate from the already committed originating request. It may hold locks for the transport timeout; this is an explicit performance tradeoff requiring hosted volume/lock-timeout acceptance.

| State | Meaning / recovery |
| --- | --- |
| `queued` | Committed request waiting for the worker; no delivery claim. |
| `sending` | Durable intent; provider result not yet durably recorded. Five-minute lease is recovery evidence, never permission to resend. |
| `accepted` | Graph returned 202. This does not establish mailbox delivery. |
| `failed` | Non-ambiguous, non-retryable transport rejection. No automatic retry. |
| `canceled` | Current authority/source/recipient/expiry/pilot gate no longer permits the message. No automatic retry. |
| `needs_review` | Envelope/configuration integrity problem, interrupted submission, timeout/unknown result or potentially retryable provider failure. No automatic retry. |

There is deliberately **no automatic retransmission**, including 429 and 5xx. This is conservative even where a pre-send failure could be proven safe. Unknown/timeout outcomes and abandoned leases never become queued again. A final result/audit/commit failure leaves the previously committed `sending` claim; lease recovery changes it to `needs_review` without another provider call. A failed enqueue/claim audit prevents sending. The source enquiry maps queued/accepted explicitly and suppresses duplicate retry buttons; legacy `sent` remains historical behavior. The new English/French UI wording distinguishes queued and accepted from delivery. [Microsoft's sendMail contract](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0) explicitly distinguishes 202 acceptance from completed processing/delivery.

Verification resends remain subject to the one-minute throttle and create a new code. Issuing another invitation revokes earlier invitations through the existing workflow. Enquiry `needs_review` requires operator reconciliation; a reconciliation UI is not part of this slice. Do not manually reset uncertain/accepted rows to queued or recreate an enquiry notification without checking the provider outcome. A future retry/reconciliation workflow needs explicit decisions and audit tests.

## Prepared operations; not enabled

Migration 061 requires the existing identity, consent, enquiry and migration 060 foundations. It is an apply-once script, tested on disposable MariaDB and MySQL only. It has not been applied to hosting. No runtime grants were broadened. Before rollout, operations must verify the runtime principal's minimum SELECT/row-lock access to current authority/source tables, outbox SELECT/INSERT/UPDATE, audit INSERT, enquiry notification UPDATE and existing named-lock support; migration credentials remain separate.

New private settings in `api/.env.example`:

```text
COMMIT_SAFE_EMAIL_ENABLED=false
COMMIT_SAFE_EMAIL_KEY=
```

An approved deployment would generate a fresh 64-hex-character cryptographically random key into private configuration outside public roots; never commit it, put it in frontend variables, or paste it into support logs. The feature also requires existing `MAIL_ENABLED`, sender credentials and pilot approvals. `MAIL_ENABLED` is shared: review existing queues and approved destinations before changing it; enabling this slice must not silently activate unrelated delivery or clinic intake. Retain keys under the approved backup/key process. Replacing a key without first draining/canceling old work makes those envelopes unreadable and terminal for review; there is no silent fallback or plaintext recovery.

The prepared command is `php api/bin/send-account-emails.php --limit=20` (valid limit 1–100), run from the repository/private API installation with its existing private environment loader. It refuses HTTP before autoload/private configuration, verified using an exact entry-file copy with a synthetic bootstrap tripwire. It prints counts only; failure output is an exception class, never a payload, URL or credential. **It has not been run against the hosted configuration and no scheduler was installed.** Before enabling, approve an approximately one-minute schedule, latency below code expiry, overlap/lock behavior, exit-status monitoring, oldest-queued-age alerts, review ownership and incident escalation. A successful request is not evidence that a worker is running.

Operational inspection should use minimal counts and age, for example:

```sql
SELECT kind,status,COUNT(*) AS items,MIN(created_at) AS oldest_created_at
FROM commit_safe_emails GROUP BY kind,status;
```

Rollback preparation: stop future worker launches, account for any in-flight provider effect, disable `COMMIT_SAFE_EMAIL_ENABLED`, and restore the previous reviewed API/frontend revision if needed. Keep the additive schema and queue/audit history; do not reverse migrations or delete rows as a routine rollback. Keep the required private key while encrypted pending rows remain. With server sessions active, disabling the queue restores the documented fail-closed email behavior; manual invitations remain available. An accepted external email cannot be recalled by database rollback. Hosted backup/restore and artifact rollback have not been rehearsed for this change.

No identity client, cookie/session architecture, API scope, grant, sender mailbox, provider endpoint, consent wording or third-party data field was added. Existing endpoints remain Microsoft OAuth `login.microsoftonline.com` and Graph `graph.microsoft.com`, using existing application `Mail.Send`; actual deployed least-privilege sender grants remain unverified. There were no payments, purchases, SMS messages or clinical-record effects. The existing English-only enquiry-notification body is retained; bilingual email-template review remains a product/release item.

## Validation and implementation record

The code/test/configuration revision is `5e279864ca3c211637f847495068c2e7783afbc2`, based on `1025b355593033feec83c92217f2d76962ec3e1a`. The 184-check dual-engine suite covers the unchanged core from `fecf1cf60e952ed8b3d0c9ab44b3e69669acd9b2`; the follow-up changes only the CLI HTTP guard and adds its four-check boundary test. No authentication implementation or dependency/lockfile version changed. Local checks do not establish hosted acceptance, legal applicability, live identity-provider/MFA policy, real Safari behavior, backup recovery or permission approval.

Commands ran from the repository root unless a directory is stated. PowerShell test processes used `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf`; this did not alter machine or hosted configuration. Runtime versions: PHP **8.4.14**, OpenSSL **3.0.16**, Node **22.22.1**, npm **11.19.0**, Composer **2.9.5**, MariaDB **11.4.8**, MySQL **5.7.44**, installed Edge **157.0.4341.30**, Playwright Firefox **155.0** (build 1543). Recording these versions is not a patch-currency acceptance.

| Command / scope | Result |
| --- | --- |
| `Frontend: npm run build` | Exit 0: ESLint (zero warnings permitted), content validation (4 entries), i18n validation (2,572 English/French keys), strict `tsc --noEmit`, public/portal/central production builds. |
| `Wellnexa: npm run lint; npm run build; npm run test:browser` (exit checked between commands) | All exit 0; 24 browser tests passed. Oxlint retains 4 existing warnings: 2 immutability and 2 component-export warnings. |
| `Frontend: npm test -- --workers=2 --reporter=line`, with process-scoped `PLAYWRIGHT_CHANNEL=chromium` and isolated matching browser cache | Exit **1**; **297 passed, 2 existing optional private-fixture skips, 1 guest-availability failure**, all 300 cases completed. All nine new/expanded email-state cases passed. Chrome for Testing **153.0.8010.12**, build **1243**, matches locked Playwright 1.63.0. Earlier Edge attempts are detailed below. |
| Same config, `--grep "guest availability keeps location hints" --repeat-each=3 --workers=1` | Exit 0; **3/3 passed** without source/assertion/timeout changes. The full-run failure remains recorded, not relabeled a clean aggregate run. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | Exit 0; 15 built-page tests passed. |
| `Frontend: npx playwright test --config playwright.application-admin.config.ts --reporter=line` | Exit 0; 46 central-admin tests passed, including private-state denial/focus/mobile cases. |
| `Frontend: npx playwright test --config playwright.invitations.config.ts --reporter=line` | Exit 0; **7 passed**, matching isolated Chromium; not included in default config. |
| `Frontend: npx playwright test --config playwright.applications.config.ts --reporter=line` | Exit 0; **22 passed**, matching isolated Chromium; not included in default config. |
| PHP syntax: `Get-ChildItem api/src,api/bin,api/tests -Recurse -File -Filter '*.php'` then `php -l` on each | Exit 0; **283 files**, zero failures, final revision rerun. |
| `php api/tests/account-email-cli.php` | Exit 0; **4** synthetic HTTP/CLI checks; HTTP cannot load the private bootstrap, while CLI can reach its synthetic bootstrap. |
| PHP policies: `Get-ChildItem api/tests -File -Filter '*.php'` then `php <file>` on each | Exit 0; **64 suites**, zero failures, final revision rerun. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory <owned mysql-5.7.44-winx64 runtime> -Port 13404 -IntegrationTest commit-safe-email.php` | Exit 0; **184 checks**, disposable synthetic database, fake Graph only. |
| MariaDB serial invocation of `php api/tests/integration/commit-safe-email.php` on an owned loopback server, explicit `RECURRING_TEST_ALLOW_CREATE=true` | Exit 0; **184 checks**, same source revision and synthetic/fake-transport boundary. See environment recovery below. |
| Same MariaDB serial server: `php api/tests/integration/server-session-integration.php` | Exit 0; **129** SQL/real-HTTP session checks. |
| Same MariaDB serial server: `php api/tests/integration/practitioner-enquiries.php` | Exit 0; **37** enquiry checks; its inherited suites also passed: recurring 34, client forms 111, form library 53. These are separate suite outputs, not an invented combined count. |
| Same MariaDB serial server: `php api/tests/integration/consent-practitioner-enquiries.php` | Exit 0; **171** consent/enquiry SQL/HTTP checks, plus inherited booking-access 74 and access-audit 28 outputs. |
| Same MariaDB serial server: `php api/tests/integration/consent-client-registration.php` | Exit 0; **85** client-registration SQL/HTTP checks; inherited booking-access 74, access-audit 28, consent 70, schedules 59, notifications 61, travel 105 and time-off 107 also passed. |
| MySQL `server-session-integration.php`, without browser option | Exit 0; **129** checks. The separate Edge/Firefox run and its initial failures are recorded below. |
| `api: composer validate --strict; composer install --no-interaction; composer audit --format=json` (exit checked between commands) | All exit 0; lock validated/restored, nothing updated; zero advisories/abandoned packages. |
| `npm ci --offline --no-audit --prefix <fresh system temporary Frontend directory>` using copied unchanged package/lock files | Exit 0; 350 packages restored. Separate audits below were performed; no audit finding was hidden by this restore's `--no-audit`. Esbuild 0.25.12 postinstall remained unapproved/blocked under the existing npm script policy; no override was applied. |
| `npm ci --prefix .tmp/commit-safe-email-validation/ci/Wellnexa` | Exit 0; 30 packages restored. Disposable install only; source dependencies unchanged. |
| `Frontend: npm audit --json; npm audit --omit=dev --json` | Full exit **1**, **11 moderate development-tree findings**; runtime exit 0, zero findings. These are not presented as a clean full audit. |
| `Wellnexa: npm audit --json; npm audit --omit=dev --json` | Both exit 0; zero findings. |
| `git diff --check`; scan actual committed source revision and generated frontend builds | No whitespace errors; inspection details below. |

The 184-check email suite covers committed visibility on an independent connection; hidden-root/ordinary transaction dispatch refusal; rollback of code/invitation/outbox state when required audits fail; actual code/token/hash matching; destination/sender/identity/pilot/member/grant/restriction changes; revoke-and-restore suppression; expired/stale/tampered/wrong-key requests; malformed configuration; preserved throttle; disabled mail; duplicate requests; fixed batch bounds; provider 400/429/500/unknown results; abandoned claims; accepted-but-final-audit failure; competing workers and a revocation waiting on dispatch locks. The real session-protected HTTP paths all queue successfully, all roll back their outbox when the final request audit fails, and retain CSRF enforcement.

Environment recovery is recorded rather than hidden. Two workspace frontend restores stalled and were stopped; the same lock restore then completed in a new system temporary directory. Parallel MariaDB initializations timed out, and later workspace-datadir reruns stalled; only task-owned test processes were stopped. A previously stopped **disposable synthetic** MariaDB datadir from this task was copied to a fresh system temporary directory, with a checked path, no reset/deletion of existing databases, a separately probed loopback port **13413**, `--no-defaults`, and `--bind-address=127.0.0.1`. The recovery command was `& .tmp/commit-safe-email-validation/retest-maria-temp.ps1`, using the owned copied temporary datadir through `COMMIT_SAFE_EMAIL_TEST_DATADIR`. The serial runner then created a new randomly named disposable database for each suite listed above, checked every PHP exit, and stopped its owned server in `finally`. It never loaded private hosted configuration or attached to existing hosted data. Earlier attempts and logs remain private under `.tmp/commit-safe-email-validation`; the temporary runtime/logs/keys/data are not committed. The maintained runners now allowlist the new suite; ordinary runner commands remain available for reproduction on a working disposable runtime.

The full frontend run initially reached case 281/300 with one client-login redirect failure and two teardown timeouts, then stalled before finishing. Its log was retained and only its task-owned process tree was stopped. A single-worker Edge rerun also hit context teardown timeouts and was stopped. The matched-Chromium full run above retains all original assertions; no test timeout, assertion, skip, lint or production control was relaxed. Its one guest-availability assertion could not find the expected location control within five seconds; all three focused repeats subsequently passed. A frontend owner must resolve the remaining flake rather than treating repeats as a clean full run. A separate Edge/Firefox session run initially passed 14 Edge cases while Firefox failed all 14 before assertions with `spawn UNKNOWN`. Restoring the **same** Firefox build into an isolated test directory resolved launch; executable SHA-256 was identical. That second run passed 27 cases and had one Firefox page-setup/context-teardown failure (`_maybeDontRestoreTabs` protocol error), rather than a product assertion failure. A further unchanged-matrix run completed **26 passed / 2 failed**: Edge French sign-out left Sign in disabled, and Firefox idle expiry timed out waiting for the Protected request button after advancing server time. The latter is consistent with a poll removing the button before the test dispatches its click, but remains a failed test; the sign-out readiness result requires focused diagnosis. No authentication implementation or session-test assertion was changed to hide either failure. These are open validation/release gates for the optional server-session feature, not a claim of complete browser acceptance.

Not rerun/established: every other disposable SQL suite in the repository; isolated historical session prototype/WebKit matrices; real Safari/BrowserStack (previously deferred by owner); live Google/Microsoft/MFA/Graph mailbox delivery or provider outages; representative hosted database volume; manual assistive-technology/contrast/theme/zoom audit; release-package deployment, grants, backup restoration or hosted smoke/rollback. Default browser tests retain their existing optional private-fixture skips; no new skip was introduced. No production test identity was reactivated.

### Dependency and artifact evidence

Lockfile SHA-256 values (unchanged):

| Lockfile | SHA-256 |
| --- | --- |
| `Frontend/package-lock.json` | `CB46757CDD6FD546AA12A1149DDD66BC7EBF576F595F8AF075F73B8B5BE7970C` |
| `Wellnexa/package-lock.json` | `17B1D75BCE7D40A857DFD16C319F32A116B905E799F339C4443446DA3A571F1E` |
| `api/composer.lock` | `16FAB4FC79B83AFD34D4200474204BFC55CD7FE40D650A0C39D848DC4F93265F` |

Existing locked frontend runtime versions: MSAL browser 5.19.0 / React 5.6.0; Emotion React 11.14.0 / styled 11.14.1; FullCalendar React 7.1.0; Google Maps loader 2.1.1; MUI icons/material 7.3.11; zip.js 2.24.0; date-fns 4.4.0; i18next 26.4.2; libphonenumber-js 1.13.14; lucide-react 0.468.0; React/React DOM 19.2.8; react-i18next 17.0.14; react-markdown 10.1.0; react-router-dom 7.18.2; remark-gfm 4.0.1; temporal-polyfill 1.0.5; yaml 2.9.1. Frontend build/test versions: Vite 6.4.5, TypeScript 5.7.3, Playwright 1.63.0, ESLint 10.13.0. Existing Wellnexa runtime React/React DOM 19.3.0; Vite 8.3.4, TypeScript 6.0.3, Playwright 1.64.1, oxlint 1.88.0. All other resolved development/transitive versions remain in the unchanged lockfiles above.

Existing Composer runtime versions: dompdf 3.1.6, php-font-lib 1.0.2, php-svg-lib 1.0.2, firebase/php-jwt 7.1.0, libphonenumber-for-php 9.0.40, giggsey/locale 2.9.0, masterminds/html5 2.11.0, nikic/fast-route 1.3.1, sabberworm/php-css-parser 9.5.0, symfony/polyfill-mbstring 1.43.0. AES-GCM uses the existing OpenSSL extension, not a new cryptography library.

The 11 moderate frontend findings are the existing minimatch glob-pattern amplification advisory and development-only ESLint/TypeScript-ESLint paths (direct affected packages include eslint, @eslint/js and typescript-eslint; minimatch is transitive). The runtime audit reports none. No forced fix, version override, major/toolchain upgrade or risk acceptance was applied. Build/security owner disposition, maintenance/licence review and exploit-exposure assessment remain release work; see [frontend quality gates](FRONTEND_QUALITY_GATES.md).

All **21** changed source/test/configuration files at the final implementation revision were scanned for private-key material, literal configured outbox keys and bearer literals: no matches. This verifies those patterns in this revision; it is not a historical secret audit. No private environment, dump, attachment, dependency, build output, runtime key or release ZIP was added to Git. The two unrelated user files `documentation/REQUIRED_FOR_SITE.md` and `documentation/blog-topics.pdf` remain untouched/untracked.

Generated frontend build inspection covered **93 Frontend files** and **10 Wellnexa files**: zero source maps, private env/SQL/PEM/ZIP files, private-key/outbox-key pattern matches or token-like matches. Four Frontend JavaScript files retain development-host fallback literals; Wellnexa has none. These are local builds, **not approved release artifacts**; release configuration/endpoints must be reviewed in an actual packaged build. Existing size warnings remain: public bootstrap approximately 1,061.63 kB, practitioner identity chunk 552.84 kB and central bootstrap 1,558.24 kB (minified, before gzip); central also retains the existing static/dynamic AuthProvider import warning. No warning threshold was suppressed. This task created no deployable ZIP, artifact manifest, hosted migration or release activation.

### Changed files and purpose

| File | Purpose |
| --- | --- |
| `api/database/migrations/061_commit_safe_email.sql` | Add encrypted outbox and queued/accepted enquiry states; no activation/grant. |
| `api/src/Service/CommitSafeEmail.php` | Atomic enqueue, encryption/AAD, exact authority/source rechecks, durable claim/audits, bounded one-attempt worker and uncertainty handling. |
| `api/src/Service/StaffNotificationPreferences.php` | Queue code/hash/throttle/audit in one transaction when opted in. |
| `api/src/Service/CustomerOnboarding.php` | Queue email invitation with its issue/revocation transaction; keep manual behavior. |
| `api/src/Service/PractitionerEnquiryService.php` | Authorize/queue retries transactionally and suppress duplicates. |
| `api/bin/send-account-emails.php` | Explicit bounded private CLI delivery entry point with redacted diagnostics. |
| `api/.env.example` | Default-off queue flag and blank private-key placeholder. |
| `api/tests/account-email-cli.php` | Exercise HTTP denial before private bootstrap and legitimate CLI bootstrap, using only a synthetic copy/tripwire. |
| `api/tests/integration/commit-safe-email.php` | 184-check disposable SQL/HTTP/provider-fake security/regression suite. |
| `api/tests/helpers/server-session-http.php` | Optional synthetic queue-mode fixture settings/key; default unchanged. |
| `api/tests/helpers/server-session-router.php` | Explicit test-only private queue configuration without real mail credentials/transport. |
| `scripts/test-recurring-sql.ps1` | Allowlist the new disposable integration suite. |
| `scripts/test-mysql57-sql.ps1` | Allowlist the same suite for the hosted-version-compatible engine. |
| `Frontend/src/profile/StaffNotificationSettings.tsx` | Truthful queued verification confirmation, retaining legacy response support. |
| `Frontend/src/clients/ClientInvitations.tsx` | Recognize queued invitation without exposing absent token/manual fallback. |
| `Frontend/src/booking/StaffAppointments.tsx` | Same queued behavior for newly created booking clients. |
| `Frontend/src/admin/PractitionerEnquiries.tsx` | Distinguish queued/accepted from delivered and keep duplicate sends unavailable. |
| `Frontend/src/i18n/accountEmail.ts` | New English/French delivery-state wording. |
| `Frontend/src/i18n/index.ts` | Register those translations. |
| `Frontend/scripts/validate-i18n.mjs` | Include the new module in the actual translation gate. |
| `Frontend/tests/portal.spec.ts` | Legacy/queue confirmation, absent-token prevention and bilingual enquiry-state regression. |
| `documentation/COMMIT_SAFE_EMAIL_2026-11-03.md` | Design, operations/rollback, validation, versions, all controls and release limitations. |
| `documentation/SESSION_SERVER_INTEGRATION_2026-11-03.md` | Link this local follow-up while preserving the original blocked-path finding. |
| `documentation/README.md` | Index the new report. |


Initial failures are retained as evidence: the new test fixture initially cleared its own synthetic destination allowlist, used unsupported central/member status names, and exceeded the existing audit correlation length; these fixture errors were corrected without relaxing application checks. The first new queued-booking UI test retained an unconditional legacy-acceptance assertion; it now asserts the correct queued/legacy behavior separately. An initial policy run omitted the local OpenSSL configuration and six RSA-key fixture suites failed; a process-scoped configuration rerun passed all 63. No production change or dependency/toolchain upgrade was used to fix these failures.

## Completion and outstanding work

The three requested email paths are implemented as a local, default-off encrypted outbox and an explicit private worker. Core commit `fecf1cf60e952ed8b3d0c9ab44b3e69669acd9b2` and CLI guard commit `5e279864ca3c211637f847495068c2e7783afbc2` are on `feature/commit-safe-email`; this documentation follow-up records the final evidence. The branch is for review, not merged/deployed. There are no dependency, permission, consent-wording or external provider-field changes.

The scoped email/security tests pass on both database engines and all new email UI cases pass. This is **not** a clean overall release checkpoint: the full browser run has a guest-availability flake (three focused repeats passed), the latest optional-session matrix has the two failures above, and the 11 moderate frontend build-tree advisories remain unaccepted. A frontend/session owner must resolve those outcomes without relaxing assertions or changing authentication architecture without its required review. Operations must verify host support and provision the dedicated account-email worker (existing notification triggers do not drain this table), key custody, runtime grants, monitoring, retention and rollback before activation. Custodian/privacy, security/operations, clinical and release-owner approval, hosted synthetic acceptance and the existing real-PHI gates remain outstanding. No production setting, provider, account or data was changed.

## All 38 controls: scoped evidence and remaining gates

Reviewer: Codex local implementation/testing, 3 November 2026; this is not human privacy, clinical, security or release approval. Each row applies to the implementation revision recorded below. “Implemented” is limited to the cited local scope. Organizational evidence is not inferred from absent repository evidence. Luis Duran owns release coordination; accountable custodian/privacy, security/operations and clinical owners/signatures still require explicit assignment/verification.

| Control | Status | Evidence / next action and owner |
| --- | --- | --- |
| GOV-01 | Unverified | No custody/clinic authority expansion. Custodian/privacy owner must approve actual entities, roles and HINP assessment before PHI release. |
| GOV-02 | Unverified | This is not a dated legal applicability/commencement assessment. Privacy/legal owner must refresh the register at release. |
| GOV-03 | Unverified | Existing identity/contact flows only; privacy/legal owner must decide applicable commercial/cross-border rules. |
| GOV-04 | Partial | Account invitation/verification and recruitment notification only; no new clinical inference. Clinical owner must confirm intended-use record. |
| GOV-05 | Partial | Flow, envelope contents and threats above; synthetic fixtures only. Privacy/security owners must approve PIA/TRA and residual risk. |
| PRIV-01 | Unverified | No notice/contact changes. Custodian must verify actual bilingual clinic notices and contacts. |
| PRIV-02 | Partial | Existing purposes/wording retained; no new joining acceptance or marketing authority. Privacy owner must approve message purpose and wording applicability. |
| PRIV-03 | Implemented | Scoped worker checks current original authority, source, recipient and invitation restrictions with locks; SQL negative/revocation/concurrency tests. Wider system remains separately gated. |
| PRIV-04 | Unverified | Invitation still requires independent identity review and grants no representative access. Custodian must approve supported/assisted representative process. |
| PRIV-05 | Unverified | No patient-rights process altered; custodian must evidence request/correction workflow and protected delivery. |
| PRIV-06 | Partial | Terminal ciphertext removal and source expiry checks tested; no clinical deletion. Custodian/operations must approve queue/audit/key/backup retention and holds. |
| PRIV-07 | Unverified | Safe worker failure/review states are not a breach rehearsal. Incident/privacy lead must evidence runbook and escalation. |
| AUTH-01 | Implemented | Existing JWT/session routes retained; synthetic signed HTTP coverage, exact source identity binding and unknown mapping denial. Live provider policy remains outside this scope. |
| AUTH-02 | Implemented | Original effective/persisted roles, exact clinic/member/admin/source checks, 060 versions, revocation/restoration and cross-clinic tests. No role granted by queue. |
| AUTH-03 | Partial | Optional session regression has open French sign-out readiness and idle-test failures, recorded above; no production/MFA policy changed. Security/operations must approve actual session/MFA/departure policy and hosted evidence. |
| AUTH-04 | Partial | No new browser credential storage; encrypted short-lived account secrets at rest. Existing legacy identity cache exception and approved session rollout remain unresolved release gates. |
| AUTH-05 | Partial | No dependency/grant/scope/sender expansion. Operations/security must verify actual Graph sender scope and minimum worker/migration database privileges. |
| SEC-01 | Partial | Private fixtures/logs/builds excluded; changed-source/build inspection recorded below. Operations must evidence deployed secrets/history/rotation review; no actual private env was read. |
| SEC-02 | Partial | Existing verified HTTPS transport; authenticated envelope encryption. Hosting/database TLS, at-rest/backups, key custody and restore remain operational gates. |
| SEC-03 | Partial | Existing session/CSRF/private-response checks retained in local HTTP/browser regressions. Per-host headers/cache/TLS require hosted artifact acceptance. |
| SEC-04 | Implemented | Fixed message kinds/queries, prepared values, authenticated envelope metadata, current ownership/state validation and safe errors; malformed/tampered/stale tests. |
| SEC-05 | Partial | Batch 1–100, original verification throttle, enquiry duplicate suppression, provider timeouts and no automatic resend. Hosted abuse/volume/backlog and lock-timeout acceptance still required. |
| SEC-06 | Implemented | Required enqueue, durable intent and result audits with actor/realm/clinic/resource/state/correlation. Failure tests prevent send or preserve uncertainty; operational log integrity/retention remains separately unverified. |
| SEC-07 | Implemented | Safe user messages and reason codes, class-only CLI failure diagnostics; no credentials/recipient/body in new audit or worker output. |
| SEC-08 | Partial | Existing OAuth/Graph endpoints and fields unchanged; private outbox added to flow inventory above. Privacy/security must verify current provider terms/retention/support access. |
| SEC-09 | Partial | Existing minimal texts, pilot checks, recipient restrictions and truthful acceptance states. Privacy owner must approve classification, operational reconciliation and bilingual template coverage. |
| DEV-01 | Implemented | Dedicated feature branch, meaningful commits, preserved unrelated files, source/tests/docs reviewed and branch pushed for review. No merge/deploy. |
| DEV-02 | Implemented | Focused implementation, current-authority decisions explicit; no new runtime package, suppression, weakened test or new explicit any. Local self-review is not independent security sign-off. |
| DEV-03 | Partial | Lockfiles unchanged; clean lock restores and audits below. Zero runtime advisories; 11 moderate frontend development-tree findings remain for build/security owner disposition. |
| DEV-04 | Partial | Exact local commands/counts above, including the full-run guest flake and two open session-matrix failures; hosted and omitted scenarios are explicit. No default test configuration is treated as coverage of every suite. |
| DEV-05 | Partial | New states translated and browser checked in English/French; existing controls/denial recovery retained. Full keyboard/focus/AT/contrast/zoom/theme and AODA applicability review still required. |
| DEV-06 | Partial | Bounded worker/source reads and indexes; no new frontend dependency or polling. Existing bundle warnings and provider-duration locks need performance/operations review. |
| REL-01 | Implemented | This dated 38-control evidence ledger, revision binding and explicit owner/next-action gaps; no compliance certification. |
| REL-02 | Partial | Local checkpoint and exact outcomes above; browser failures prevent a clean aggregate checkpoint; no hosted fixture, production account or real data used. |
| REL-03 | Unverified | Local build inspection only; no release ZIP, approved hosted artifact/migration, provider delivery, backup/rollback acceptance or actual grants verified. |
| REL-04 | Missing | Required organizational/hosted/operational approvals and build-advisory disposition remain absent. Release coordination must retain NOT READY. |
| REL-05 | Partial | Owner authorized local implementation/testing; no production operation performed. Separate deployment/schema/key/scheduler/grant decisions and accountable sign-offs remain required. |
| REL-06 | Implemented | Completion/file/version/validation record above; implementation commits and documentation follow-up on `feature/commit-safe-email`, with no merge/deploy. Human approvals and operational gaps are not implied by a successful build. |

RELEASE STATUS: NOT READY FOR RELEASE
