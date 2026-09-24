# Temporary hosted test suite

This is a **development-only** acceptance aid for Netfirms. It is not a production health endpoint or a substitute for human browser tests. The normal release archives intentionally exclude the test runner. The test kit archive keeps all tools under the private `/wellness-api` directory except for one temporary public entry point.

## Scope

- 21 existing PHP fixture/policy tests: booking validation, schedules and DST, mobile visits, client/onboarding authorization, calendar/email formatting, notification status, API preflight, and related rules. They do not read or change the current clinic's records.
- A read-only check of the configured MySQL connection and six required tables.
- Eight HTTPS GET smoke checks against the deployed API. They verify HTTP 200 and the API JSON envelope, not the correctness of every item returned.
- One four-scenario concurrent booking test. It **writes** a separate synthetic clinic, users, rooms, services, appointments, history, and notifications in the current development database. The test then deactivates that clinic and cancels its pending notifications. It leaves the records for inspection; it does not delete them or touch an existing clinic.

The page reports each case independently and continues after a failure. Frontend Playwright tests, Microsoft/Google login, real email delivery, and three existing integration tests that create whole scratch databases are **not** run here. Those remain separate acceptance work; a green hosted page is not a claim that every user journey passes.

## Build and deploy

1. Back up the **development** database. From the repository root, run `powershell -ExecutionPolicy Bypass -File scripts/build-hosted-test-kit.ps1 -ReleaseName hosted-test-kit-YYYY-MM-DD` or use the checked-in `hosted-test-kit.zip` if one is supplied with the release.
2. Extract the zip by its folder structure: `wellness-api/tests/` and `wellness-api/deploy/`/`wellness-api/hosting/` go under the private `/wellness-api` directory; its only public file goes to `/public_html/wellness/api/test-suite.php`. The zip has no `.env`, vendor libraries, application source, or frontend build. Do not extract the `wellness-api` folder inside an existing `/wellness-api` folder; merge its contents there.
3. Confirm the existing private `/wellness-api/vendor/autoload.php` and application `.env` are present. Add these temporary lines to that private `.env`, substituting your exact development database name and a newly generated random secret of at least 32 characters:

   ```ini
   HOSTED_TEST_ENABLED=true
   HOSTED_TEST_DB_ACK=<exact value of DB_NAME>
   HOSTED_TEST_SECRET=<new random secret>
   HOSTED_TEST_API_BASE=https://wellness.copihue.ca/api
   ```

   The acknowledgement must match `DB_NAME` exactly. Do not send the secret or database credentials in chat, commit them, or put them in a URL. If the web PHP binary cannot launch CLI scripts, set `HOSTED_TEST_PHP_CLI` to the absolute PHP **CLI** executable on Netfirms. If CLI execution is unavailable, most fixture checks run one per web request. Three CLI-dependent fixture checks are reported as **skipped**. The booking race instead uses two signed, concurrent HTTPS callbacks to this same temporary endpoint; it does not send database credentials or the test secret in the URL or request body.
4. Visit `https://wellness.copihue.ca/api/test-suite.php` over HTTPS. Enter the secret once. Use **Run non-booking checks** first, then select the backup confirmation and **Run complete suite** to include the synthetic booking race. Each case runs in a separate request so earlier results remain visible if a later case fails. Record the page results and any synthetic clinic ID. Do not press Run again while a request is still in progress.
5. When the review is over, set `HOSTED_TEST_ENABLED=false`, remove the other `HOSTED_TEST_*` settings, and **delete `/public_html/wellness/api/test-suite.php`**. You may leave `/wellness-api/tests`, `/wellness-api/deploy`, and `/wellness-api/hosting` in private storage for future testing. Verify that the public URL now returns 404. The private test files must never be served directly by the web server.

Only the named `test-suite.php` file should be exposed publicly. Do not copy the private `tests` tree into `public_html`. If the race test fails or times out, inspect synthetic clinics named `Synthetic booking race ...` and their notification statuses before re-running; do not delete unrelated records. See [booking validation](BOOKING_VALIDATION_TESTS.md) and the [read-only overlap audit](../api/database/maintenance/audit_booking_overlaps.sql) for follow-up.

If the PHP error log reports a host restriction or a missing test file, fix the deployment or CLI path and re-run that case. No SQL migration is required for this kit.
