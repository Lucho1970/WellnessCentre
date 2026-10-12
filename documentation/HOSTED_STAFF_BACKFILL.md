# Temporary browser backfill for Esther (local user 2)

No SSH is required. This runner uses the private backfill service and is restricted to the owner-confirmed local user ID 2. It does not change the identity provider, local grants, client records or practitioner record. Membership login must remain disabled throughout. No extra SQL migration is needed after migration 032.

## Files to upload

For the dry-run, only upload:

| Local source | Netfirms destination |
| --- | --- |
| `api/src/Service/StaffMembershipBackfill.php` | `/wellness-api/src/Service/StaffMembershipBackfill.php` |
| `api/tests/hosted/staff-membership-backfill.php` | `/public_html/willow-wellness-portal/api/staff-membership-backfill.php` |

Keep the public filename unchanged. Confirm Willow's actual domain document root if it differs; the runner belongs beside the existing public API `index.php`. The current live `Config.php` is sufficient for the dry-run; full pilot login code need not be deployed yet. The runner also supports the newer Config and refuses to run while its pilot switch is on.

## Private configuration

In the shared `/wellness-api/.env`:

```dotenv
HOSTED_STAFF_BACKFILL_ENABLED=true
HOSTED_STAFF_BACKFILL_APPLY_ENABLED=false
HOSTED_STAFF_BACKFILL_SECRET=replace-with-a-new-random-secret-at-least-32-characters
STAFF_MEMBERSHIP_PILOT_ENABLED=false
STAFF_MEMBERSHIP_PILOT_USER_IDS=
```

Generate the secret privately, for example with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Do not send it in chat, put it in a URL or reuse the former IP-test secret.

Open `https://willowwellness.copihue.ca/api/staff-membership-backfill.php`, enter the secret and click **Run dry-run for user 2**. Send the displayed account/clinic/action report for review. Expected: exactly user 2, the verified Willow clinic, and `would_import` (or `already_imported` on a later run). Conflicts stop the operation; do not repair them by matching email.

## Apply after reviewed report

Back up the database and rehearse on a disposable copy first. After review, set only `HOSTED_STAFF_BACKFILL_APPLY_ENABLED=true`, then run a new dry-run. Its confirmation form requires the secret again, a backup/user acknowledgement, and the exact text `IMPORT USER 2`. A private single-use review expires after 15 minutes. Apply revalidates the exact identity binding under database locks; changed sources, conflicts, revoked records, duplicate identities and replayed reviews are rejected. The backfill remains atomic. An unsuccessful apply consumes the review; run a new dry-run after fixing the cause.

This protects the import review; it does not bypass normal Entra roles, enable pilot login or constitute evidence of full multi-clinic isolation. Existing matching active imports are idempotent. Other staff and all customer links stay untouched.

After completion, set both `HOSTED_STAFF_BACKFILL_*_ENABLED` flags false, remove the secret and delete the public `staff-membership-backfill.php` file. Private review files in `/wellness-api/var/staff-backfill` contain no raw identity subjects; expired files are cleaned on subsequent dry-runs and may be removed after the runner is disabled.

Then follow [Staff membership pilot](STAFF_MEMBERSHIP_PILOT.md) to deploy the matching pilot source and deliberately activate user 2 only, retaining an administrator outside the pilot. Do not enable pilot login before reviewed import, database rehearsal and recovery-login checks.
