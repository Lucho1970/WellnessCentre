# Shared portal sign-in release preparation — 5 November 2026

## Decision and exact files

The HTTPS/privacy and staff sign-in branches were fast-forwarded into `main`, pushed at source `6cb33763d6b72755c8fa8c8082b2e4a7dfc18e51`, and their three fully merged local/remote branches were removed. The remaining Jane comparison and receipt roadmap branches were not merged. The unrelated untracked `documentation/FULLSCRIPT_INTEGRATION.md` was left untouched.

**The first production attempt was rolled back.** The owner copied the first narrow ZIP's `index.html` to `/public_html/wellness-common-portal/` before the assets had arrived. Both hosts served the new index, but its JavaScript URL returned SPA HTML. The agent copied the 70 assets through the owner's open Beyond Compare session; all 70 then returned HTTP 200 with non-HTML types. A clean hosted browser check exposed a second defect: `/staff/external` rendered the organization Staff portal because the build omitted the practitioner's pilot flags and external client identifiers. The agent restored the saved previous `index.html`. Both hosts again served `index-D6hU82NU.js`; a clean Edge check showed Wellnexa's Practitioner sign-in and Livin Lively's Staff portal with no page errors. The extra hashed assets remain on the shared host and do not change the restored shell. `.htaccess`, `api/`, PHP, private settings, roles and pilot approvals were not changed.

**Corrected candidate deployed under the owner's new exact-artifact approval:** `deployments/portal-signin-pilot-corrected-narrow-2026-11-05/01-shared-portal-assets-only.zip`, SHA-256 `68f0d402781add0e453f88eaef85d1095e7eb01d1f476215725ae2786f00eccd`, source `b57f2b7959363078420a5047ff62bc99c749eafb`. It contains `index.html` and 68 `assets/` files (69 entries). All entries are byte-identical to the canonical seven-archive build in `deployments/portal-signin-pilot-corrected-2026-11-05`. The new index references `index-kq95j7Df.js`. Both clinic hosts use the same shared root. The canonical build's API, website, Admin and other archives were **not** transferred.

Both superseded candidate directories, `portal-signin-2026-11-05` and `portal-signin-narrow-2026-11-05`, contain `DO_NOT_DEPLOY.txt`. The first used the lower-level six-archive builder and failed the full verifier (`Unexpected archive set`); the second used the canonical builder but lacked practitioner configuration and failed hosted acceptance. Preserve these records for the incident review. Do not retry either ZIP. The corrected build reads the previously hosted pilot's **public** build identifiers from `deployments/2026-11-02-practitioner-ui-reliability-v3/manifest.json`. Its manifest confirms applications, external authentication and Google/personal Microsoft sign-in enabled, invitations disabled, and all four external identity identifiers equal to the prior verified manifest. No new provider, grant or permission was added.

## Validation

| Check | Result |
| --- | --- |
| `Frontend: npm run build` on merged `main` | Passed lint, bilingual content/i18n, strict TypeScript and three production builds. Existing chunk/mixed-import warnings remain. |
| `Wellnexa: npm run build` | Passed. |
| `Frontend: npx playwright test --config playwright.applications.config.ts --reporter=line` | 24 passed on exact sign-in source before the fast-forward merge. |
| `Frontend: npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 46 passed on exact sign-in source before the fast-forward merge. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | 16 passed after merge; this initial run expected practitioner sign-in off, so it did not catch the missing pilot configuration. |
| `Wellnexa: npm run lint` and `npm run test:browser -- --reporter=line` | Lint exited 0 with existing React warnings; 27 browser tests passed. |
| `build-current-deployment.ps1 -RequirePractitionerPilot` with no flags | Correctly rejected missing pilot settings before packaging. |
| `build-deployment.ps1` with pilot flags but no external IDs | Correctly rejected missing external IDs before packaging. |
| `./scripts/build-current-deployment.ps1 -ReleaseName portal-signin-pilot-corrected-2026-11-05 -RequirePractitionerPilot` | Succeeded from committed source with Node 22.22.1, npm 11.19.0, PHP 8.4.14 and locked Composer packages. |
| `./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/portal-signin-pilot-corrected-2026-11-05` | Passed: seven archives, 2,192 entries, 2,109 text entries scanned and 257 checks. Hosted acceptance was not performed for this corrected artifact. |
| `Frontend: BUILD_PRACTITIONER_SIGN_IN=1 npx playwright test --config playwright.build.config.ts --reporter=line` | 16 passed against the corrected production build, including separate practitioner/workforce entry and the approved external authority/scope. |
| `Frontend: BUILD_PRACTITIONER_SIGN_IN=1 BUILD_PRODUCTION_HOSTS=1 npx playwright test --config playwright.build.config.ts --grep 'built practitioner rollout retains' --reporter=line` | 1 passed with host-mapped bundle routing and synthetic identity provider. |
| Corrected narrow archive check | 69 allowed entries; all 69 byte-identical to the corrected canonical full ZIP; both `index.html` asset references present. |

The first narrow ZIP contained one bundled MSAL library fallback string `http://localhost`, which the browser path does not use. The corrected full verifier includes a signature scan; a later release security review must still inspect actual deployed bundles and browser network traffic. The release builder now fails when a requested practitioner pilot is missing its required flags or public external identity settings. It does not claim that all production configuration is approved.

Read-only hosted checks on 5 November returned HTTP 200, no-store HTML and five-minute HSTS for the two clinic entry paths. The restored index on both hosts references `index-D6hU82NU.js` and `index-CM5qosUr.css`; the old JS asset returned JavaScript, HTTP 200. A copy of the previous live `index.html` is in `deployments/portal-signin-pilot-corrected-narrow-2026-11-05/rollback-live-index.html` (SHA-256 `783aa52bf2bcd7d73597cd0d71badc9cba279a88668bae00587e339fc1b46481`). The owner's own backup remains the primary rollback copy. Confirm the live index still matches before another transfer.

## Corrected hosted transfer and rollback

The owner explicitly approved the corrected ZIP and destination after reviewing the failed first attempt. Before the second transfer, the agent checked that the live index on Wellnexa matched the saved rollback file byte-for-byte and that the corrected ZIP checksum matched its manifest. Using the owner's open Beyond Compare session, the agent copied the corrected `assets/` folder first, without mirror deletion. HTTP HEAD checks then passed for all 68 asset paths at Wellnexa; `index-kq95j7Df.js` returned HTTP 200 and JavaScript content type on both clinic hosts. Only then did the agent copy `index.html`.

Both hosts then served the new index with HTTP 200, `Cache-Control: no-store, must-revalidate, no-cache, max-age=0` and `Strict-Transport-Security: max-age=300`. In clean, unauthenticated Edge sessions, Wellnexa `/staff/external` and Livin Lively `/staff/external` displayed separate Practitioner sign-in with both Google and personal Microsoft buttons. Both `/staff/login` pages displayed the organization Staff portal without those personal-provider buttons. Wellnexa `/staff/apply` showed the application entry; both `/client` pages loaded. Seven checked pages had zero JavaScript page errors. French Wellnexa practitioner entry rendered `Connexion des praticiens` at 390-pixel width without horizontal overflow. Wellnexa's Privacy statement link resolved to `https://wellnexa.ca/privacy?lang=en` with HTTP 200. These are shell and unauthenticated workflow checks; an authenticated callback and actual authorization response remain pending.

No `.htaccess`, `api/`, PHP, private settings, roles, pilot approvals or patient data were changed. The earlier rollback copy remains available. Do not delete old hashed assets while this release is being assessed.

On a broken shell, callback or routing failure, restore the backed-up previous `index.html` (or the local rollback copy after verifying its hash). The retained old hashed assets allow rollback without deleting new assets. Recheck both hosts and preserve incident details. No schema/data rollback is needed for this portal-only package. The intentionally Inactive, pilot-revoked Microsoft test account should authenticate then receive pilot denial; it must not be reactivated just to make a dashboard appear.

The owner's first approval covered the **first exact ZIP**, which was rolled back. A second explicit REL-05 approval covered this corrected ZIP. The release owner, privacy owner and security/operations reviewer must still record final acceptance, authenticated identity evidence, monitoring and incident contact before describing the broader release as accepted. The prior five-minute HSTS trial and the larger real-PHI release gates remain separate. No patient, practitioner role, pilot approval, mail/SMS, payment or database change was authorized by this package.

## Revision control ledger

Status is for this packaging slice only. Reviewer: Codex, 2026-11-05. Unverified items require the accountable release/privacy/security/clinical owner to provide current evidence before real-data release; they are not claims of organizational noncompliance.

| Control | Status | Evidence or remaining action |
| --- | --- | --- |
| GOV-01 | Unverified | Confirm platform/clinic custody and release owner. |
| GOV-02 | Unverified | Refresh applicability register at release. |
| GOV-03 | Unverified | PIPEDA decision outside package check. |
| GOV-04 | Unverified | No clinical function added; intended-use approval remains. |
| GOV-05 | Partial | Synthetic tests only; PIA/TRA decisions outstanding. |
| PRIV-01 | Partial | Wellnexa hosted sign-in pages show the privacy link; wider clinic notice review pending. |
| PRIV-02 | Unverified | No consent/authority change. |
| PRIV-03 | Partial | Pilot denial observed; broader restrictions not retested. |
| PRIV-04 | Unverified | Representative authority unaffected. |
| PRIV-05 | Unverified | Access/correction process unaffected. |
| PRIV-06 | Unverified | Retention unaffected. |
| PRIV-07 | Unverified | Breach process unaffected. |
| AUTH-01 | Partial | Distinct identity routes render live; hosted authenticated callback after upload pending. |
| AUTH-02 | Partial | Inactive/pilot-revoked test account denied live; full clinic/record matrix pending. |
| AUTH-03 | Partial | Duplicate sign-in guard tested locally; complete session policy pending. |
| AUTH-04 | Unverified | No session architecture change; existing storage gate remains. |
| AUTH-05 | Unverified | No permission grant change; deployed grant inventory pending. |
| SEC-01 | Partial | Narrow ZIP has only shell/assets; full signature scan passed, wider review pending. |
| SEC-02 | Partial | Read-only HTTPS checks passed; broader infrastructure evidence pending. |
| SEC-03 | Partial | Two hosts returned HSTS and no-store HTML; authenticated headers/cache checks pending. |
| SEC-04 | Partial | Existing frontend validation retained; no new API change. |
| SEC-05 | Partial | Duplicate interactive clicks suppressed; wider abuse controls unchanged. |
| SEC-06 | Unverified | Hosted audit and operations logs not checked. |
| SEC-07 | Partial | Raw MSAL error suppressed locally; hosted behavior pending. |
| SEC-08 | Unverified | No new endpoint/subprocessor; register approval outstanding. |
| SEC-09 | Not applicable | No communication path changed. |
| DEV-01 | Partial | Main merged/pushed; dedicated release-prep branch records this package. |
| DEV-02 | Partial | Release scripts now fail closed on missing pilot configuration; first hosted attempt was rolled back. |
| DEV-03 | Partial | Lock hashes recorded; no new dependency; fresh audit not run. |
| DEV-04 | Partial | Corrected pilot build and 17 built-browser checks pass; seven hosted shell pages passed. Earlier 97 relevant source/website browser tests passed. Full PHP/SQL suites not run for frontend-only transfer. |
| DEV-05 | Partial | Browser regressions include mobile/French; manual AT audit pending. |
| DEV-06 | Partial | Existing bundle warnings remain; representative-volume review pending. |
| REL-01 | Partial | This ledger records all 38 controls; owner decisions pending. |
| REL-02 | Partial | Both builds/browser suites and artifact verifier pass; full baseline gate not repeated. |
| REL-03 | Partial | First hosted artifact failed and was rolled back; corrected artifact passes local verifier and hosted shell checks, authenticated acceptance pending. |
| REL-04 | Missing | Real-data release remains blocked by open gates. |
| REL-05 | Partial | Both exact-artifact approvals recorded; first upload rolled back, second transferred and smoke-tested; formal owner sign-offs pending. |
| REL-06 | Partial | Task report must include scope, checks, warning and rollback. |

**Current decision:** NOT READY FOR RELEASE.
