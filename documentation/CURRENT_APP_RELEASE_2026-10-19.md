# Current application release — 19 October 2026

This release includes the cumulative access-audit and consent changes through recurring availability/one-time overrides, plus the Livin Lively marketing site's corrected `https://livinlively.copihue.ca/` portal destination. It is built from the `bugs/livinlively-portal-release` branch; the generated manifest records the exact source revision and archive hashes. No branch merge or hosted deployment is performed by the build.

## Apply the release

1. Back up the database and affected hosted files. Pause scheduling writes and notification workers; drain old API/worker processes during the coordinated update.
2. Check the hosted migration history. Apply only unapplied migrations, in numeric order, through **050**. In particular, `sql-updates/049_access_audit_events.sql` must precede `sql-updates/050_consent_directives.sql`, and both must exist before the new API runs. These CREATE TABLE migrations are not safe to rerun blindly. Do not import every historical SQL or maintenance script. If hosted history is uncertain, establish the current schema revision first.
3. Extract `wellness-api-private.zip` into **`/wellness-api`**. Preserve its private `.env`, attachments, runtime files and permissions. The archive intentionally contains no private environment file.
4. Extract `wellness-common-portal.zip` into **`/public_html/wellness-common-portal`**, including hidden `.htaccess`. Both clinic portal hostnames use this directory. Extract `wellness-public.zip` into **`/public_html/livinlively.ca`**. Extract `copihue-portal-landing.zip` into **`/public_html/wellness-portal`** for the general landing/application administration. Extract the optional thin `wellness-api-public.zip` into the existing Livin Lively marketing site's **`api/`** directory; the shared portal and landing archives already include those entries. Keep old hashed assets until rollout completes.
5. The generated mail-bridge archive is not needed for this URL/consent release. The independent Wellnexa marketing site is unchanged; its shared portal and API receive the updates above. Do not overwrite its separate catalogue bridge with the general API entry points.
6. Verify hosted routing maps `livinlively.copihue.ca` to the active original clinic and retains the Wellnexa clinic's own hostname. Verify required callback/logout registrations, HTTPS and host mappings. The private original-clinic fallback `CLIENT_PORTAL_URL` should be `https://livinlively.copihue.ca/client`; changing `.env.example` locally does not update the server. Retain correct active clinic IDs and other private settings.
7. Restore service after the matching schema, API and frontend are in place. Refresh in-progress coverage proofs. Clear HTML/CDN caches and hard-refresh browsers; then verify both clinic portals. Avoid mixed old/new API or worker versions.

## Hosted checks

Open `https://livinlively.ca/` in a fresh browser. Sign-in, booking and practitioner booking links must lead to `https://livinlively.copihue.ca/`; subsequent booking and authentication callbacks must stay on that clinic's hostname. Confirm same-origin `/api/v1` returns JSON. Test staff and customer sign-in separately, and verify Wellnexa still uses its own clinic context.

Use synthetic records to verify permitted and restricted reads, searches, booking contacts, notifications, messages, address approval/revocation, time off, recurring rules and overrides. Confirm denied schedule changes leave records unchanged and audits are recorded. Verify database locking/isolation and notification-worker behavior on the intended host. Follow the detailed consent documents in `documentation/README.md` and the [deployment layout](DEPLOYMENT_LAYOUT.md).

The release remains subject to hosted acceptance and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md). Packaging these changes does not approve real-patient-data use. Keep schema/data backups for rollback; do not drop audit/consent history or attempt an automatic schema downgrade. Restore a compatible application/schema combination under the release rollback plan.
