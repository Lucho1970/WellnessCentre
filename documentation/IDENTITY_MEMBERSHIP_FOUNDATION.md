# Identity and staff membership foundation

This is an additive source foundation, not a login cutover or portal invitation release. Existing Entra login still resolves `identity_links` and intersects directory roles with local roles. No live user IDs, roles, customer links or sessions are migrated.

`IdentityAdapter::verify` returns an immutable `VerifiedIdentity` after provider verification. The Entra implementation preserves signature, expiry, tenant, issuer, audience and scope checks, and its legacy tenant/object-ID mapping. Subjects are provider-specific: workforce uses Entra object ID, not an email or a customer subject. The interface does not imply a new provider has been selected.

Migration 032 introduces empty `product_identities` and `staff_memberships`. Identity adapter/issuer/subject keys are case sensitive. Each identity can have one staff membership per clinic, bound to an existing clinic-local user. Memberships default to pending and carry a version for future session invalidation. Existing local role/permission tables remain authoritative; this slice does not add a separate grant workflow. Database foreign keys establish existence; the resolver additionally enforces matching clinic and staff user type.

`StaffMembershipResolver` is deliberately not connected to the API, which serves as a disabled rollout boundary: applying empty tables cannot enable membership login. The future pilot must introduce an explicit disabled configuration gate. The resolver rejects customer adapters, inactive identities/memberships/clinics/users, and mismatched local clinic bindings. It preserves the current Entra/local role intersection. It creates no accounts or identities and never matches by email.

## Reconciliation before backfill

Run `api/database/identity-membership-preflight.sql` against a backed-up test copy of the hosted schema. It produces counts only. Review incomplete mappings, multiple links per staff user and unlinked staff before approving a mapping. Verify live migration history, column types/collations and legacy tenant/object-ID interpretation. Do not automatically merge identities by email or silently choose a link. Customer identities remain in their legacy tables until a separate clinic-client migration is designed.

Only after reconciliation, apply migration 032 in a disposable MySQL database and test two synthetic clinics, revoked/pending memberships, inactive users/clinics, different issuer/subject case and customer-versus-staff denial. Reconcile imported counts and roles before enabling any new login path. No production deployment is required now; do not apply the migration as a remedy for existing login problems.

## Validation and next work

The PHP regression suite and signed-token foundation tests exercise legacy login preservation, provider claim rejection, selected-clinic parameters, role intersection and mismatch denial. PDO doubles validate query predicates; real MySQL constraint and row-isolation acceptance remain outstanding because the local Docker database is unavailable.

Next: review the preflight counts and hosted schema; implement an explicit conflict-reporting backfill and gated pilot resolver. Then design provider proof-of-concept and invitations. This foundation does not yet allow practitioners to register independently of Entra provisioning.
