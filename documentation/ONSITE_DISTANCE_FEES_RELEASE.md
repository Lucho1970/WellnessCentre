# On-Site distance fees deployment

For current package destinations and shared portal hosting, see [Deployment layout](DEPLOYMENT_LAYOUT.md).

This release adds two driving-distance thresholds per practitioner/service assignment, a flat extra fee, address-specific quotes before confirmation, and an audited waiver per session before billing. It includes the existing Wellnexa portal theme and receipt workflow. The standalone wellnexa.ca website does not need an upload.

## Before uploading

1. Back up the hosted database and current private API/portal files. Pause booking and payment activity for the short deployment window.
2. In phpMyAdmin select **wellness_centre**. Run `sql/onsite-distance-fees-preflight.sql`. The prerequisite tables must all say **OK**. Migration 043 (the deployed receipt update) must already be installed.
3. The new-column count must be **0** before the first migration. If it is 6, migration 044 is already installed: skip the migration and check postflight instead. A count from 1 to 5 means a partial update: stop and inspect the columns before retrying. MySQL/MariaDB DDL is not transactional; do not rerun ALTER statements blindly.
4. Run **sql/044_onsite_distance_fees.sql once** in wellness_centre. Do not import schema.sql, seed data, or migration 043 again.
5. Run `sql/onsite-distance-fees-postflight.sql`: expect six rows, all OK. Existing appointment fees are not changed by this migration; existing assignments have a NULL free threshold and retain the old flat-fee behavior.

## Copy the ZIP contents

| ZIP | Destination | Action |
| --- | --- | --- |
| wellness-api-private.zip | Existing private `wellness-api/` directory above public_html | Extract contents directly here; replace src, bin, vendor and composer files. Preserve the existing private .env. |
| wellness-common-portal.zip | `/public_html/wellness-common-portal/`, shared by both clinic hosts. Extract contents once, including hidden `.htaccess` and `api/`; do not nest another folder. |

The private API is shared by the clinic portals. Apply the database migration before replacing it. The portal ZIP is clinic-host aware and updates all clinic hosts sharing this directory; it does not contain a clinic-specific database or login identity. Existing portals with NULL free thresholds keep their existing flat-fee bookings. Refresh the browser after upload; reset PHP OPcache through hosting if it retains old PHP files.

No new .env flag is needed. Keep the existing Google Address Validation/Routes configuration and ADDRESS_VALIDATION_SIGNING_KEY; OpenSSL with AES-256-GCM is required (normally already available for authentication). Keep SESSION_RECEIPTS_ENABLED=true to test receipt issuance. The package includes PDF dependencies; existing PHP DOM/mbstring requirements and GD for PNG logos still apply.

## Configure and try it

1. Sign in as clinic administrator. Open **Services**, select a service, choose **Assignments**, then **Edit assignments**. Under the practitioner enable **On-Site visits**.
2. Set **Free travel distance (km)**, **Maximum driving distance (km)** and **Flat extra travel fee CAD**. For example: 20 km, 50 km and $15. Save assignments. Distances are whole kilometres; the free distance must be 0 through the maximum, and the maximum must be 1 through 500 km. Travel buffers remain separate and still reserve time even when travel is free or waived.
3. A blank free distance keeps legacy flat fees at all covered distances. Enter the maximum as the free distance to offer free travel throughout the service area. At exactly the free threshold travel is free; at exactly the maximum the address is still bookable. Beyond the maximum, booking is rejected.
4. Start a test On-Site booking. Validate its complete address. The quote uses road-driving distance from the practitioner's saved starting location (or the clinic base when no private origin is configured). Clients see the fee before confirmation; private origin details and exact origin distance are not exposed in their token or quote. Actual Google Maps validation must be tested on hosting with your configured keys.
5. Confirm one booking inside the free area and another within the paid area. The confirmation subtotal must include the quoted travel fee. Check that an address beyond the maximum is rejected. New threshold pricing always requires a fresh address check; old staff area approvals do not bypass fee calculation.
6. Sign in as the assigned practitioner, open the appointment details and enter **Reason for waiving travel fee**, then choose **Waive travel fee for this session**. The fee becomes zero. Clinic administrators can also waive it. Other practitioners and clients cannot. A waiver is a one-way reduction for a confirmed, rescheduled or completed unbilled session; there is no restore/increase action in this release.
7. Complete the test session and issue its receipt through the existing manual payment workflow. Review tax and the full amount actually received. The receipt must use the reduced total, show zero travel charge and note the waiver. Download it from the client portal. Issued PDFs remain immutable.
8. Test a recurring series if used: each occurrence gets its own saved price. Waive one occurrence and verify the others retain their fees. Assignment changes and rescheduling do not retrospectively reprice existing appointments.

Payment/invoice issuance and waivers use the same clinic/appointment lock order, preventing a fee change during payment. An existing active invoice blocks the waiver even if an appointment is still marked completed. Refresh after another user changes the session. Waiver reason, actor, date and original assessed fee are retained internally; clients' receipts do not show the internal reason.

## Validation and rollback

Local automated validation covers fee boundaries, stale/tampered proofs, ownership/clinic isolation, legacy approvals, recurring snapshots, a waived session's PDF/client download, frontend builds and browser regressions. It uses synthetic local data and mocked Google responses; hosted database, Google keys and hosting acceptance remain your deployment checks.

If acceptance fails, restore the prior API and portal backups together and leave the additive columns in place. Do not drop waiver columns or overwrite the database after new sessions/payments have been recorded. Setting an assignment's free distance back to blank restores flat fees for future bookings only. Do not restore a database backup over later live payments.

The package manifest identifies the committed source revision and contains file hashes. Keep this release and its backup until hosted acceptance is complete.
