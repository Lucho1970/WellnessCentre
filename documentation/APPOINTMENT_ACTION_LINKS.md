# Secure appointment email links

Implemented on `feature/appointment-action-links`; not deployed or enabled. This is the email navigation slice of Phase 5; recurrence is separate work.

## Client experience

When enabled, client booking confirmations, changes and email reminders include a bilingual **Review, reschedule or cancel** link. It opens the matching appointment after the client signs in with the already linked client account. Possession of a forwarded email does not grant appointment access, link accounts by email, or change a booking. Clients without account linking must use the existing clinic-reviewed invitation process; clinic staff can continue managing their appointments.

Opening the link shows appointment details with explicit Reschedule and Cancel appointment buttons. Cancellation retrieves the accepted policy snapshot and displays any fee. Confirmation sends the displayed fee as an acknowledgement; the server recalculates inside the appointment transaction. If the deadline changed the fee, the mutation is rejected, the preview is refreshed and the client must confirm again. The acknowledged amount never overrides policy. No payment is collected. Rescheduling requires a newly available slot, preserves service/practitioner/location/delivery/duration/price and uses existing version/concurrency checks. A slot conflict clears the old selection so the client must search again.

Cancelled, expired, revoked, wrong-owner or superseded links show recovery to **My appointments**, without exposing booking details. Eligible clients can still use their own appointment list without a token.

## Security and lifecycle

- Tokens use 32 random bytes and are stored only as SHA-256 hashes in `appointment_action_links`. Issuance rechecks active clinic/client, notification recipient ID/current contact email, sending email event, appointment version/status and future start.
- A token has one purpose: opening one client-owned appointment management screen. It is reusable while eligible, rather than consumed by a visit. It is neither a login token nor a mutation credential.
- Tokens expire at the earlier of 30 days after issue and appointment start. Every appointment version change invalidates old links, including rescheduling, cancellation and reassignment. New messages issue fresh links for the current version.
- Resolution requires existing customer bearer authentication, a valid customer session, approved account link, active client and trusted host/clinic context. The lookup binds client, clinic, version/status, expiry and revocation before returning minimal appointment details. Mutation endpoints independently recheck ownership, policy and availability.
- The browser receives `/client/appointment#token=<64 hex characters>`, removes the fragment/query before mounting the UI, and retains the link only in session storage for sign-in (up to 24 hours in that tab). Successful resolution or explicit return to My appointments removes the saved token. The existing sign-in callback is unchanged. A new link takes priority over an old guest booking or return-to-browse intent.
- The raw token is posted only in the authenticated resolve request body, never in API query strings, database rows, notification payloads or audit metadata. Avoid copying complete links into logs, feedback or chat. Fragments are not part of HTTP request URLs.
- Staff messages, SMS and cancellation emails carry no such token. Calendar attachments remain privacy-minimal and link to the ordinary client portal. Transport retries may issue new hashes; preparation failures use the existing worker needs-review handling.

## Staff revocation

When enabled, administrator/reception appointment details show **Appointment email links → Revoke existing email links → Confirm link revocation**. Only Super Admin, Clinic Admin and reception can revoke, scoped to their clinic. The transaction revokes existing links and records `appointment.action_links.revoke`; it does not cancel the appointment or change client permissions. Future messages may issue new links, so revocation does not disable all future notifications. Turning off the feature flag disables issuance and resolution globally.

## API contract

| Endpoint | Authorization / behavior |
|---|---|
| `POST /api/v1/customer/appointment-links/resolve` with `{ "token": "…" }` | Existing linked customer session; returns `{ "appointment": { … } }`. 404 for missing/malformed/expired/revoked/wrong-owner/stale links; 503 when disabled. |
| `POST /api/v1/appointments/{id}/action-links/revoke` with `{}` | Clinic administration role; returns `{ "revoked": true }`. 404 outside clinic, 403 wrong role, 503 when disabled. |
| Existing client cancellation PATCH | Requires integer `expected_cancellation_fee_cents` equal to the current calculated fee. Missing/invalid acknowledgement: 422; changed fee: 409 `cancellation_fee_changed`; no mutation on failure. |

Staff appointment GET includes boolean `action_links_enabled` to hide revocation while disabled. Responses inherit no-store handling. Successful resolution records `appointment.action_link.open`. Audits contain appointment IDs, never raw tokens or client contact details. Tokens help navigate; staff revocation does not revoke the client's ordinary ownership rights.

## Deployment and rollback

1. Back up the development database and matching private API/Willow portal files.
2. Keep **`APPOINTMENT_ACTION_LINKS_ENABLED=false`** (default when absent) in the shared private API `.env`. Leave practitioner invitation and staff membership settings unchanged.
3. Rehearse/apply **034_appointment_action_links.sql** once. It depends on existing clinics/users/appointments/notification_events and adds only its token table. Migration 033 is not required. The fresh schema already includes the table; do not apply 034 a second time after importing that full schema.
4. Deploy matching private API source, bin/worker changes and the rebuilt Willow portal, preserving `.env`, runtime files and uploads. No frontend setting, identity app registration or provider callback is added. Leave the neutral landing and public website alone.
5. Hard-refresh client/staff tabs. Client cancellation now requires fee acknowledgement even while link generation is disabled, so the portal must match the API. Staff cancellation requests remain unchanged.
6. After migration/code acceptance, set **`APPOINTMENT_ACTION_LINKS_ENABLED=true`** in the live private `.env`; no rebuild is needed for this runtime switch. `CLIENT_PORTAL_URL` must be HTTPS, end in `/client` (optionally with a base path), and contain no query, fragment or credentials.
7. Test a new synthetic booking email and reminder. Previously sent emails keep their old general portal link; do not resend all historical notifications. Verify copied links fail under another client/clinic, stale links fail, revocation works and visiting a link never confirms a change.

Rollback: disable the flag to stop issuance/resolution, retaining hashes for review. When reverting code, restore a matching API/portal pair. The extra table can remain; do not delete token/audit history for a UI rollback. No deployment package was generated or uploaded during implementation.

## Validation limits

Local verification on 6 October 2026: both production frontend builds, content/translation validation and TypeScript checks passed; all 114 browser regression tests and 13 production-build browser checks passed; all 45 top-level PHP test scripts passed, including 63 appointment-link policy/query assertions. This includes explicit staff revocation, sign-in handoff, invalid/unavailable links, fee re-confirmation, rescheduling conflict recovery and French/mobile behavior.

Local tests use PDO doubles and browser intercepts of test-only authentication/data modules. Real SQL rehearsal is `api/tests/integration/appointment-action-links.php`: it never loads `.env`, connects only to `127.0.0.1` (default port 13317), creates a random synthetic database retained for inspection, and applies 034 to the baseline. Set `APPOINTMENT_LINK_TEST_ALLOW_CREATE=true`; optional credentials/port use `APPOINTMENT_LINK_TEST_USER`, `APPOINTMENT_LINK_TEST_PASSWORD` and `APPOINTMENT_LINK_TEST_PORT`. It checks actual issuance, isolation, expiry, version/status/account invalidation, revocation and hash-only storage. The attempted connection was refused; real MySQL acceptance remains outstanding.

Hosted acceptance also needs real sign-in redirects, email/reminder delivery, unlinked/inactive accounts, wrong client/clinic, expired/revoked/version-changed links, fee-window transitions, concurrent reschedule conflicts, staff role denials, French/mobile recovery and identifier-only audits. Use synthetic records for isolation. The earlier hosted booking concurrency checkpoint remains outstanding.
