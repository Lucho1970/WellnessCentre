# Client appointment history and practitioner access

Implemented on `feature/client-appointment-history`. This is an administrative read-only overview of an existing client. It does not create permissions, change practitioner assignments, send messages, or expose private treatment notes.

## Where to find it

Open **Staff portal → Clients**, select a client, and click **Details**. The client detail panel retains contact details, date of birth, emergency contact, saved service address, administrative notes and the existing edit/booking/invitation actions. Two buttons at the top open **View appointments** and **Practitioner access**. The selected client's name stays in the panel header. **Back to client details** returns to that record.

The appointment history offers **All appointments**, **Upcoming appointments**, **Past appointments** and **Cancelled appointments**, with totals and 25 rows per page. Each row shows the saved service, assigned practitioner, current date/time in the location's timezone, location, room or On-Site delivery mode, recorded appointment price and applicable On-Site/cancellation fees, creation time and appointment ID. These are appointment snapshots, not invoice balances or payment history. Upcoming includes unended requested/confirmed/rescheduled appointments; past is based on end time; cancelled includes either cancellation status regardless of date. The cancelled count overlaps the other date-based counts.

**Open appointment** uses the existing staff appointment details/actions and their existing permissions. **Back to client history** returns to the same client, including an appointment outside the general appointment list's current page. The history is reloaded so changes made on the appointment screen can appear. Returning resets the history filter/page to its initial view.

**View recorded changes** on an appointment shows saved status changes, practitioner reassignments and cancellation-fee adjustments, including recorded times, actor names, reasons, status transitions, practitioner names and fee amounts where available. Changes paginate separately. Historical reschedule entries cannot reconstruct old appointment dates/times that were never saved; the view explicitly says so. No synthetic event is inserted to fill a gap. Private practitioner-client notes and clinical treatment records are not queried.

## Practitioner access interpretation

The access tab lists all practitioner profiles in the selected clinic, with related practitioners first, including inactive accounts and unrelated profiles. It shows discipline, local account/profile status, the number of appointments currently assigned to that practitioner for this client, first/latest scheduled appointment dates in UTC, whether that practitioner created the client, and the configured scope for the client directory, booking contact lookup, client administration, appointments, appointment changes and appointment logistics notes. Assigned appointment counts include cancelled appointments and future bookings; they are not completed-visit counts. Reassignment history names former practitioners but does not create a new relationship/grant for them.

This describes **configured portal access**, not proof that a practitioner has viewed the client or can currently obtain a valid provider token. Actual sign-in also requires the accepted identity, provider roles and applicable active membership. No provider subjects or authentication tokens are returned. Local account deactivation blocks all projected access. Membership-required pilot/external staff identities must have an active identity and membership; external staff projections apply the existing practitioner-only role/permission restrictions. Workforce provider roles still need to intersect local grants when signing in.

The report follows the existing rules rather than introducing a per-client access-control list:

- Super Admin, Clinic Admin and reception can use the administrative client directory and clinic appointment workflow.
- A practitioner sees a client in **My clients** when currently assigned any appointment for them or when recorded as the successful creator of that client. A cancelled appointment still counts as a relationship under the current rule.
- Practitioner booking contact search and saved-address lookup currently allow active-client lookup across the clinic, even without a My clients relationship. The report shows that broader capability separately. Inactive clients are excluded from that booking lookup.
- A practitioner normally sees their own appointments. The explicit scheduling-for-other-practitioners permission broadens appointment visibility/changes, while logistics notes stay limited to their own appointments unless they also have an operations role.
- Changing an owned appointment requires an active practitioner profile and practitioner-managed booking mode, unless clinic scheduling authority applies. Appointment status/time and other action-specific restrictions still apply on the appointment screen.
- An inactive practitioner profile alone does not remove all staff access under the existing APIs. The report warns that the staff account must also be deactivated to block configured staff access.

Local roles and additional permissions are displayed for review. The feature has no grant/revoke controls; changing the underlying policy is a separate task.

## API and privacy

All endpoints are under `/api/v1` and require the existing Clients administration roles (Super Admin, Clinic Admin, reception). Practitioners, accountants and client tokens are denied before client data is loaded.

| Endpoint | Result |
|---|---|
| `GET /clients/{id}/appointments?view=all&page=1` | Clinic-local client summary, filtered appointment rows, counts, current page and `has_more`. Views: `all`, `upcoming`, `past`, `canceled`. |
| `GET /clients/{id}/appointments/{appointment}/history?page=1` | Validated appointment/timezone/currency and saved operational changes, current page and `has_more`. |
| `GET /clients/{id}/practitioner-access?page=1` | Clinic-local client summary and configured practitioner access projections, current page, `has_more` and `basis: local_configuration`. |

Each endpoint validates the client belongs to the actor's clinic, is a client account and is not a merged-away duplicate. Appointment history additionally validates appointment ownership by that exact client/clinic. Joins keep service/location/practitioner/actor names in the same clinic. Wrong-clinic, wrong-client and merged record lookups return 404. IDs in browser links are navigation hints only and cannot bypass those checks.

Views audit `client.appointments.view`, `client.appointment_changes.view` and `client.practitioner_access.view` using client/appointment IDs, page and view parameters. Audit metadata does not copy contact details, event reasons or notes. Responses inherit the API's existing no-store policy. Appointment summaries omit saved destination snapshots; authorized detailed appointment viewing retains its existing destination audit behavior.

## Deployment and acceptance

This feature has not been deployed. It introduces **no new SQL migration or environment settings**. The shared private API must already have the current appointment schema (including cancellation snapshot fields and migration 029 reassignment history), client-merge tables (006), and identity/membership foundation (032). These are prerequisites, not instructions to rerun existing migrations. Migration 033's invitation tables are not required for this read-only feature while invitations remain disabled.

Deploy the matching private `src/Api.php` and new `src/Service/ClientOverviewService.php` with the current source prerequisites, and rebuild/upload the portal frontend. Keep `.env` and private runtime files intact. Do not upload PHP service classes into the public `api/` folder. The old client-management release packages documented elsewhere do not contain this feature. No deployment package was generated or uploaded during this implementation.

The top-level PHP policy/query tests use PDO doubles; browser tests substitute test data/auth modules without adding a production bypass. Real MySQL acceptance uses `api/tests/integration/client-overview.php`: it never loads `.env`, connects only to `127.0.0.1` (default port 13317), and creates a random synthetic database retained for inspection. Explicitly set `CLIENT_OVERVIEW_TEST_ALLOW_CREATE=true`; optional credentials/port use `CLIENT_OVERVIEW_TEST_USER`, `CLIENT_OVERVIEW_TEST_PASSWORD` and `CLIENT_OVERVIEW_TEST_PORT`. The rehearsal was attempted but the local MySQL service refused the connection, so real SQL execution and hosted acceptance remain outstanding.

Local verification on 5 October 2026: all 44 top-level PHP test scripts passed, including 58 client-overview policy/query assertions; the frontend content/i18n validation, TypeScript check and both production builds passed; 12 focused browser checks (five new overview scenarios and seven existing regressions) and 13 production-build browser checks passed. These results do not replace the outstanding database and hosted acceptance checks.

Hosted acceptance: test two clients in one clinic and a synthetic client in a second clinic; verify appointment totals/filters/pagination, saved change events, missing/empty history, incorrect appointment/client pairs, inactive accounts, selected membership revocation, broad scheduling versus own logistics-note scope, wrong-role denial, return navigation, mobile/French layout and identifier-only audit records. Do not use real clinical data for the isolation test.
