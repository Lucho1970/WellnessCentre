# Wellness Centre internal API

PHP 8.2+ / MySQL API, deployed on Netfirms with MySQL 5.7 compatibility. It includes Microsoft Entra access-token validation, role enforcement, public catalogue and availability endpoints, production setup endpoints, transactional booking, notification queuing, and audit logging. See [`DATABASE_PLAN.md`](DATABASE_PLAN.md) for the complete data model and delivery sequence.

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
- `GET /api/v1/practitioners?service_id=`
- `GET /api/v1/availability?location_id=&service_id=&practitioner_id=&date_from=&date_to=`

Authenticated:

- `GET /api/v1/auth/me`
- `GET /api/v1/appointments`
- `POST /api/v1/appointments`
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

Protected requests require an Entra access token with the configured audience and `access_as_user` scope. Identity is linked using the immutable tenant ID and `oid`, not email address. Effective permissions are the intersection of the user's Entra app roles and local database roles.

See [`../ENTRA_SETUP.md`](../ENTRA_SETUP.md) for both app registrations, role assignment, local configuration, and staff provisioning.

## Production notes

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
