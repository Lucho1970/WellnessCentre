# Shared portal pilot merge review — 15 November 2026

## Scope and source

Integration branch `feature/portal-chain-merge-review` combines `origin/main` at `deccccd` with `bugs/client-pilot-denial-callback` at `36d2420`. The latter includes the earlier booking wording, unified sign-in, guest client navigation, client account display, account menu, and asset cleanup review branches. The merge commit is `b4eda98`. The only textual conflict was `documentation/README.md`; both sets of links were kept. Booking copy and portal tests merged automatically and were inspected.

This is a source merge, not a production deployment. No dependency, API permission, consent, identity configuration, endpoint, database, or runtime setting was changed by the portal chain. The already hosted synthetic pilot checks are recorded in the linked feature documents. The integration commit itself has no hosted acceptance or release approval.

## Combined validation

Run from `Frontend` unless stated otherwise:

| Command | Result |
| --- | --- |
| `npm run build` | Passed: lint, content and i18n validation, TypeScript, and public, portal, central-admin builds. Existing Vite chunk-size and mixed-import warnings remain. |
| `npx playwright test --config playwright.config.ts --reporter=line` | 309 passed, 2 skipped. The skips are optional private CSV/workbook acceptance fixtures. |
| `npx playwright test --config playwright.application-admin.config.ts --reporter=dot` | 46 passed. |
| `npx playwright test --config playwright.applications.config.ts --reporter=dot` | 27 passed. |
| `php -l` on all 306 tracked PHP files | 306 passed. |
| `php api/tests/appointment-access.php` | 40 passed. |
| `php api/tests/staff-access.php` | Passed. |
| `php api/tests/access-audit.php` | 69 passed; expected unavailable-audit diagnostics were emitted by negative tests. |

## Control and release state

The source merge preserves the scoped GOV, PRIV, AUTH, SEC, DEV and REL findings and evidence in the linked feature documents, the [29 October audit](COMPLIANCE_AUDIT_2026-10-29.md), and the [practitioner governance release preparation](PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md). It does not upgrade any unverified or missing control to Implemented. In particular, real-data governance sign-offs, complete hosted cross-role acceptance, current dependency/security review, artifact provenance and rollback rehearsal for a combined production release remain open under REL-01 through REL-05. No combined artifact was built for deployment. The current hosted pilot uses synthetic records and separate previously reviewed frontend uploads.

Five branches have unique commits outside this merge and must be reviewed separately before removal: `bugs/booking-email-promise`, `feature/docker-restored-schema-rehearsal`, `feature/hosted-pilot-test-readiness`, `feature/local-fullstack-docker-test`, and `feature/unified-portal-sign-in`. Branches that are ancestors of the merged tip can be removed after `main` is updated and no worktree uses them.

Release owner: Luis Duran. Implementation reviewer: Codex, 15 November 2026. Custodian/privacy, security/operations and clinical release decisions for real-data use are not recorded here. Overall release decision: **NOT READY FOR RELEASE**.
