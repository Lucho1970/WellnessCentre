# Local synthetic full-stack Docker rehearsal — 11 November 2026

**Source:** `deccccd24a5e31a78359b9000eb63da7e69874a6` on `main`; harness developed on `feature/local-fullstack-docker-test`. This is a local infrastructure/test addition only. Production files, schema, feature flags, providers, permissions, identity registrations and private settings were unchanged. The custodian confirmed that the 11 November export contains only synthetic data. The prior [restore-only rehearsal](PRACTITIONER_DOCKER_RESTORE_REHEARSAL_2026-11-11.md) recorded its SHA-256 and object counts.

## What runs

The [local stack instructions](../docker/local/README.md) launch pinned MySQL 5.7.44, a PHP 8.4 Apache API (tested 8.4.27), an Nginx 1.25.5 web proxy, and host-built portal, central Admin, Wellnexa and Livin Lively assets. The DB and API have no published ports and sit on an internal Docker network. Only the web proxy publishes ports 18080–18083 on IPv4 loopback. The API mounts only source, public entry and the local lockfile-installed vendor tree; no private `.env` is mounted or copied into an image. The API build context ignores every application file. Random local-only settings are ignored under `.tmp/docker-test/`.

The launcher builds with local browser URLs and disables identity, intake, browser sessions, mail, SMS and automated communications. It then streams the synthetic export into temporary MySQL storage and applies migrations 059–064. This allows real public HTTP and database reads and negative protected-route tests. It is not a substitute for the hosted Netfirms thin entries, TLS, external login redirects or provider/worker tests. Some website links and image paths still name hosted destinations; do not follow them during this local rehearsal.

## Validation evidence

| Check | Result |
| --- | --- |
| `./docker/local/start.ps1 -BackupPath <synthetic-export> -ConfirmSynthetic` | Final clean run passed: both builds, container startup, export restore, migrations 059–064; 162 tables and 29 triggers. First run exposed a MySQL entrypoint readiness race after Compose health; importer now waits for the target schema, and the clean rerun passed. |
| `docker compose ... config --quiet`, `nginx -t`, PHP `-l` of the API entry, PowerShell/Python syntax parses, `git diff --check` | Passed. |
| `python docker/local/http-smoke.py` | 14/14: five HTML shells, runtime config, API and DB health, two clinic configs, Wellnexa public catalogue, 401 unauthenticated calendar denial and 404 unmapped Admin host. No response records printed. |
| `node Frontend/local-docker-smoke.mjs` using installed Edge | 5/5 built browser shells rendered actual content with zero page errors. The first iteration detected that a generic HTTP 200 was serving the startup-error page; the proxy now routes the PHP runtime config correctly and the smoke check rejects that error state. |
| `Frontend/npm run build` | Passed ESLint, 4 bilingual content entries, 2,679 bilingual UI keys, strict TypeScript and all three builds. Existing large-chunk and mixed-import warnings remain. |
| `Wellnexa/npm run lint`, `npm run build`, `npm run test:browser -- --workers=1 --reporter=line` | Exit 0; lint retained four React warnings; build passed; browser suite 27/27 passed. |
| `Frontend/npx playwright test --config playwright.build.config.ts --workers=1 --reporter=line` | Initial run on the deliberately localhost public artifact passed 15/16; its artifact assertion expected the hosted Livin Lively URL. After rebuilding that artifact with its expected hosted URL, the full suite passed 16/16. The public artifact was then rebuilt for localhost, and the five Docker browser smokes passed again. The initial failure is retained as evidence of profile coupling. |
| Docker network/port inspection | DB and API attached only to the internal network with no published ports; web attached to internal and edge networks with only loopback host bindings. |
| Import safety guards | Launcher refused to run without `-ConfirmSynthetic`; importer refused a second import into the populated 162-table database. Both expected denials exited nonzero without changing it. |

No API PHP service code, TypeScript product code, npm/Composer dependency manifest or lockfile was changed. The local images are test infrastructure, not a release package. The existing synthetic export stays outside Git and outside images. Database contents are temporary and disappear on `docker compose down` or some Docker restarts; do not treat the container as a backup. The host's D: drive is unencrypted, so real personal or health records are out of scope.

## Remaining gates and control impact

The [38-control governance ledger](PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md#change-scoped-38-control-ledger) remains applicable. This is a scoped dated update; all controls not named below retain their baseline status and owner action.

| Control | Current status for this harness / remaining action |
| --- | --- |
| GOV-05, SEC-01, SEC-02 | Partial: custodian-attested synthetic data only, no dump/secret committed, temporary DB storage and internal API/DB network. Host backup encryption, real-data gate, TLS/at-rest/restore evidence and approved storage remain open. |
| AUTH-01–05 | Unverified for real identity: no redirect URI, JWT, MFA or session acceptance performed. The API denied an unauthenticated protected route; this does not prove role, record, stale-principal or cross-clinic authorization. No new permission was granted. |
| PRIV-01–07, SEC-03–09 | Unchanged from the baseline: local public pages and reads work; the rehearsal does not establish consent, rights, retention, breach, HTTPS/security headers, external-flow or provider compliance. Mail/SMS and automated dispatch were disabled. |
| DEV-01–06 | Partial: focused, versioned harness; builds and browser checks above. Clean dependency restores, PHP policy/SQL integration suite reruns, manual accessibility, representative load, full dependency audit and all dedicated browser configs remain outside this slice. Existing bundle/lint warnings need their normal owner review. |
| REL-01–06 | Partial/Missing as in baseline: local synthetic HTTP/browser proof improved. No final deploy artifact, approved staging/hosted identity acceptance, rollback with prior application artifact, production approvals or real-data release decision. Stop/remove the temporary stack with `docker compose down`; restore the synthetic test copy from the private export if needed. |

Next, test representative authenticated workflows in an approved environment with registered redirects and separate synthetic identities, then rehearse the prior-application rollback on the migrated schema. A production schema/deployment decision must review the exact artifact and migrations separately.

RELEASE STATUS: NOT READY FOR RELEASE
