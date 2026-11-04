# Wellnexa privacy statement — 4 November 2026

## Scope and owner-supplied facts

This change publishes a bilingual public statement at `https://wellnexa.ca/privacy?lang=en` and `?lang=fr`, links it from the public navigation, footer and practitioner enquiry form, and offers a clinic-scoped link in the shared portal. It does not change the API, database, identity, consent capture, permissions, workers, providers, pricing or notification delivery. The shared portal link appears only for the Wellnexa host when its configured public site is `https://wellnexa.ca`.

The privacy owner identified **9553282 Canada Ltd operating as Wellnexa** and stated that the virtual clinic controls information stored on its site/portal, while independent practitioners control notes and other records they keep separately. The owner reported creating `privacy@copihue.ca` as the public privacy inbox and approved the custody wording in both languages on 4 November 2026. Inbox receipt has not been independently verified. No public mailing address was supplied, so the statement gives the email contact and a route to arrange protected delivery. Profession-specific responsibilities, contracts and actual operational practices still need legal/privacy review before real PHI use.

The statement describes collection, purposes, sharing, outside services, access/correction, restrictions, complaints, retention and testing limits. It avoids claiming a fixed universal retention period, guaranteed security, or that a practitioner’s separate records are held in the portal. It does not imply that website text is treatment consent. The IPC link is informational; the clinic privacy inbox is the first contact. See [PHIPA sections 15–16](https://www.ontario.ca/laws/statute/04p03), [IPC information practices guidance](https://www.ipc.on.ca/en/health-organizations/collection-use-and-disclosure-of-personal-health-information) and [IPC access/correction guidance](https://www.ipc.on.ca/en/health-individuals/accessing-or-correcting-your-personal-health-information).

## Files

| File | Purpose |
| --- | --- |
| `Wellnexa/src/Privacy.tsx`, `Wellnexa/src/config/site.ts` | English/French statement, custodian name and privacy address. |
| `Wellnexa/src/App.tsx`, `Wellnexa/src/App.css`, `Wellnexa/src/SiteNavigation.tsx`, `Wellnexa/public/.htaccess` | Public route, language/title, responsive page, links and server rewrite. |
| `Wellnexa/src/PractitionerEnquiryForm.tsx` | Link to the statement at collection. |
| `Frontend/src/shared/WellnexaPrivacyLink.tsx`, `Frontend/src/guest/GuestPortal.tsx`, `Frontend/src/portal/PortalApp.tsx`, `Frontend/src/customer/ClientApp.tsx`, `Frontend/src/i18n/en.ts`, `Frontend/src/i18n/fr.ts` | Wellnexa-only guest, staff and client portal link and bilingual label. |
| `Wellnexa/tests/browser/privacy.spec.ts` | Public entry, both languages, mobile layout, contact and enquiry link checks. |
| `Frontend/tests/build.smoke.spec.ts` | Built Wellnexa link and Livin Lively isolation check. |
| This document, `documentation/README.md`, `Wellnexa/README.md` | Delivery and validation record. |

## Validation and release evidence

Source branch: `feature/wellnexa-privacy-statement`; baseline `6cee57b59f6c4aebe2aff41b235acf4fc26de602`; final source revision will be recorded in the package manifest. Local environment: Windows, PowerShell, Node 22.22.1, npm 11.19.0, Playwright on Microsoft Edge. Lockfile SHA-256: Frontend `cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c`; Wellnexa `17b1d75bce7d40a857dfd16c319f32a116b905e799f339c4443446da3a571f1e`. `npm ci` completed in both frontends. The first Wellnexa build and browser attempt ran while the Frontend restore was still writing shared `node_modules`; TypeScript reported a partially written Google Maps type file and Vite could not resolve MSAL. They were stopped and rerun after the restore; these were test-environment failures, not evidence that the source passed on the first attempt.

| Check | Result |
| --- | --- |
| `Wellnexa: npm run lint` | Passed with four existing warnings: two document-mutation and two Fast Refresh warnings. |
| `Wellnexa: npm run build`; `npx playwright test tests/browser/privacy.spec.ts --reporter=line` | Passed after final statement wording; 33 modules, Vite 8.3.4; three focused tests passed. |
| `Wellnexa: npm run test:browser -- --reporter=line` | Full run 27 passed after final navigation change, including three new privacy checks. |
| `Frontend: npm run build` | Passed after guest link: lint, 4 bilingual content entries, 2,573 translation pairs, strict TypeScript and all three production surfaces. Existing large-chunk and central dynamic-import warnings remain. |
| `Frontend: npx playwright test --config playwright.build.config.ts --reporter=line` | Final 16 passed. The new cross-clinic check first exposed a malformed test fixture, then the missing guest-portal link; both were corrected and rerun. |
| `Frontend: npm test -- --reporter=line` | 298 passed, 2 expected skips (private service-import CSV/workbook acceptance inputs absent), 10.1 minutes. Default suite includes portal and client tests but omits dedicated application-admin/invitation suites; neither is changed here. |
| `Wellnexa: npm audit --json`; `Frontend: npm audit --omit=dev --json`; `Frontend: npm audit --json` | Zero Wellnexa and Frontend runtime advisories; full Frontend tree has 10 moderate development dependency advisories in ESLint/typescript-eslint/minimatch paths. No forced major upgrade; assess with the existing quality-gate follow-up. |
| `git diff --check` | Passed after final documentation. |
| Hosted English/French page, portal link on both hosts, mailbox receipt, headers and private release controls | Not yet verified. |

There are no PHP, SQL, migrations or disposable database changes in this slice; PHP/database suites are not applicable to the changed code. Local browser tests use synthetic responses. They do not verify hosted privacy practices or operational controls.

## Package, upload and rollback

Build from the final commit and record its SHA and lockfile hashes. Package `Wellnexa/dist/` including hidden `.htaccess` for `/public_html/wellnexa.ca/`. Package the tested `Frontend/dist/portal` with the maintained portal-only builder for `/public_html/wellness-common-portal/`. Preserve existing `.env`, API, uploads and all old hashed assets during upload; do not mirror-delete. The shared portal package serves both Wellnexa and Livin Lively. No database or private configuration change is needed. Check ZIP contents for secrets, source maps, localhost, private files, PHI and correct public URLs. Keep a copy of previous `index.html`, `.htaccess` and matching assets for both destinations.

After upload, visit the English and French URLs directly and via footer, navigation and practitioner enquiry. Verify legal name, contact, language, mobile layout and link to IPC. Verify the Wellnexa portal client and staff link; verify the Livin Lively portal does not show a Wellnexa privacy link and its existing booking/sign-in pages still load. Check response headers and that true missing assets are not rewritten to the SPA. Do not use patient data for this smoke check. Roll back each affected destination by restoring its prior HTML, rewrite file and matching assets together. No schema rollback is required. Release owner, privacy review and hosted acceptance remain pending until recorded.

## Control ledger for this slice

Statuses describe the scope verified here, not the whole application. Review date: 4 November 2026. Privacy/custody decisions belong to the owner; security/hosting and release decisions need their assigned reviewers. The [29 October audit](COMPLIANCE_AUDIT_2026-10-29.md) remains historical evidence, not proof of current acceptance.

| Control | Status | Evidence, gap and next action |
| --- | --- | --- |
| GOV-01 | Partial | Owner supplied legal name and platform/separate-practitioner custody boundary; verify contracts, roles and clinic-specific authority. |
| GOV-02 | Partial | PHIPA/IPC sources linked above; applicability register and dated legal review remain open. |
| GOV-03 | Unverified | PIPEDA applicability to non-health/site data and data flows needs owner/legal decision. |
| GOV-04 | Partial | Notice describes administrative use only; any clinical decision function needs separate review. |
| GOV-05 | Partial | Notice lists main classes and outside services; inventory, flow/PIA, risk assessment and residual acceptance remain open. |
| PRIV-01 | Partial | Bilingual statement and collection-point/portal links implemented locally; owner wording review and hosted verification pending. |
| PRIV-02 | Partial | Notice distinguishes care, operational use and marketing; actual authority, consent capture and restrictions not changed or revalidated. |
| PRIV-03 | Unverified | No server restriction work; current enforcement needs independent release evidence. |
| PRIV-04 | Unverified | Representative/minor flows and treatment consent remain outside this page and need review. |
| PRIV-05 | Partial | Access/correction route published; operational intake, identity check and due-date process not verified. |
| PRIV-06 | Partial | No universal period promised; approved profession-specific schedule and backup/disposal evidence remain open. |
| PRIV-07 | Unverified | Notice provides complaints contact; breach runbook and reporting rehearsal remain open. |
| AUTH-01 | Unverified | No identity change; hosted token/configuration evidence remains separate. |
| AUTH-02 | Unverified | Portal link is host scoped; server clinic/record boundaries not changed or revalidated. |
| AUTH-03 | Unverified | No session change; existing security plan and MFA/departure verification remain open. |
| AUTH-04 | Unverified | No token storage change; exceptions/approved session rollout remain open. |
| AUTH-05 | Not applicable | No API permissions or grants added by this slice; inventory remains a separate release obligation. |
| SEC-01 | Partial | Source contains public contact/identifiers only; inspect final ZIP and tracked history before release. |
| SEC-02 | Unverified | Public HTTPS destinations specified; hosted TLS, backup, at-rest and private-root controls need verification. |
| SEC-03 | Unverified | Local route added; per-host live headers, caches and missing-asset behavior require hosted checks. |
| SEC-04 | Partial | Statement is static React text, no unsafe HTML sink or new input; existing app input paths not revalidated. |
| SEC-05 | Not applicable | No new request body, upload, export or worker batch in this slice. |
| SEC-06 | Not applicable | Public static page adds no sensitive read/write; existing audit coverage remains separate. |
| SEC-07 | Partial | Page has no secret diagnostics; hosted error/log redaction remains unverified. |
| SEC-08 | Partial | Notice discloses categories of existing providers; endpoint/subprocessor register, actual terms/geography and approval remain open. |
| SEC-09 | Partial | Notice mentions optional messages and avoids clinical detail; delivery/consent enforcement unchanged and not revalidated. |
| DEV-01 | Partial | Branch/source/package/config/workflow reviewed; final commit/push and host checks pending. |
| DEV-02 | Partial | Focused static change and code review; final diff/validation pending. |
| DEV-03 | Partial | Exact restores and audits performed; classify Frontend development advisories and final package inclusion. |
| DEV-04 | Partial | Builds/browser checks above; final reruns and hosted checks pending. |
| DEV-05 | Partial | Semantic page, language and phone-width browser checks; keyboard/screen-reader/zoom and human accessibility review remain open. |
| DEV-06 | Partial | Build size warnings retained; impact review remains open. |
| REL-01 | Partial | This ledger records each control and gaps; named reviewer decisions are pending. |
| REL-02 | Partial | Local UI builds/browser checks recorded; no PHP/SQL change; final results pending. |
| REL-03 | Missing | Final artifact inspection and hosted acceptance pending. |
| REL-04 | Missing | Real-data and overall release gates remain open. |
| REL-05 | Partial | Owner requested publication, supplied custody/contact and approved wording; host upload and accountable operational/clinical/release sign-off pending. |
| REL-06 | Partial | Completion report must list files, exact checks, omitted/hosted evidence, rollback and final release line. |

**Current decision:** NOT READY FOR RELEASE. This source change may be reviewed and packaged for a controlled site upload, but it does not authorize real PHI or close the broader pilot gates.
