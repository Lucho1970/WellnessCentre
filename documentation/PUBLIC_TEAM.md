# Public team profiles

The Contact page includes an **Our Team** directory. Practitioners are shown before administration. Each card directly presents the approved profile image (or initials), display name, public title, practitioner credentials/discipline and public summary. An active practitioner can expose a **Book a session** action that carries their public practitioner ID into availability browsing. Pop-up person cards are reserved for future compact contexts, such as hovering or focusing a practitioner's name in an appointment view.

## Privacy and publication

Profiles are opt-in. An active staff account is not public until a Super Admin opens **Operations → Public team**, completes the public fields and enables **Publish on the Contact page**. The public API never returns staff email, Entra identifiers, roles, private contact preferences or operational notes. Disabling the staff account, practitioner record or publication removes it from the public listing. The public image endpoint serves an application-owned profile image only while that profile remains published.

English content is required. French title and summary fall back to English when they are not yet supplied. The public slug uses lowercase letters, numbers and hyphens and is unique within the clinic.

## Deployment

1. Back up the database.
2. Apply `api/database/migrations/008_public_team_profiles.sql` before deploying the matching API and frontend.
3. Deploy the private API, both thin API entry points, and the public and portal frontend builds.
4. Sign in as Super Admin and open **Public team**.
5. Configure one practitioner without publishing it; confirm it is absent from `/api/v1/team`.
6. Publish it, confirm all approved details and actions appear directly on Contact, and test at desktop and mobile widths.
7. Follow **Book a session** and verify the intended practitioner is selected and only their assigned services are offered.
8. Unpublish the profile and verify its list and image URLs no longer expose it.

Do not add production rows directly to the migration. Publication is operational content and must be deliberate.
