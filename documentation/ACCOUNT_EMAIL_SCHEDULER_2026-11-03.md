# Account email scheduler preparation — 3 November 2026

Branch `feature/account-email-worker-scheduling`, based on the pushed commit-safe-email revision `766fffcd05992ed4b3228dbb938f40954d9985c4`. Executable revision `12586a993a2ecec158578d9c40a493cec3f3b025`. This is local preparation only. Production files, Azure workflows, databases, secrets, mail, identities, permissions and worker schedules are unchanged. This branch is not merged into `main`.

## Why a separate schedule is needed

The existing Azure/Netfirms notification trigger runs every 15 minutes and drains `notification_events` only. Staff personal-email codes expire 15 minutes after request; routing the new outbox through that cadence could leave a code no usable lifetime. The prior [commit-safe email implementation](COMMIT_SAFE_EMAIL_2026-11-03.md) correctly left its worker unscheduled. The new pointer offers a dedicated one-minute HTTPS path for hosts without private PHP cron. It calls the existing `CommitSafeEmail` worker with `limit=1`; it does not create, send or retry any mail by itself. The private CLI entry remains a valid alternative if the host can run a true private PHP cron job. Choose **one** driver for this outbox after hosted feasibility review; do not run both.

The pointer is a new public endpoint at `/api/cron/send-account-emails.php`. It accepts POST only, requires a distinct random `ACCOUNT_EMAIL_TRIGGER_SECRET` of at least 32 characters in the private API environment, and returns 404 for missing/wrong keys. It then checks the existing `COMMIT_SAFE_EMAIL_ENABLED`, `COMMIT_SAFE_EMAIL_KEY` and `MAIL_ENABLED` gates. It uses a private flock file with a 60-second cooldown; overlap or cooldown returns 204 without running the worker. It runs at most one provider attempt per call. A successful pass returns 204 and logs only accepted/failed/canceled/review counts. A failure returns 503 and logs only the exception class. Neither response includes email addresses, bodies, account codes, invitation links or credentials. The existing worker still verifies current identity, clinic, resource, recipient, pilot and consent state immediately before submission, holds the relevant locks through the provider call, and never automatically retransmits an uncertain outcome.

The [Azure template](../hosting/azure/account-email-trigger.json) prepares a **separate** Consumption Logic App, initially `Disabled`, with a one-minute recurrence, POST to the fixed reviewed `https://livinlively.ca/api/cron/send-account-emails.php` path, a dedicated secure-string parameter, hidden action inputs/outputs and no HTTP retry. It does not modify the existing notification workflow. The URL is fixed so an accidental template parameter cannot redirect the scheduler key. The owner must verify this host/path and actual public file mapping before deployment. Deploying a template can incur Azure charges; no subscription cost or quota has been verified. A one-minute recurrence can produce up to 1,440 scheduled runs per day before retries/skips; budget approval and cost monitoring are required before enabling it.

The one-item batch gives a maximum nominal capacity of one email per minute. Backlog, an interrupted request, the lock cooldown, host timeouts or provider delay can still cause a 15-minute verification code to expire. There is no delivery guarantee. Operations must establish demand/latency measurements, an oldest-queued-age alert comfortably below code expiry, 503/404 and missing-run alerts, and an owner for `needs_review` before activation. A 204 alone does **not** prove a worker pass, because overlap/cooldown also returns 204. The new pointer does not update the old `notification_scheduler_state`; that health indicator describes a different worker. No operational monitor has been installed.

## Controlled activation and rollback (not executed)

1. Review the exact source SHA, migration 060/061 state, current hosted backup, private path and runtime grants. Confirm `livinlively.ca` maps the new public pointer to the same private `/wellness-api`. Keep both email gates off during file transfer. Never upload `.env`, `vendor`, private worker source or tests under `public_html`.
2. Generate a separate 32+ character random scheduler key and store it only in private `/wellness-api/.env` and Azure's secure parameter. Preserve the outbox encryption key separately. Do not put either in a URL, source, support log or a visible Azure parameter default. Approve current Graph sender scope and pilot destination list.
3. Copy only the reviewed public pointer to the reviewed host path and the matching private API revision/migrations through the normal release procedure. Deploy the Azure template to a **new** workflow name with `state=Disabled`; inspect the resulting URL, recurrence, action retry and secure run-history settings. Do not overwrite the existing notification workflow.
4. With the outbox disabled, verify GET→405, POST without/with wrong key→404, and valid POST→503 with no mail. With synthetic, approved recipient accounts and reviewed migration/backup, enable the queue and mail flag in a controlled window, enable the new Logic App, request one code, and verify committed queue→one provider acceptance→usable code before expiry. Verify a denied/stale item and interrupted/unknown outcome without resending. Inspect provider evidence and the outbox rather than treating 204 as delivery.
5. Monitor run history, 503s, oldest queued age, terminal counts, `needs_review`, Graph acceptance and Azure cost. Verify revocation, backup/restore, key custody, lock behavior, host timeout and rollback on the approved hosting environment. Document actual latency and operations contact before any non-test recipient is enabled.

Rollback: disable the **new** Logic App first and account for an in-flight provider effect; turn `COMMIT_SAFE_EMAIL_ENABLED=false` to refuse new queued sends, preserving the existing fail-closed server-session behavior. Preserve both private keys while encrypted pending records or rollback artifacts need them. Restore the previous reviewed application artifact if required. Do not delete the outbox or audit rows, reverse migration 061, or resend `sending`/`needs_review` rows by SQL. An accepted email cannot be recalled. The existing notification scheduler is independent and should not be disabled as a shortcut. Hosted rollback and restoration have not been rehearsed.

## Local validation and boundaries

| Check | Result |
| --- | --- |
| `php api/tests/account-email-trigger.php` | 8 synthetic checks passed: disabled template safety, GET refusal, missing/wrong key, disabled queue refusal, one bounded worker pass and cooldown. Uses an exact public pointer copy, fake autoload/services and a local PHP HTTP server; no real `.env`, database or Graph connection. |
| PHP policy loop: each `api/tests/*.php` with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | 65 files, 0 failures, including the new trigger and previous CLI boundary. |
| `php -l` for all PHP files in `api/src`, `api/bin`, `api/tests`, `api/deploy/netfirms/public/cron` | 286 files, 0 syntax failures. |
| `git diff --cached --check` before code commit; JSON parse/template contract in trigger test | Passed. No lockfile or dependency change. |

Local runtime for these checks: PHP 8.4.14, OpenSSL 3.0.16. The prior commit-safe outbox revision passed 184 disposable SQL/HTTP checks on each MariaDB 11.4.8 and MySQL 5.7.44; this change does not modify that worker or its schema. Frontend builds/browser suites were not rerun for this PHP-only endpoint/template change; the prior full browser aggregate and optional session matrix have recorded failures in the linked report. No real Azure template validation, hosted request, provider send, cost check, database migration, permission grant or manual accessibility test occurred. A local synthetic HTTP pass is not hosted acceptance.

Changed files: `api/deploy/netfirms/public/cron/send-account-emails.php` (guarded pointer), `hosting/azure/account-email-trigger.json` (disabled separate recurrence), `api/.env.example` (empty private key placeholder) and `api/tests/account-email-trigger.php` (isolated HTTP/template regression). This report plus the [email operations guide](EMAIL_DELIVERY.md), [documentation index](README.md) and historical outbox report are documentation-only updates. No package, runtime dependency, provider endpoint, consent wording, Graph permission or clinical data-flow field was added. The new flow is an authenticated Azure scheduler request to an existing Netfirms host; it carries only a scheduler key and empty JSON body, not PHI. The application still sends the already documented three account email kinds through Graph after authorization.

Git hygiene: `origin` was fetched/pruned before work. Local `main` equals `origin/main` but is behind this unmerged development chain. No old feature/bug branch is merged into `main`; deleting one as a completed merged branch would violate the repository workflow. `feature/blog-topic-project-answers` is active in another worktree. The two pre-existing untracked files `documentation/REQUIRED_FOR_SITE.md` and `documentation/blog-topics.pdf` were preserved and excluded from commits. This task uses its own branch and did not reset or remove other work.

## Scoped 38-control evidence ledger

Reviewer: Codex, 3 November 2026, executable SHA above. “Implemented” is limited to the local scope. Luis Duran coordinates release; accountable custodian/privacy, security/operations, clinical and release owners must be assigned and sign their decisions. Missing repository evidence is not evidence of organizational noncompliance.

| Control | State | Evidence / unresolved owner action |
| --- | --- | --- |
| GOV-01 | Unverified | No clinic authority change; custodian/privacy owner must approve custody and operator roles. |
| GOV-02 | Unverified | Legal applicability and commencement register requires privacy/legal review. |
| GOV-03 | Unverified | Commercial/cross-border data applicability requires privacy/legal review. |
| GOV-04 | Partial | Administrative email scheduling only; clinical owner confirms intended use. |
| GOV-05 | Partial | Scheduler flow/threats above; privacy/security owners approve PIA/TRA and residual risk. |
| PRIV-01 | Unverified | No notice/contact change; custodian verifies bilingual notices. |
| PRIV-02 | Partial | No message purpose/wording change; privacy owner reviews allowed recipient purposes. |
| PRIV-03 | Partial | Existing worker rechecks current scope under locks; hosted revocation acceptance remains. |
| PRIV-04 | Unverified | No representative authority change; custodian documents assisted process. |
| PRIV-05 | Unverified | Patient access/correction process outside this endpoint; custodian evidences it. |
| PRIV-06 | Partial | No outbox deletion; custodian approves queue/log/key/backup retention. |
| PRIV-07 | Unverified | 503/class logs are not a breach rehearsal; incident lead provides runbook. |
| AUTH-01 | Partial | Separate fixed-key scheduler guard tested locally; hosted TLS/key boundary unverified. |
| AUTH-02 | Partial | No new actor role; existing worker authorization unchanged; hosted negative tests required. |
| AUTH-03 | Unverified | Existing optional session/browser failures persist; security owner resolves. |
| AUTH-04 | Partial | No new browser token storage; private scheduler key and encrypted outbox require hosted custody review. |
| AUTH-05 | Partial | No grant expansion in source; operations verifies deployed least-privilege DB/Graph grants. |
| SEC-01 | Partial | Empty example/secure-string template; hosted secret storage, history and rotation unverified. |
| SEC-02 | Partial | Fixed HTTPS URL; hosted TLS, DB transport and backup encryption unverified. |
| SEC-03 | Partial | POST guard/no-store tested locally; hosted redirects, headers and caching unverified. |
| SEC-04 | Partial | Fixed destination and bounded worker; hostile host/environment test remains. |
| SEC-05 | Partial | Flock/cooldown/one item tested; hosted concurrency, backlog, quota and abuse capacity unverified. |
| SEC-06 | Partial | Existing required outbox audits untouched; separate scheduler run monitoring still needed. |
| SEC-07 | Partial | Class/count-only output; hosted proxy/Azure/server log redaction unverified. |
| SEC-08 | Partial | New Azure→Netfirms empty-body/key flow recorded; privacy/security approve actual endpoint, terms, geography and run-history retention. |
| SEC-09 | Partial | No new email body or resend path; provider acceptance and uncertain outcomes need operator review. |
| DEV-01 | Implemented | Dedicated branch, source/config/docs review and preserved other work; code committed. |
| DEV-02 | Implemented | Local scoped change; no lint/type suppression or weakened controls. |
| DEV-03 | Partial | No dependency/toolchain change; prior frontend dev-tree advisories remain open. |
| DEV-04 | Partial | 65 PHP policies/286 syntax and HTTP tests; browser/hosted checks omitted/open as stated. |
| DEV-05 | Not applicable | No user-facing UI change; existing accessibility release gates remain. |
| DEV-06 | Partial | One attempted item/call; representative backlog/host timeout/cost measurement needed. |
| REL-01 | Implemented | This dated ledger, source revision and unresolved owners. |
| REL-02 | Partial | Local backend checkpoint above; broad browser aggregate remains non-green. |
| REL-03 | Unverified | No new release artifact or hosted synthetic acceptance, backup, actual grant, monitoring or rollback evidence. |
| REL-04 | Missing | Hosted, organizational, browser and advisory gates unresolved; release remains not ready. |
| REL-05 | Partial | Local preparation authorized; production deployment/schema/key/workflow activation require separate reviewed authorization. |
| REL-06 | Implemented | Files, versions, changes, tests, omissions, operations and rollback recorded here. |

RELEASE STATUS: NOT READY FOR RELEASE
