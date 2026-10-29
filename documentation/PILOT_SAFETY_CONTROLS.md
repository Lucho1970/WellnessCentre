# Pilot access and delivery safeguards

29 October 2026. Implementation starts from `9851733` (the frontend quality-gate work) on `feature/pilot-access-delivery-guards`. This adds opt-in pilot restrictions using the existing database. It does not create/reset a database, migrate records, create tester accounts, grant roles, enable providers or change private hosted configuration.

## Behavior and boundaries

With `PILOT_MODE=true`, protected API access requires an explicitly approved verified identity. The check runs after normal JWT signature, issuer, audience, time and scope validation for workforce staff, central administrators, external practitioners and clients. Pilot approval never supplies clinic membership, a client link, practitioner relationships, role/permission grants or consent authority. Existing restrictions still apply. Previously issued valid tokens stop passing the pilot gate when their identity is removed from the effective configuration; reload/restart processes as described below.

Approval matches the exact adapter, issuer and stable subject. It does not match names or email addresses. For workforce sign-in use the verified `oid` (legacy `sub` fallback); for external staff use the API's verified `sub`; for customers use verified `oid` when present (legacy `sub` fallback). Customer `oid` avoids requiring different approvals for the API and SPA's pairwise subjects. This does not change customer identity linking or fresh-proof/session validation. See [Microsoft's claim reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference) for the distinction between application-specific `sub` and object identity `oid`.

All Graph email and VoIP.ms SMS transport calls independently check pilot destinations before any provider request, including OAuth token retrieval. This covers invitations, notification-email verification, immediate/queued appointment notices, operational notices, shared/cloned senders, the cron bridge and SMS connectivity diagnostics that use these clients. Allowlisting a destination does not enable a disabled channel or bypass spending/consent/STOP rules. Messages are blocked, never redirected to a catch-all mailbox.

Email approval includes the recipient, sender mailbox (the default reply destination), explicit Reply-To and every supported calendar organizer/attendee address. Folded calendar lines are unfolded before checking; unsupported/delegated address forms fail closed. Email domains are compared without case; local parts remain exact. Aliases, plus-address variants, display-name syntax and wildcards do not automatically match. SMS entries require exact canonical Canadian E.164 numbers, such as synthetic `+14165550101`.

The appointment worker checks the recipient before SMS spending authorization. Known pilot blocks become terminal `canceled` events with a generic reason, no provider acceptance, no automatic retry and no new SMS ledger reservation/attempt. Operational notices also become terminal canceled rows with a redacted pilot reason. Turning pilot mode off does not replay those canceled messages. Unknown pre-existing provider outcomes retain the existing review/reconciliation rules; do not release commitments or resend simply because pilot mode was enabled.

Malformed pilot configuration fails closed. Workers validate it before claiming work; empty lists in enabled mode deny all protected identities and email/SMS delivery. `PILOT_MODE=false` preserves normal operation, but malformed supplied lists still require correction. There is no automatic operator/admin bypass.

Anonymous practitioner enquiry submissions are paused in pilot mode. Published public pages, catalogues, availability and public discovery remain public; this is not a whole-site password gate. Public maps/address requests and identity-provider sign-up/recovery emails are outside the app's email/SMS guard. Use synthetic addresses and configure the hosting/identity provider separately if a private website or restricted provider registration is needed. Manual phone calls, mailto links, exported files and communications outside these transports are also outside this guard.

**Keeping one clinic in the current database does not make its records test data.** Pilot mode does not anonymize, partition or hide already stored records from an otherwise authorized staff account. Do not give ordinary testers administrator access, link a tester to an actual patient's record, or assume a shared email establishes identity. Confirm synthetic records and appropriate roles before access. Already rendered/downloaded data cannot be remotely erased by removing a pilot approval; verify UI denial/recovery, logout and shared-device cleanup. The [privacy audit](COMPLIANCE_AUDIT_2026-10-29.md) and remaining real-PHI gates still apply.

## Private configuration

Defaults in `api/.env.example` are deliberately inactive and empty:

```dotenv
PILOT_MODE=false
PILOT_ALLOWED_IDENTITIES=[]
PILOT_ALLOWED_EMAILS=[]
PILOT_ALLOWED_SMS=[]
```

Each list is one-line JSON with at most 100 entries and a 64 KiB input limit. An identity has exactly `adapter`, `issuer`, `subject`. Supported adapters: `entra-workforce`, `entra-external-staff`, `entra-customer`. Issuers must be exact HTTPS issuer strings without credentials/query/fragment; subjects are exact opaque identifiers, not email addresses. Obtain them through an approved identity-administration/verified-identity process. Do not paste bearer tokens or real tester lists into source control, tickets or examples.

For illustration only; replace the placeholders in the private environment before enabling:

```dotenv
PILOT_ALLOWED_IDENTITIES=[{"adapter":"entra-workforce","issuer":"https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0","subject":"replace-with-verified-operator-object-id"}]
PILOT_ALLOWED_EMAILS=["pilot-sender@example.test","pilot-reply@example.test","tester@example.test"]
PILOT_ALLOWED_SMS=[]
```

Prepare the real operator approval first, then add each approved staff/customer realm separately. Keep the lists private. Do not introduce production personal data into a pilot configuration merely to test the guard.

## Activation on the existing clinic deployment

1. Name the operator and testers, scope their existing accounts/roles to synthetic records, and review the hosted database contents and known privacy gaps. Record the source revision and approved destinations. Do not infer approval for real PHI.
2. Back up the existing database and affected application files. Pause notification triggers, cron bridges and long-running workers; retain private configuration and existing migration history. No new SQL migration is required by these guards. Other previously undeployed features still need their own migration/release acceptance.
3. Deploy matching private API/auth/transport/worker files and frontend translations. Keep public PHP pointers unchanged. The shared API affects every mapped clinic. Set `MAIL_ENABLED=false` and `SMS_ENABLED=false` during coordinated activation; do not leave an old process or bridge running unguarded.
4. Configure real operator/tester identity approvals in the private environment and set `PILOT_MODE=true`. Start with empty delivery lists. Drain/restart all API/worker/bridge processes that may retain earlier environment settings, and reconcile conflicts with process-level environment values (those override file values). This implementation does not hot-reload files in a running worker.
5. Run the private CLI preflight on the intended host, if CLI is available:

   ```text
   php /wellness-api/bin/pilot-preflight.php --env /wellness-api/.env
   ```

   It prints only enabled state and counts, never identities/destinations, and makes no DB/provider connection. Exit `0`: valid enabled configuration with at least one identity; `2`: disabled mode or no approved identity; `1`: invalid input/configuration. Exit zero is not hosted identity, delivery, privacy or release acceptance. If CLI is unavailable, arrange an approved private operator check; do not expose the CLI or `.env` through the web root.
6. Verify approved staff/client sign-in and their existing role boundaries; verify an unapproved signed-in test account receives `403 pilot_access_denied` without records. Verify approval alone cannot create administrator authority and removal denies the next protected request after configuration reload. Public enquiry POSTs must be refused; ordinary published browsing remains available.
7. Review queued work before resuming workers. Use only approved test mailboxes/numbers and synthetic messages; approve actual clinic senders, default/explicit reply destinations and calendar participants. Enable channels individually only when their normal sender/consent/budget/STOP checks are ready. Confirm one allowed delivery through provider evidence, and one unapproved synthetic destination canceled without provider submission or spending. Do not deliberately send to a real unapproved person to test the restriction.
8. Record hosted results, runtime/database versions, disabled features, test instructions, issue contact, rollback and responsible sign-off before inviting testers. Display appropriate pilot/synthetic-data instructions using the existing clinic content/settings. No hosted steps or provider sends were executed by this implementation task.

For rollback, pause all sending before changing code/configuration. Preserve canceled rows, audit and unknown provider outcomes. Restore matching API/frontend artifacts and private settings under the approved rollback plan. Disabling pilot mode removes the additional identity/destination restrictions; it is not a safe workaround for a misconfigured list. Restore normal delivery only through a deliberate approved change, with queue review and process restart. No record deletion or schema downgrade is needed for these controls.

## Files and implementation

| File | Purpose |
| --- | --- |
| `api/src/PilotPolicy.php` | Strict private configuration, immutable identity matching, destination/calendar checks and redacted summary. |
| `api/src/PilotDeliveryBlocked.php` | Distinct known-no-send result with no identifying values. |
| `api/src/Config.php` | Reject malformed pilot configuration at normal application configuration load. |
| `api/src/Auth/EntraAuthenticator.php` | Apply pilot check to verified workforce identities, including central-admin authentication. |
| `api/src/Auth/ExternalStaffAuthenticator.php` | Check verified external practitioner identity. |
| `api/src/Auth/CustomerAuthenticator.php` | Check verified customer object identity on API and fresh ID-proof paths. |
| `api/src/Api.php` | Pause anonymous practitioner enquiry writes during pilot. |
| `api/src/Service/GraphMailClient.php` | Enforce guard before OAuth/send for every mail client/sender clone. |
| `api/src/Service/VoipMsSmsClient.php` | Enforce guard before SMS provider calls for every sender clone. |
| `api/src/Service/NotificationWorker.php` | Validate configuration before work; block before SMS spending; cancel known pilot blocks without retry. |
| `api/src/Service/CommunicationNoticeWorker.php` | Validate before work and persist redacted terminal pilot cancellations. |
| `api/bin/pilot-preflight.php` | Private CLI-only configuration check with redacted counts and no connections. |
| `api/.env.example` | Document inactive/empty safe defaults; contains no actual tester list. |
| `api/tests/pilot-policy.php` | Policy, transport and real signed-JWT regression; all network calls are fake. |
| `api/tests/pilot-preflight.php` | CLI exit/status/redaction checks using a temporary synthetic environment file. |
| `api/tests/integration/pilot-safety.php` | Disposable SQL/worker/real HTTP tests alongside existing communications/grant regressions. |
| `scripts/test-recurring-sql.ps1` | Add pilot suite selection to the disposable MariaDB runner. |
| `scripts/test-mysql57-sql.ps1` | Add pilot suite selection to the disposable MySQL 5.7 runner. |
| `Frontend/src/i18n/en.ts` | English pilot denial message. |
| `Frontend/src/i18n/fr.ts` | French pilot denial message. |
| `Frontend/tests/customer.spec.ts` | Both languages show denial without fetching protected client records. |
| `Frontend/tests/practitioner-applications.spec.ts` | Wait for asynchronous workforce startup before checking that application intent was cleared. |
| `api/README.md` | Link operational configuration and test guidance. |
| `documentation/README.md` | Index the pilot controls. |
| `AGENTS.md` | Direct contributors to the controls and their activation limits. |
| `documentation/PILOT_SAFETY_CONTROLS.md` | Scope, configuration, deployment/rollback and validation evidence. |

## Validation and remaining evidence

Local environment: Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14, MariaDB 11.4.8, MySQL 5.7.44 and Edge. All SQL tests use disposable localhost databases with synthetic identities/data and fake provider callbacks. No private `.env`, hosted records, real SMS/email, DNS, identity-provider registrations or production deployment was touched.

| Command / check | Result |
| --- | --- |
| `php api/tests/pilot-policy.php` with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | 80 policy/configuration/transports/signed-identity checks passed. No live provider calls. |
| `php api/tests/pilot-preflight.php` | 10 CLI status/redaction checks passed using temporary synthetic files; no database/provider connection. |
| Each root `api/tests/*.php` with the same OpenSSL configuration | 58 scripts passed, including identity/session, notification, privacy, SMS and new pilot checks. |
| `php -l` on recursive PHP files in api/src, api/public, api/bin, api/tests and Wellnexa/public/api | 251 files passed. |
| `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13350 -IntegrationTest pilot-safety.php` | Final run passed 36 ledger + 69 application + 58 communications + 21 pilot SQL/worker/HTTP checks on MariaDB 11.4.8. Disposable server stopped. |
| `./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/Users/duran/AppData/Local/Temp/wellness-mysql57-c02a74656b4f4ed29aa543c2dfc8fa3e/mysql-5.7.44-winx64 -Port 13351 -IntegrationTest pilot-safety.php` | Final run passed the same 36 + 69 + 58 + 21 checks on MySQL 5.7.44. Disposable server stopped. |
| PowerShell parser on both modified SQL runners | Passed. |
| Frontend: `npm run build` | Lint, strict application TypeScript, four bilingual content entries, 2,521 UI translations and all production surfaces passed. Existing large-chunk warnings remain. |
| Wellnexa: `npm run build` | Passed. |
| Frontend: `npx playwright test tests/customer.spec.ts --grep 'pilot denial' --reporter=line` | Both English/French denial cases passed without protected client reads. |
| Frontend: `npm test -- --reporter=line` | 285 passed, two existing optional private CSV/workbook acceptance cases skipped; no failures. |
| Frontend: `npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 36 passed. |
| Frontend: `npx playwright test --config playwright.build.config.ts --reporter=line` | 14 passed against local production output. |
| Wellnexa: `npm run test:browser -- --reporter=line` | 24 passed. |
| Frontend: `npx playwright test --config playwright.invitations.config.ts --reporter=line` | 7 passed. |
| Frontend: `npx playwright test --config playwright.applications.config.ts --reporter=line` | Final run: 9 passed. |
| Wellnexa: `npx playwright test --config playwright.applications.config.ts --reporter=line` | 1 passed. |
| Frontend: `npm run lint` after final test edits | Passed with zero warnings. |
| Changed-file and local production-output credential signature scan | No private-key, GitHub-token, AWS-access-key or Azure account-key signature matches. No source maps, environment files, SQL dumps or ZIPs found in either local output; the website's expected public PHP bridges remain present. This limited scan is not final packaged-artifact acceptance. |

An initial SQL acceptance run blocked the allegedly approved email because its fixture listed a different sender than the actual clinic configuration. The test list was corrected to approve the configured sender; the guard was not weakened. Final reruns on both database engines also verify the redacted operational cancellation reason.

The first dedicated practitioner-application browser run passed eight cases and failed one immediate session-storage assertion during asynchronous page startup. The test now uses Playwright polling for the same required cleared values, without changing application behavior or adding an arbitrary delay. All nine cases then passed. Across the final browser suites, 376 unique cases passed and two existing optional acceptance cases were skipped; the separately targeted two denial cases are included in that total.

Dependency restore/audit was not repeated because dependency manifests/locks are unchanged from the [validated quality-gate baseline](FRONTEND_QUALITY_GATES.md). No hosted database, real identity configuration, production provider, final release ZIP, full accessibility/load/penetration or real-PHI acceptance was performed.

No dependency changes. No API permission or role-grant expansion. Pilot mode adds restrictive access/delivery policy only; identity protocols, normal membership/consent checks and database schema remain unchanged. No new external endpoints. Local configuration, SQL and browser results do not close hosted acceptance, session-storage, infrastructure, vendor, privacy/retention, recovery or real-PHI approval gates.

RELEASE STATUS: NOT READY FOR RELEASE
