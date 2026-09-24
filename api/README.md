# Wellness Centre internal API

PHP 8.2+ / MySQL API, deployed on Netfirms with MySQL 5.7 compatibility. It includes Microsoft Entra access-token validation, role enforcement, public catalogue and availability endpoints, production setup endpoints, transactional booking, notification queuing, and audit logging. See [Master Requirements](../documentation/MASTER_REQUIREMENTS.md) for scope and delivery stages and [System Design](../documentation/SYSTEM_DESIGN.md) for architecture and data design. [`DATABASE_PLAN.md`](DATABASE_PLAN.md) is a historical table reference, not the current master plan.

Create the database and optionally load non-sensitive development data:

```powershell
mysql -u root -p < database/schema.sql
mysql -u root -p wellness_centre < database/seed-development.sql
```

Install dependencies and configure the environment:

```powershell
composer install --no-dev --optimize-autoloader
Copy-Item .env.example .env
```

Set the database and Microsoft Entra values in `.env`, then serve the front controller:

```powershell
php -S localhost:8080 -t public public/index.php
```

For Apache, point the document root at `api/public` and enable `mod_rewrite`. For Nginx, route missing files to `index.php`.

## First production administrator

Do not run the development seed. After configuring `.env`, obtain your Microsoft Entra tenant ID and immutable user object ID (`oid`), then run:

```powershell
php bin/provision-admin.php --tenant="TENANT-ID" --oid="USER-OBJECT-ID" --email="you@example.com" --name="Your Name" --clinic="Back To Balance Wellness Centre" --location="Toronto Clinic" --timezone="America/Toronto"
```

This is a one-time bootstrap command. Further staff accounts should be created through the authenticated admin endpoint.

## Implemented endpoints

Public:

- `GET /api/v1/health`
- `GET /api/v1/health/database`
- `GET /api/v1/site-config`
- `GET /api/v1/locations`
- `GET /api/v1/services?practitioner_id=`
- `GET /api/v1/public/services` and `GET /api/v1/public/services/{slug}`
- `GET /api/v1/practitioners?service_id=`
- `GET /api/v1/team` and `GET /api/v1/team/{slug}/image` (published public profiles only)
- `GET /api/v1/availability?location_id=&service_id=&practitioner_id=&date_from=&date_to=`

Authenticated:

- `GET|POST /api/v1/clients` and `GET|PATCH /api/v1/clients/{id}` (staff with Super Admin, Clinic Admin, or reception role; clinic-scoped). See `../documentation/CLIENT_MANAGEMENT.md` for payloads and revision handling.

- `GET /api/v1/auth/me`
- `GET /api/v1/appointments`
- `GET /api/v1/booking-options` (staff administrators/reception; clinic-scoped booking combinations and room names)
- `POST /api/v1/address-coverage/validate` (authorized staff/practitioner mobile address validation and driving-distance coverage proof)
- `POST /api/v1/appointments`

Appointment lists accept `view=upcoming|past|all&page=1` and return up to 50 rows per page. Staff listing requires an operational role; practitioners and clients see only their own appointments. Staff booking workflow and acceptance instructions: `../documentation/STAFF_BOOKING.md`.
- `GET|PUT|DELETE /api/v1/profile/avatar`

Clinic administration:

- `GET /api/v1/admin/locations` (Super Admin only)
- `POST /api/v1/admin/locations`
- `PATCH /api/v1/admin/locations/{id}` (Super Admin only)
- `POST /api/v1/admin/rooms`
- `GET /api/v1/admin/rooms` (Super Admin only)
- `PATCH /api/v1/admin/rooms/{id}` (Super Admin only)
- `GET|POST /api/v1/admin/room-capabilities` (Super Admin only)
- `PUT /api/v1/admin/room-capability-assignments` (Super Admin only)
- `POST /api/v1/admin/staff`
- `POST /api/v1/admin/practitioners`
- `GET /api/v1/admin/practitioners` (Super Admin only)
- `POST /api/v1/admin/practitioners/onboard` (Super Admin only)
- `PATCH /api/v1/admin/practitioners/{id}` (Super Admin only; updates the local profile, location, booking ownership, and active status)
- `POST /api/v1/admin/services`
- `GET /api/v1/admin/services` (Super Admin only)
- `PATCH /api/v1/admin/services/{id}` (Super Admin only)
- `GET /api/v1/admin/service-assignments` (Super Admin only)
- `PUT /api/v1/admin/services/{id}/assignments` (Super Admin only)
- `POST /api/v1/admin/availability-rules`
- `GET /api/v1/admin/availability-rules` and `DELETE /api/v1/admin/availability-rules/{id}`
- `GET /api/v1/admin/schedule-exceptions`
- `POST|DELETE /api/v1/admin/availability-overrides[/{id}]`
- `POST|DELETE /api/v1/admin/time-off[/{id}]`
- `PATCH /api/v1/admin/clinic` (Super Admin only)
- `GET /api/v1/admin/team-profiles` and `PUT /api/v1/admin/team-profiles/{userId}` (Super Admin only)

Protected requests require an Entra access token with the configured audience and `access_as_user` scope. Identity is linked using the immutable tenant ID and `oid`, not email address. Effective permissions are the intersection of the user's Entra app roles and local database roles.

Mobile coverage validation requires a server-side Google Maps key and a separate signing
key. See [`../documentation/GOOGLE_ADDRESS_COVERAGE.md`](../documentation/GOOGLE_ADDRESS_COVERAGE.md)
and [`google-maps.env.example`](google-maps.env.example). Never expose these values through
frontend `VITE_` variables.

See [`../ENTRA_SETUP.md`](../ENTRA_SETUP.md) for both app registrations, role assignment, local configuration, and staff provisioning.

## Production notes

Booking confirmation now rechecks availability while holding a clinic-level transaction lock. See `../documentation/BOOKING_VALIDATION_TESTS.md` for replay behavior, local tests, and required MySQL concurrency acceptance. Timestamps require ISO-8601 seconds and a timezone; source is assigned by the server. No migration is needed.

### Availability checkpoint (2026-09-15)

Availability results include `available_room_ids` for services requiring rooms. Search merges recurring hours and available overrides; time off, blocked overrides, imported busy periods, and appointments at any location take precedence. Service buffers must fit working hours. Room checks include capabilities, practitioner restrictions, and turnover on both existing and proposed bookings. Recurrence weeks are anchored to Monday of the rule's valid-from week.

Run `php tests/schedule-intervals.php` for interval and daylight-saving checks. Before deployment acceptance, verify extra openings, time off, room shortages, and cross-location appointments against MySQL. Booking-time revalidation and concurrency coverage remain Phase 4 work. No schema migration is needed for this checkpoint.

The practitioner-first availability administration update adds editing for recurring hours, one-time availability changes, and time off. Apply `database/migrations/014_time_off_location.sql` before deploying the matching API. It records the location/timezone used by each new time-off entry and backfills existing entries from the practitioner's active location when available.

Client duplicate prevention and Super Admin merge require `database/migrations/006_client_merge.sql` before the matching API is deployed. The merge endpoints are `GET /api/v1/clients/{survivor}/merge-preview/{duplicate}` and `POST /api/v1/clients/{survivor}/merge/{duplicate}`. See `../documentation/CLIENT_MERGE.md` for safeguards, deployment order and tests.

The governed public service catalogue requires `database/migrations/009_public_service_catalogue.sql` before the matching API and frontend are deployed. It preserves the services already visible in public booking, then lets Super Administrators review their stable slugs and bilingual public content. See `../documentation/PUBLIC_SERVICE_CATALOGUE.md`.

Configurable business logos and favicons require `database/migrations/011_clinic_brand_assets.sql` before the matching API and frontends are deployed. Run it once, then use Administration → Business settings to upload the two independent assets. See `../documentation/CONFIGURABLE_BRANDING.md`.

Personalized staff dashboards require `database/migrations/012_dashboard_preferences.sql` before the matching API and portal are deployed. It stores layout preferences only; live widget values remain authorization-scoped projections. See `../documentation/DASHBOARD_WIDGETS.md`.

Runtime Super Admin widget upload/version management additionally requires `database/migrations/013_dashboard_widget_catalogue.sql`. JSON definitions are data only and can select only allowlisted renderers, projections, filters, permissions, routes, sizes, and icons.

- Use a restricted database account rather than the MySQL server administrator.
- Keep `.env`, `vendor`, and runtime cache files outside source control.
- Serve only the `public` directory.
- Require HTTPS and restrict `CORS_ALLOWED_ORIGINS` to exact frontend origins.
- Keep public health responses minimal; database health confirms connectivity without exposing server or schema details.
- The notification table is a durable queue; an email worker/provider is still required before reminders are delivered.
- `GET|PUT|DELETE /api/v1/admin/users/{id}/avatar` (Super Admin moderation)
- `GET /api/v1/admin/staff` and `PATCH /api/v1/admin/staff/{id}` (Super Admin only)
- `GET /api/v1/admin/catalogue-settings` (Super Admin only)
- `POST /api/v1/admin/service-categories` and `POST /api/v1/admin/taxes` (Super Admin only)
- `PATCH /api/v1/admin/booking-settings` (Super Admin only)
- `GET /api/v1/admin/room-practitioner-restrictions` and `PUT /api/v1/admin/rooms/{id}/practitioners` (Super Admin only)
## VoIP.ms staff SMS (pending A2P approval)

Staff may save a mobile number and request text notifications. Delivery remains
off unless `SMS_ENABLED=true` is explicitly set in the private API `.env`.
Do not enable it until VoIP.ms confirms that DID `2892975234` and the intended
automated appointment use are approved. No client SMS is sent by this feature.

After approval, set `VOIPMS_API_USERNAME` to the VoIP.ms account login email,
`VOIPMS_API_PASSWORD` to the account's dedicated API password, and
`VOIPMS_FROM_DID=2892975234`. The Netfirms outbound IP must be on the VoIP.ms
API allowlist. Keep credentials only in `/wellness-api/.env`, never in the public
web directory or deployment archives. The existing notification scheduler
processes staff SMS alongside email when enabled. New appointments queue texts
only for staff who explicitly requested them; disabling SMS stops new queuing
and the worker ignores any outstanding SMS. A staff member changing their mobile
number or turning SMS off cancels their queued messages.
Queued texts older than one hour are canceled instead of being sent late.

Text bodies contain no client name, address, service, or other clinical details.
They link to the authenticated practitioner schedule and stay within one
160-character SMS. Provider uncertainty goes to `needs_review` rather than
being retried automatically, to avoid duplicate texts. Incoming SMS and STOP
handling are not yet integrated; confirm VoIP.ms requirements before enabling
live delivery.
