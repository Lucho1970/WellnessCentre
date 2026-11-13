# Shared portal sign-in chooser — 13 November 2026

## Decision and scope

The owner found the three guest-header sign-in buttons confusing on Wellnexa. The shared portal now has one **Log in** button. It opens an accessible dialog with direct links for Client, Practitioner and Clinic staff, each with a short description. The practitioner choice appears only when the existing practitioner identity rollout is enabled. Signed-out visitors see no separate **My appointments** header link; an active client session retains that shortcut. The existing routes and identity clients are unchanged: `/client`, `/staff/external` and `/staff/login` stay distinct.

This design uses direct actions in the dialog rather than a dropdown or radio selection followed by Continue. Jane's [staff/patient sign-in guidance](https://jane.app/guide/help-i-m-a-practitioner-but-keep-getting-logged-in-as-a-patient) illustrates the cost of confusing account areas. The [GOV.UK button guidance](https://design-system.service.gov.uk/components/button/) recommends reducing competing actions; its [radio guidance](https://design-system.service.gov.uk/components/radios/) fits a form selection, which this one-step navigation does not need. The [W3C dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) informed the titled dialog, Escape behavior and focus restoration. These sources inform the design; they do not validate this application's accessibility.

The changes are limited to `Frontend/src/guest/GuestPortal.tsx`, the new `Frontend/src/guest/GuestSignInChooser.tsx`, the English and French translation files, and browser/build smoke tests. No API, SQL, PHP, provider registration, permission, consent, endpoint, external data flow, dependency or private setting changed. This is source work on `feature/unified-portal-sign-in`, based on `main` before the separate booking-copy branch was merged. **Do not deploy this branch's full build:** it also contains newer unreleased governance code from `main` and lacks the already-hosted booking-copy fix. An eventual portal update needs an integrated, reviewed narrow release artifact and explicit REL-05 approval.

## Validation

| Check | Result |
| --- | --- |
| `Frontend/npm ci` | Passed, 350 packages from lockfile. Existing `esbuild@0.25.12` install-script warning. |
| `Frontend/npm audit --json` | Exit 1: ten moderate development-tool advisories, zero high/critical. |
| `Frontend/npm audit --omit=dev --json` | Exit 0: zero runtime advisories. No dependency change or forced fix. |
| `Frontend/npm run build` | Passed lint (ESLint 10.13.0, zero warnings), four content entries, 2,684 bilingual translations, TypeScript 5.7.2, and public/portal/application-admin builds with Node 22.22.1, npm 11.19.0, Vite 6.4.5. Existing large-chunk and central static/dynamic import warnings remain. |
| `npx playwright test --config playwright.applications.config.ts --workers=1 --reporter=line` | 25 passed. Includes direct destination checks, separate practitioner/workforce authentication, English keyboard Escape/focus restoration and French 375-pixel layout. After the final signed-out header adjustment, the two affected cases passed again, including an explicit no-horizontal-overflow assertion. |
| `npx playwright test --config playwright.config.ts --grep 'guest portal\|public navigation\|guest catalogue\|public home' --workers=1 --reporter=line` | 10 passed. |
| `npx playwright test --config playwright.build.config.ts --reporter=line` | 16 passed for ordinary default build. |
| Pilot-flagged `npm run build:portal`, then `BUILD_PRACTITIONER_SIGN_IN=1 npx playwright test --config playwright.build.config.ts --reporter=line` | Build passed. Original 17-test run: 16 passed, one timed out because the **new test fixture** returned an object for a service list and crashed the guest page. The fixture was corrected to return an array for list routes; the new built-chooser test passed on rerun with the practitioner choice enabled. The original failure remains recorded. |
| Default `npm run build:portal`, then the new built-chooser test | Passed with practitioner choice absent. |

Synthetic desktop and 375-pixel French screenshots were inspected locally after dialog animation settled. Buttons, text and Cancel remained visible; the 375-pixel page also passed an explicit horizontal-overflow assertion. Screenshot artifacts stay ignored under `Frontend/test-results/`. The full default 300-case browser suite, other dedicated browser suites, PHP/SQL tests, manual screen-reader/200% zoom checks, real provider sign-in, hosted database acceptance, production package scan and rollback rehearsal were not run for this UI-only change. No hosted files changed.

## Local Docker review — 13 November 2026

The existing `wellness-local-test` containers run a PHP/web frontend against the restored **synthetic** MySQL 5.7 database. Docker Desktop 29.9.3 and Compose 5.7.0 were running; the web container serves the workspace's ignored `Frontend/dist/portal` through a read-only bind mount. This preview was rebuilt with local URLs, `/api/v1`, identity client IDs empty, server sessions off, external staff authentication off, practitioner social sign-in off, and practitioner application navigation on. These process-scoped build settings changed no tracked configuration. The local API has pilot mode and outbound mail/SMS disabled. The Docker setup source is on the separate, unmerged `feature/local-fullstack-docker-test` branch; the running containers were already present before this review.

Open `http://localhost:18080/` and click **Log in**. Review the three choices, their descriptions, Cancel/Escape and focus return. Use the globe to review French; inspect narrow/mobile width and zoom. The Wellnexa public site preview is at `http://localhost:18081/` (`18083` is Livin Lively). Opening an entry route for visual review is fine; Google/Microsoft sign-in will report that it is unconfigured on localhost. The localhost redirect URI and identity clients are deliberately unconfigured, so authenticated registration, roles, booking and revocation require the separately controlled hosted test flow or a reviewed local identity harness. HTTP-only localhost also does not validate hosted TLS, headers or cookie behavior.

Read-only checks returned HTTP 200 for `/api/v1/health`, `/api/v1/health/database` and `/api/v1/site-config`. A Chromium browser check returned HTTP 200, found the dialog's `/client`, `/staff/external` and `/staff/login` links, and reported zero page JavaScript errors. The local Docker response initially rendered punctuation in the database-sourced Wellnexa name as mojibake; the static chooser copy rendered correctly. Inspection showed that the source SQL export already spells the name with mojibake and declares `clinics` as `latin1`, even though the intended dash uses UTF-8 bytes. For the disposable **synthetic local database only**, the `clinics.name` column was changed to `utf8mb4` and row 4's name set to the intended spelling. A subsequent API read returned Unicode U+2014 correctly. The source export and hosted database were not changed; other restored text may have the same encoding problem and needs separate review before relying on local content fidelity. No production files, live database or hosted settings changed.

## Control ledger for this change

Statuses refer to this slice only; unresolved project findings in `COMPLIANCE_AUDIT_2026-10-29.md` and the current governance ledger remain open. Owner review and real-data release decisions are separate.

| Control | Status | Evidence or remaining action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/agent allocation unchanged; owner/legal review remains. |
| GOV-02 | Unverified | Applicability register unchanged. |
| GOV-03 | Unverified | PIPEDA applicability unchanged. |
| GOV-04 | Unverified | Intended-use classification unchanged. |
| GOV-05 | Unverified | No data inventory, PIA or threat assessment update for this navigation-only change. |
| PRIV-01 | Unverified | Privacy notice unchanged. |
| PRIV-02 | Unverified | No consent or collection change. |
| PRIV-03 | Partial | Separate auth routes retained; hosted restriction checks remain. |
| PRIV-04 | Unverified | Representative authority unchanged. |
| PRIV-05 | Unverified | Access/correction process remains incomplete. |
| PRIV-06 | Unverified | Retention decisions remain open. |
| PRIV-07 | Unverified | Breach-runbook rehearsal remains open. |
| AUTH-01 | Partial | Existing identity clients and routes preserved; hosted sign-in not retested. |
| AUTH-02 | Partial | UI does not grant a role; server clinic/record checks remain separately gated. |
| AUTH-03 | Unverified | MFA, session and departure controls unchanged. |
| AUTH-04 | Unverified | Browser storage/session exception remains unresolved. |
| AUTH-05 | Unverified | No permission or grant change; deployed inventory remains. |
| SEC-01 | Partial | No secrets introduced in source; final release artifact scan pending. |
| SEC-02 | Unverified | HTTPS/encryption unchanged. |
| SEC-03 | Partial | Current hosted header gaps remain; no host rule changed. |
| SEC-04 | Partial | Only fixed internal routes used; no unsafe HTML sink added. |
| SEC-05 | Unverified | No request/worker change. |
| SEC-06 | Unverified | Audit paths unchanged. |
| SEC-07 | Partial | Dialog contains no token or provider diagnostic; hosted error review pending. |
| SEC-08 | Unverified | No new endpoint/provider; register approval remains. |
| SEC-09 | Unverified | Messaging unchanged and pilot channels remain disabled. |
| DEV-01 | Partial | Dedicated branch and unchanged lock; human review and eventual integration remain. |
| DEV-02 | Partial | Focused source review done; human review remains. |
| DEV-03 | Partial | Clean restore and audit done; ten moderate dev-tool advisories remain for exposure review. |
| DEV-04 | Partial | Build and applicable browser checks passed; omitted suites listed above. |
| DEV-05 | Partial | Keyboard, focus, small viewport and bilingual checks passed; assistive technology/zoom audit pending. |
| DEV-06 | Partial | Existing bundle warnings remain; no representative-load check. |
| REL-01 | Partial | This scoped ledger is dated; accountable reviewer decision pending. |
| REL-02 | Partial | Applicable local frontend checks passed; full broad checkpoint not claimed. |
| REL-03 | Missing | No final artifact or hosted acceptance for this change. |
| REL-04 | Missing | Release decision blocked by project and hosted gates. |
| REL-05 | Partial | No live change; future artifact and explicit deployment authorization required. |
| REL-06 | Partial | Implementation/evidence/rollback scope recorded; operational result pending. |

RELEASE STATUS: NOT READY FOR RELEASE
