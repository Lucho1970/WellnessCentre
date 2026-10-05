# Practitioner person card

The reusable practitioner card appears after a short hover (or keyboard focus) over a practitioner link in the guest catalogue, guest booking choices, public service details, practitioner directory, and team section. The portal practitioner detail page uses the same public contact actions in its expanded layout. The card uses a circular profile photo when available, preferred public name, professional title/credentials, and the bilingual public biography. Missing fields are omitted rather than filled from staff account data.

The public name is `public_team_profiles.public_name`, **not** the identity-provider display name. `booking_name` remains the shorter friendly name used in booking actions. Both can be edited by the practitioner under Profile → Public practitioner card, or by an administrator under Team profiles. Publishing, profile URL, and professional title remain administrator-controlled. Existing profiles retain their current public names until edited.

Contact details are a separate, opt-in set of fields: `public_contact_email`, `public_contact_phone` (international format such as `+12892975234`), and `public_contact_sms`. They start empty/false after migration 030. Neither staff sign-in email nor private notification preferences are copied into the public profile. When present, the card exposes `mailto:`, `tel:`, and optionally `sms:` links. Texting is only offered when `public_contact_sms` is enabled and a public phone is present. A published active practitioner is required before any card data can be fetched publicly. Public contact details are visible to anyone, including search engines, so use a business address/number if preferred.

## Deployment and checks

1. Back up the database and apply `api/database/migrations/030_practitioner_public_card_contact.sql` once. Do this **before** deploying the matching API and frontend; the new queries require these columns.
2. Deploy `wellness-api-private.zip` to the existing private `/wellness-api`, preserving `.env`, then `wellness-portal.zip` to `/public_html/willow-wellness-portal` and `wellness-public.zip` to `/public_html/wellness`. The neutral `portal.copihue.ca` landing page does not change.
3. In a practitioner account, open Profile, change the preferred public name and biography, and save. Enter a **test-only public** email/phone, opt in to Text, and verify the guest catalogue hover card and its links. Clear the fields and confirm the links disappear. Check the card with no photo, credentials, or bio. Verify French text and keyboard focus. Confirm private notification email/mobile never appear unless separately entered into the public fields.
4. From admin Team profiles, verify the same fields and the published setting. Unpublish the profile and confirm it disappears from public endpoints. The practitioner cannot publish their own profile or edit the admin-controlled professional title.

Local checks: `php api/tests/practitioner-public-card.php`, `npm run build` in `Frontend`, and the build smoke suite. Hosted database integration and real `tel:`/`sms:` handler behaviour require browser/device testing.

## Website or social page implementation

The optional external website or social-page URL is now implemented locally. Practitioners and administrators can edit it; cards and detail profiles expose the action. This supports independent practitioners and portal-only virtual clinics. Hosted deployment acceptance remains pending. See [the requirements and implementation plan](PRACTITIONER_EXTERNAL_LINK_PLAN.md).

## Website or social page

Practitioners can maintain an optional Website or social page URL under Profile → Public practitioner card; administrators can edit it under Team profiles. Full HTTP(S) URLs, including Facebook links with query parameters, are accepted. Published cards and practitioner detail pages on both frontends show a Website / social page link that opens in a new tab. Blank values hide the action. The field remains separate from private staff data and the clinic public website destination. Apply migration 031 before deploying the matching API and frontends. Hosted acceptance remains pending.
