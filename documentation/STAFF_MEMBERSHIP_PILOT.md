# Selected staff membership pilot

This release still uses Entra sign-in and requires existing legacy staff provisioning. It does not introduce invitations or a new identity provider. The pilot adds membership enforcement for an explicit list of existing local user IDs only; all other accounts keep the legacy path. The global switch defaults off.

## Reconciliation evidence supplied on 5 October 2026

Owner's hosted screenshots show eight active staff, two complete Microsoft links, zero staff with multiple Microsoft links, six staff without Microsoft links, seven customer identities and five customer links. The two new tables are empty; full table definitions match migration 032, including ASCII binary identity columns, uniqueness constraints and foreign keys. One practitioner is testing without real clients; the owner intends to migrate her after the flow is ready. These facts do not establish which local ID is hers or verify live issuer/subject values.

Do not import the six unlinked accounts or customer records. Do not infer identity from email. Select the practitioner's existing `users.id`, confirm it has the intended Microsoft link, and leave at least one tested administrator outside the pilot list. Take a backup and rehearse in a test copy before applying.

## Backfill

The private CLI tool is dry-run by default. From the private API directory:

```text
php bin/backfill-staff-memberships.php --user-ids=LOCAL_USER_ID
php bin/backfill-staff-memberships.php --user-ids=LOCAL_USER_ID --apply --expected-count=1
```

Replace the placeholder with a verified positive integer, not an Entra object ID. Inspect the dry-run account IDs, clinic IDs and actions first. The apply command revalidates and locks selected source rows in one transaction; any conflict aborts the entire batch. It creates only exact tenant/object-ID identities and active memberships for active staff in active clinics. It leaves local grants and legacy records untouched. Existing matching active imports are skipped; inactive identities, revoked/pending memberships and changed bindings are never overwritten or reactivated. Concurrent constraint failures roll back; rerun the dry-run after investigating.

The tool prints local IDs and actions, not identity subjects or emails. It intentionally requires a recognized Entra GUID mapping; ambiguous tenant/subject values need review. The expected count confirms the selected-account count, not the count of newly inserted rows.

If Netfirms does not provide CLI/SSH execution, do not upload the CLI script under `public_html` or change it to a public runner. Use the separate [protected browser runner for Esther, local user 2](HOSTED_STAFF_BACKFILL.md); it defaults to dry-run and uses a private single-use review for apply.

## Deployment and activation

Deploy the matching private source files from this branch: `Config.php`, `Api.php`, `Auth/EntraAuthenticator.php`, `Auth/IdentityAdapter.php`, `Auth/VerifiedIdentity.php`, `Auth/StaffMembershipResolver.php`, `Service/StaffMembershipBackfill.php`, and private `bin/backfill-staff-memberships.php`. They include the previously undeployed foundation. Existing dependencies remain unchanged. Migration 032 has already been applied according to the owner; no new SQL migration is needed. Keep these settings disabled during upload:

```dotenv
STAFF_MEMBERSHIP_PILOT_ENABLED=false
STAFF_MEMBERSHIP_PILOT_USER_IDS=
```

After rehearsal, reviewed backfill and tested recovery-admin sign-in, set the selected local ID and deliberately enable the pilot. Verify `/api/v1/me`, practitioner actions, wrong-host denial, role intersection and revoked/missing membership denial. A failed pilot membership never falls back to legacy access. Sessions are not new application grants; membership state is checked on every authenticated staff request.

The pilot verifies a new membership resolves to the same existing local account. It cannot yet enable one identity to access another clinic: the legacy mapping still bounds this pilot. Customer sign-in, customer links and runtime public settings are unchanged.

## Revocation and rollback

Use local account deactivation as the compatibility-safe revocation mechanism while two paths coexist. Do not turn the pilot off for a membership-revoked user whose legacy account is still active: that would restore legacy access. Before rolling back, deactivate locally any user revoked through membership-only changes, verify denial, then disable the switch. Preserve all imported rows and versions for review; do not delete or recreate memberships to bypass revocation.

## Validation limits

Hosted owner report, 5 October 2026: Esther (local user 2, clinic 1) was imported with the protected runner; the runner was removed and its flags disabled. Recovery administrator login works, and Esther can sign in with her existing workforce account and reports her usual workflow working. This establishes basic hosted sign-in acceptance, while explicit wrong-host, revocation and real database concurrency acceptance remain outstanding. The next disabled source slice is documented in [Practitioner invitation pilot](PRACTITIONER_INVITATIONS.md).

Signed-token tests cover disabled/enabled pilot selection, nonpilot legacy access, denied membership without fallback and local binding mismatch. Candidate tests reject ambiguous/inactive/wrong-provider data. PDO-double tests exercise dry-run, whole-batch rollback, idempotency and no reactivation. Real MySQL transaction, constraint, idempotency and concurrent backfill checks still require the disposable database rehearsal; unit tests do not establish those hosted results. No code or backfill has been deployed or executed by the agent.
