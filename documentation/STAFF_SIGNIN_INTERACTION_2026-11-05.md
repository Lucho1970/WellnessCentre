# Staff sign-in interaction error — 5 November 2026

## Scope and diagnosis

The reported screen was `/staff/login`, the **organization workforce** entry. The account used was a personal Hotmail/Outlook practitioner account; its intended entry is `/staff/external` through **Practitioner sign-in → Continue with personal Microsoft account**. The Microsoft practitioner pilot identity was previously made Inactive and pilot access revoked, so a subsequent access denial there is expected until an owner deliberately restores both controls. Identity success and practitioner authorization are separate checks.

The workforce screen also exposed MSAL's raw `interaction_in_progress` diagnostic to the user. This error can occur when a second interactive request starts while an earlier one is still running. The local fix prevents rapid duplicate clicks, checks MSAL interaction state before starting a redirect, and shows a short localized recovery message without the provider diagnostic. It does not change the identity realm, grants, session architecture, or practitioner authorization.

## Changes and evidence

| File | Purpose |
| --- | --- |
| `Frontend/src/auth/AuthProvider.tsx` | Check MSAL interaction state before browser redirect sign-in. |
| `Frontend/src/auth/StaffSignIn.tsx` | Block synchronous repeated clicks and render a safe recovery message. |
| `Frontend/src/i18n/en.ts`, `Frontend/src/i18n/fr.ts` | English and French recovery text. |
| `Frontend/tests/practitioner-applications.spec.ts` | Simulate MSAL error and duplicate clicks; verify personal practitioner entry stays separate. |

Source baseline: `734e324` on `bugs/staff-signin-interaction-error`; final revision is the commit carrying this record. No dependency, lockfile, schema, permission, consent, endpoint, external data-flow or production configuration change. No migration or deployment package. Production remains unchanged.

Local checks from `Frontend`:

| Command | Result |
| --- | --- |
| `npm run build` | Passed lint, bilingual content/i18n, strict TypeScript, public/portal/Admin builds. Existing Vite large-chunk and mixed import warnings remain. |
| `npx playwright test --config playwright.applications.config.ts --reporter=line` | 24 passed, including the two new regression tests. |
| `npx playwright test --config playwright.application-admin.config.ts --reporter=line` | 46 passed; shared central Admin path regressed successfully. |
| `git diff --check` | Passed. |

The browser tests mock MSAL and the API. They do not prove hosted login with either Microsoft realm or real revocation. The user is checking the practitioner route in a separate live browser session; that result must be recorded separately. A full accessibility review, dependency audit, PHP/SQL suites, hosted artifact review, security/clinical release sign-offs and rollback drill were outside this frontend-only correction. The current 5-minute HTTPS/HSTS trial and all pilot safety controls remain independently governed by their own evidence.

## Control ledger for this revision

Status refers only to evidence for this change. `Unverified` means this task did not establish the control, not that the organization is necessarily noncompliant. Owner for outstanding release evidence: privacy owner, security/operations owner, clinical owner and release owner as assigned in the repository standard. Reviewer: Codex, 2026-11-05. Next action for every unverified item is to retain its existing release gate and collect owner-reviewed evidence before real-data release.

| Control | Status | Evidence or limit |
| --- | --- | --- |
| GOV-01 | Unverified | Custody and operator mapping outside this UI fix. |
| GOV-02 | Unverified | Applicability register not reassessed here. |
| GOV-03 | Unverified | PIPEDA assessment outside scope. |
| GOV-04 | Unverified | Intended-use assessment outside scope. |
| GOV-05 | Partial | Synthetic browser fixtures; PIA and threat assessment not revalidated. |
| PRIV-01 | Unverified | Published privacy wording unaffected. |
| PRIV-02 | Unverified | No consent flow changed. |
| PRIV-03 | Partial | Prior pilot revocation is preserved; hosted enforcement not retested here. |
| PRIV-04 | Unverified | Representative authority unaffected. |
| PRIV-05 | Unverified | Access/correction process unaffected. |
| PRIV-06 | Unverified | Retention unaffected. |
| PRIV-07 | Unverified | Breach process unaffected. |
| AUTH-01 | Partial | Existing MSAL configuration preserved; mocked route test only. |
| AUTH-02 | Partial | Workforce and personal practitioner routes remain distinct; hosted authorization not retested. |
| AUTH-03 | Partial | Duplicate interaction guard; full session policy remains separate. |
| AUTH-04 | Unverified | No new credential storage; existing session exception/gate remains. |
| AUTH-05 | Unverified | No grant changed; deployed grant inventory remains separate. |
| SEC-01 | Partial | No secrets introduced in changed source; full artifact/history scan not run. |
| SEC-02 | Unverified | TLS/database/backups unaffected. |
| SEC-03 | Unverified | HTTPS/header trial tracked separately. |
| SEC-04 | Partial | Provider error text no longer rendered on this sign-in form. |
| SEC-05 | Partial | Repeated button clicks suppressed; provider/edge rate limits not assessed. |
| SEC-06 | Unverified | Audit persistence not retested. |
| SEC-07 | Partial | Raw MSAL diagnostic suppressed in this UI; wider logs not audited. |
| SEC-08 | Unverified | No new service or data flow; register not reapproved. |
| SEC-09 | Not applicable | No outbound communication path changed. |
| DEV-01 | Partial | Dedicated bug branch and source review; commit/push recorded separately. |
| DEV-02 | Partial | Small reviewed change; broader source review not repeated. |
| DEV-03 | Unverified | No dependency changes; current dependency audit not run. |
| DEV-04 | Partial | Build/lint/i18n/strict TS and relevant browser tests pass; full gate not run. |
| DEV-05 | Partial | Existing semantic button and link retained; full manual accessibility audit pending. |
| DEV-06 | Partial | No added network calls or timers; existing bundle warnings remain. |
| REL-01 | Partial | This revision ledger records limits; owner decisions outstanding. |
| REL-02 | Partial | Frontend checks above; PHP/SQL and other browser suites not run for this change. |
| REL-03 | Unverified | No new artifact or hosted acceptance. |
| REL-04 | Partial | Not ready for release while hosted and owner gates remain. |
| REL-05 | Partial | No production deployment or authentication architecture change; release approval outstanding. |
| REL-06 | Partial | Completion report in task response; outstanding risks retained here. |

## Rollback and next action

The change can be reverted by restoring the previous frontend source revision and rebuilding. No schema or data rollback is involved. Do not use the personal Microsoft account on the organization workforce button. If the correct practitioner route reaches a pilot/access denial, verify its retained Inactive and revoked-pilot state before treating it as an identity failure. Production deployment requires a separately reviewed artifact and REL-05 approval.
