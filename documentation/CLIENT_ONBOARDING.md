# Client onboarding and record linking — implementation contract

Status: implemented on `codex/client-onboarding`, disabled by default. Local synthetic
database and browser acceptance are recorded below. Hosted External ID freshness proof
and Netfirms acceptance are required before enabling this for real clients.

This runbook implements AUTH-04/05/06/07, CRM-02 and the onboarding portion of R3 in
MASTER_REQUIREMENTS.md; SYSTEM_DESIGN.md remains the architecture authority.

## Owner-confirmed decisions — 17 September 2026

1. A signed-in client accepts an invitation, then authorized staff verify
   the claimant and approve the link. Possession of the invitation alone grants no
   access to the existing record. Staff use a known contact channel independently of
   unverified details supplied in the claim. No date-of-birth or email knowledge quiz
   is treated as sufficient identity evidence.
2. Client application session limits: 30 minutes idle, 8 hours absolute.
   Fresh identity proof is required to establish a replacement after expiry. Staff
   session changes are a separate unit; do not change workforce permissions here.

## Scope and boundaries

- New client: explicitly choose new registration, supply first/last name, contact
  email, phone and a reusable mobile-visit address. Address uses the existing Delivery
  field limits. Contact email is not a login identity and is not implicitly verified.
- Existing client: request an invitation from staff; accept it while signed in, then
  complete the approved review process. Do not search or disclose records by email.
- Returning linked client: view their own contact profile and their own appointments.
  The appointment page opens by default and provides Upcoming, Past, and All views.
  It shows the service, scheduled start/end, status, practitioner, visit type/location,
  and appointment reference. Booking and changes remain staff-assisted in this slice.
  No clinical notes, staff administrative notes, other attendees' information, financial
  records or other clients' addresses are returned. A linked client can create a booking
  only for their server-resolved client record. The client chooses an active service,
  duration, practitioner, visit mode and offered time; On-Site destinations use their
  saved address by default and require the same Google coverage proof as staff booking.
  Confirmation reuses the locked/idempotent booking transaction and rechecks current
  eligibility, availability, room, price and coverage. Selection never claims a slot hold.
  Changing or canceling an existing appointment remains staff-assisted in this slice.
- Preserve all current user IDs and appointment references. Preserve existing unique
  clinic/email constraint; duplicate registration fails with a neutral contact-clinic
  message and never links or merges automatically.
- Shared email/dependents, duplicate merges, recovery and additional-provider linking
  are deferred. Separate external subjects cannot silently share a record. A staff
  account with the same email is not converted or linked through this workflow.
- Invitation delivery starts as an explicitly labelled staff-copyable link, manually
  sent through the clinic's existing trusted channel. Do not claim an email was sent.
  Automatic delivery requires the separately planned notification service.

## Additive database design

Migration: `api/database/migrations/005_customer_onboarding.sql`. Additive MySQL
5.7-compatible InnoDB tables, UTC timestamps and explicit foreign keys. No seed records.

| Table | Purpose and key constraints |
| --- | --- |
| customer_identities | Immutable trusted issuer + case-sensitive subject, unique by a canonical identity digest; separate from workforce identity_links. Stores no roles from token claims. |
| customer_client_links | Approved identity-to-existing-client relationship; unique identity and client for this first self-only release. Includes clinic, approval actor and timestamp. |
| client_link_invitations | Intended clinic/client, SHA-256 digest of random token, creator, expiry, revoked/consumed timestamps. Raw invitation returned once, never stored or logged. |
| client_link_claims | Invitation claimant identity, pending/approved/rejected state, reviewing staff actor and timestamps. No client data disclosed while pending. |
| client_contact_addresses | Reusable private address for a client. Separate from immutable appointment destination snapshots. Updating it never rewrites past appointments. |
| customer_sessions | Hashed random session credential, identity, verified authentication time, creation/last-activity/absolute-expiry/revocation timestamps. |
| customer_auth_challenges | Hashed server-generated nonce, ten-minute expiry and one-time consumption. |
| customer_rate_limits | HMAC-digested fixed-window buckets for challenge, session and invitation attempts. |

An explicit server-side deployment clinic selection is required for new registrations;
do not infer clinic from an untrusted request or choose the first clinic implicitly.
Invitation routes always use the invitation's clinic and the staff actor's clinic.

## Transactions and failure behavior

- Registration locks the configured clinic, rechecks whether the identity is linked, then creates the
  client/profile/address/link and audit in one transaction. Retried requests return the
  existing result for that identity; unique constraints handle races without duplicates.
- Issuing a replacement invitation revokes prior outstanding invitations for the same
  record atomically. Block inactive clients and records already linked in this slice.
- Acceptance locks the configured clinic and invitation; verifies expiry, revocation and unused
  state; consumes it once and creates a pending claim. Another identity cannot replay it.
- Approval requires Super Admin, Clinic Admin or reception client-management permission,
  same clinic and explicit confirmation of independent identity verification. Lock the
  clinic, invitation, claim and client in a consistent order; recheck both link uniqueness and
  active client status immediately before insertion. Competing approvals cannot reassign
  ownership. Rejection grants nothing. Staff status changes apply on the next API call.
- Audit issue/revoke/accept/approve/reject/register/profile access with internal identifiers
  only; no token, invitation URL, address, contact values or clinical contents in metadata.
- Invalid/expired/used invitation responses are generic and reveal no record details.
  Rate-limit invitation attempts and session-creation endpoints; errors must not leak
  database exceptions. Handle stale profile updates with revision checks.

## Application-session requirement

The current API access token is not proof of a fresh interactive login: a refreshed
token's `iat` must not substitute for authentication time. Session establishment needs
verified broker authentication-time evidence and replay protection. Prototype this with
the configured External ID tenant before exposing the linked profile/appointment API.

Implemented design: keep the current Authorization access token and additionally
require an opaque server-tracked application-session credential on protected customer
operations. Store only its digest server-side; bind it to the validated issuer/subject.
Use a dedicated header and tab-scoped storage, never a URL or shared-domain cookie.
Validate token and session on every request; expire/revoke server-side, including logout.
Do not silently start another session after a timeout. No profile data belongs in the
public initials bridge, even when a client has an approved record link.

If External ID cannot supply the required authentication-time/replay proof in this flow,
stop and resolve the session exchange design explicitly. Do not weaken token validation,
accept unsigned browser claims, or label a UI-only timeout as an enforced session policy.

## UI and API implementation slices

1. Identity principal and additive migration; validate with synthetic isolated DB fixtures.
2. Server sessions and safe reauthentication/logout with token/session isolation tests.
3. New-registration form and persistence, duplicate/retry handling and private address.
4. Staff invitation controls in Clients; client accept/pending UI; staff review/revoke.
5. Own-profile dashboard and minimal read-only appointments; server derives owner ID from
   the approved link, never from a browser-supplied client_id.
6. Update OpenAPI, acceptance guide and deployment ZIPs; commit/push completed slices.

## Required acceptance

- Existing staff bookings and client edits still work after migration.
- Same email with a different external identity never grants access; staff tokens cannot
  use customer routes and customer tokens cannot use staff routes.
- Cross-clinic staff actions fail; client A cannot access client B by IDs, list filters,
  invitation manipulation, stale requests or simultaneous acceptance/approval.
- Test invalid, expired, revoked, replayed invitations; repeated registration/acceptance;
  concurrent claim approval; inactive accounts; safe rejection and cleanup.
- Profile editing excludes status, roles, clinic_id, client_id, notes and other privileged
  fields. Mobile address edits do not change existing appointment destinations.
- Idle/absolute expiry and logout deny subsequent requests on the server; background
  polling cannot keep the session alive indefinitely. Reauthentication is deliberate.
- Fresh and returning sign-in, refresh, public navigation, initials, staff switching and
  failed API requests do not produce request loops or expose stale private content.
- Back up before migration; deploy migration before matching code. Roll back code without
  dropping identity/link/audit records. Never undo real onboarding by destructive cleanup.

## Session exchange implementation

1. Anonymous `POST /customer/auth/challenge` creates a 256-bit nonce (20/IP/10 minutes).
2. MSAL redirects with that nonce, `prompt=login`, explicit `max_age=0`, and an essential
   `auth_time` ID-token claim request. The explicit query parameter is covered by a real
   MSAL browser test; `maxAge: 0` alone was not serialized by this installed version.
   For a returning Google identity, the portal derives the provider only from the signed
   cached ID-token `idp` claim and sends the allowlisted External ID `domain_hint=google`.
   This returns an expired Google session to Google instead of offering a password for
   External ID's generated local username. First sign-in and unknown providers retain the
   normal provider chooser; arbitrary claim values are never forwarded.
3. `POST /customer/auth/session` validates the API access JWT and a separate signed
   ID-token proof. The proof must have the configured SPA audience, trusted issuer,
   matching signed tenant/object IDs, an unused nonce and authentication within ten
   minutes (not predating the challenge by more than 60 seconds). No unsigned decoding
   or `iat` fallback is allowed. An ID token alone cannot call protected APIs.
4. A random credential goes into tab-scoped sessionStorage. Only its SHA-256 hash is
   stored in MySQL. Send it as `X-Customer-Session` alongside the API bearer token.
5. Normal reads do not extend inactivity. Trusted pointer/keyboard interactions send
   at most one activity request per minute. The server rejects expired/revoked sessions
   even on the activity endpoint. Absolute expiry is verified `auth_time` + 8 hours.
6. Logout revokes the current application session before provider logout. It does not
   claim to revoke sessions in every other browser. UI expiry removes private forms.

The fixed-window rate limits are an application safeguard, not a replacement for host
request-size/traffic controls. Customer request bodies are capped at 64 KiB. Error logs
contain exception class + correlation ID, not SQL values, proofs or stack arguments.

## Deployment and activation

1. Back up the existing database and private `.env`/`var` folders. Run **005 only** if
   migrations 001–004 were already applied. Do not rerun the base schema on a live DB.
   For a brand-new DB, the current base schema already includes 001–004; run 005 after it.
2. Deploy matching public website, portal (including its small `/api` pointer), private
   API with its complete matching vendor directory, and public API pointer. Preserve
   `.env` and `var`. Never mix files from different vendor builds.
3. Add these settings to the private API `.env` (also in `api/onboarding.env.example`):

   ```dotenv
   CUSTOMER_ONBOARDING_ENABLED=false
   CUSTOMER_CLINIC_ID=YOUR_EXISTING_CLINIC_ID
   ```

   Find the real clinic with `SELECT id, name, status FROM clinics;`. Do not assume ID 1.
   Existing CUSTOMER_ENTRA_* settings remain unchanged. No new secret belongs in React.
4. With the flag false, existing customer sign-in verification and staff operations
   remain available; onboarding, invitations and private customer records stay disabled.
5. In the development deployment, enable the flag and sign in afresh with a test
   customer. Verify `auth/session` succeeds without copying its token into logs/chat.
   If it returns `fresh_sign_in_required`, the provider may not have supplied fresh
   `auth_time`/object-ID evidence. Turn the flag off and resolve provider configuration;
   do not weaken the check. Local tests cannot establish what the live provider emits.
6. Test new registration, refresh/profile save, client-created duplicate email refusal,
   staff-issued invitation, manual delivery, pending privacy, review-code + independent
   verification approval, own appointments, logout and both timeout limits. Repeat
   staff booking/practitioner access checks. Use synthetic development records only.
7. Revert the flag to false if acceptance fails. Roll back matching code packages if
   needed, but retain the additive tables and links/audit trail. Never delete real links
   or clients to undo this deployment.

## Client self-booking deployment and acceptance

This increment has no database migration. Deploy the matching public frontend, portal and
private API together; the public API pointer is unchanged. Preserve the private `.env`,
runtime files and uploads. Do not deploy the portal alone because it hides self-booking
until the API returns `book_own_appointments`, and do not expose the new API with an older
portal that has not been acceptance-tested.

With a synthetic linked client account:

1. Browse public availability, choose a time and continue to the portal. Confirm the care
   choices survive sign-in, while the selected time is clearly not presented as reserved.
2. Confirm clinic and On-Site appointments. For On-Site, verify the saved address loads,
   Google coverage succeeds, and editing it does not change the profile address.
3. Confirm the resulting appointment appears once in the client's list and in the assigned
   practitioner's schedule with matching duration, price snapshot and destination access.
4. Attempt to add `client_id` to the customer request and verify it is rejected. Confirm an
   unlinked/inactive client and a staff token cannot use customer booking endpoints.
5. Submit the same idempotency key twice and verify one appointment/history/notification
   event. Race the last offered slot from two sessions and verify only one succeeds.
6. Change price, assignment, availability, room or coverage after search but before
   confirmation; verify confirmation fails safely and requires refreshed choices.
7. Repeat in English/French, mobile/desktop and after session expiry. Email remains queued
   but unsent, so retain the explicit appointment-number notice and operational follow-up.

Existing client invitations are in **Clients → edit a client → Client portal access**.
Links expire after 48 hours and are displayed once; send them manually through a known
channel. The customer gives staff the review code during independent verification.
Expired pending claims remain pending until staff reject/revoke or replace the invitation;
this intentionally avoids offering a new duplicate registration while a claim is unresolved.

Optional daily bounded cleanup: `api/database/maintenance/purge_expired_customer_sessions.sql`.
It removes old expired challenges/rate buckets/sessions only, never clinical/link/audit data.

## Local verification and remaining acceptance

- PHP policy/JWT tests cover signed proof audience/issuer separation, fresh authentication,
  replay/expiry boundaries, profile field allowlisting and staff/customer separation.
- `php api/tests/integration/customer-onboarding.php` creates a random scratch DB on
  **127.0.0.1:13317 only**, never loads `.env`, and uses synthetic records. Tested against
  portable MariaDB 10.11.14: schema + migration rerun, registration retry/conflict,
  pending access denial, verified approval, inactive/cross-clinic/role denial, profile
  revision conflicts, own appointment filtering, logout/idle/absolute expiry and rate
  limiting. Separate PHP processes exercise concurrent registration, acceptance and approval.
- Browser tests cover actual MSAL authorization parameters, forms, invitations, private
  UI removal on idle expiry, request-loop regression and existing workforce journeys.
- Netfirms **MySQL 5.7** compatibility and real External ID provider/session behavior
  still require hosted acceptance. MariaDB/local mocked browser APIs are not that proof.
- This slice does not implement client appointment creation, cancellation/rescheduling,
  automated email, provider linking/recovery, staff+client persona merge or dependent accounts.
