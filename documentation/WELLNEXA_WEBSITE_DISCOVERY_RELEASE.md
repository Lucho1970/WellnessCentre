# Wellnexa website discovery and practitioner social links

## What changed

The marketing website has sticky translucent navigation, a `/join` page for practitioners, local `/services/{slug}` pages and local `/practitioners/{slug}` pages. Home-page practitioner cards are compact; detail pages show published biography, credentials, photo, services, public contact actions and social icons. “Book with me” hands the practitioner ID to Wellnexa availability. Service details include published description, preparation instructions, durations and delivery options. No medical copy or practitioner data is fabricated. Booking still takes place in the clinic portal.

The public service “From” price now considers price overrides from active, published, bookable practitioners in the current clinic. If no eligible practitioner is published, the existing service/duration price is the fallback. Travel fees remain assessed during booking; the marketing price is not a final quote.

Social links are an explicit opt-in list of up to 20 platform names and HTTPS profile URLs. The practitioner edits them in **Profile → Public practitioner card** in each clinic. Labels allow networks beyond the common platforms. Existing website/email/phone fields remain separate. Links open in a new tab with accessible labels; uncommon platforms use a compact letter icon. Older API clients that omit `social_links` preserve the saved list. Clinic admin edits also preserve it. Saving an empty list removes all social links. Publication and clinic boundaries are unchanged.

## Database update, before API deployment

Back up the hosted database and select `wellness_centre` in phpMyAdmin. Check whether the column exists:

```sql
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM information_schema.columns
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'public_team_profiles'
  AND COLUMN_NAME = 'social_links';
```

If there are no rows, apply `api/database/migrations/046_practitioner_social_links.sql` **once**. Run the check again; it should return `social_links` with type `text`. If it already exists, do not rerun the ALTER. This migration adds one nullable column; it neither publishes existing profiles nor imports social accounts.

## Package destinations

1. Extract `wellness-api-social-links-patch.zip` into `/wellness-api/`, merging its `src/Service/` directory. It contains only `CatalogService.php`, `PractitionerPublicProfileService.php` and the new `PublicSocialLinks.php`. Keep all other API files and private configuration in place. Requires the currently deployed API baseline; this is a patch, not a fresh installation.
2. Extract `wellness-common-portal.zip` into `/public_html/wellness-common-portal/`, including hidden files. All clinic host mappings continue using the shared code.
3. Extract `wellnexa-website.zip` into `/public_html/wellnexa.ca/`, including root `.htaccess`, `api/.htaccess` and `api/catalogue-lib.php`. Do not create an extra nested directory. No changes to Livin’ Lively's marketing site or the general portal landing are required.

Every generated package manifest identifies the source revision. No new private environment settings are required. Apply the migration first: the new API queries require its column. An API rollback may leave the nullable column in place without dropping saved public links.

## Verify after upload

- Refresh the Wellnexa homepage; scroll and check sticky menu, section anchors and mobile wrapping.
- Open a service and practitioner, refresh their detail URLs, then check booking links target `wellnexa.copihue.ca` with the correct service/practitioner selection.
- As Esther, add two social links under the public card, save and reload. Confirm they appear on her published website profile; remove one and verify removal. Unpublished profiles must remain hidden.
- Test prices against a known practitioner override. Final pricing, availability and travel fees are confirmed in the booking portal.
- Open `/join` and its enquiry link. **Publish the clinic's real contact email/phone in clinic public settings before inviting enquiries**; no email address, application form or message delivery is invented by this page.

Local checks cover marketing and shared frontend builds, website browser regression, practitioner social-link save/remove browser flow, PHP public projection/validation and real MariaDB clinic isolation, migration, social persistence and practitioner price overrides. Hosted Apache rewrites and the operator's production database remain separate acceptance checks.

Validation on 17 October 2026: both frontend production builds passed (including public/portal/application-admin), 1,957 EN/FR portal translations validated, 12 website browser scenarios and two focused portal browser scenarios passed, 25 PHP website projection assertions and existing public-card validation passed, and 16 social/profile/pricing SQL checks plus 34 existing booking checks passed on MariaDB 11.4.8. Mobile profile and homepage screenshots were inspected. The initial portal browser run timed out during cold startup; the focused rerun passed with a 90-second startup allowance and one worker.

Generate deployment packages from committed source with `scripts/build-wellnexa-discovery-deployment.ps1 -ReleaseName wellnexa-discovery-2026-10-17`. This reuses the shared portal production configuration, rebuilds Wellnexa with its clinic portal hostname, includes migration 046, and records the source revision and ZIP hashes. Generated files stay outside Git.
