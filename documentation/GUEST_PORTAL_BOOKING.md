# Guest portal booking split

The public website remains the CMS-led explanation of the clinic, services, and practitioners. It no longer runs an availability search. Its booking actions link to public booking routes on the portal. Existing public `/book` and `/#booking` addresses redirect to the portal, preserving valid hints.

`willowwellness.copihue.ca/` is Willow's guest-accessible catalogue of structured services, published bookable practitioners, and duration prices; it does not render a time picker or request availability. `/services/{slug}/book` is the public, shareable booking page for a specific treatment, with `?duration={minutes}` for a specific length. `/availability` retains practitioner-first/general discovery. None of these routes requires staff or client authentication. The guest availability API omits room IDs and refuses unpublished services or practitioners without a published booking profile. It returns free slots only, not appointments, time off, or client data. `portal.copihue.ca/` is the separate neutral landing page.

Existing staff Entra app registrations may still use the portal root as their redirect URI. A root request carrying an authorization response (`state` with `code` or `error`) is routed to the staff MSAL callback bootstrap; an ordinary root visit is not. Staff routes and callbacks must be regression-tested on the deployed host.

Selecting **Book this time** opens portal `/book` with the selected IDs and start time. The portal captures that short-lived intent in tab-scoped session storage, removes it from the URL, and initiates client sign-in if no valid customer session exists. The existing onboarding and clinic approval rules are unchanged. After sign-in, the client workspace revalidates the service and slot against current authenticated booking options and availability. A selected slot is not reserved before final confirmation. An invalid direct `/book` link returns to guest browsing.

The portal can use each clinic's logo and administrator-managed primary colour, accent colour, and font. These settings are stored per clinic, though **host-to-clinic resolution is still single-clinic**: the current API selects the first active clinic. Do not point another clinic's portal hostname at this API until host-based tenant resolution and isolation are implemented and tested.

Deployment: apply `027_clinic_portal_theme.sql` before uploading the updated API and portal. If migration 026 from the preceding practitioner-qualifications release has not yet been applied, apply 026 first. Upload the portal and private API archives, verify guest browsing and booking handoff, then upload the public archive so public booking links switch over. Do not upload the public archive first.

Pilot checks:

1. Open the portal root in a private browser window. Services and published practitioners appear without a sign-in prompt, `/auth/me`, or availability request. The care and time selectors appear only after opening a booking route.
2. From the public site, click a service/practitioner booking link. The portal starts with that selection. Copy a duration link to a new private window and confirm the service and length are retained. An older public `/book` bookmark also redirects safely.
3. Choose a length, date, and time; click **Book this time**. Sign-in starts and the selected slot is checked again afterward.
4. Confirm that an occupied slot, practitioner time off, and unpublished service or practitioner never appear as guest bookable choices.
5. Test a newly registered client and an existing approved client; their existing onboarding permissions remain unchanged.
6. In Operations → Business settings, change portal colours/font and verify guest, client, and staff portal pages update. Confirm the public CMS site remains unchanged.
7. Check mobile layout and English/French language switching.
