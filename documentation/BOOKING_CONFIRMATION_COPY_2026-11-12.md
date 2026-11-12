# Booking confirmation wording — 12 November 2026

## Scope

The hosted Wellnexa client pilot displayed an unconditional email promise on the appointment review screen while app mail delivery was disabled. The same promise appeared in the staff review screen and in both success notices. This change makes those notices describe the confirmed booking and directs people to the appointment list. It does not infer that a queued email was delivered, accepted or enabled. English and French text are updated together.

The change is limited to the shared portal frontend. It does not alter booking requests, pricing, role checks, session behavior, notification dispatch, API settings, database schema, consent, third-party endpoints or payment handling. It does not enable mail or SMS. The hosted portal still runs its prior asset until a separately approved deployment; no production file was changed for this fix.

## Evidence

- `npm run lint`: passed, ESLint 10.13.0 with zero warnings.
- `npm run validate:content`: passed, four bilingual content entries.
- `npm run validate:i18n`: passed, 2,679 English and French UI translations.
- `npx tsc --noEmit`: passed, TypeScript 5.7.2.
- `npx playwright test tests/customer.spec.ts tests/portal.spec.ts --grep 'linked client reviews distance fee|mobile-only booking captures|pilot denial explains' --workers=1 --reporter=line`: five passed. The client and staff booking tests assert the review wording; the client test asserts the success notice. The pilot-denial pair passed on rerun.
- `npm run build`: passed public, portal and application-admin production builds using Node 22.22.1, npm 11.19.0 and Vite 6.4.5. Existing large-chunk and central static/dynamic import warnings remain; no chunk limits were changed.
- An initial wider `npx playwright test tests/customer.spec.ts tests/portal.spec.ts --workers=1 --reporter=line` run reached test 55 of 238 and was stopped to focus the relevant cases. Its first English pilot-denial case failed once; the same case and French variant passed in the focused rerun. This is not a full browser-suite pass and the initial failure remains recorded.
- `git diff --check`: passed. Existing installed dependencies were used; no `npm ci` clean-environment restore, dependency audit, PHP/SQL suite or hosted artifact test was run for this wording-only branch.

## Review and release limits

The hosted pilot record (`documentation/HOSTED_CLIENT_PILOT_2026-11-12.md` on `feature/hosted-pilot-test-readiness` at `ffadc07`) is on a separate unmerged branch and captures the observed booking quote, temporary third-party address entry and other hosted gaps. The active test profile was changed back to a public civic address. With the owner and practitioner's tester consent, one hosted appointment was subsequently confirmed and canceled with no fee; its canceled history was visible. The privacy review, direct SQL/worker verification, receipt checks and pilot revocation remain open. This copy fix does not establish any of them.

The 38-control baseline in [the 10 November governance ledger](PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md#change-scoped-38-control-ledger) remains in effect. For GOV-01–05, PRIV-01–07, AUTH-01–05, SEC-01–09, DEV-01–06 and REL-01–06, baseline Partial/Missing/Unverified items remain unmet unless explicitly narrowed here. DEV-04 bilingual checks, lint, TypeScript, the three builds and focused browser regression passed for this frontend text. DEV-05 manual keyboard, zoom, screen-reader and visual accessibility review remains unverified; structure and controls were unchanged. REL-03 hosted acceptance and REL-05 release approvals remain missing. The release owner, custodian/privacy, security/operations and clinical owners must make their separate decisions before real-data release.

For a future approved portal release, package from the exact reviewed commit, verify hashes/assets on both Wellnexa and LivinLively before replacing the shared `index.html`, and retain the previous shell/assets for rollback. Confirm both languages and the review/success wording in a clean hosted browser. Rolling back this copy change restores the prior email promise; it does not change booking or messaging state.

RELEASE STATUS: NOT READY FOR RELEASE
