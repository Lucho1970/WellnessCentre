# Local full-stack Docker rehearsal

This is a **synthetic-only**, HTTP-only local test stack. It runs MySQL 5.7, the current PHP API, the clinic portal, central Admin shell, Wellnexa public site and Livin Lively public site. It does not reproduce Netfirms, HTTPS, identity-provider redirect registration, provider delivery, workers or real-data protections. Do not use a real patient/staff export here; the host's D: drive is currently unencrypted.

The database has no published port and uses temporary Docker storage. MySQL and PHP share an internal Docker network with no direct outbound route. The web proxy also joins an edge network and publishes only `127.0.0.1` ports. The API mounts `src`, `public` and the lockfile-installed `vendor` directory read-only; it does **not** mount `api/.env`. Browser builds mount only generated `dist` directories. Local generated settings belong in ignored `.tmp/docker-test/`.

## Start

From the repository root in PowerShell, run the launcher with an export that the owner confirms contains **only synthetic rows**. It uses `npm ci` and `composer install` from the lockfiles if dependencies are absent, builds both web projects with process-scoped local/disabled-identity settings, creates random local-only settings in ignored `.tmp/docker-test/compose.env`, starts Docker and imports the export:

```powershell
./docker/local/start.ps1 -BackupPath 'D:\Dev\WellnessCentre-backup\database\wellness_centre.sql.gz' -ConfirmSynthetic
```

The importer refuses a populated database, a published DB port, a non-temporary DB mount, a wrong Docker project, or a backup stored inside the repository. It streams the export to the isolated test database, applies migrations 059–064, and prints object counts only. Its confirmation switch is an owner attestation that **every row** in the export is synthetic. It does not sanitize data. Re-running it requires a new empty database; `docker compose down` destroys the temporary copy.

| Surface | Local URL |
| --- | --- |
| Clinic portal | <http://localhost:18080/> |
| Wellnexa public site | <http://localhost:18081/> |
| Central Admin shell | <http://localhost:18082/admin/> |
| Livin Lively public site | <http://localhost:18083/> |

All four sites use the same local PHP API at `/api/v1`. The Wellnexa public site's `/api/public/*` routes are mapped to the API's public routes for this rehearsal. Its hardcoded hosted image/link destinations and the host-specific thin PHP entry pages are **not** reproduced. Do not click live sign-in or external links during isolated testing. Browser identity flows need a separately approved redirect URI and identity-provider setup; protected API routes should deny requests without a valid bearer.

Run the HTTP and built-page smoke checks with Python 3 and installed Microsoft Edge:

```powershell
python docker/local/http-smoke.py
Push-Location Frontend
node local-docker-smoke.mjs
Pop-Location
```

The HTTP script checks public/database-backed reads and protected-route denials without printing records. The browser script checks that each shell renders React content, rather than merely returning HTTP 200. Neither exercises authentication or care workflows.

Stop and remove the temporary database and containers with:

```powershell
docker compose --env-file .tmp/docker-test/compose.env -f docker/local/compose.yaml down
```

Stopping/restarting Docker Desktop can also lose the temporary database. The source export remains outside the repository and is the only recovery copy for this local rehearsal.
