# Client management — deployment and acceptance

## Included

The Clients page is available to Super Admin, Clinic Admin, and reception staff. The API enforces those roles and requires a staff identity. Every lookup and edit is restricted to clients in the actor's clinic; practitioners, accounting staff, and clients cannot access this directory.

- Search by name, email, or phone, with 25 records per page.
- Create and edit first/last name, email, phone, preferred contact, optional date of birth, emergency contact, and administrative notes.
- Set active/inactive status; no hard deletion of client history.
- Reject duplicate emails, invalid values, and stale edits.
- Audit detail views, creation, and updates using record identifiers, without copying contact values or notes into audit metadata.
- Client creation inserts a local user and profile only. It does not provision Entra, create login credentials, send an invitation, or send an email.

Email is required and must be unique among accounts within the clinic, matching the existing database constraints. Shared family email addresses and client sign-in/invitations require further design. Addresses and mobile-visit destination handling remain in the Phase 4 plan; this checkpoint manages contact profiles.

## Deployment

Deploy both ZIPs from `release/client-management-2026-09-15`:

1. Extract the frontend ZIP into `/public_html/wellness/`, preserving its `api/` directory.
2. Extract the private API ZIP into the private API directory, preserving `.env` and runtime files.
3. Refresh the browser and open Staff portal → Clients.

No SQL migration and no seed data are required. This uses the existing `users`, `client_profiles`, and `audit_logs` tables. The packages include preceding Phase 4 changes from the scheduling branch.

## API

- `GET /api/v1/clients?q=&page=1` returns `{items, page, has_more}`; summaries omit date of birth, notes, and emergency contact.
- `GET /api/v1/clients/{id}` returns details and a `revision` token.
- `POST /api/v1/clients` creates a client; given_name, family_name, and email are required.
- `PATCH /api/v1/clients/{id}` saves the full form and requires its latest `revision`. Missing/stale revisions return 409. Treat this endpoint as a full client-form update, not a partial-field merge.

## Verification

Local checks: `npm run build` in Frontend, `composer run lint`, `composer validate --strict`, and `php tests/clients.php` in api. Client tests cover field validation, normalization, and the role boundary. Existing booking and interval tests also run.

MySQL and signed-in browser acceptance still need the deployed development site:

1. Create a development client; reopen and check that all supplied values persist.
2. Search by name, email, and phone; change contact details and check the updated results.
3. Attempt a duplicate email and verify a readable error without an extra client row.
4. Open the same client in two browser tabs. Save tab one, then tab two. Tab two should report that the record changed; close and reopen to get its latest revision.
5. Set the client inactive, then active; verify status persists and inactive clients cannot be booked.
6. Confirm reception can manage clients, while practitioner/accounting/client tokens receive 403. A client ID from a different clinic must return 404 for permitted staff.
7. Verify audit records exist for viewing and saving, with no note or contact contents in metadata.

The next development unit is the staff booking form using active client records and offered slots.
