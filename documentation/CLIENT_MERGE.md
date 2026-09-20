# Client duplicate prevention and merge

This release adds conservative duplicate handling to the staff client directory. It does not automatically merge people based on names, dates of birth, phone numbers, or email addresses.

## Behaviour

- Creating a client with the same normalized first and last name as an existing client returns a possible-duplicate warning. Staff can review the candidates and deliberately choose **Create anyway** when they are different people.
- Every client email is recorded in `client_email_addresses`. Changing a primary email preserves the old address as an alias, and directory searches include aliases.
- Only a `super_admin` can merge records. The API enforces this independently of the portal.
- The merge preview shows the survivor, duplicate, relationship counts, and customer-sign-in conflicts.
- The administrator chooses the primary email, profile details, and service address to retain; enters a reason; and types the exact confirmation phrase.
- The merge runs in one InnoDB transaction with both client rows locked and revision tokens checked.
- Appointments, recurring series, waitlist entries, forms, practitioner notes, invoices, consents, export requests, invitations, appointment attendance, and the compatible customer identity link are reassigned.
- Both email addresses become aliases of the survivor. The duplicate `users` row remains as an inactive historical record with a non-routable tombstone email. It is not deleted.
- Merged duplicate rows are excluded from the staff client directory, booking selectors, and possible-duplicate suggestions. Ordinary inactive clients remain visible in the administrative directory.
- Audit authorship and other historical actor fields are not rewritten.
- If both records are linked to different customer identities, the merge is blocked. Identity ownership must be resolved manually before retrying.

## Database deployment

Back up the database, then run `api/database/migrations/006_client_merge.sql` once before deploying the matching API and portal. It is additive and compatible with MySQL 5.7. It creates:

- `client_email_addresses`
- `client_merge_records`

The migration backfills current client primary emails. It does not merge or deactivate any client.

Deploy order:

1. Back up and verify that the backup can be restored.
2. Apply migration 006 and confirm both tables exist.
3. Deploy the private API and portal from the same release.
4. Search for an existing client by their primary and alternate email.
5. Use synthetic records to preview and complete one merge before handling live duplicates.

Code rollback is safe while the two additive tables remain. A completed merge is a business transaction, not a schema change to reverse manually. Restore from backup or perform a reviewed forward correction if the wrong records were merged.

## Validation

- `php api/tests/clients.php`
- `php api/tests/integration/client-merge.php` against the documented local MariaDB test port
- `npm run build` in `Frontend`
- `npm test` in `Frontend`

The database integration test creates and retains a random synthetic scratch database for inspection. It never loads the application `.env`.
