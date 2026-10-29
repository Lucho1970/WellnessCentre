# Frontend quality gates — pilot preparation

29 October 2026. Follow-up to findings F-02 (missing frontend lint tooling) and F-03 (build dependency advisories) in the [baseline audit](COMPLIANCE_AUDIT_2026-10-29.md). Implementation starts from `38ac97a1e41ee3acf6212ea2ba30f4c6cd4f2f7d` on `bugs/frontend-quality-gates`. Historical audit results are retained; this document records the new validation and its limits.

## Changes

`Frontend/npm run lint` now uses declared, locked ESLint tooling and a flat configuration. The canonical `npm run build` runs lint first, so a lint failure stops that production build. Lint checks first-party TypeScript/TSX, browser tests, Node scripts and build/test configurations with ESLint and TypeScript recommended rules. Application code also uses the React rules-of-hooks check. Generated output, dependencies, browser reports and previously emitted Vite configuration artifacts are excluded; maintained source and tests are not excluded. Browser/Node globals follow their respective execution contexts. Warnings fail the command, inline configuration is disabled, and unused suppression directives are errors. React exhaustive-dependency analysis and typed ESLint presets are not enabled by this initial configuration; application strict TypeScript compilation remains a separate build gate.

Enabling those checks exposed existing issues. The change removes unused imports/bindings and redundant boolean casts/regex escapes, uses constant bindings where appropriate, and rewrites 142 standalone comma sequences as ordered statements. Conditional bodies retain braces and evaluation order. It replaces explicit `any` request contracts with generic responses defaulting to `unknown`, adds concrete response types at consuming call sites, and narrows test capture types. Existing runtime form/series validation remains in place; type annotations are not new runtime schema validation. Caught authentication errors preserve their cause while retaining the same user-facing messages. No access policy, session-storage design, schema, API permission or external integration is changed.

Browser assertions and optional private-fixture skips are preserved. Tests that inspect optional published form sections/options now explicitly fail when those members are missing before accessing them. The lint cleanup does not add blanket suppressions, weaken TypeScript, skip tests or relax existing expected results.

## Dependencies

All package changes are development/build-only. No runtime dependency versions change.

| Dependency | Before | After | Purpose / classification |
| --- | --- | --- | --- |
| `eslint` | Not declared | `10.13.0` | Supported core lint tool; development only. |
| `@eslint/js` | Not declared | `10.0.1` | ESLint recommended rules; development only. |
| `typescript-eslint` | Not declared | `8.72.1` | TypeScript parser/plugin/recommended rules; development only. |
| `eslint-plugin-react-hooks` | Not declared | `7.1.1` | Hook call-order checking; development only. |
| `globals` | Not declared | `16.5.0` | Browser/Node environment definitions; development only. |
| `vite` | Locked `6.4.3`, range `^6.0.0` | Pinned `6.4.5` | Compatible patch within existing major; addresses Vite advisory findings. |
| `vite → postcss → source-map-js` | `1.2.1` | `1.2.2` | Transitive build dependency security patch, retained in lockfile. |

Node `22.22.1` satisfies the new lint tooling's engine requirements. React, TypeScript, Node and Vite major versions are unchanged. An initial ESLint 9 installation reported end of support; it was replaced with supported ESLint 10 before final validation. Added transitive packages are development-only; lockfile licence labels include MIT, Apache-2.0, ISC, BSD-2-Clause, BSD-3-Clause and BlueOak-1.0.0. Preserve applicable notices when distributing tooling; this check is not a legal licence opinion. Installation reports esbuild's existing postinstall script as not yet covered by npm's optional `allowScripts` configuration; it does not justify approving unknown scripts or disabling package integrity checks.

## Validation

Final checks use Node 22.22.1, npm 11.19.0, PHP 8.4.14 and local Edge. Do not infer hosted acceptance from these checks. No production database, private environment settings, actual provider sends, deployment or merge is part of this task.

| Command / check | Result |
| --- | --- |
| Frontend: `npm ci` | Passed from the updated lockfile; zero advisories. Existing esbuild install-script warning noted above. |
| Frontend: `npm run lint` | Passed with zero warnings after clean restore. |
| ESLint Node API enforcement probe (in memory) | Confirmed unused declarations, explicit any and conditional hooks produce errors; dist and browser-result paths are globally ignored. No probe source file was created. |
| Frontend: `npm run build`, then final `npm run test:build -- --reporter=line` | Production builds and strict application TypeScript/content checks passed. Final command runs the new lint-first build and built-output smoke suite; 14 smoke tests passed. Existing >500 kB bundle warnings remain. |
| Wellnexa: `npm run build` | Passed. An early attempt ran while shared frontend dependencies were being restored and failed on missing modules; repeat after restore passed. |
| Frontend: `npm audit --json`; `npm audit --omit=dev --json` | Both passed, zero advisories, including build dependencies. |
| Frontend: `npm ls eslint @eslint/js typescript-eslint eslint-plugin-react-hooks globals vite source-map-js --depth=2` | Passed; confirmed installed versions and Vite → PostCSS → source-map-js path. |
| Wellnexa: `npm audit --json`; api: `composer audit --format=json`; `composer validate --strict` | Passed; no reported advisories/abandoned PHP packages. |
| Wellnexa: `npm run lint` | Exit 0; four pre-existing warnings in App.tsx and catalogue/Details.tsx remain. This frontend task does not suppress them. |
| PHP root scripts: each `api/tests/*.php` with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | 56/56 passed. |
| `php -l` recursively for api/src, api/public, api/tests and Wellnexa/public/api | 242/242 passed. |
| Frontend: `node --test tests/cache-config.test.mjs` | 2 passed. |
| Wellnexa: `npm run test:browser -- --reporter=line`; `npx playwright test --config playwright.applications.config.ts --reporter=line` | 24 website + 1 joining-entry test passed. |
| Frontend: `npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 36 passed. |
| Frontend: `npm test -- --reporter=line` | 283 passed, two existing optional private CSV/workbook fixtures skipped; zero failures. The earlier audited startup timeout did not recur in this full run. |
| Frontend: `npx playwright test --config playwright.invitations.config.ts --reporter=line`; `npx playwright test --config playwright.applications.config.ts --reporter=line` | 7 staff invitation + 9 practitioner application checks passed. |
| Limited current-surface artifact scan | 82 built JS/HTML/CSS/JSON files inspected for selected hardcoded credential/private-key patterns: no candidates; no source maps. This is not a full secret/history scan or final ZIP review. |
| Markdown local links / Git whitespace / dependency classification | Local links and whitespace passed; only Vite/source-map-js existing versions changed and all new packages are development-only. |

Initial lint/type checks failed while the rules and explicit response types were being introduced. A later configuration check caught generated bundles being included because the ignore object also carried linter options; it was corrected to a global ignores-only object. The enforcement probe now verifies that boundary. No recommended rule was disabled to clear these failures.

No SQL suites were rerun because PHP/database behavior is unchanged; no hosted checks, final deployment package, real-provider acceptance, full accessibility/performance assessment or real-PHI release review was performed.

**F-02/F-03 outcome:** local lint tooling is operational and both reported advisory packages are patched. Remaining evidence and release findings are listed below.

## Changed file inventory

| File | Purpose |
| --- | --- |
| `AGENTS.md` | Link current lint/dependency evidence without changing the mandatory controls. |
| `Frontend/eslint.config.mjs` | Configure recommended JS/TS rules, application hook ordering, execution globals and generated-file exclusions. |
| `Frontend/package-lock.json` | Lock lint tooling and compatible Vite/source-map security patches; runtime versions unchanged. |
| `Frontend/package.json` | Declare exact development tools/security patch; fail lint on warnings and gate canonical builds on lint. |
| `Frontend/playwright.build.config.ts` | Remove unnecessary regex escapes using precise ESLint suggestions. |
| `Frontend/src/admin/BusinessSettings.tsx` | remove unused imports/bindings where flagged. |
| `Frontend/src/admin/CommunicationsAdmin.tsx` | apply recommended lint fixes without changing intended behavior. |
| `Frontend/src/admin/DashboardWidgetAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/LocationAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/NotificationStatusAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/PractitionerAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/RoomAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/RoomCapabilities.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/ServiceAdmin.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/admin/ServiceAssignments.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/admin/StaffAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/StaffInvitations.tsx` | separate ordered validation/error statements. |
| `Frontend/src/admin/TeamAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/auth/AuthProvider.tsx` | preserve caught authentication error cause. |
| `Frontend/src/auth/StaffInvitationPage.tsx` | separate ordered validation/error statements. |
| `Frontend/src/auth/StaffSignIn.tsx` | separate ordered validation/error statements. |
| `Frontend/src/auth/UserAccountMenu.tsx` | remove unused imports/bindings where flagged. |
| `Frontend/src/booking/AppointmentLinkRevocation.tsx` | separate ordered validation/error statements. |
| `Frontend/src/booking/AppointmentLogisticsNotes.tsx` | separate ordered validation/error statements. |
| `Frontend/src/booking/ManageRecurringSeries.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/booking/OnsiteFeeWaiver.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/booking/PractitionerVisitOutcome.tsx` | separate ordered validation/error statements. |
| `Frontend/src/booking/RecurringBooking.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/booking/StaffAppointments.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/clients/ClientInvitations.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/clients/ClientManagement.tsx` | separate ordered validation/error statements. |
| `Frontend/src/clients/ClientOverview.tsx` | separate ordered validation/error statements. |
| `Frontend/src/customer/CustomerAppointmentLink.tsx` | separate ordered validation/error statements. |
| `Frontend/src/customer/CustomerAppointmentManager.tsx` | separate ordered validation/error statements. |
| `Frontend/src/customer/CustomerBooking.tsx` | separate ordered validation/error statements. |
| `Frontend/src/customer/CustomerWorkspace.tsx` | separate ordered validation/error statements. |
| `Frontend/src/customer/auth.ts` | preserve caught authentication error cause. |
| `Frontend/src/discovery/ClientDiscovery.tsx` | apply recommended lint fixes without changing intended behavior. |
| `Frontend/src/discovery/DiscoverySettings.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/discovery/LocationSearch.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/discovery/TravelEnquiries.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/forms/FormLibrary.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/forms/FormSourceDialog.tsx` | separate ordered validation/error statements. |
| `Frontend/src/forms/FormTasks.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/forms/FormTemplates.tsx` | separate ordered validation/error statements; replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/forms/api.ts` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/guest/GuestBooking.tsx` | separate ordered validation/error statements. |
| `Frontend/src/portal/PractitionerClients.tsx` | separate ordered validation/error statements. |
| `Frontend/src/portal/PractitionerNotifications.tsx` | separate ordered validation/error statements. |
| `Frontend/src/profile/PractitionerQualifications.tsx` | separate ordered validation/error statements. |
| `Frontend/src/profile/PractitionerTravelSettings.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/profile/PractitionerWorkLocation.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/profile/ProfileSettings.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/profile/PublicCardSettings.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/profile/StaffNotificationSettings.tsx` | separate ordered validation/error statements; remove unused imports/bindings where flagged. |
| `Frontend/src/public/Practitioners.tsx` | remove unused imports/bindings where flagged. |
| `Frontend/src/receipts/SessionReceipts.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/scheduling/AvailabilityAdmin.tsx` | separate ordered validation/error statements. |
| `Frontend/src/shared/FormValidation.tsx` | replace loose contracts with unknown/generic/domain response types. |
| `Frontend/src/shared/PractitionerPersonCard.tsx` | apply recommended lint fixes without changing intended behavior. |
| `Frontend/tests/application-admin.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/tests/appointment-action-links.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/tests/client-forms.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/tests/customer.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/tests/portal.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/tests/practitioner-applications.spec.ts` | Narrow captured fixture/window types or constant bindings; preserve assertions and test scope. |
| `Frontend/vite.application-admin.config.ts` | Remove unused build callback parameter. |
| `documentation/FRONTEND_QUALITY_GATES.md` | Record dependency versions, implementation scope, validation and remaining release gates. |
| `documentation/README.md` | Index the quality-gate follow-up. |

## Remaining pilot gates

This work addresses local engineering gates. Existing browser credential storage, hosted identity/permissions, backup/restore, host headers, vendor/data-flow review and patient privacy/operational findings remain open. Keep the existing single-clinic database as requested; this change neither creates a database nor changes data. Controlled tester accounts, synthetic records, outbound delivery restrictions and hosted acceptance are still required before inviting testers. No real-PHI acceptance or production deployment is approved here.

No permission changes. No endpoint changes. Rollback is a reviewed source/lockfile revert followed by exact restore and rebuild; no data restoration or migration is introduced.

RELEASE STATUS: NOT READY FOR RELEASE
