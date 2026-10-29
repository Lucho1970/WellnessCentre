# Full deployment build — 29 October 2026

This cumulative package includes the quality-gate fixes, pilot identity/delivery safeguards, bounded API input and MySQL 5.7 form corrections, plus all existing application code through source migrations 057. It builds both marketing sites, the shared clinic portal, central administration and the full private API. The generated manifest records the exact committed source, branch, lock hashes, tool versions, archive checksums and destinations. Packaging does not merge branches or change hosting, private settings, identities or database records.

## Upload map

Extract archive contents directly into these existing directories, including hidden `.htaccess` files. Do not add an enclosing release directory.

| Archive | Destination | Notes |
| --- | --- | --- |
| `wellness-api-private.zip` | `/wellness-api` | Full private API/src/bin/vendor. Preserve `.env`, attachments, runtime data and permissions. Never extract into a public web root. |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal` | Shared by both clinic hostnames; includes its thin `api/` entries. |
| `wellness-public.zip` | `/public_html/livinlively.ca` | Livin Lively marketing site. Also update its thin API entries from the next archive. |
| `wellness-api-public.zip` | `/public_html/livinlively.ca/api` | Thin entries only. Portal/central ZIPs already include these. Do not place them in Wellnexa's separate catalogue API directory. |
| `copihue-portal-landing.zip` | `/public_html/wellness-portal` | General landing, `admin/` and thin API entries. |
| `wellnexa-website.zip` | `/public_html/wellnexa.ca` | Independent Wellnexa marketing site and its own public catalogue/discovery/enquiry bridges. |
| `tuff-tar-mail-bridge.zip` | Existing separately configured mail-bridge host only | Optional existing integration. Not a clinic website; do not install or enable a new bridge merely because the ZIP is included. |

## Coordinated deployment

1. Back up the current database, application files and private settings. Record the current release and tested restore method. Pause booking/scheduling writes, mail/SMS dispatch, cron bridges and workers during the coordinated update. Keep old hashed assets through rollout.
2. Verify the hosted migration history against `sql-updates/`. It contains historical source migrations through **057**, not a single upgrade to run wholesale. Apply only verified unapplied migrations, in numeric order, using the existing migration process and separate migration credentials. Do not import `schema.sql`, reset the current database, re-run CREATE TABLE migrations or run `sql-maintenance` as an upgrade. If the host already has 057, these latest safeguards and form fixes require no new migration. Establish the hosted revision before choosing SQL files.
3. Deploy the matching private API, common portal, public sites, central administration and thin entries using the upload map. Keep private configuration and runtime files. Drain/restart all API, worker and bridge processes so old and new code/settings cannot overlap. Preserve both clinic host mappings; one currently active clinic does not require a database split.
4. Verify the private API `.env` against maintained feature documentation. This package contains no `.env` and does not enable pilot mode, mail/SMS, applications, new roles or provider permissions. Keep unapproved flows disabled. For tester restriction, follow `PILOT_SAFETY_CONTROLS.md`: approve the verified operator first, configure private `PILOT_*` identity/destination lists, restart processes, and run `php /wellness-api/bin/pilot-preflight.php --env /wellness-api/.env`. Empty delivery lists block app transport delivery only when pilot mode is enabled. Preflight success is configuration evidence only.
5. Keep sending paused until queues, sender/reply/calendar destinations, consent/STOP/budget controls and uncertain prior outcomes have been reviewed. Pilot-canceled rows must not be automatically replayed. Identity-provider registration/recovery emails and maps are outside the app's delivery guard.
6. Refresh HTML/CDN caches and browsers. Complete the synthetic hosted checks below before inviting testers. Restore writes/workers/channels deliberately after successful checks and the responsible release decision. Preserve backups, evidence, checksums and rollback access.

## Hosted acceptance

Use approved synthetic records and scoped test identities. The existing clinic database is not automatically synthetic; pilot approval does not hide records from staff who already have access. Avoid granting ordinary testers administrator access or linking them to real patients.

- Check both marketing sites, clinic `/availability` and `/client`, and central `/admin/`. Booking and authentication must retain the correct clinic hostname; same-origin `/api/v1` must return JSON.
- Test approved and unapproved sign-in, current role/clinic/record restrictions, revoked tester access, logout/account switching and shared-device cleanup. Confirm no administrator role is granted by pilot approval.
- On hosted MySQL 5.7, test assigned practitioner form reads, identical submission retries, changed-answer refusal and no duplicate submission/consent records. Test service import preview/apply/retry and normal branding/avatar/receipt workflows.
- Verify oversized API requests return safe 413 errors; preserve existing 64 KiB/1 MiB policies. The 2 MiB fallback is not a host rate, timeout, buffering or concurrency control.
- Verify headers, TLS, cache behavior, database privileges/transport, audit persistence, backups/restore, migration state, worker revocation and rollback on the intended host. Test allowed synthetic provider delivery only after its separate configuration/approval. Local fixtures do not verify actual providers or identity registrations.

## Rollback and limitations

Pause writes and delivery first. Restore the previous compatible API/frontend/worker files and private configuration together. Preserve audit/consent/provider history and unknown delivery outcomes. Do not automatically drop new tables, replay canceled notifications or restore an old database over newer records. If a schema/data restore is necessary, use the approved backup/restore plan with the accountable operator. Restoring the previous API can restore its unbounded requests and MySQL form defects; assess that risk before rollback.

Remaining privacy/session, infrastructure, vendor, recovery, accessibility and real-PHI approvals are recorded in `COMPLIANCE_AUDIT_2026-10-29.md` and the follow-ups. This build is supplied for the requested deployment preparation and hosted synthetic acceptance; it does not establish readiness for real patient data. No dependency versions, permission grants or external services are added by the build wrapper. Initial local validation and final archive inspection results are recorded in the accompanying build report.

## Rebuild

From clean committed source, restore each frontend with `npm ci`, audit both lockfile trees, then run:

```powershell
./scripts/build-current-deployment.ps1 -ReleaseName YOUR-UNIQUE-RELEASE-NAME
./scripts/verify-current-deployment.ps1 -ReleaseDirectory deployments/YOUR-UNIQUE-RELEASE-NAME
```

The established builder installs the private Composer lock with `--no-dev`. If the Windows CLI's already installed ZIP extension is disabled, the wrapper loads it through a temporary build-only scan directory and restores the process setting afterward; no global PHP configuration or deployed extension is changed. Production browser settings come from the existing private local build configuration; they are public identifiers/settings once compiled. Private API credentials and pilot allowlists must stay outside ZIPs. Archive hashes and inventory are in `manifest.json` and `release-files.json`. The verifier checks archive safety/checksums, runtime source and built-output equality, lock hashes and selected credential signatures; it does not replace hosted acceptance. Generated packages remain ignored by Git; build scripts and this guide are versioned.

RELEASE STATUS: NOT READY FOR RELEASE
