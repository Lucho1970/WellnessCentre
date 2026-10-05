# Practitioner beta readiness audit — 30 September 2026

This is evidence for a **limited single-clinic development pilot**, not production-launch approval. Do not use real clinical histories, billing details, or unapproved client records in the test kit.

| Gate | Evidence checked | Current status |
| --- | --- | --- |
| Portal/public release shell | Live `portal.copihue.ca` served the same entry script as `deployments/pilot-workspace-preference-2026-09-30/wellness-portal.zip` and the practitioner page returned its profile-specific metadata. The public-site entry script matched the preceding package. | Current portal package is live; private API build parity and the signed-in workspace-switch journey still need hosted evidence. |
| API availability | Portal API health, portal database health, and public-site API health returned HTTP 200. | Pass for the check time only; not a booking or authorization test. |
| Local PHP policies | All 36 non-integration, non-hosted PHP tests passed after setting this Windows machine's `OPENSSL_CONF` to the Git OpenSSL configuration. The Azure scheduler test fixture was updated to model scheduler-health calls; production scheduler code was not changed. | Pass locally. |
| Browser journeys | The initial two-worker run passed 98/99; the unknown-page test briefly rendered the application-startup error and passed in isolation. A one-worker run also passed 98/99 and exposed a workspace-preference click/navigation race. The preference is now saved synchronously on switch. The adjusted frontend passed all 99 Playwright tests with two workers and its production build completed. | Pass locally. Keep the earlier transient startup error under observation during hosted testing. |
| Hosted MySQL booking race | Owner reports that **Run complete suite** displayed all checks passing on 30 September 2026, including the booking-race cases. The result page, four case details, synthetic clinic ID, and database/server versions were not saved. The temporary `/api/test-suite.php` URL returned 404 afterward. | Owner-observed pass, not independently reproducible from saved evidence. Do not rerun solely for a screenshot; capture the details during the next planned acceptance run. |
| Authenticated hosted roles and messaging | Prior owner testing reported successful booking, email and SMS flows. This audit did not sign in as a client or staff member, create an appointment, inspect private notifications, or check another user's access. | Open for a recorded pilot acceptance run. |
| Database backup, migrations and recovery | The repository-root `wellness_centre.sql` is dated 24 September 2026; live database contents and migrations were not independently inspected. | Create and verify a fresh backup before rerunning the hosted suite or inviting testers. |

## Next acceptance actions

1. Verify a dual-role account's workspace choice after navigation and a fresh session. The portal archive is live; no API or public-site archive replacement was needed for this small UI fix.
2. Confirm a fresh development-database backup and the release/migrations intended for the pilot. The owner reports a passing [hosted test suite](HOSTED_TEST_SUITE.md) run, but the detailed result was not retained. Keep the temporary public endpoint absent (404); save the four race cases, synthetic clinic ID, and MySQL/server versions at the next planned acceptance run, without storing the secret.
3. Use controlled accounts and synthetic appointments for the [pilot journeys](PILOT_TEST_READINESS.md). Record client self-booking/change/cancellation, practitioner-scoped appointment access, admin/reception booking, a narrow viewport, expired-session recovery, and email/SMS provider status versus actual receipt. Record failures with a correlation ID where available.
4. Review any failure as a release gate. The race result is owner-observed rather than backed by a saved report; complete the hosted role-boundary checks before widening the pilot. Do not treat a provider's `accepted` state as proof of inbox or handset delivery.

The temporary hosted suite writes synthetic data to the current development database. Its runner and secret are not part of the ordinary deployment archive; keep the private test files outside `public_html` and remove the one public entry point after the run.
