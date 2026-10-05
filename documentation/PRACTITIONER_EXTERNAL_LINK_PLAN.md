# Practitioner external website or social page

Status: implemented locally; hosted database migration and deployment acceptance pending.

## Purpose and requirements

A virtual clinic can operate entirely from the portal. An independent practitioner may have a separate website or a Facebook page instead of a clinic-hosted public website. Allow the practitioner to publish one optional external URL on their public profile, which visitors can click from the portal and public website.

- Add an optional **Website or social page URL** field under Profile → Public practitioner card, with equivalent editing in administrator Team profiles.
- Store it on the clinic-scoped public practitioner profile, separately from private account information and the clinic's public website destination.
- Display a **Website / social page** action on the shared practitioner card and expanded practitioner profiles in both surfaces. Open the destination in a new tab, preserving the current booking journey.
- Permit complete HTTP(S) URLs, including social-page paths and query strings. Prefer HTTPS in examples. Reject executable schemes, malformed URLs, embedded credentials, and values longer than 2048 characters.
- Treat blank values as no link. Existing profiles start without a link, and clearing the field removes the action.
- Retain existing ownership, clinic isolation, active-practitioner and publication controls. Adding a URL does not let a practitioner publish their own profile.
- Provide English and French labels and guidance. Describe the field as public and optional.

## Implementation sequence

1. Add nullable `public_website_url` to `public_team_profiles` through a new migration and update the fresh-install schema.
2. Extend shared public-contact validation, practitioner self-service reads/writes and administrator team-profile reads/writes. Include the field in published practitioner catalogue and detail projections only.
3. Extend frontend profile forms and practitioner types. Add the external action to the shared contact component, with HTTP(S) checks and `noopener noreferrer` for new-tab links. Review every practitioner detail/card surface for coverage.
4. Add translations and document the field in the practitioner card documentation and user manual after implementation.
5. Verify valid personal-site and Facebook URLs, invalid schemes and credentials, blank/cleared values, existing profiles, tenant ownership, unpublished profiles, and links on both public and portal profiles. Run relevant API tests, frontend content/translation validation, TypeScript checks and builds.
6. Prepare a deployment package and instructions. Apply the database migration before the matching API and frontend deployment, then perform hosted acceptance checks.

## Related runtime URL configuration

The practitioner URL is profile data and changes through the portal without rebuilding. It does not replace the clinic runtime `PUBLIC_WEBSITE_URL` setting or the build-time `VITE_PORTAL_URL`.

The clinic public website destination now loads through a same-origin PHP endpoint; see [runtime website configuration](RUNTIME_WEBSITE_CONFIGURATION.md). A portal-only clinic should be able to omit its clinic public website URL and hide the corresponding navigation action rather than require an external website. Confirm this behavior as part of that change. Asset base paths, API routing and identity callbacks need separate review before making them runtime-configurable.

## Acceptance

A published practitioner in a portal-only virtual clinic can save a website or Facebook URL, and a visitor can open it from the practitioner's card or profile. Updating or clearing it takes effect on the next profile fetch without rebuilding either frontend. Existing booking, contact and profile publication behavior remains intact.
