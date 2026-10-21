# Repository integration and branch cleanup — 21 October 2026

## Scope and history

The repository owner authorized merging ready work into `main` and deleting old branches. The starting `main` revision was `e04b068f123c749cd8534e51ccddda80722fdb2a`. The accumulated candidate was `179b60945c70ac115c94ae831b64378da20660a0`, containing 51 commits beyond that base. Every inventoried topic branch was already an ancestor of that candidate or the starting main; there was no divergent topic work.

The integrated work includes the Wellnexa theme and website discovery/enquiries, shared portal routing fixes, receipts and on-site fees, form libraries, and the documented PHIPA application safeguards through clinic branding authorization. Existing feature and deployment documents retain the detailed behavior, migration prerequisites and acceptance limits.

Integration branch: `feature/repository-cleanup-2026-10-21`. Integration corrections:

- Add the existing practitioner `travel` page to the role-policy expected list. The runtime policy already included it.
- Create empty `clinic_hosts` and `application_administrators` storage before later schema statements reference them. The table-creation portions of migrations 039/040 tolerate those bootstrap tables; the other steps of migration 039 remain apply-once. Bootstrap does not grant central access.
- Update private-address, clinic-time-off and enquiry fixtures for current patient relationships, practitioner assignments, explicit patient IDs, consent storage and the canonical host table. New address assertions verify denial before provider disclosure when a required assignment or relationship is absent.

No application permissions were broadened.

## Validation

- Frontend production build: passed, including public, portal and application-admin bundles, four bilingual content entries and 2,115 translation validations. Existing bundle-size warnings remain.
- Wellnexa production build: passed.
- Frontend default browser run: 265 passed and one stale role-policy assertion failed out of 266. That expectation was corrected and the affected test passed on rerun; the entire default suite was not rerun after this test-only correction.
- Additional Frontend browser checks: staff invitations 7 passed; application administration 8 passed; built artifacts 14 passed.
- Wellnexa browser regression: 24 passed.
- PHP syntax: 98 source/public files passed.
- Standalone PHP scripts: 54 passed (53 API and one Wellnexa).
- SQL/HTTP integration: all 29 selected suites passed in the final serial checkpoint, including inherited booking, audit, consent, recurring-booking and form checks. The affected central-administration and clinic-isolation paths passed after the bootstrap fixes.

Database tests use disposable synthetic schemas on local MariaDB 11.4.8. The initial parallel attempt on the mechanical repository drive hit an HTTP timeout during inherited service-suite setup. Temporary local storage tuning did not resolve the slow fixture creation; unfinished suites were stopped. Validation moved to a fresh disposable database on the local NVMe SSD with default storage settings. The service suite passed there without an application change. The final serial checkpoint covers the schema and fixture corrections on default storage settings. These checks do not establish hosted database, identity-provider, messaging-delivery, backup/recovery or real-patient-data acceptance. This integration does not deploy an application or authorize real patient data; the PHIPA readiness gates still apply.

### SQL checkpoint coverage

All files below are in `api/tests/integration/`; each completed successfully in the final local checkpoint.

```text
consent-availability.php
privacy-audit-review.php
consent-reminder-schedules.php
consent-dashboard-widgets.php
consent-practitioner-management.php
consent-staff-invitations.php
consent-qualifications.php
consent-staff-avatars.php
consent-receipt-templates.php
consent-practitioner-enquiries.php
consent-locations.php
consent-rooms.php
consent-room-assignments.php
consent-services.php
consent-booking-settings.php
consent-clinic-branding.php
session-receipts.php
onsite-distance-fees.php
form-library.php
massage-intake-seed.php
location-discovery.php
practitioner-enquiries.php
practitioner-social-links.php
practitioner-work-location.php
clinic-time-off.php
client-merge.php
session-receipt-deployment.php
application-administration.php
separate-clinics.php
```

## Merge and deletion safeguards

Use merge commits to preserve the original branch ancestry, including the existing Wellnexa theme pull request. Before deleting any inventoried topic branch, fetch current refs, verify its current head is contained in `origin/main`, and check that no worktree uses it. Remote deletions must use the verified expected head as a lease so a branch moved by another contributor is preserved. Local deletion must use `git branch -d`, never forced deletion.

The starting inventory contains 85 local topic branches and 84 remote topic branches, plus `main`; the temporary integration branch is additional. Untracked user branding and sales/library files remain outside this task's commits. Ignored dependencies, build outputs, database fixtures and generated release packages are excluded.
