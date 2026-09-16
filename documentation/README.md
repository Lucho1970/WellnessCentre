# Wellness Centre Documentation

This folder contains the living technical and delivery documentation for the Wellness Centre scheduling platform.

Start with these two authoritative documents:

- [Master Requirements](MASTER_REQUIREMENTS.md): product scope, retained features, role permissions, implementation baseline, delivery stages and acceptance criteria.
- [System Design](SYSTEM_DESIGN.md): public/portal architecture, identity and account linking, API/data boundaries, transactions, deployment, migrations and testing.

## Supporting records and historical sources

- [Public/portal separation checkpoint](PORTAL_SEPARATION.md): build outputs, routes, preserved permissions, subdomain deployment steps and acceptance checks. No SQL upgrade for this change.

- [Staff booking checkpoint](STAFF_BOOKING.md), [Client management](CLIENT_MANAGEMENT.md) and [Booking validation tests](BOOKING_VALIDATION_TESTS.md) describe specific implementation/test checkpoints; they do not establish production readiness.
- [API setup](../api/README.md) and [Entra setup](../ENTRA_SETUP.md) remain component runbooks. Follow the master documents if a cross-system design statement conflicts.
- [Original product requirements](wellness-centre-app-requirements.docx), [former solution plan](SOLUTION_BUILD_PLAN.md), [site restructuring proposal](SITE_STRUCTURE_REQUIREMENTS.md) and [former database plan](../api/DATABASE_PLAN.md) are preserved historical inputs, superseded as master specifications.
- Upgrade SQL lives in `api/database/migrations/`; `api/database/schema.sql` is the fresh-install schema, not a bulk upgrade for an existing database.

## Document maintenance

- Update Master Requirements when scope or acceptance changes and System Design when architecture changes. Keep stable requirement IDs traceable to implementation and tests.
- Do not create another competing master plan. Put procedural details and release evidence in linked supporting documents.
- Mark work complete only after its acceptance criteria and tests pass.
- Never place credentials, tenant secrets, database passwords, client records, or production configuration values in documentation.
- Keep implementation-specific setup instructions beside the relevant component, such as `api/README.md`, while keeping cross-solution decisions here.
