# MT0: identity, clinic membership and independent practices

Date: 5 October 2026. Status: source inventory and proposed migration design complete; decision review and acceptance pending. This document supports the master requirements and multi-tenant roadmap. It introduces no production schema, login or permission changes.

## Findings from the current source

| Boundary | Current implementation | Consequence |
| --- | --- | --- |
| Staff sign-in | `EntraAuthenticator::loadUser` resolves a Microsoft tenant/object link to one `users` record. Effective roles intersect application roles with Entra token roles. | A portal invitation alone cannot replace Entra provisioning or role assignment today. |
| Staff provisioning | `AdminService::createStaff` requires a provider object ID and tenant ID; writes local user, identity link and roles. | Keep this as a legacy adapter during transition, then replace the user-facing provisioning workflow. |
| Identity and clinic | `users.clinic_id` assigns each local staff/client record to one clinic; `identity_links` globally identifies one linked local user. | Preserve local user IDs; introduce global identities and separate memberships rather than making existing users global. |
| Client identity | Migration 005 defines `customer_identities` and `customer_client_links`; the latter's primary key is `identity_id`. | One verified identity cannot currently link to two clinic-local client records. |
| Client onboarding | `CustomerOnboarding` uses the global `customerClinicId` configuration. | Onboarding needs a trusted per-request clinic context before a second clinic can use the same API. |
| Public clinic routing | Catalogue and public availability use the first active clinic; the runtime public website setting is global. | A second hostname is not sufficient to isolate or brand a second clinic. |
| Independent client profiles | `client_profiles` and contact addresses attach to a clinic-local user. Practitioner access can follow appointment history, but no separate practitioner-owned profile is stored. | Independent practices require new relationship/profile storage and explicit access policies, not only filtering the current client list. |
| Appointment provider | Appointments store one `practitioner_id`. | Coverage needs separate relationship owner and actual performer; reassignment must not implicitly transfer records. |

Evidence is local source, not live database inspection. The existing migrations and deployed versions must be reconciled against a fresh backup before any data conversion. See [the source inventory](MT0_SOURCE_INVENTORY.md).

## Proposed architecture

Keep one core API and keep `users.id` as the clinic-local operational account referenced by appointments, audit and existing profiles. Add a global verified identity layer above it. An identity proves who signed in; an active clinic membership and explicit permissions determine what they can do. A client link and a practitioner relationship determine which client record they can access.

The authentication adapter validates its registered issuer, audience, signature, lifetime and sign-in requirements. It returns an immutable issuer/subject identity, never roles inferred from an email match. The membership resolver constructs the existing `AuthContext` for the selected trusted clinic and local account. Client and staff contexts remain distinct even if the same human holds both.

Public requests resolve an active clinic using a server-owned host mapping. Unknown hosts are rejected. Do not trust a browser-supplied clinic ID or forwarded-host header unless an explicitly trusted proxy policy validates it. Authenticated requests must additionally match active membership or client access in that clinic. The neutral portal host has no implicit clinic and remains a public landing page for this phase.

## Proposed additive data model

These are design contracts, not executable migrations. Final names and keys should be checked against the reconciled database inventory.

| Entity | Proposed fields and constraints | Migration rule |
| --- | --- | --- |
| Product identity | ID, adapter, exact issuer, immutable subject, status, timestamps; unique adapter/issuer/subject with case-sensitive comparison. | Import verified legacy staff and customer identities with provider-specific mapping; preserve source identifiers and require review for conflicts. Do not join people by email. |
| Staff membership | ID, identity ID, clinic ID, local staff user ID, status, version; unique clinic/local user; explicit identity/clinic membership rules. | Bind existing identity links to their existing staff user IDs. No role expansion. |
| Membership roles/permissions | Membership ID plus role/permission; grantor, grant/revoke audit. | Copy existing local grants as a candidate baseline. Legacy Entra login continues its current intersection until the new adapter and application-role policy pass acceptance. |
| Staff invitation | Clinic, intended role/permissions, inviting member, expiry, hashed one-time token, state, accepted identity/membership, audit. | New rows only. Enforce allowed grant scope, atomic acceptance, expiry/revocation, replay protection and invitation-recipient verification. Never grant operator powers through a clinic invitation. |
| Clinic-client identity link | Identity ID, clinic ID, local client ID, verification basis, status, timestamps; unique identity/clinic plus clinic/client rules matching the approved link policy. | Preserve current client IDs. Replace the single-identity primary key only after backfill and conflict reports; ensure sessions recheck the clinic link. |
| Clinic host mapping | Exact normalized host, clinic ID, surface, active status; unique host. | Seed confirmed Willow domains from reviewed deployment configuration. Unknown/generic hosts cannot fall back to clinic 1. |
| Clinic operating mode | Clinic ID, traditional or independent-practitioner mode, versioned policy. | Existing Willow stays in traditional mode. Do not automatically convert its clients into independent records. |
| Practice-client relationship | ID, clinic, owning practitioner, optional linked clinic-local client, status, provenance; composite clinic/owner keys. | Create independent relationships only under an approved mapping. Client identity is optional for practitioner-created records. |
| Relationship profile | Relationship ID; separately maintained name/contact/address/preferences and care-record references. | No automatic copy of another practitioner's overrides. Client-confirmed identity-provider details may seed a new relationship. |
| Access grant | Clinic, relationship, grantee member, scope, purpose, approved basis, start/expiry/revocation, grantor and audit. | Default deny. Operational assistance and clinical access are distinct scopes. |
| Coverage assignment | Appointment, owner relationship/practitioner, performing practitioner, invitation/acceptance state, limited grant and expiry. | Preserve original provider/authorship and existing event history. Backfill only unambiguous ownership; quarantine ambiguous cases for review. |

Use composite foreign keys or equivalent transaction-enforced invariants so a user, relationship, location, service or grant from another clinic cannot be attached by guessed ID. Avoid destructive renaming of existing tables in the first migration.

## Portal invitation journey

1. An authorized clinic administrator selects Invite practitioner, supplies the intended recipient and an allowed clinic role, and sends a time-limited invitation. A practitioner cannot grant themselves roles; a clinic admin cannot create platform operators.
2. The recipient follows the invitation and signs in/registers with the selected product identity provider. The provider handles passwords, MFA and recovery. The application verifies both the invitation and its intended-recipient proof; email coincidence alone is insufficient.
3. In one transaction, lock the invitation, recheck inviter/clinic/grant eligibility, resolve the immutable identity, and create or reuse the clinic-local staff account and membership. Concurrent accepts produce one result; expired/revoked/used invitations deny access. Do not merge existing accounts silently.
4. Create the practitioner operational record and unpublished profile through the existing governed workflow. Role membership does not itself publish the profile or prove professional qualifications.
5. Deactivation immediately blocks new requests and sessions in that membership. It does not delete historical appointments or disable the person's unrelated memberships. Invitation delivery status is separate from provider account creation and actual receipt.

Operator-established first administrators and audited recovery remain separate from ordinary invitation management.

## Isolation and shared-resource review

| Area | Required change/acceptance owner |
| --- | --- |
| API reads/writes/lists/counts | Application authorization: clinic context plus relationship/grant policy at the service boundary, including direct IDs and empty-list leakage. |
| Availability and booking | Scheduling: published clinic projection, practitioner service ownership, room sharing, idempotency/races and relationship ownership. Keep one scheduling implementation. |
| Client search, merge, address and exports | Client-data services: independent-mode search defaults to own relationships; prohibit automatic cross-practice merge; explicitly scope delegated operational fields. |
| Dashboard and reports | Reporting: aggregate only authorized resources; counts and recent activity obey the same boundary as details. |
| Notifications and background jobs | Worker: persist clinic and relationship context, recheck grants and destination ownership at dispatch; scoped retry/deduplication. |
| Images, files and previews | Media/public projection: clinic and publication check before retrieval; storage keys and cache keys include the relevant scope. Public assets contain no private contact fields. |
| Identity/JWKS/session caches | Authentication: issuer-keyed verification cache; clinic-bound application session; recheck active membership. No domain-wide session grants. |
| Provider credentials and runtime settings | Configuration: clinic-specific reference to secret storage and approved senders; exact host routing and public allowlist. The existing global `.env` remains single-clinic until migrated. |
| Audit, support, diagnostics and test tooling | Operations: scoped searches/results; operator metadata access separate from time-limited exceptional data access. Never treat current clinic `super_admin` as a new platform role. |
| Backup, restore, retention and deletion | Operations/data governance: demonstrate clinic-specific restore/export/deletion under the chosen storage model before real multi-clinic onboarding. |

## Database and provider decisions

| Option | Benefit | Cost/risk | Proposed disposition |
| --- | --- | --- | --- |
| Shared database with enforced tenant/relationship keys | Fits existing core API; one migration path; simpler shared identity registry. | Every query/job must enforce scope; selective restore and incident containment need proof. | First synthetic proof-of-concept candidate, not an approved production isolation choice. |
| Separate operational database per clinic with shared identity registry | Stronger operational separation and simpler whole-clinic restores. | Connection routing, migrations, cross-clinic identity mapping and shared-resource operation are more complex. | Retain as alternative until restore, operations and isolation evidence are compared. |

Provider selection remains open. Evaluate standards-based OIDC, staff MFA, Google/Microsoft client access, invitations, recovery, stable subjects, revocation, logout behavior, audit, residency, pricing and portability. Compare candidates through the same proof-of-concept tests; do not select or purchase a provider merely because the existing application uses Entra. No cloud configuration changes are part of MT0.

Record access, operational delegation, consent basis, record stewardship and coverage policy remain product/governance decisions before real independent-practice care data is enabled. This document proposes enforceable software boundaries without claiming a legal policy has been approved.

## Threat model and required evidence

| Failure/attack | Required control and synthetic acceptance |
| --- | --- |
| Forged host or browser clinic ID | Exact server-owned mapping; reject unknown/proxy-spoofed host and mismatched membership; no first-clinic fallback. |
| Same email presented by another identity | Bind issuer/subject and verified invitation proof; conflict requires controlled resolution, not automatic account merge. |
| Invitation theft, replay or concurrent acceptance | Hashed secret, expiry/revocation, recipient verification and locked single-use transaction; prove only one membership grant. |
| Client sign-in used as staff sign-in | Adapter/context separation and active staff membership; valid customer tokens cannot grant workforce permissions. |
| Revoked membership or grant used by an existing session | Recheck active state/version on protected requests and queued work; removal in one clinic leaves unrelated memberships intact. |
| Guessed IDs, aggregates or bulk exports expose another clinic/practice | Scope queries and joins, including counts/search/history; test paired resources in two clinics and two independent practices in one clinic. |
| Coverage silently becomes ownership or broad clinical access | Separate owner/performer and limited accepted grant; test expiry, revocation, notification and immutable authorship. |
| Shared cache, worker or file bypasses route authorization | Scope keys and resource lookup; recheck destination/grant at dispatch; tests exercise jobs/media as well as HTTP handlers. |
| Migration loses identity linkage or broadens privileges | Backfill count reconciliation, unique-constraint conflicts and explicit role comparison; rehearse rollback with denied accounts remaining denied. |

Before declaring MT0 accepted, review this design and the inventory, reconcile a backed-up live schema with the local migration history, record the storage/restore decision and provider proof-of-concept evaluation criteria, and assign unresolved operating-policy decisions. MT1 tests prove identity/invitations; MT2 tests prove isolation; MT3 tests prove repeatable provisioning. A provider comparison may require current vendor documentation and pricing research in that later decision task.

## Migration and rollback sequence

1. Reconcile the live schema/migrations and source release; capture a verified backup and synthetic test fixtures. Inventory ambiguous identity links, duplicated recipients, and clinic-local ownership without copying personal values into documentation.
2. Build trusted clinic-context resolution and additive identity/membership tables behind a disabled feature gate. Backfill existing links without changing local user IDs, permissions or sessions. Produce count/conflict reports; never automatically resolve conflicts by email.
3. Prove the adapter and membership resolver with two synthetic clinics. Test wrong issuer/audience, uninvited identity, revoked membership, client-versus-staff confusion and concurrent invitation acceptance. Keep existing Entra login available during the pilot.
4. Introduce portal invitations for a controlled test cohort only after provider and role policy acceptance. Test account linking and rollback; keep at least one tested legacy administrator path.
5. Expand clinic-client links and onboarding/session scope; prove the same verified identity reaches separate client records in two clinics with no data transfer.
6. Add independent relationship/profile/grant storage. Backfill only an approved traditional-clinic mapping; separately opt synthetic clinics into independent mode. Enforce all service and worker boundaries before enabling a second real clinic.
7. Establish repeatable provisioning, backup/restore and delegated staff administration. Release gates MT1–MT3 govern real onboarding, not completion of this source review.

Rollbacks disable the new authentication/invitation gate and preserve additive tables and all audit/relationship data. They must not re-enable revoked accounts, restore wider access, or overwrite a newer membership state. Once new independent records exist, legacy screens must not read them through clinic-wide queries; reverting code alone is no longer a safe rollback. Rehearse this with synthetic data.

## Next implementation slice

The trusted `ClinicContext` resolver is implemented on `feature/trusted-clinic-routing`, with an explicit single-clinic customer compatibility boundary for Willow. Public catalogue, branding and availability use mapped clinic IDs; unknown hosts and mismatched staff are rejected. See [Trusted clinic routing](TRUSTED_CLINIC_ROUTING.md) for configuration, deployment and remaining database acceptance checks. This source implementation is a prerequisite for memberships and does not change the live practitioner identity provider.

Then implement the additive identity/membership schema and adapter interface, followed by provider proof-of-concept and portal invitations. Hosted source/schema reconciliation, identity/storage decisions and policy review remain MT0 exit items; this document does not mark the entire milestone accepted.
