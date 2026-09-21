# Public team profiles

The Contact page includes an **Our Team** directory. Practitioners are shown before administration. Each card directly presents the approved profile image (or initials), public full name, public title, practitioner credentials/discipline and public summary. An active practitioner can expose a friendly **Book with _name_** action that carries their public practitioner ID into availability browsing. Pop-up person cards are reserved for future compact contexts, such as hovering or focusing a practitioner's name in an appointment view.

## Names

Practitioner identity and presentation names are intentionally separate:

- `users.given_name` and `users.family_name` store the structured personal name.
- `users.display_name` is the internal staff/administration display name.
- `public_team_profiles.public_name` is the full name approved for public cards and service pages.
- `public_team_profiles.booking_name` is the familiar name used in calls to action, such as **Book with Esther**. It may be a nickname or include a surname initial when two published practitioners use the same given name.

The administration screen warns about duplicate published booking names but allows the clinic to choose the most recognizable disambiguation. Public APIs expose only the approved public and booking names, not the internal display name.

Migration `010_practitioner_public_names.sql` adds the public-name fields and preserves each existing profile's visible name. Existing practitioners with missing structured names are given a best-effort first/last suggestion in the edit form and should be reviewed the next time their account is edited.

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
