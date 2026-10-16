# Practitioner travel settings

Practitioners can open **Profile → My travel settings** in their clinic portal and edit the maximum driving distance, free travel distance, flat extra travel fee in CAD and travel minutes each way for their active assigned On-Site services. The clinic administrator still manages assignments, locations, visit modes and the service catalogue. Settings remain on each clinic's local practitioner/service assignment; a global practitioner identity does not synchronize them between clinics.

## Upload

Back up the two private API files being replaced and the current common portal files. Keep the existing private `.env`, dependencies, uploads and runtime directories. Upload both packages from this release:

| Archive | Extract contents into |
| --- | --- |
| `wellness-api-practitioner-travel-patch.zip` | `/wellness-api/` |
| `wellness-common-portal.zip` | `/public_html/wellness-common-portal/` once for all clinic hosts |

The API patch contains only `src/Api.php` and `src/Service/PractitionerTravelSettingsService.php`. It extends the current shared API; it is not a standalone API installation. The portal archive includes its thin `api/` entries and hidden `.htaccess`. Do not nest another directory, put the private files in public_html, or copy the portal package to either marketing website or the general `/public_html/wellness-portal` landing. Install the API patch first, then the matching portal. Refresh old browser tabs; reset PHP OPcache through hosting if it retains old PHP code.

**No new database migration is required. Migration 044 must already be installed**, as confirmed on the hosted database. Run the included read-only `sql/onsite-distance-fees-postflight.sql` against `wellness_centre` if checking another environment: expect six OK rows. Do not rerun 044 when the columns exist, or import schema/seed files. No new Composer dependency, environment flag or identity-provider configuration is required.

See [Deployment layout](DEPLOYMENT_LAYOUT.md) for all confirmed server folders. Marketing sites and central administration need no upload for this feature.

## Use and hosted acceptance

1. Sign in as Esther in Wellnexa, switch to the practitioner workspace if needed, and open **Profile**. Scroll to **My travel settings**. Administrator-only accounts do not see this panel; a practitioner role and active local assignment are required.
2. Each card represents one active assigned service with On-Site visits enabled. An empty panel asks for administrator assignment configuration. Practitioners cannot add a service, enable a visit mode, change another practitioner or alter service/location assignments through this panel.
3. Try maximum 50 km, free distance 20 km, flat extra fee 15 CAD and travel 30 minutes each way. Free distance may be 0 through the maximum, and maximum is 1–500 km. A blank free distance preserves legacy flat pricing at every covered distance. Fees allow zero through 100000 CAD; travel buffers allow zero through 240 minutes in 15-minute increments.
4. Save and reload the page. Verify the selected service retained the values and another service retained its own settings. Check the same person's assignment in another clinic separately: it must retain that clinic's settings.
5. Preview a new booking. Address validation uses the new distance and fee policy; available times include the new travel buffer before and after the visit. A changed fee policy invalidates the old coverage quote, and the address must be validated again. Do not confirm a live test appointment unless intended.
6. Open an existing appointment: its saved travel fee and travel buffer remain unchanged. Per-session fee waivers still use the separate appointment action.
7. If another tab or an administrator edits the assignment while the form is open, saving the stale form must report a conflict. Select **Reload travel settings**, review the latest values and save again. Removing/disabling an assignment prevents further saves.

Updates are audited with practitioner, clinic, service and before/after travel values. The save transaction locks the clinic before the assignment, matching assignment administration and booking confirmation. It never updates existing appointments.

## Build and rollback

From clean committed source, run:

```powershell
./scripts/build-practitioner-travel-deployment.ps1 -ReleaseName YOUR-UNIQUE-RELEASE
```

The release contains the two ZIPs, this guide as README, the shared deployment guide, read-only prerequisite SQL, and a manifest with source revision, destinations and SHA-256 hashes. Hosted acceptance remains pending until checked on Netfirms.

To roll back, restore the previous private `src/Api.php`, remove the newly added service file only if it was absent before this release, and restore the matching common portal index/assets/routing files. The stored assignment values remain valid for the administrator editor; no database rollback is needed. All clinic hosts sharing the portal roll back together.

## Local validation

The full frontend build and 1901 EN/FR translation checks passed. Five browser regressions cover saving/reloading, independent service cards, concurrent updates, empty assignments, administrator-only visibility, the existing private work-location editor, and French mobile field validation/layout. Screenshots were inspected. Real MariaDB acceptance passed 79 On-Site pricing/waiver/travel checks and 34 recurring-booking checks. The new checks cover wrong-clinic/wrong-practitioner requests, inactive assignments/practitioners, mode restrictions, strict numeric limits, stale practitioner/administrator edits, unchanged appointments and colleague/other-clinic assignments, refreshed coverage quotes, booking options and audit metadata. PHP lint and 38 mobile availability/delivery/booking-request checks passed. Hosted database and identity-provider acceptance remain separate.
