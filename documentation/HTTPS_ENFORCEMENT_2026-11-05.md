# HTTPS enforcement for Netfirms app hosts — 5 November 2026

## Scope and observed baseline

Branch `bugs/https-enforcement` follows the already-pushed `feature/wellnexa-privacy-statement` source at `7f5405a`. This task changes only three versioned root `.htaccess` files: `Wellnexa/public/.htaccess` for `/public_html/wellnexa.ca`, `hosting/netfirms/portal/.htaccess` for `/public_html/wellness-common-portal` (both clinic portal hostnames), and `hosting/netfirms/portal-landing/.htaccess` for `/public_html/wellness-portal` (central Admin). No PHP, JS, dependencies, schema, private configuration, identity grants or mail/SMS flow changes. The Livin Lively public site already has an HTTPS 302 rule and is not changed here.

Before this change, verified-TLS `https://` requests succeeded for `wellnexa.ca`, `wellnexa.copihue.ca`, `livinlively.copihue.ca` and `portal.copihue.ca`, so new certificates are not needed. On 5 November, each of those four hosts also returned HTTP 200 without a redirect. The Wellnexa privacy page and both clinic `/client` shells were likewise accessible over HTTP; an HTTP public clinic API returned JSON. Existing project [privacy acceptance](WELLNEXA_PRIVACY_STATEMENT_2026-11-04.md) found missing HSTS/CSP/nosniff/referrer headers on the Wellnexa website. This is current hosted evidence for these requests only; not a full host/header inventory.

Netfirms [documents enabling SSL and adding `.htaccess` HTTPS redirects](https://www.netfirms.com/help/article/enable-your-free-ssl-certificate). The existing Livin Lively public site uses `RewriteCond %{HTTPS} !=on` successfully on this account. [OWASP transport guidance](https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Security_Cheat_Sheet.html) recommends HTTPS for all pages and rejecting unencrypted API requests where feasible. The new rules use fixed HTTPS destinations, keeping the two clinic portal hosts separate. They reject HTTP `/api` requests before PHP; redirecting after a POST body has arrived would not undo plaintext disclosure. They preserve HTTPS routes and query strings through the existing rewrites.

## Staged rollout

The first source version uses **302** on non-API pages to test live routing without caching a permanent mistake. No HSTS is enabled in this stage. `mod_rewrite` syntax cannot be exercised locally because Apache is not installed; successful source checks do not replace hosted acceptance.

1. Confirm the active Netfirms domain mappings in [deployment layout](DEPLOYMENT_LAYOUT.md) and that HTTPS opens without browser certificate errors on each host. These read-only checks passed on 5 November.
2. Download/back up each live root `.htaccess` privately. Compare it with the corresponding staged file. Stop if the remote file has hosting-only rules not represented in source; reconcile them before replacing. Do not mirror-delete directories, touch `api/.htaccess` or upload a full portal bundle.
3. Upload only the Wellnexa website root `.htaccess`; test HTTP `/`, `/privacy?lang=fr`, a genuine missing page, `/api/public/services` and the HTTPS equivalents. Expected HTTP pages: 302 to the *same* HTTPS host with path/query intact; HTTP API: 403; HTTPS behavior unchanged. Check for loops and unexpected 500s. Restore the backup immediately if these checks fail.
4. Repeat for the shared clinic portal root `.htaccess`, testing **both** `wellnexa.copihue.ca` and `livinlively.copihue.ca` on `/`, `/client`, `/staff/login`, `/api/v1/site-config` and a missing route. The two hostnames must remain distinct. Test browser sign-in entry without submitting accounts or real records.
5. Repeat for central Admin `portal.copihue.ca` on `/`, `/admin/`, `/api/v1/...` and legacy redirects. Verify HTTPS Admin still loads with the existing workforce sign-in option. Do not change identity provider redirects or grants.
6. After these 302 checks and exact remote `.htaccess` backups are recorded, change the tested page redirects to 301 (or 308 only if every client/hosting path has been tested) and add a short host-only HSTS policy on verified HTTPS responses. Recheck all hosts and APIs, including direct HTTP access, before increasing HSTS duration. Do **not** use `includeSubDomains` until every current and future child host is reviewed. CSP, cache, no-sniff, referrer, cookie and provider/endpoint controls need their own per-host review; a redirect alone does not close SEC-03 or the real-data gate.

For rollback, restore the one backed-up root `.htaccess` at the same directory; retain all other files and the record of which requests failed. If a CDN/proxy caches an earlier 200 or 302, use a fresh path/query and confirm the server-side response before concluding rollback succeeded. Do not restore an older application package or database to undo a rewrite rule.

## Validation and release status

Pending: source whitespace/diff review, remote `.htaccess` comparison, staged ZIP/hash inspection, hosted 302 checks, permanent redirect/HSTS follow-up and full host/header acceptance. No database test or frontend build is relevant to these rewrite-only files; no API implementation is changed. Hosted tests will use `curl.exe -I --max-redirs 0` for safe read-only routes, `curl.exe` for HTTPS response/headers, and browser checks for public and sign-in entry pages. API HTTP denial can be checked with a harmless GET; no credentials or PHI are sent over HTTP. Test 500/loop recovery before asking external testers to authenticate.

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
| SEC-01 | Partial | Three public rewrite files only; inspect final artifacts for private material. |
| SEC-02 | Partial | HTTPS certificates work; HTTP 200 baseline requires staged remediation and hosted proof. |
| SEC-03 | Partial | Redirect/deny rules prepared; headers/cache/HSTS and full hosted results pending. |
| SEC-04 | Partial | Fixed redirect hosts avoid user-controlled destination; verify pathological paths/query. |
| SEC-05 | Partial | HTTP API requests refused at web root; abuse/rate/body controls unchanged. |
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
| REL-05 | Partial | Owner requested HTTPS setup; exact live files/backups and staged operator action pending. |
| REL-06 | Partial | Final report must include file/permission/dependency scope, commands, hosted evidence and rollback. |

**Decision:** NOT READY FOR RELEASE until hosted checks and the wider real-data gate are completed.
