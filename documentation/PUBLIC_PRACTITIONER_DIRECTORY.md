# Public Practitioner Directory

## Delivered scope

The public website now provides a practitioner-first discovery path at `/practitioners` and stable profile pages at `/practitioners/{slug}`. The pages reuse the governed public team profile and active catalogue assignments; they do not create a second practitioner biography or pricing source.

- The directory lists only active practitioners whose team profile is explicitly published.
- Visitors can filter practitioners by an assigned, active, published service.
- Profile pages show the approved public name, bilingual title and summary, credentials, discipline, approved application photo, and current published services.
- Each service retains its catalogue duration/price data and links back to its service detail page.
- Booking actions transfer allowlisted practitioner and service identifiers to `/book`; no personal or operational data is placed in the URL.
- Contact-page and service-detail practitioner cards link to the same stable profile page. All actions are visible and usable without hover.

## Names

Practitioner identity fields remain intentionally distinct:

- first and last name support structured internal records;
- internal display name supports staff and administration views;
- public full name is the approved name on a full public profile;
- preferred public name (`public_team_profiles.booking_name`) supports familiar list display and calls to action such as **Book with Esther**.

The practitioner administration list/details now surfaces the preferred public name when a public team profile exists. The Public Team administration page remains the owner of public publishing fields.

## API projection

Anonymous endpoints:

- `GET /api/v1/public/practitioners`
- `GET /api/v1/public/practitioners/{slug}`

Both endpoints are allowlisted projections. They exclude email, Entra identifiers, internal account status, private biography fields, schedules, client relationships, and unpublished profiles. Detail services come from the same active published catalogue used by the service directory.

## Booking behavior

The public booking page supports both discovery directions:

1. service first, then an eligible practitioner; or
2. optional practitioner first, which filters the service list to that practitioner's assignments.

A practitioner profile booking link preselects the practitioner and filters services. The server still revalidates the final service/practitioner/location combination and availability.

## Deployment and acceptance

No new database migration is required beyond migration 010, which introduced structured practitioner names and the public preferred/booking name.

After deploying the current API and public build:

1. Publish a practitioner in **Administration → Public team**, including a unique URL name and preferred booking name.
2. Assign at least one active, published service to the practitioner.
3. Open `/practitioners`, filter by that service, and open the profile.
4. Verify English and French public content, photo/initial fallback, mobile layout, keyboard navigation, and empty/error states.
5. Select **Book with _preferred name_** and confirm that only that practitioner's services are offered.
6. Select a service and confirm the practitioner and availability results remain valid.

