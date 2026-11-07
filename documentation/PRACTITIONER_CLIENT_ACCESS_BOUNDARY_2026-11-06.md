# Practitioner client-access boundary follow-up

**Date/revision:** 6 November 2026; `bugs/practitioner-client-access-boundary` based on unmerged record-governance commit `90ab17f`. Production and `main` are unchanged. This follow-up depends on migration 063 and the care-assignment UI in that earlier branch. Do not deploy it alone. Review existing clinic relationships with the responsible clinician before entering assignments; historical appointments, client creation and form tasks are not automatically backfilled.

## Change and behavior

The earlier record-governance branch made new clinic notes depend on an explicit active care-team assignment. This branch applies that same boundary to four older practitioner paths:

- `ClientService::practitionerList`: returns contact details only for active assignments, active practitioner profiles and active local staff accounts. It still shows appointment counts for clients the practitioner may currently access.
- `BookingClientAccess`: saved booking contacts, addresses, duplicate candidates and direct/recurring booking client IDs require a current assignment. A scheduling delegation cannot expose an unassigned patient's contact details. Clinic administrators and reception retain their separately authorized clinic scope.
- `ClientFormsService`: practitioners can read, assign and review a client's forms only while they have an active care assignment. A form task alone cannot create that authority. Clinic administrators and the client retain their own roles.
- `CustomerOnboarding`: a practitioner with `add_clients` may inspect or issue a client invitation only with a current care assignment and applicable patient restriction check. Creation history alone no longer grants invitation authority.

`ClientOverviewService` now reports care assignment separately from historical appointment/client-creation facts. The English/French Admin text explains that history is for review, not a current access grant. The underlying `schedule_for_other_practitioners` permission still affects appointment scheduling and visibility; a separate endpoint-by-endpoint review of that permission and other historical access paths is required. Practitioner-created new clients now require clinic assignment before the practitioner can view contacts, invite or book them. The clinic must provide that operational step before enabling this branch.

## Files and verification

| File | Purpose |
| --- | --- |
| `api/src/Service/BookingClientAccess.php` | Replace historical/creator/form/delegated contact grants with active care assignment. |
| `api/src/Service/ClientService.php` | Scope practitioner directory to active assignments. |
| `api/src/Service/ClientFormsService.php` | Recheck assignment on form client operations. |
| `api/src/Service/CustomerOnboarding.php` | Restrict practitioner invitation and review to active assignment and patient restriction. |
| `api/src/Service/ClientOverviewService.php` | Report current care assignment as the access basis. |
| `Frontend/src/clients/ClientOverview.tsx` | Show current assignment separately from history. |
| `Frontend/src/portal/StaffPortal.tsx` | Describe practitioner client list as current care assignments. |
| `Frontend/src/booking/StaffAppointments.tsx` | Keep a newly created practitioner client unselected until clinic care assignment; prevent an invitation action that would be denied. |
| `Frontend/src/i18n/clinicRecords.ts` | English/French explanation. |
| `api/tests/client-overview.php` | Policy and SQL projection checks. |
| `api/tests/integration/booking-client-access.php` | Synthetic SQL checks for historical, delegated, revoked and invitation access. |
| `api/tests/integration/client-forms.php` | Synthetic form and merge regressions under care assignments. |
| `api/tests/integration/consent-directives.php` | Keep an assigned unrestricted-client control case and assert an unassigned client remains hidden. |
| `api/tests/integration/consent-client-registration.php` | Rehearse new-client creation, explicit care assignment and hidden duplicate behavior under delegated scheduling. |
| `api/tests/integration/consent-address-coverage.php` | Require an explicit care assignment for the positive address-coverage fixture and deny all coverage actions after revocation. |
| `Frontend/tests/client-overview.spec.ts` | Admin report and denial presentation. |
| `Frontend/tests/portal.spec.ts` | Practitioner new-client pending-assignment workflow. |
| `documentation/README.md` | Index this follow-up. |

No schema, dependency, identity-provider grant, new endpoint, consent wording, mail/SMS flow, external provider or runtime configuration changes are in this branch. The only data-flow change is less practitioner access to existing clinic PHI. A rollback of the code would re-enable the older broad access rules, so rollback is a privacy decision, not an automatic safe fallback. Existing assignment records remain intact. No release artifact or hosted migration was made.

## Release gaps

This is a local implementation slice only. Review and test the remaining practitioner appointment, scheduling, address-coverage, export, worker, client-invitation and form paths for stale relationships and concurrent revocation; verify deployed session/membership behavior and clinic isolation on an approved synthetic host. Assignments must be reviewed clinically and maintained on transfer/departure. No existing data should be auto-assigned from past activity. Patient rights, retention, formal departure, final agreements and real-PHI owner decisions in the parent [record-governance decision](WELLNEXA_RECORD_GOVERNANCE_2026-11-06.md) remain open.

## Change-scoped 38-control ledger

**Reviewer:** Codex, 6 November 2026. These statuses cover this branch only. The custodian, clinical, privacy/legal, security/operations and release owner must date their decisions before real-data release. Missing evidence is not proof that organizational controls do not exist.

| Control | Status | Evidence and next action |
| --- | --- | --- |
| GOV-01 | Partial | Clinic-assignment model inherited; custodian/legal owner confirms practice and tenant authority. |
| GOV-02 | Unverified | Privacy/legal owner updates current law/profession register at release. |
| GOV-03 | Unverified | Privacy owner confirms commercial/cross-border applicability. |
| GOV-04 | Partial | Administrative and record access only; clinical owner confirms intended use. |
| GOV-05 | Partial | Access flows narrowed; privacy/security owner updates inventory, PIA and threat assessment. |
| PRIV-01 | Unverified | No notice change; custodian reviews published custody text against workflow. |
| PRIV-02 | Partial | Existing restrictions retained; privacy owner approves assignment authority. |
| PRIV-03 | Partial | Four legacy practitioner paths narrowed; remaining read/write/worker routes and races require review. |
| PRIV-04 | Unverified | Representative/minor process remains outside this branch. |
| PRIV-05 | Missing | Patient access/correction process remains to implement. |
| PRIV-06 | Missing | Retention/hold/disposal remains to implement. |
| PRIV-07 | Unverified | Breach runbook not retested. |
| AUTH-01 | Partial | Existing identity context used; hosted realm/token verification outstanding. |
| AUTH-02 | Partial | Current assignment/account/restriction checked on changed paths; other roles/routes outstanding. |
| AUTH-03 | Partial | Revocation tested locally; hosted session/in-flight behavior outstanding. |
| AUTH-04 | Unverified | No browser credential storage change. |
| AUTH-05 | Unverified | No new grant; deployed API/DB permissions require inventory. |
| SEC-01 | Partial | Changed-file pattern scan found no private key or credential assignment; historical and final artifact scan remain. |
| SEC-02 | Unverified | No transport/storage change; operations verifies deployment. |
| SEC-03 | Unverified | No header change; hosted header/cache checks remain. |
| SEC-04 | Partial | Existing prepared SQL and scoped IDs retained; security reviews all affected queries. |
| SEC-05 | Partial | Existing search/page caps retained; concurrency/abuse tests remain. |
| SEC-06 | Partial | Existing sensitive-read audit retained; audit failure and operator logging need review. |
| SEC-07 | Partial | Denials use existing safe API codes; hosted diagnostics need check. |
| SEC-08 | Not applicable | No new external endpoint or subprocessor in this branch. |
| SEC-09 | Not applicable | No outbound message content or transport change. |
| DEV-01 | Partial | Dedicated bug branch based on unmerged parent; merge/release decision remains separate. |
| DEV-02 | Partial | Focused SQL authorization edits; final review pending. |
| DEV-03 | Unverified | No dependency/lock change; release audit still applies. |
| DEV-04 | Partial | Local build, unit, browser and SQL evidence recorded below; hosted tests outstanding. |
| DEV-05 | Partial | Bilingual Admin text; manual keyboard/AT/zoom review outstanding. |
| DEV-06 | Unverified | Indexed assignment lookup; representative volume and bundle review outstanding. |
| REL-01 | Partial | This dated ledger; named owner decisions and hosted evidence outstanding. The exact source SHA is recorded by the branch commit. |
| REL-02 | Partial | Local changed-surface checkpoint; exact results and omissions below. |
| REL-03 | Missing | No artifact or hosted synthetic acceptance. |
| REL-04 | Missing | Parent and remaining access/operational blockers prevent release. |
| REL-05 | Partial | No deployment/migration/permission broadening; separate approval required later. |
| REL-06 | Partial | Files, flows, risks, rollback and local evidence stated; operational decisions remain open. |

### Local evidence

Completed locally on 6 November 2026: `npm run build` passed ESLint, content/i18n validation (2,661 EN/FR strings after the final wording change), strict TypeScript and all three production builds; Vite reported existing large-chunk and mixed-import warnings. `npx playwright test tests/client-overview.spec.ts tests/client-forms.spec.ts --workers=1 --reporter=line` passed 46/46 source-browser cases. `npx playwright test --config playwright.build.config.ts --workers=1 --reporter=line` passed 16/16 built-artifact cases after the final UI change. A focused practitioner list/new-client flow passed 2/2 after the final UI change. `php api/tests/client-overview.php` passed 64 policy/query checks; `php api/tests/clients.php` passed 44; `php api/tests/customer-onboarding.php` passed 25. Applicable `php -l` and `git diff --check` passed. Disposable SQL suites on MariaDB 11.4.8 passed 112 client-form, 83 booking-contact and 108 clinic-care checks. With process-scoped OpenSSL configuration, `./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -IntegrationTest consent-client-registration.php -Port 13370` passed its nested authorization suites and 89 registration checks; the corresponding `consent-address-coverage.php` run on port 13372 passed the same nested suites plus 239 coverage checks, including active-assignment revocation. Earlier runs stopped because local OpenSSL could not generate a synthetic RSA key and because historical fixtures expected an unassigned client to be visible; the fixtures were corrected to distinguish assigned and unassigned clients. The 207-case portal suite was intentionally stopped after 20 unrelated cases to run the two affected flows directly; this was an optional test-scope correction, not a product failure. Exact-lock clean install, full default browser suite, MySQL 5.7, hosted identities/database, manual accessibility and representative-volume tests were not run in this branch. Synthetic SQL uses disposable MariaDB databases only; no hosted patient data is read or changed.

RELEASE STATUS: NOT READY FOR RELEASE
