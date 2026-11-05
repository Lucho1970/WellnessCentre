# Shared portal sign-in release preparation — 5 November 2026

## Decision and exact files

The HTTPS/privacy and staff sign-in branches were fast-forwarded into `main`, pushed at source `6cb33763d6b72755c8fa8c8082b2e4a7dfc18e51`, and their three fully merged local/remote branches were removed. The remaining Jane comparison and receipt roadmap branches were not merged. The unrelated untracked `documentation/FULLSCRIPT_INTEGRATION.md` was left untouched.

**Production has not received the frontend update.** The only proposed transfer is the contents of `deployments/portal-signin-narrow-2026-11-05/01-shared-portal-assets-only.zip` to `/public_html/wellness-common-portal/`. It contains `index.html` and 70 files under `assets/` (71 entries total). It contains no `.htaccess`, PHP, API directory, private configuration, migration, database record, or staff/pilot setting. Copy the archive contents to that directory; do not mirror-delete old assets. Both `wellnexa.copihue.ca` and `livinlively.copihue.ca` use this shared root, so both must be smoke-tested.

The narrow ZIP SHA-256 is `88ec41f2e14f60da2b4c45b49982565a799702520dc55fac05b120a14fafeb5c`. Its `manifest.json` records source commit, build time, target, lock hash, archive hash and entry count. Every entry was compared byte-for-byte with the corresponding entry in the repository's canonical seven-archive release build. The full build is in `deployments/portal-signin-full-2026-11-05`; its API, website, Admin and other archives are **not** part of this proposed portal-only transfer.

An earlier lower-level six-archive build in `deployments/portal-signin-2026-11-05` failed the full verifier (`Unexpected archive set`) because the lower-level script omits the Wellnexa website archive added by `build-current-deployment.ps1`. Its narrow ZIP also differs from the canonical build because it used a different release URL setting. It is explicitly marked `DO_NOT_DEPLOY.txt`; use only the canonical narrow ZIP above. This is a packaging selection error, not a hosted failure.

## Validation

| Check | Result |
| --- | --- |
| `Frontend: npm run build` on merged `main` | Passed lint, bilingual content/i18n, strict TypeScript and three production builds. Existing chunk/mixed-import warnings remain. |
| `Wellnexa: npm run build` | Passed. |
| `Frontend: npx playwright test --config playwright.applications.config.ts --reporter=line` | 24 passed on exact sign-in source before the fast-forward merge. |
| `Frontend: npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 46 passed on exact sign-in source before the fast-forward merge. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | 16 passed after merge. |
| `Wellnexa: npm run lint` and `npm run test:browser -- --reporter=line` | Lint exited 0 with existing React warnings; 27 browser tests passed. |
| `./scripts/build-current-deployment.ps1 -ReleaseName portal-signin-full-2026-11-05` | Succeeded from committed source with Node 22.22.1, npm 11.19.0, PHP 8.4.14 and locked Composer packages. |
| `./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/portal-signin-full-2026-11-05` | Passed: seven archives, 2,194 entries, 2,111 text entries scanned and 259 checks. Hosted acceptance was not performed. |
| Narrow archive check | 71 allowed entries; all 71 byte-identical to canonical full ZIP; both `index.html` asset references present. |

The narrow ZIP contains one bundled MSAL library fallback string `http://localhost`, which is used only if browser `window.location` is unavailable; the production browser path selects the actual location. No private key signature or `.env.production` path was found in the portal bundle. The full verifier includes a broader signature scan; a later release security review must still inspect actual deployed bundles and browser network traffic.

Read-only hosted checks on 5 November returned HTTP 200 and five-minute HSTS for `/staff/external` at Wellnexa and `/staff/login` at Livin Lively. Both served the old `index-D6hU82NU.js` and `index-CM5qosUr.css`; the old JS asset returned HTTP 200. The new committed build references `index-B8bXwhGX.js` and the same CSS. The public current `index.html` was saved locally as `deployments/portal-signin-narrow-2026-11-05/rollback-live-index.html` (SHA-256 `783aa52bf2bcd7d73597cd0d71badc9cba279a88668bae00587e339fc1b46481`). Before transfer, the operator should also save a direct remote copy of the live index outside the web root and confirm it still matches the observed version.

## Controlled transfer and rollback, after release approval

1. Confirm the live shared-portal `index.html` still references `index-D6hU82NU.js`, and back it up using the hosting transfer client. Keep the current `.htaccess`, `api/`, PHP and private settings untouched. Verify the ZIP hash above and compare its 71 paths before copying.
2. Copy all `assets/` entries without deleting existing files. Verify the new `index-B8bXwhGX.js` is served as JavaScript with HTTP 200 over HTTPS from both clinic hosts. Then replace only `index.html`.
3. Smoke-test both hosts' entry pages, languages, privacy links, HTTPS/HSTS and basic navigation. Check workforce organization sign-in separately from Wellnexa personal Microsoft/Google practitioner sign-in. The intentionally Inactive, pilot-revoked Microsoft test account should authenticate then receive the pilot denial; do not treat this as a failed identity callback. Do not reactivate it without a separate test decision.
4. On a broken shell or routing failure, restore the backed-up live `index.html` (or the local rollback copy after verifying its hash). The retained old hashed assets allow that rollback without deleting new assets. Recheck both hosts and preserve the incident details. No schema/data rollback is needed for this portal-only package.

Release owner, privacy owner and security/operations reviewer must record approval, actual transfer time, operator, live asset/header evidence and incident contact before describing this as hosted acceptance. The prior five-minute HSTS trial and the larger real-PHI release gates remain separate. No patient, practitioner role, pilot approval, mail/SMS, payment or database change is authorized by this package.

## Revision control ledger

Status is for this packaging slice only. Reviewer: Codex, 2026-11-05. Unverified items require the accountable release/privacy/security/clinical owner to provide current evidence before real-data release; they are not claims of organizational noncompliance.

| Control | Status | Evidence or remaining action |
| --- | --- | --- |
| GOV-01 | Unverified | Confirm platform/clinic custody and release owner. |
| GOV-02 | Unverified | Refresh applicability register at release. |
| GOV-03 | Unverified | PIPEDA decision outside package check. |
| GOV-04 | Unverified | No clinical function added; intended-use approval remains. |
| GOV-05 | Partial | Synthetic tests only; PIA/TRA decisions outstanding. |
| PRIV-01 | Partial | Privacy links build/test locally; hosted shared-portal check pending. |
| PRIV-02 | Unverified | No consent/authority change. |
| PRIV-03 | Partial | Pilot denial observed; broader restrictions not retested. |
| PRIV-04 | Unverified | Representative authority unaffected. |
| PRIV-05 | Unverified | Access/correction process unaffected. |
| PRIV-06 | Unverified | Retention unaffected. |
| PRIV-07 | Unverified | Breach process unaffected. |
| AUTH-01 | Partial | Distinct identity routes tested locally; hosted callback after upload pending. |
| AUTH-02 | Partial | Inactive/pilot-revoked test account denied live; full clinic/record matrix pending. |
| AUTH-03 | Partial | Duplicate sign-in guard tested locally; complete session policy pending. |
| AUTH-04 | Unverified | No session architecture change; existing storage gate remains. |
| AUTH-05 | Unverified | No permission grant change; deployed grant inventory pending. |
| SEC-01 | Partial | Narrow ZIP has only shell/assets; full signature scan passed, wider review pending. |
| SEC-02 | Partial | Read-only HTTPS checks passed; broader infrastructure evidence pending. |
| SEC-03 | Partial | Two hosts returned HSTS; all authenticated headers/cache checks pending. |
| SEC-04 | Partial | Existing frontend validation retained; no new API change. |
| SEC-05 | Partial | Duplicate interactive clicks suppressed; wider abuse controls unchanged. |
| SEC-06 | Unverified | Hosted audit and operations logs not checked. |
| SEC-07 | Partial | Raw MSAL error suppressed locally; hosted behavior pending. |
| SEC-08 | Unverified | No new endpoint/subprocessor; register approval outstanding. |
| SEC-09 | Not applicable | No communication path changed. |
| DEV-01 | Partial | Main merged/pushed; dedicated release-prep branch records this package. |
| DEV-02 | Partial | Narrow package selection reviewed; no code change in prep branch. |
| DEV-03 | Partial | Lock hashes recorded; no new dependency; fresh audit not run. |
| DEV-04 | Partial | Builds and 113 relevant browser tests pass; full PHP/SQL suites not run for frontend-only transfer. |
| DEV-05 | Partial | Browser regressions include mobile/French; manual AT audit pending. |
| DEV-06 | Partial | Existing bundle warnings remain; representative-volume review pending. |
| REL-01 | Partial | This ledger records all 38 controls; owner decisions pending. |
| REL-02 | Partial | Both builds/browser suites and artifact verifier pass; full baseline gate not repeated. |
| REL-03 | Partial | Full local artifact passes; hosted acceptance after upload pending. |
| REL-04 | Missing | Real-data release remains blocked by open gates. |
| REL-05 | Partial | Package/rollback are reviewable; production transfer and owner sign-offs pending. |
| REL-06 | Partial | Task report must include scope, checks, warning and rollback. |

**Current decision:** NOT READY FOR RELEASE.
