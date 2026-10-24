# Travel-enquiry inbox compatibility with MySQL 5.7

The live Wellnexa portal returned `internal_error` for `GET /api/v1/travel-enquiries`; its background unread-message request repeats every 60 seconds. Hosted schema inspection showed the expected travel and consent columns, and the database reported `5.7.44-log`.

## Cause and correction

Reproduced the production service query locally on official MySQL 5.7.44: `SQLSTATE[42S22]`, driver error 1054, `Unknown column 'e.clinic_id' in 'on clause'`. The unread-message subquery put outer enquiry references in an inner join condition. MySQL does not resolve those references there; MariaDB 11.4 accepted the same query. The engine difference explains why earlier MariaDB acceptance did not catch this defect. See [MySQL's explanation of the join scope](https://bugs.mysql.com/bug.php?id=15269).

Keep `author.id=m.sender_id` in the inner join and move the outer enquiry predicates into the subquery's `WHERE`. The list and unread count share this expression. Retain same-clinic authors, permitted sender types, patient ownership, practitioner scope, consent restrictions, read positions and pagination. No schema change or additional permission is required.

This reproduces a concrete cause consistent with the hosted failure. Hosted verification after installing the patch is still required; the original generic response did not expose the server's SQLSTATE.

## Validation

- New `api/tests/integration/travel-enquiry-inbox.php` fails before the correction on MySQL 5.7.44 with error 1054 and passes afterward: 11 inbox checks plus 74 inherited booking-authorization checks. Covers practitioner/administrator/patient lists, recipient restriction, clinic isolation, unrelated/foreign senders, read positions, pagination, denied roles and missing consent storage.
- The same focused suite passes on MariaDB 11.4.8.
- Existing `consent-travel-enquiries.php` HTTP chain passes on MariaDB 11.4.8: booking 74, access audit 28, consent 70, dashboard/schedule 59, notifications 61 and travel/message authorization 94 checks.
- An attempt to run the full inherited HTTP chain on MySQL stopped at an existing audit fixture assertion that compares JSON object key insertion order. MySQL stored `{id,type}` while the fixture expects `{type,id}`; the observed response was correctly 404/denied. That full chain is not reported as passing on MySQL. The new focused service suite does not depend on that assertion.
- 53 standalone API PHP scripts and changed PHP syntax checks pass. Both SQL runner PowerShell scripts parse; the new MySQL runner was exercised successfully.

Run the focused MySQL acceptance against an official extracted Windows MySQL 5.7 runtime:

```powershell
./scripts/test-mysql57-sql.ps1 -MySqlDirectory 'C:/path/to/mysql-5.7.44-winx64'
```

The runner initializes a new random directory under the operating system temporary folder, binds only to loopback on a free test port, does not read private `.env`, restores its environment and stops the server. It installs no service. Synthetic data/logs remain in its printed temporary directory. The MariaDB runner also accepts `-IntegrationTest travel-enquiry-inbox.php`.

## Deployment and rollback

The original source branch was `bugs/travel-enquiries-mysql57`. The correction is now carried forward onto `bugs/restore-travel-inbox-mysql57`, based on the merged service importer. A generated patch ZIP contains only `src/Service/TravelEnquiryService.php`; its adjacent release manifest records the committed source revision and file/archive hashes. Extract the ZIP into the private `/wellness-api` application directory, preserving the existing `.env` and all other files. Back up the previous service file before replacement. No frontend build, dependency update or SQL import is needed for this patch.

The `service-import-2026-10-21` full API archive was built from main without the earlier separate compatibility branch. It contains the affected query and can overwrite an earlier hosted hotfix. When deploying that historical archive, apply this patch afterward. Keep historical packages unchanged; future full releases should be built from a revision that includes this correction.

Build a new one-file patch from clean committed source:

```powershell
./scripts/build-travel-inbox-patch.ps1 -ReleaseName travel-inbox-mysql57-YYYY-MM-DD
```

After deployment, sign in to the Wellnexa portal with the affected account, open browser Network, and check that the initial and subsequent one-minute `/api/v1/travel-enquiries` requests return 200. Verify unread counts and clinic/role boundaries using synthetic records and confirm matching periodic PDO failures stop. If the same failure remains, verify the deployed file/OPcache and obtain privacy-safe SQLSTATE/driver diagnostics rather than logging SQL values or exception payloads. Restore the backed-up service file to roll back.

The patch has not been uploaded by this task. It does not update the hosting PHP runtime or establish that the separate PHP security fixes are installed.
