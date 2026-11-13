# Unified sign-in hosted release preparation — 13 November 2026

## Scope and source

`feature/unified-signin-hosted-release` starts at `bugs/booking-email-promise-live` (`4936e18`), whose source produced the booking-copy `index.html` currently served by both shared clinic hosts. It adds the guest sign-in chooser from `feature/unified-portal-sign-in` (`1910821`) and its test notes. Relative to that hosted source, the runtime change is limited to the shared portal guest header and English/French chooser copy. The newer practitioner-governance UI on `main` is not in this candidate. No PHP, SQL, provider registration, runtime dependency, role, consent, mail/SMS configuration or external endpoint changes are included.

The intended package contains **only** `index.html` and content-addressed files under `assets/`, copied additively to `/public_html/wellness-common-portal/`. Both `wellnexa.copihue.ca` and `livinlively.copihue.ca` serve that folder. Do not upload `.htaccess`, `api/`, PHP, private `.env`, source maps or the entire repository. Keep the hosted booking-copy wording. The release build must retain the existing practitioner application, external authentication and Google/personal Microsoft sign-in flags, while invitations and server sessions stay disabled. The four public External ID identifiers must match the 5 November corrected pilot manifest. The existing production frontend `.env.production` supplies the other public identifiers/URLs. Mail/SMS and pilot admission remain controlled by the unchanged private API settings.

## Local evidence and open gates

- `Frontend/npm ci`: passed, 350 lockfile packages, Node 22.22.1 and npm 11.19.0. Existing `esbuild@0.25.12` install-script warning.
- `npm audit --json`: ten moderate development-tool advisories, no high/critical; `npm audit --omit=dev --json`: zero runtime advisories. No dependency change or forced fix.
- `npm run build`: passed ESLint 10.13.0, four bilingual content entries, 2,579 translation keys, TypeScript 5.7.2 and all three Vite 6.4.5 builds. Existing large-chunk and central mixed-import warnings remain. The first portal build omitted the four dedicated practitioner public identifiers and was **discarded before packaging**.
- Rebuilt `npm run build:portal` with the pilot manifest's four identifiers and exact enabled/disabled flags: passed. Bundle scan found all four identifiers, the release marker and chooser copy; found no local Docker URL, source map or private file. The narrow package selection has 69 files (`index.html` and 68 assets); the other three generated root files are excluded. The local Docker build was restored after the candidate build.
- `npx playwright test --config playwright.applications.config.ts --workers=1 --reporter=line`: 25 passed.
- `BUILD_PRACTITIONER_SIGN_IN=1 npx playwright test --config playwright.build.config.ts --reporter=line`: 17 passed against the corrected candidate. Includes the one-button chooser and separate practitioner/workforce routes.
- `npm test -- --workers=1 --reporter=line`: 298 passed, two skipped in 16.8 minutes. The default `portal.spec.ts` file was slow (11.4 minutes); no failure or timeout. Dedicated central Admin/invitation suites, PHP/SQL, manual screen-reader and 200% zoom audit were not repeated for this frontend-only candidate. No hosted authentication acceptance has yet occurred.

Both live hosts served the exact previous `booking-email-copy-2026-11-12` shell on 13 November (SHA-256 `785efb6dbccf372663b8b18c8d07fa51c86ada81c0f627b853f82c08b12734ba`). A verified TLS `curl` request and Git OpenSSL chain check succeeded for each host; the wildcard certificate is valid through 13 January 2027. Python's local trust path reported an expired certificate, but that was not reproduced by the system TLS clients. Recheck TLS from a normal browser before upload. The current live `index.html` is backed up privately at `D:\Dev\WellnessCentre-backup\unified-signin-2026-11-13\live-index-before.html`. The saved shell matches the previous release ZIP. The five-minute HSTS trial remains; the shared shell still lacks CSP, nosniff and referrer headers. These unrelated SEC-03 release gaps are not fixed by a portal asset upload.

## Controlled upload and rollback

After the source commit and artifact manifest/hash review, obtain a new explicit REL-05 production decision. First upload **only new `assets/` files** additively, without mirror-deleting existing assets. Verify every candidate asset returns HTTP 200 on both clinic hosts. Compare the live `index.html` hash with the saved prior hash immediately before replacing it. Then replace only `/public_html/wellness-common-portal/index.html`. Verify the exact new shell hash and cache/header response on both hosts, and run fresh English/French guest and entry-route smoke checks. Do not change API/private settings, test-account access or clinic roles as part of this upload.

If either host's shell or sign-in fails, restore `live-index-before.html` to that one shared `index.html` path. Leave added content-addressed assets in place pending separate cleanup. Recheck both hosts and their routes after rollback. The release owner is Luis Duran; operations/monitoring and incident contact must be confirmed at action time. Custodian/privacy, security/operations, clinical and release-owner decisions remain separate from a successful build. This candidate is for the existing synthetic, pilot-restricted hosted test; it does not authorize real PHI intake or broaden tester access.

## Hosted acceptance sequence

1. In a clean browser on each host, verify one guest **Log in** button, the correct clinic name, three readable English/French choices, keyboard Escape/focus return and narrow layout.
2. On Wellnexa, choose **Client** and use the previously allowed synthetic client identity. Verify the client entry/workspace and a safe sign-out. Do not create a new booking merely to test navigation.
3. Choose **Practitioner** and use the existing personal Microsoft test identity. Its current Inactive membership and revoked pilot access should deny entry; a positive dashboard test requires a **separate, recorded temporary access decision**, followed by Inactive/revoked cleanup.
4. Choose **Clinic staff** and have the owner sign in with the organization workforce account. Verify that it uses the workforce route and does not consume the personal practitioner session.
5. Recheck both hosts' shell/asset hashes, console errors, API-denial behavior, existing booking-copy wording and rollback readiness. Record any real identity-provider/cookie failures with short references only; do not place tokens or personal records in Git.

The 38-control ledger in `UNIFIED_PORTAL_SIGN_IN_2026-11-13.md` remains the scoped status register. In particular, REL-03 hosted acceptance, REL-05 owner approvals and project-wide GOV/PRIV/AUTH/SEC gaps remain open until separately evidenced. A successful synthetic login is not a real-data release decision.

RELEASE STATUS: NOT READY FOR RELEASE
