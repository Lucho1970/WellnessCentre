# HTTPS enforcement for Netfirms app hosts — 5 November 2026

## Scope and observed baseline

Branch `bugs/https-enforcement` follows the already-pushed `feature/wellnexa-privacy-statement` source at `7f5405a`. This task changes the three versioned root `.htaccess` files (`Wellnexa/public/.htaccess`, `hosting/netfirms/portal/.htaccess`, `hosting/netfirms/portal-landing/.htaccess`). The roots map to `/public_html/wellnexa.ca`, `/public_html/wellness-common-portal` (both clinic portal hostnames) and `/public_html/wellness-portal` (central Admin). The nested API `.htaccess` files were tested and restored to their original content. No PHP, JS, dependencies, schema, private configuration, identity grants or mail/SMS flow changes. The Livin Lively public site already has an HTTPS 302 rule and is not changed here.

Before this change, verified-TLS `https://` requests succeeded for `wellnexa.ca`, `wellnexa.copihue.ca`, `livinlively.copihue.ca` and `portal.copihue.ca`, so new certificates are not needed. On 5 November, each of those four hosts also returned HTTP 200 without a redirect. The Wellnexa privacy page and both clinic `/client` shells were likewise accessible over HTTP; an HTTP public clinic API returned JSON. Existing project [privacy acceptance](WELLNEXA_PRIVACY_STATEMENT_2026-11-04.md) found missing HSTS/CSP/nosniff/referrer headers on the Wellnexa website. This is current hosted evidence for these requests only; not a full host/header inventory.

Netfirms [documents enabling SSL and adding `.htaccess` HTTPS redirects](https://www.netfirms.com/help/article/enable-your-free-ssl-certificate). The existing Livin Lively public site uses `RewriteCond %{HTTPS} !=on` successfully on this account. [OWASP transport guidance](https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Security_Cheat_Sheet.html) recommends HTTPS for all pages and rejecting unencrypted API requests where feasible. The new rules use fixed HTTPS destinations, keeping the two clinic portal hosts separate. They reject HTTP `/api` requests before PHP; redirecting after a POST body has arrived would not undo plaintext disclosure. They preserve HTTPS routes and query strings through the existing rewrites.

## Staged rollout

The first source version uses **302** on non-API pages to test live routing without caching a permanent mistake. No HSTS is enabled in this stage. `mod_rewrite` syntax cannot be exercised locally because Apache is not installed; successful source checks do not replace hosted acceptance. The first hosted Wellnexa root upload confirmed a one-hop HTTP `/privacy?lang=fr` → HTTPS 302 with path/query preserved, and the HTTPS page/browser remained healthy. The bare HTTP `/` intermittently returned a cached older 200; a fresh query returned the 302. Crucially, a fresh HTTP `/api/public/services` request still returned JSON 200. The root API rule therefore did **not** protect the nested API on this host.

The second source version tried a nested API denial rule. After the owner uploaded that one file, HTTPS `/api/public/services` and `/api/public/contact` returned hosting 404 pages while the direct HTTPS `catalogue.php` entry still returned JSON. An HTTP API request redirected to `https://wellnexa.ca/public/services`, dropping `/api`. The owner restored the backed-up nested API `.htaccess`; HTTPS `/api/public/services` again returned JSON 200. This hosted failure is retained as a regression finding. The corrected root rules now match the complete `%{REQUEST_URI}` and redirect using that complete path. Whether the parent rule is inherited into the nested API remains unverified; test that on Wellnexa before any portal upload. If HTTP API remains accessible, obtain hosting-level enforcement or a host-approved rewrite design before continuing. Do not infer API safety from the page redirect.

1. Confirm the active Netfirms domain mappings in [deployment layout](DEPLOYMENT_LAYOUT.md) and that HTTPS opens without browser certificate errors on each host. These read-only checks passed on 5 November.
2. Download/back up each live root `.htaccess` privately. Compare it with the corresponding staged file. Stop if the remote file has hosting-only rules not represented in source; reconcile them before replacing. Do not mirror-delete directories, overwrite PHP files or upload a full portal bundle.
3. Replace only the backed-up Wellnexa website root `.htaccess` with the corrected root file. Verify fresh HTTP page paths and query strings redirect to the same HTTPS path; HTTP `/api/public/services` returns 403; HTTPS `/api/public/services` still returns JSON. Test direct PHP API entry as well. Restore the root backup if HTTPS breaks, paths change or HTTP still reaches PHP. Leave the restored nested API `.htaccess` alone.
4. Only after the website host passes, compare/back up the live shared portal root `.htaccess`, then upload that one file, testing **both** `wellnexa.copihue.ca` and `livinlively.copihue.ca` on `/`, `/client`, `/staff/login`, `/api/v1/site-config` and a missing route. Expected HTTP pages: one 302 to the same HTTPS host; HTTP API: 403; HTTPS behavior unchanged. The two hostnames must remain distinct. Test browser sign-in entry without submitting accounts or real records. Restore the root backup if a host or API fails.
5. Only after both clinic hosts pass, compare/back up the live central Admin root `.htaccess`, then upload and test `portal.copihue.ca` on `/`, `/admin/`, `/api/v1/...` and legacy redirects. Verify HTTPS Admin still loads with the existing workforce sign-in option. Do not change identity provider redirects or grants.
6. After these 302 checks and exact remote `.htaccess` backups are recorded, change the tested page redirects to 301 (or 308 only if every client/hosting path has been tested) and add a short host-only HSTS policy on verified HTTPS responses. Recheck all hosts and APIs, including direct HTTP access, before increasing HSTS duration. Do **not** use `includeSubDomains` until every current and future child host is reviewed. CSP, cache, no-sniff, referrer, cookie and provider/endpoint controls need their own per-host review; a redirect alone does not close SEC-03 or the real-data gate.

For rollback, restore the one backed-up root `.htaccess` at the same directory; retain all other files and the record of which requests failed. If a CDN/proxy caches an earlier 200 or 302, use a fresh path/query and confirm the server-side response before concluding rollback succeeded. Do not restore an older application package or database to undo a rewrite rule.

## Validation and release status

Pending: corrected source whitespace/diff review, staged ZIP/hash inspection, hosted API denial and redirect checks, permanent redirect/HSTS follow-up and full host/header acceptance. The owner backed up and compared the live Wellnexa root and nested API files. No database test or frontend build is relevant to these rewrite-only files; no API implementation is changed. Hosted tests use safe read-only routes and fresh cache-busting query strings, with no credentials or PHI over HTTP. Test 500/loop recovery before asking external testers to authenticate.

## Control ledger for this change

Review date 5 November 2026; source SHA and hosting operator/reviewer decision will be added after commit and hosted checks. Status describes this slice only. The [29 October audit](COMPLIANCE_AUDIT_2026-10-29.md) is historical; the observed HTTP behavior above supersedes its unverified transport entry for the checked hosts.

| Control | Status | Evidence / next action |
| --- | --- | --- |
| GOV-01 | Unverified | No custody or contract change; confirm host ownership with custodian. |
| GOV-02 | Partial | Transport is project policy; refresh applicability register separately. |
| GOV-03 | Unverified | No PIPEDA scope decision in this config change. |
| GOV-04 | Not applicable | No clinical/device function added. |
| GOV-05 | Partial | Hosts/data categories identified; full flow/PIA/TRA remains open. |
| PRIV-01 | Partial | Public statement remains live; portal notice link awaits coordinated frontend release. |
| PRIV-02 | Unverified | No authority/consent change; test existing boundaries separately. |
| PRIV-03 | Unverified | No record restriction change; HTTP denial must not bypass HTTPS authorization. |
| PRIV-04 | Unverified | No representative/treatment-consent change. |
| PRIV-05 | Unverified | No access/correction workflow change. |
| PRIV-06 | Unverified | No retention/backup process change. |
| PRIV-07 | Unverified | No incident runbook change. |
| AUTH-01 | Partial | No token code change; verify HTTPS sign-in callbacks continue working. |
| AUTH-02 | Partial | Fixed host destinations preserve clinic identity; verify both portal hosts. |
| AUTH-03 | Unverified | No session configuration change; cookie/session acceptance remains separate. |
| AUTH-04 | Unverified | No token storage change; HTTPS is necessary but insufficient. |
| AUTH-05 | Not applicable | No API grants or privileges added. |
| SEC-01 | Partial | Three root rewrite files only; inspect final artifacts for private material. |
| SEC-02 | Partial | HTTPS certificates and one hosted page redirect work; nested HTTP API still reached PHP; corrected root denial requires hosted proof. |
| SEC-03 | Partial | Website redirect verified; nested API attempt broke HTTPS and was rolled back. Other hosts, headers/cache/HSTS pending. |
| SEC-04 | Partial | Fixed redirect hosts avoid user-controlled destination; verify pathological paths/query. |
| SEC-05 | Unverified | HTTP API requests were not refused in the first hosted test; abuse/rate/body controls unchanged. |
| SEC-06 | Unverified | No API audit change; host redirect/denial logs need operational review. |
| SEC-07 | Partial | Redirect exposes no token/body in rule; verify server/proxy logs and errors. |
| SEC-08 | Not applicable | No new endpoint or subprocessor. |
| SEC-09 | Not applicable | No message content or delivery change. |
| DEV-01 | Partial | Dedicated bug branch and affected config reviewed; commit/push pending. |
| DEV-02 | Partial | Narrow rewrite scope; hosted behavior is the decisive test. |
| DEV-03 | Not applicable | No dependency/toolchain change. |
| DEV-04 | Partial | No frontend/PHP/SQL code; staged config and hosted regression pending. |
| DEV-05 | Partial | Recheck public/client/staff navigation and bilingual URLs after redirects. |
| DEV-06 | Partial | Check loops and one-hop redirects; representative traffic/performance separate. |
| REL-01 | Partial | This ledger covers all controls; owners/reviewer signatures pending. |
| REL-02 | Partial | Config-only local checks pending; PHP/SQL suites not applicable. |
| REL-03 | Missing | Final ZIP and hosted acceptance pending. |
| REL-04 | Missing | Real-data release remains blocked by wider open controls. |
| REL-05 | Partial | Owner requested HTTPS setup; Wellnexa root and API backups recorded, failed API change restored; other hosts pending. |
| REL-06 | Partial | Final report must include file/permission/dependency scope, commands, hosted evidence and rollback. |

**Decision:** NOT READY FOR RELEASE until hosted checks and the wider real-data gate are completed.
