# Public service catalogue

## Delivered scope

This slice adds governed service publishing without creating a general-purpose CMS.

- Super Administrators manage publication alongside the existing service record.
- Each service has a stable public slug, display order, publish switch, English/French name, summary, description, and preparation instructions.
- `/services` groups and filters published services by the existing service category.
- `/services/:slug` shows approved descriptions, duration/price choices, appointment modes, bookable locations, and published practitioners assigned to the service.
- Booking actions pass the service slug to the existing `/book` flow; the booking API resolves the current numeric service and duration identifiers.
- Anonymous catalogue endpoints return an explicit public projection. Administrative scheduling rules, buffers, cancellation values, inactive records, staff identity data, and unpublished practitioner profiles are omitted.

Publishing a service does not make an invalid assignment bookable. The normal active service, practitioner assignment, location assignment, delivery-mode, and availability rules remain authoritative.

## Database update

Run [`009_public_service_catalogue.sql`](../api/database/migrations/009_public_service_catalogue.sql) once before deploying the new API.

The migration gives existing services collision-free `service-{id}` slugs and preserves current public-booking behavior by publishing services that are currently active. Review and replace those generated slugs and public descriptions in the Services administration page after deployment. Newly created services default to unpublished.

Do not rerun the migration after it succeeds. It is an additive migration and does not change appointments, duration prices, service assignments, or historical records.

Deployment order:

1. Back up the database and run migration 009.
2. Deploy the private API and its Composer dependencies.
3. Deploy the portal build.
4. Deploy the public build.
5. In Portal → Administration → Services, edit each active service, review both languages and publish state, and save an intentional public URL.

## Acceptance checks

1. An unpublished active service remains visible to administrators but is absent from `/services` and anonymous booking.
2. Publishing it makes its directory card and detail URL available.
3. English and French select the corresponding content, falling back to the available language when a translation is blank.
4. Category filtering shows only matching services; uncategorized services remain visible under All categories.
5. Prices and durations match the administrative service configuration.
6. Only active, published team profiles assigned to the service appear as public practitioners.
7. “Book this service” opens booking with that service selected; practitioner-specific actions select both service and practitioner.
8. In-clinic and On-Site labels reflect active practitioner assignments, and inactive assignments never appear.
9. Duplicate public slugs are rejected and unpublished slugs return 404 from the public detail endpoint.
10. Changing a slug is intentional and warned because previously shared links will no longer resolve.

The current migration integration test is `api/tests/integration/service-duration-pricing.php`. It requires the documented disposable local MySQL endpoint and never reads the deployed `.env`.
