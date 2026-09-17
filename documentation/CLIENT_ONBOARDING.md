# Client onboarding and record linking — implementation contract

Status: design checkpoint on `codex/client-onboarding`. No onboarding endpoints,
database migration, customer record access or session implementation is released by
this document. The existing verified-sign-in flow remains unchanged.

This runbook implements AUTH-04/05/06/07, CRM-02 and the onboarding portion of R3 in
MASTER_REQUIREMENTS.md; SYSTEM_DESIGN.md remains the architecture authority.

## Decisions to confirm before enabling existing-record access

1. Recommended: a signed-in client accepts an invitation, then authorized staff verify
   the claimant and approve the link. Possession of the invitation alone grants no
   access to the existing record. Staff use a known contact channel independently of
   unverified details supplied in the claim. No date-of-birth or email knowledge quiz
   is treated as sufficient identity evidence.
2. Recommended client application session limits: 30 minutes idle, 8 hours absolute.
   Fresh identity proof is required to establish a replacement after expiry. Staff
   session changes are a separate unit; do not change workforce permissions here.

## Scope and boundaries

- New client: explicitly choose new registration, supply first/last name, contact
  email, phone and a reusable mobile-visit address. Address uses the existing Delivery
  field limits. Contact email is not a login identity and is not implicitly verified.
- Existing client: request an invitation from staff; accept it while signed in, then
  complete the approved review process. Do not search or disclose records by email.
- Returning linked client: view their own contact profile and their own appointments.
  No clinical notes, staff administrative notes, other attendees' information, financial
  records or other clients' addresses are returned. Booking mutations remain disabled.
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

Allocate the next migration only after decisions are confirmed. Use MySQL 5.7-compatible
InnoDB tables, UTC timestamps and explicit foreign keys. Do not seed live records.

| Proposed table | Purpose and key constraints |
| --- | --- |
| customer_identities | Immutable trusted issuer + case-sensitive subject, unique by a canonical identity digest; separate from workforce identity_links. Stores no roles from token claims. |
| customer_client_links | Approved identity-to-existing-client relationship; unique identity and client for this first self-only release. Includes clinic, approval actor and timestamp. |
| client_link_invitations | Intended clinic/client, SHA-256 digest of random token, creator, expiry, revoked/consumed timestamps. Raw invitation returned once, never stored or logged. |
| client_link_claims | Invitation claimant identity, pending/approved/rejected state, reviewing staff actor and timestamps. No client data disclosed while pending. |
| client_contact_addresses | Reusable private address for a client. Separate from immutable appointment destination snapshots. Updating it never rewrites past appointments. |
| customer_sessions | Hashed random session credential, identity, verified authentication time, creation/last-activity/absolute-expiry/revocation timestamps. |

An explicit server-side deployment clinic selection is required for new registrations;
do not infer clinic from an untrusted request or choose the first clinic implicitly.
Invitation routes always use the invitation's clinic and the staff actor's clinic.

## Transactions and failure behavior

- Registration locks the identity, rechecks whether it is linked, then creates the
  client/profile/address/link and audit in one transaction. Retried requests return the
  existing result for that identity; unique constraints handle races without duplicates.
- Issuing a replacement invitation revokes prior outstanding invitations for the same
  record atomically. Block inactive clients and records already linked in this slice.
- Acceptance locks the invitation and identity; verifies expiry, revocation and unused
  state; consumes it once and creates a pending claim. Another identity cannot replay it.
- Approval requires Super Admin, Clinic Admin or reception client-management permission,
  same clinic and explicit confirmation of independent identity verification. Lock the
  identity, claim and client in a consistent order; recheck both link uniqueness and
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

Preferred first design: keep the current Authorization access token and additionally
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

Hosted acceptance, session proof and MySQL concurrency tests remain required before this
unit is described as ready to deploy. No live database changes are authorized by this file.
