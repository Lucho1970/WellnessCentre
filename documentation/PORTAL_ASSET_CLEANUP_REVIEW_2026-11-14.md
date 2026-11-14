# Shared portal asset cleanup review — 14 November 2026

## Scope and observed inventory

The shared `/public_html/wellness-common-portal/assets/` folder serves both Wellnexa and LivinLively. Repeated frontend releases leave content-addressed JavaScript/CSS files in place so open tabs and rollback shells can continue to load lazy chunks. The owner asked whether older copies could be removed. **No hosted file was deleted in this review.** This is `feature/portal-asset-cleanup-review`, based on the deployed client-display branch at `6faa1fc`.

After the current `client-account-display-2026-11-14` portal upload, the owner downloaded the entire remote `assets/` folder to `D:\Dev\WellnessCentre-backup\asset-cleanup-2026-11-14\assets\` without changing the server. The confirmed local inventory has **632 files / 24,766,141 bytes** in one directory. All **131 distinct assets** in the current 69-file package and immediate 68-file rollback package are present and SHA-256 match their package manifests; six filenames overlap. Both live clinic hosts served the current shared shell hash `2bc51f6107a50c5b452bf2f50a52381f6ca52594d012eb58cd340689cc6efafc` at the time of review.

The remaining 501 files group by preserved modification date: 64 on 29 October, 124 on 1 November, 62 on 2 November, 127 on 5 November, 62 on 12 November and 62 on 13 November 2026. The conservative **keep** list contains the 131 current/rollback paths plus all 124 November 12–13 paths: **255 files**. The proposed older candidate list contains **377 files / 14,724,477 bytes**, dated through 5 November; 376 are `.js` and one is `.css`. These are *candidates*, not automatically safe or approved for deletion. Filenames in both lists partition the 632-file backup exactly. The private local lists are:

- `D:\Dev\WellnessCentre-backup\asset-cleanup-2026-11-14\KEEP_CURRENT_ROLLBACK_AND_RECENT_255.txt` (SHA-256 `90f9739dee57fda07f2cf3c4b2e36440ecbdd4a8e578afdf266750b81d0f18df`).
- `D:\Dev\WellnessCentre-backup\asset-cleanup-2026-11-14\PROPOSED_OLDER_ASSETS_377.txt` (SHA-256 `93b00f7aa9ebd227bd9ccc405cdf52c35f9261b57cf462d554bc3358e7be8c31`).

## Safe decision and action sequence

Do not mirror-delete the remote folder. Current and rollback entry pages require the 131 manifest assets, and open tabs loaded before 12 November may request older chunks after the page has opened. The full backup makes recovery possible but does not prevent a live old tab from failing. Before deleting even the 377 candidates, confirm that all active testers can refresh/reopen their portal tabs, that no retained rollback shell earlier than the immediate previous release will be used, and that no other entry page in the shared root references the candidates. Compare the *current* remote inventory with this backup immediately before action; stop if it differs. Obtain explicit owner authorization for the exact 377-file list and a restore method for individual files. Delete only named candidate paths, in a staged batch, then recheck both hosts' shell/assets, client/staff/practitioner routes and a signed-in synthetic client. Monitor for chunk-load failures and restore affected files from the backup if needed. Keep the 255-file list intact. The 124 November 12–13 files can be reviewed after a separate grace period and fresh inventory.

No code, dependency, API, SQL, identity, permission, consent, endpoint, mail/SMS or private configuration change is proposed. This review does not authorize real PHI use or a site-wide deployment. The current portal rollback remains the saved `index.html` at `D:\Dev\WellnessCentre-backup\client-account-display-2026-11-14\live-index-before.html`; restoring an older entry page also requires its referenced assets. Release/operations owner: Luis Duran. A production deletion needs a separate REL-05 decision and operational record.

## Scoped 38-control ledger

Review date: 14 November 2026. Statuses address this inventory and proposed cleanup only; unresolved application findings in the current audit and release records remain. For every unverified item, the accountable owner must use the existing release process; this document is not an exception.

| Control | Status | Evidence or next action |
| --- | --- | --- |
| GOV-01 | Unverified | Custodian/agent roles unchanged; owner/legal review. |
| GOV-02 | Unverified | Applicability register unchanged; release review. |
| GOV-03 | Unverified | PIPEDA assessment unchanged; owner/legal review. |
| GOV-04 | Unverified | Intended use unchanged; clinical review remains. |
| GOV-05 | Unverified | Data inventory/PIA/threat review unchanged; asset retention belongs in operational inventory. |
| PRIV-01 | Unverified | Notice/contact unchanged. |
| PRIV-02 | Unverified | No collection/use/disclosure change. |
| PRIV-03 | Unverified | No record restriction change; hosted checks remain. |
| PRIV-04 | Unverified | Representative process unchanged. |
| PRIV-05 | Unverified | Access/correction process unchanged. |
| PRIV-06 | Partial | Build-asset rollback retention considered; clinical/backup retention schedules remain. |
| PRIV-07 | Unverified | Incident/breach runbook unchanged. |
| AUTH-01 | Unverified | Identity validation unchanged; synthetic smoke required after cleanup. |
| AUTH-02 | Unverified | No role/clinic boundary change. |
| AUTH-03 | Unverified | Session controls unchanged; old-tab risk noted. |
| AUTH-04 | Unverified | Browser storage exception unchanged. |
| AUTH-05 | Unverified | No API grant change. |
| SEC-01 | Partial | Asset names/hashes only in local lists; no private files committed. |
| SEC-02 | Partial | Backup kept outside web root; encryption/access/recovery operational evidence remains. |
| SEC-03 | Partial | Current shell hash checked; full header/TLS review remains. |
| SEC-04 | Unverified | No source/input processing change. |
| SEC-05 | Unverified | No request/upload/worker change. |
| SEC-06 | Unverified | Hosting-level file deletion audit/log procedure remains to be recorded. |
| SEC-07 | Unverified | Chunk-load failure monitoring/restoration still to be rehearsed. |
| SEC-08 | Unverified | No external endpoint change. |
| SEC-09 | Unverified | Messaging unchanged. |
| DEV-01 | Partial | Dedicated branch and preserved source; review/commit/push pending. |
| DEV-02 | Partial | Inventory classification reviewed; no runtime code edit. |
| DEV-03 | Unverified | No dependency/toolchain change; earlier advisory review remains. |
| DEV-04 | Partial | Manifest/local SHA checks and live shell reads; no code build or full browser rerun for doc-only review. |
| DEV-05 | Unverified | Accessibility unchanged; smoke after any deletion required. |
| DEV-06 | Partial | 14.7 MB older candidates identified; no runtime performance assertion. |
| REL-01 | Partial | Dated scoped ledger; owner decision pending. |
| REL-02 | Partial | Read-only asset/manifest checks; broad checkpoint not claimed. |
| REL-03 | Partial | Current/rollback hashes and hosted shell observed; deletion acceptance pending. |
| REL-04 | Missing | Real-data release remains separately blocked. |
| REL-05 | Partial | No delete; exact-list approval and restore/smoke plan required. |
| REL-06 | Partial | Inventory, candidate list, keep set, risk and restore path recorded; action pending. |

RELEASE STATUS: NOT READY FOR RELEASE
