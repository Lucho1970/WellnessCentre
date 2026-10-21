# Development checkpoint — 12 October 2026

For current package destinations and shared portal hosting, see [Deployment layout](DEPLOYMENT_LAYOUT.md).

`feature/checkpoint-ready` preserves the accumulated work through central application administration (`eb769fa`) and prepares it for integration review. This checkpoint does not authorize a merge or perform hosted deployment.

The accumulated baseline includes clinic routing and identity memberships, practitioner invitations, client appointment history, appointment email action links, recurring appointments, versioned intake forms and author drafts, private practitioner work locations, shared validation feedback, consecutive availability entry, separate clinic portals and central application administration. Individual feature documents retain rollout requirements and acceptance limits.

Checkpoint cleanup preserves the existing English resource formatting without changing any of its 1,683 keys or values. The client-search debounce browser test uses a controlled clock and waits for the recorded request rather than assuming wall-clock precision. The portable SQL runner now supports both clinic-isolation and central-administration rehearsals with the correct `CLINIC_TEST` environment variables.

## Local validation

- All three production frontend builds, TypeScript, bilingual content and 1,745 translation checks passed.
- All 49 top-level PHP scripts and syntax checks for 75 source/entry files passed. Windows ephemeral RSA fixtures used Git's OpenSSL configuration.
- All four central administration browser checks, seven practitioner invitation checks and 14 built-site smoke checks passed.
- The general browser run passed 163 of 165 cases initially. The appointment-link recovery assertion timed out while still loading; it passed three focused reruns without a production change. The client-search debounce assertion observed an intermediate query under load; after controlling the clock, it passed three focused reruns. This records the initial failures explicitly rather than claiming a clean full-suite rerun.
- Real MariaDB rehearsals passed 34 central administration assertions and 30 clinic/location isolation checks; both managed test servers stopped afterward.

## Release and hosted acceptance

Generate the coordinated package from the committed checkpoint:

```powershell
./scripts/build-deployment.ps1 -ReleaseName central-admin-checkpoint-2026-10-12 -DeploymentGuide APPLICATION_ADMINISTRATION.md
```

The ignored release directory contains the API, clinic/public frontends, central landing/administration frontend, maintained SQL and deployment guide. Its manifest records the source revision and archive hashes. Generated archives, dependencies, synthetic databases and private configuration stay out of Git.

Use [Application administration](APPLICATION_ADMINISTRATION.md) for the current setup sequence. Reconcile which numbered migrations are already applied before applying upgrades; do not run every packaged SQL file against an existing database. Migration 039 precedes 040. Independently verify the intended workforce identity before granting central access. Configure central and clinic callbacks, DNS/HTTPS, host aliases and runtime flags as described in the guides.

Hosted acceptance remains pending for real sign-in/callbacks, administrator grant/revocation, clinic registration and duplicate rollback, clinic/session/data isolation, and notification destinations. Local tests use synthetic data and do not prove these hosted gates.

## Next development slice

After this checkpoint is reviewed and integrated, start a separate `feature/` branch from updated `main`. The next documented forms slice is client-answer drafts and append-only amendments. Preserve immutable original submissions and assignment versions; retain client ownership and assigned-practitioner answer access. Due dates/reminders and template archival follow separately. Real clinical response collection still requires the privacy/content review and hosted isolation/consent acceptance recorded in [Client intake forms](CLIENT_INTAKE_FORMS.md).
