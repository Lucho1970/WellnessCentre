# Client pilot denial on sign-in return — 15 November 2026

## Finding and scoped change

During the synthetic LivinLively client check, Google and personal Microsoft returned to `/client` with the generic callback error. The browser's `customer/auth/session` response was HTTP 403, `pilot_access_denied`. The Google picker had selected a different account from the approved synthetic identity; signing in with the approved identity then worked and returned **verified but not linked**, without Wellnexa records. The pilot gate worked, but the generic callback message made that expected denial hard to understand. No callback URL, token, response body or identifying details were retained in this record.

On `bugs/client-pilot-denial-callback`, `Frontend/src/customer/bootstrap.tsx` now recognizes **only** a `CustomerRequestError` with HTTP 403 and code `pilot_access_denied` during the legacy client callback. It displays the existing bilingual pilot-access message. All other callback failures still receive a generic, now translated, recovery message. The callback continues clearing authorization response data from the address bar before mounting the UI. `Frontend/tests/customer.spec.ts` covers English and French pilot denial, no private-record read, callback URL cleanup and an unrelated authorization failure remaining generic. `Frontend/src/i18n/en.ts` and `fr.ts` add the generic recovery sentence. No provider identity, allowlist, role, record link, API or session architecture changed.

## Local validation and package

Source commit: `d351acb0baae8b8c1330fd263b8f08b6fd5b06db`, based on the unmerged deployed `bugs/client-account-menu-profile` branch. Runtime: Node 22.22.1, npm 11.19.0, Vite 6.4.5, Playwright 1.63.0. Frontend lockfile SHA-256: `cb46757cdd6fd546aa12a1149ddd66bc7ebf576f595f8af075f73b8b5be7970c`; dependencies and lockfile were not changed.

- `npm run lint; npm run validate:i18n; npx tsc --noEmit` passed (2,580 English/French entries).
- `npx playwright test --config playwright.config.ts tests/customer.spec.ts --grep "client callback|callback failure strips response" --reporter=line` passed **4/4**.
- `npm run build` passed lint, content, i18n, strict TypeScript and public/portal/application-admin builds. Existing large-chunk and mixed-import warnings remain.
- `npx playwright test --config playwright.config.ts tests/customer.spec.ts --reporter=line` passed **36/36**.
- The explicitly configured existing pilot build passed `$env:BUILD_PRACTITIONER_SIGN_IN='1'; npx playwright test --config playwright.build.config.ts --reporter=line`: **17/17**, including practitioner/workforce separation. Pilot flags retain external auth, applications and social sign-in, with invitations and server sessions off.

The ignored portal-only ZIP is `D:\Dev\WelnessCentre\deployments\client-pilot-denial-callback-2026-11-15\01-shared-portal-assets-only.zip`, SHA-256 `06e14dc332421423c8fe84912a25b5d9e959fb38ecfd7764f54050407bfa0d44`. It has exactly `index.html` (SHA-256 `f2d9e80d8749c1b00d45f606e7b31dbdefa96c3c425fea997d84aa0cbb31c2f8`) and 69 JS/CSS assets. All 70 archive hashes match the local manifest; no unexpected path, source map or private-key marker was found. The new browser bundle contains existing public identity identifiers and the existing browser Maps key; no new external endpoint or permission was introduced. The previous live `index.html` on both hosts matched SHA-256 `bca0b2dbab3294b1ff28444c7bb355d510428b362b02808afccc4373961959a5` and is backed up outside the web root at `D:\Dev\WellnessCentre-backup\client-pilot-denial-callback-2026-11-15\`. The owner approved and uploaded the portal-only artifact. Rollback restores that saved entry page and retains hashed assets. No SQL, API, `.htaccess`, identity grant or private setting is in the package.

Before the entry switch, HTTPS downloads of **69/69** packaged assets on each host matched the manifest SHA-256 values (**138/138**), while both hosts still served the previous exact entry hash. After the owner replaced only the shared `index.html`, both hosts served the new exact entry hash. `/`, `/client`, `/client/auth/callback`, `/staff/login` and `/staff/external` returned HTTP 200 `text/html` on both hosts (ten route checks), with the existing `no-store` entry cache and five-minute HSTS. The owner then repeated sign-in on LivinLively with the same unapproved synthetic Google identity that previously received `pilot_access_denied`: the specific English pilot access message appeared instead of the generic callback error, and the address bar was clean `/client` without `code=` or `state=`. Separately, the approved synthetic Google identity returned **verified but not linked**, without Wellnexa profile or appointments, and sign-out passed. Hosted French message verification remains. Keep the prior entry backup and both sets of hashed assets.

Hosted acceptance of the new message is still required with a specifically approved synthetic identity and a separate disallowed test identity. Do not broaden pilot access to test the denial. A 403 alone is insufficient: verify the new text in English and French, no record exposure, cleared callback URL, normal approved sign-in and sign-out on both hosts. The remaining real-PHI release gates in the repository audit and pilot safety controls are unaffected.

## Scoped 38-control ledger

Review date: 15 November 2026. Owner: Luis Duran for release/operations; privacy, security and clinical reviewer decisions remain required for wider release. Statuses below apply to this UI slice, not organizational compliance. “Unverified” means no new evidence in this change, not proof of a failure. No exception is granted.

| Control | Status | Evidence or next action |
| --- | --- | --- |
| GOV-01 | Unverified | Clinic custody and processor register unchanged; accountable review remains. |
| GOV-02 | Unverified | Applicability register unchanged; recheck for real-data release. |
| GOV-03 | Unverified | PIPEDA analysis unchanged. |
| GOV-04 | Not applicable | Message only; no clinical decision function added. |
| GOV-05 | Partial | Synthetic identity test used; full PIA/data-flow review remains. |
| PRIV-01 | Unverified | Existing notices/contact unchanged. |
| PRIV-02 | Partial | No consent/data collection change; pilot rejection shows no record. |
| PRIV-03 | Partial | Exact pilot-denial code only changes text; server restriction remains. Wider boundaries unverified. |
| PRIV-04 | Unverified | Representative/minor process unchanged. |
| PRIV-05 | Unverified | Patient access/correction process unchanged. |
| PRIV-06 | Unverified | Retention/hold process unchanged. |
| PRIV-07 | Unverified | Breach process unchanged. |
| AUTH-01 | Partial | Existing MSAL and server validation unchanged; callback error is classified only after API rejection. Hosted retest pending. |
| AUTH-02 | Partial | No role/link grant; denial test checks no private read. Other clinic/record negative tests remain. |
| AUTH-03 | Unverified | Session policy unchanged. |
| AUTH-04 | Unverified | Existing sessionStorage exception/gate remains; no new storage. |
| AUTH-05 | Unverified | No API grant change; deployed inventory remains. |
| SEC-01 | Partial | ZIP paths/hashes and private-key markers checked; broader secret/history review remains. |
| SEC-02 | Unverified | TLS/backups/infrastructure unchanged; previous entry backup verified. |
| SEC-03 | Unverified | No header/CORS change; earlier missing nosniff finding remains. |
| SEC-04 | Partial | Error code compared exactly; text React encoded from maintained translations. |
| SEC-05 | Unverified | No request size/rate/worker change. |
| SEC-06 | Unverified | Server audit unchanged; hosted denial-audit review remains. |
| SEC-07 | Partial | Known denial receives safe text; other failures remain generic; callback query cleared in tests. Hosted logs/correlation review remains. |
| SEC-08 | Unverified | No endpoint/subprocessor change; register remains. |
| SEC-09 | Not applicable | No message dispatch or marketing change. |
| DEV-01 | Partial | Dedicated branch, source/tests/docs reviewed and pushed; dependent earlier branch unmerged. |
| DEV-02 | Partial | Narrow typed error classification; human code review remains. |
| DEV-03 | Partial | Exact unchanged lockfile used; prior advisory review remains; no runtime dependency added. |
| DEV-04 | Partial | Lint, content/i18n, TypeScript, all builds, 36 customer and 17 built smoke tests passed; wider PHP/SQL suites omitted as unchanged. |
| DEV-05 | Partial | English/French alert and callback recovery tested; manual screen reader/zoom and hosted check remain. |
| DEV-06 | Partial | Existing bundle warnings recorded; representative performance review remains. |
| REL-01 | Partial | This dated scoped ledger exists; accountable reviewer decision remains. |
| REL-02 | Partial | Frontend/build/browser checks passed; broad API/SQL/admin suites omitted for this UI-only change. |
| REL-03 | Partial | ZIP/manifest and built browser checks passed; hosted 138/138 assets, both entry hashes, ten routes, English denied-identity message/clean callback, approved unlinked sign-in and sign-out passed. Hosted French remains. |
| REL-04 | Missing | Real-data release still blocked by broader project gates. |
| REL-05 | Partial | Owner approved and transferred exact package after prior entry backup and both-host asset checks; broader operational signatures remain. |
| REL-06 | Partial | Files, versions, tests, package, no permission/data-flow changes, hosted static, English denial and approved sign-in results recorded; French and wider gates remain. |

RELEASE STATUS: NOT READY FOR RELEASE
