# Booking confirmation wording — 12 November 2026

## Scope

The hosted Wellnexa client pilot displayed an unconditional email promise on the appointment review screen while app mail delivery was disabled. The same promise appeared in the staff review screen and in both success notices. This change makes those notices describe the confirmed booking and directs people to the appointment list. It does not infer that a queued email was delivered, accepted or enabled. English and French text are updated together.

The change is limited to the shared portal frontend. It does not alter booking requests, pricing, role checks, session behavior, notification dispatch, API settings, database schema, consent, third-party endpoints or payment handling. It does not enable mail or SMS. The hosted portal still runs its prior asset until a separately approved deployment; no production file was changed for this fix.

For a narrow hosted update, `bugs/booking-email-promise-live` starts from the **actual deployed portal source** `b57f2b7959363078420a5047ff62bc99c749eafb` and cherry-picks only the wording/test commit as `573e02c`. Relative to that source, five frontend source files, two browser tests and this document change. The broader `bugs/booking-email-promise` branch also contains later practitioner governance UI changes inherited from `main`; deploying its full bundle for this copy fix would change unrelated behavior. The narrow branch is a deployment candidate, not a replacement for merging the reviewed fix into current `main` later.

## Evidence

- `npm run lint`: passed, ESLint 10.13.0 with zero warnings.
- `npm run validate:content`: passed, four bilingual content entries.
- `npm run validate:i18n`: passed, 2,679 English and French UI translations.
- `npx tsc --noEmit`: passed, TypeScript 5.7.2.
- `npx playwright test tests/customer.spec.ts tests/portal.spec.ts --grep 'linked client reviews distance fee|mobile-only booking captures|pilot denial explains' --workers=1 --reporter=line`: five passed. The client and staff booking tests assert the review wording; the client test asserts the success notice. The pilot-denial pair passed on rerun.
- `npm run build`: passed public, portal and application-admin production builds using Node 22.22.1, npm 11.19.0 and Vite 6.4.5. Existing large-chunk and central static/dynamic import warnings remain; no chunk limits were changed.
- An initial wider `npx playwright test tests/customer.spec.ts tests/portal.spec.ts --workers=1 --reporter=line` run reached test 55 of 238 and was stopped to focus the relevant cases. Its first English pilot-denial case failed once; the same case and French variant passed in the focused rerun. This is not a full browser-suite pass and the initial failure remains recorded.
- `git diff --check`: passed. Existing installed dependencies were used; no `npm ci` clean-environment restore, dependency audit, PHP/SQL suite or hosted artifact test was run for this wording-only branch.

### Narrow live-source candidate validation

- `npm ci`: passed on the unchanged lockfile; 350 packages installed. npm warned that `esbuild@0.25.12` has an install script not covered by its `allowScripts` setting.
- `npm audit --json`: exit 1, ten **moderate** advisories in the ESLint/TypeScript ESLint development tooling tree, zero high/critical. `npm audit --omit=dev --json`: exit 0, zero runtime advisories. No forced audit fix or dependency update was applied.
- `npm run build`: passed lint, four content entries, 2,574 bilingual translation keys, strict TypeScript and public/portal/central builds on the narrow source. Existing large-chunk and central dynamic/static import warnings remained.
- `npm test -- --reporter=line`: 296 passed, two skipped and two timed out in the full 300-case suite. The timeouts were `service import csv 409 after preview clears unsafe confirmation` and `reminder settings privacy denial requires reload after create`; neither tests this copy change. The same two cases passed in `npx playwright test tests/portal.spec.ts --grep 'service import csv 409 after preview clears unsafe confirmation|reminder settings privacy denial requires reload after create' --workers=1 --reporter=line`. The initial failed run remains part of the evidence.
- A portal-only build with the previous release's practitioner pilot flags and identity identifiers, production URL and release marker passed. The built asset count matched the currently deployed ZIP (68 assets). Hash names changed as expected from rebuilding the translated chunks and their imports; source diff and build flags, rather than asset count alone, define the scope.
- `BUILD_PRACTITIONER_SIGN_IN=1 npx playwright test --config playwright.build.config.ts --reporter=line`: 16 passed. The first smoke run omitted that test-mode variable: 15 passed and the practitioner-sign-in case expected the default disabled mode, so it failed against the deliberately enabled pilot build. No product flag was changed to satisfy the test.
- `npm test -- --workers=1 --reporter=line`: 298 passed, two skipped in 16.0 minutes. This second full default browser run resolved the two earlier timeouts; the initial failed run remains recorded above. No PHP or SQL source changed; hosted PHP/database behavior is not established by these frontend checks.

## Review and release limits

The hosted pilot record (`documentation/HOSTED_CLIENT_PILOT_2026-11-12.md` on `feature/hosted-pilot-test-readiness` at `b5fd99e`) is on a separate unmerged branch. One approved synthetic appointment was confirmed and canceled there; a read-only hosted query confirmed its zero fee, status history and canceled app email rows. The earlier real-address privacy review, receipt test and client pilot revocation remain open. This copy fix does not establish any of them.

The 38-control baseline in the 10 November practitioner governance ledger on current `main` remains in effect; that later document is not part of this older live-source branch. For GOV-01–05, PRIV-01–07, AUTH-01–05, SEC-01–09, DEV-01–06 and REL-01–06, baseline Partial/Missing/Unverified items remain unmet unless explicitly narrowed here. DEV-04 bilingual checks, lint, TypeScript, the three builds and focused browser regression passed for this frontend text. DEV-05 manual keyboard, zoom, screen-reader and visual accessibility review remains unverified; structure and controls were unchanged. REL-03 hosted acceptance and REL-05 release approvals remain missing. The release owner, custodian/privacy, security/operations and clinical owners must make their separate decisions before real-data release.

For a future approved portal release, package from the exact reviewed commit, verify hashes/assets on both Wellnexa and LivinLively before replacing the shared `index.html`, and retain the previous shell/assets for rollback. Copy `assets/` additively and replace only `index.html` under `/public_html/wellness-common-portal`; **do not mirror-delete assets** or copy the candidate's `.htaccess`, `api/`, PHP or private settings. The current live shell is `portal-signin-pilot-corrected-2026-11-05`, backed by `deployments/portal-signin-pilot-corrected-narrow-2026-11-05/01-shared-portal-assets-only.zip`. Back up the live `index.html` before replacement. If either host fails, restore that saved shell; leave added content-addressed assets in place until a separate cleanup review. Confirm Wellnexa and LivinLively English/French shell, client/practitioner/workforce entry, and review/success wording in a clean hosted browser. Rolling back this copy change restores the prior email promise; it does not change booking or messaging state. No migration, API deployment, provider grant or channel enablement is part of this candidate.

RELEASE STATUS: NOT READY FOR RELEASE
