# Deployment build verification — 29 October 2026

Requested result: a cumulative build for manual deployment of the whole solution. Delivered artifacts are generated under `deployments/2026-10-29-full/`; the convenient outer ZIP is `deployments/2026-10-29-full.zip`. These generated files are ignored by Git. The maintained upload guide is [Current app release](CURRENT_APP_RELEASE_2026-10-29.md); it is also the package's `README.md`.

The package source is **`f738e22b1320aab5fd2c1647bbe7a719fcf7d116`**, branch `feature/full-deployment-build`. It includes the quality-gate, pilot guard and API/MySQL fix commits through `44e9421`; no merge to `main` or hosted deployment was performed. Subsequent archive-verifier/evidence edits do not change the packaged application source. The manifest records exact locks, build time, runtimes, seven ZIP hashes/counts and destinations. The final file inventory and bundle checksum are supplied with the generated build.

## Contents and configuration

The seven ZIPs contain Livin Lively public output, the common clinic portal, general portal/central administration, Wellnexa public output, the full private API with production Composer dependencies, thin public API entries and the existing optional mail bridge. Source SQL migrations through 057 and maintained rollout/rollback/privacy guides are included. The optional bridge is not required unless the existing installation uses it. The handoff also includes the unchanged normative `AGENTS.md` and reference guides under `documentation/` so the standard's relative links work, plus the email guide referenced by the optional bridge. Historical deployment guides retained by the base builder are references; use the current `README.md` for this release's upload map. Unzip the outer bundle locally and upload each inner archive to its documented target; do not put the whole bundle or private API under a public web root.

No dependency manifests/locks, runtime API/frontend code, database records/schema, permission grants, new endpoints, identity registrations, hosted environments or provider settings were changed by this build task. Existing production frontend configuration was used without printing values. The production build leaves invitation, practitioner-application and external-staff feature flags at their existing false settings; inclusion of their code does not enable them. Private API feature and `PILOT_*` settings are preserved on the host, not supplied in ZIPs. Do not infer pilot restriction is enabled after uploading files.

Local toolchain: Windows, Node 22.22.1, npm 11.19.0, PHP CLI 8.4.14, Composer 2.9.5 and Edge via Playwright. Restored frontend dependencies include React 19.2.8, Frontend Vite 6.4.5 / ESLint 10.13.0, and Wellnexa Vite 8.3.4. Exact complete dependency trees remain anchored by the lockfile hashes and private `vendor/composer/installed.json`; no versions were upgraded during this task.

## Validation ledger

Commands run from the indicated directory, with logs retained in ignored `.tmp/release-2026-10-29-*` files. Application tests use existing synthetic fixtures; no hosted identity, patient data or real provider send was used.

| Command/check | Result |
| --- | --- |
| Frontend and Wellnexa: `npm ci` | Both exact-lock restores passed; no lock changes. Frontend npm warned that esbuild 0.25.12's install script was not yet covered by its allowScripts list; actual production compilation succeeded with the installed platform binary. No install policy was weakened. |
| Frontend: `npm audit --json`; `npm audit --omit=dev --json` | Both passed, zero advisories. |
| Wellnexa: `npm audit --json` | Passed, zero advisories. |
| api: `composer validate --strict --no-ansi`; `composer audit --format=json --no-ansi` | Passed; no advisories/abandoned packages. |
| `./scripts/build-current-deployment.ps1 -ReleaseName 2026-10-29-full` | Passed: Frontend lint/content/2,521 translations/strict TypeScript/public/portal/central builds, private locked `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`, Wellnexa TypeScript/Vite build, all seven ZIPs and manifest/inventory. |
| Wellnexa: `npm run lint` | Exit 0 with four existing warnings: two React immutability warnings in App and two component-only-export warnings in catalogue Details. |
| Every root `api/tests/*.php`, individually with process `OPENSSL_CONF=C:/Program Files/Git/usr/ssl/openssl.cnf` | 59 scripts passed, including the 51 request-body HTTP boundary checks. |
| Recursive `php -l` in api/src, api/public, api/bin, api/tests and Wellnexa/public/api | 252 files passed. |
| Frontend first `npm test -- --reporter=line` | 284 passed, two existing optional private CSV/workbook cases skipped, one browser context teardown timeout in the customer Book handoff case; no assertion failure was reported for that case. This is a failed full run and is retained as such. |
| Frontend: `npx playwright test tests/customer.spec.ts --grep 'portal Book handoff preserves' --repeat-each=3 --workers=1 --reporter=line` | All three unchanged serial repeats passed. This alone does not erase the initial full-run failure. |
| Frontend confirmation `npm test -- --reporter=line` after compilation finished | 285 passed, two existing optional private-file cases skipped; exit 0 in 7.5 minutes. Across the final suites, 376 distinct cases passed plus two existing skips; the separately repeated handoff checks are not counted again. |
| Frontend: `npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 36 passed. |
| Frontend: `npx playwright test --config playwright.invitations.config.ts --reporter=line` | 7 passed. |
| Frontend: `npx playwright test --config playwright.applications.config.ts --reporter=line` | 9 passed. |
| Frontend: `npx playwright test --config playwright.build.config.ts --reporter=line` | 14 passed against the final production output. |
| Wellnexa: `npm run test:browser -- --reporter=line` | 24 passed. |
| Wellnexa: `npx playwright test --config playwright.applications.config.ts --reporter=line` | 1 passed. |
| Final private build stage: `composer check-platform-reqs --no-dev --no-ansi`; `composer audit --format=json --no-ansi` | Passed for the local runtime; zero advisories/abandoned packages. Does not establish the host's runtime/extensions. |
| Final private-stage autoloader/class checks and `php bin/pilot-preflight.php --env <temporary synthetic env>` | Packaged policy/request/form classes loaded; clean-process CLI preflight passed with one synthetic identity, empty destinations and no database/provider connection. An initial probe that manipulated process environment keys was rejected; no application code was changed to accommodate it. |
| `./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/2026-10-29-full` | 237 checks passed across seven ZIPs / 2,172 entries. Scanned 2,089 selected text/key/document entries. Verified archive checksums, safe/private-file exclusions, exact runtime source/build/lock hashes, canonical portal routing override and all thin public API copies. One reviewed public dependency documentation key fixture is classified below. |
| PowerShell parsing for the three changed/added build/verification scripts; `git diff --check` | Passed. |

The database-specific runtime files match the previously validated [API/MySQL source](API_REQUEST_BODY_LIMITS.md): 480 consent/import SQL/HTTP checks plus 145 recurrence/form checks on each of disposable MariaDB 11.4.8 and MySQL 5.7.44. Those database suites were not repeated for packaging-only edits. Their local results do not establish hosted database acceptance or permission/migration state. No ZIP has been tested on the intended host.

The initial browser teardown timeout's root cause is unproven. Compilation overlapped that first run; it was finished before the confirmation. No timeout, assertion, retry policy or test was weakened to obtain a pass. The first failed run remains in this ledger even if the full confirmation passes.

## Artifact review and initial failures

The first package attempt fell back to Composer source clones because the installed CLI ZIP extension was disabled. The wrapper now enables the existing bundled DLL only through a temporary build-process scan directory, restores the previous setting, and uses locked distribution archives. No global PHP configuration or deployment requirement was changed. Initial pre-correction drafts were moved into ignored `.tmp`; they are not the delivered build.

The archive guard initially rejected Dompdf's required `vendor/dompdf/php-font-lib/maps/*.map` encoding tables as source maps. Only that exact private runtime directory is now allowed; browser and other source maps remain excluded. An initial verifier comparison also detected the established portal `.htaccess` override; verification now checks it against `hosting/netfirms/portal/.htaccess`, with no routing change or skipped content check.

The credential signature scan identified **`vendor/firebase/php-jwt/README.md`**, the dependency's published “Example with RS256 (openssl)” documentation. Its sample signing key is public example material, is not application configuration and is not trusted by the API's issuer/JWKS validation. The verifier pins the entire reviewed README SHA-256 `438ada1aa84a29a2b0e938f8d3fdcfa298de6587675ae0b342347a2eeb99290d`; any changed file/signature is rejected. The private archive retains its LICENSE. No actual application credential or private environment file was found, and no key values were printed. This is a reviewed documentation fixture, not an exception authorizing production credentials in source.

Public browser identifiers and the existing browser API key are intentionally compiled as before; they are not server credentials, and actual hosted key restrictions remain unverified. PHP/vendor source belongs only in `/wellness-api`. The three React bundle `http://localhost` matches were inspected: router fallbacks are replaced with the browser's location origin. No `/@vite/client` match was found in the four built surfaces. Same-origin API compilation and final output/browser checks passed. This signature/content inspection is bounded and does not certify absence of every possible secret, endpoint, vulnerability or PHI; complete host/vendor/logging/privacy assessment remains open.

Existing large-chunk warnings remain (public about 1,051 kB, central administration about 1,532 kB, uncompressed). No size limits or lint rules were disabled. No hosted providers/identities, migrations, backup restoration, headers/TLS, session exception, full accessibility/load/penetration/licence review or accountable real-PHI release approvals were performed. The release owner and hosting/security/privacy owners must resolve those gates using synthetic records and the current rollout guide. Preserve the current database and private settings; verify unapplied migration history before executing any SQL. Rollback requires the compatible prior files/configuration and an approved data recovery plan, preserving new audit/consent/provider history.

## Versioned changes for this build task

| File | Purpose |
| --- | --- |
| `scripts/build-current-deployment.ps1` | Cumulative all-surface builder, temporary ZIP capability, private-file/source-map checks, seven-archive provenance and inventory. |
| `scripts/build-deployment.ps1` | Permit the current dated deployment guide in the established builder. |
| `scripts/verify-current-deployment.ps1` | Read-only archive/source/build/lock/signature verification with explicit runtime font-map and pinned public documentation handling. |
| `documentation/CURRENT_APP_RELEASE_2026-10-29.md` | Upload destinations, current SQL/configuration policy, coordinated deployment, synthetic hosted checks and rollback. |
| `documentation/DEPLOYMENT_BUILD_2026-10-29.md` | This validation/failure/limitations ledger. |
| `documentation/README.md` | Discover the maintained release guide and evidence. |

The ZIPs contain the requested cumulative code. Remaining hosted/privacy gates mean packaging success is not real-patient-data release approval.

RELEASE STATUS: NOT READY FOR RELEASE
