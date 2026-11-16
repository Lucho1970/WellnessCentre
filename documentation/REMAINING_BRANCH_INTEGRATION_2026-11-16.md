# Remaining branch integration review — 16 November 2026

## Scope and reconciliation

This source-only integration begins at `main` revision `c5ab21c` and records the remaining five branch tips:

| Branch | Decision |
| --- | --- |
| `feature/hosted-pilot-test-readiness` (`b5fd99e`) | Merge its unique hosted synthetic pilot evidence. The documented third-party address review remains open. |
| `feature/docker-restored-schema-rehearsal` (`761b0e2`) | Merge its unique MySQL 5.7 synthetic restore and migration evidence. |
| `feature/local-fullstack-docker-test` (`4db1449`) | Merge its unique local-only Docker harness and validation record. |
| `bugs/booking-email-promise` (`0acf82f`) | Preserve its ancestry with an `ours` merge: the live-follow-up branch previously merged into `main` contains the booking wording fix and a fuller hosted result. Reapplying the earlier branch would conflict with and regress later client UI work. No source content from this branch is reapplied. |
| `feature/unified-portal-sign-in` (`52cc237`) | Preserve its ancestry with an `ours` merge: the hosted-release branch previously merged into `main` contains the chooser and its complete documentation; `main` has subsequent client-session behavior and tests. Reapplying the older branch would regress them. No source content from this branch is reapplied. |

The imported changes comprise `Frontend/local-docker-smoke.mjs`, `api/.dockerignore`, seven `docker/local/` files, three new evidence documents, and two lines added to `PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md`. This index and review are the only additional edits. No production PHP/React feature, database schema, runtime configuration, dependency manifest/lockfile, identity grant, consent wording, permission or endpoint is changed by this integration.

## Validation

On the existing local synthetic Docker stack: `python docker/local/http-smoke.py` passed 14/14 public and denial checks; `node Frontend/local-docker-smoke.mjs` passed 5/5 rendered shells with zero page errors. `docker compose --env-file .tmp/docker-test/compose.env -f docker/local/compose.yaml config --quiet`, Python parsing of the two scripts, `node --check Frontend/local-docker-smoke.mjs`, `Frontend/npm run lint`, and `git diff --check` passed. Docker Desktop 29.9.3, Compose 5.7.0, Python 3.12.10, Node 22.22.1 and PHP 8.4.14 were present. The existing Docker database and containers were not reset, imported or removed by this review.

The portal product source is unchanged from `c5ab21c`, whose combined build, 309/311 default browser tests (two optional skips), 46/46 central-admin tests, 27/27 practitioner-application tests, 306 PHP syntax checks and selected PHP policy tests are recorded in [the preceding merge review](PORTAL_CHAIN_MERGE_REVIEW_2026-11-15.md). Those checks were not rerun for this documentation and local-harness integration. The imported harness's original clean restore and build checks are recorded in [its own evidence](LOCAL_DOCKER_FULL_STACK_2026-11-11.md); they were not repeated against a fresh database today. Local HTTP and browser smoke do not establish hosted identity, clinical authorization or real-data acceptance.

## Control and release state

The [38-control governance ledger](PRACTITIONER_GOVERNANCE_RELEASE_PREP_2026-11-10.md#change-scoped-38-control-ledger) remains the baseline for GOV-01–05, PRIV-01–07, AUTH-01–05, SEC-01–09, DEV-01–06 and REL-01–06. Each control retains its last recorded Partial, Missing or Unverified status and owner action unless a cited evidence document narrows the tested scope; none is upgraded to Implemented by merging. The hosted pilot document's third-party address/provider-log assessment, privacy/security decision and potential notification/reporting determination remain outstanding under GOV-05, PRIV-07 and SEC-08. Local Docker uses an owner-attested synthetic export on an unencrypted host drive and cannot accept real PHI. Protected backup, identity/session, cross-clinic and role acceptance, dependency review, combined artifact provenance/rollback, and named custodian/privacy, clinical, security/operations and release decisions remain open. No production deployment or migration was performed or authorized by this merge.

Source reviewer: Codex, 16 November 2026. Release owner: Luis Duran. Overall decision: **NOT READY FOR RELEASE**.
