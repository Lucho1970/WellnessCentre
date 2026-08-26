# Wellness Centre internal API

PHP 8.2+ / MySQL 8 internal API. Copy `.env.example` to `.env`, import `database/schema.sql`, then serve the `public` directory.

```powershell
php -S localhost:8080 -t public
```

Public availability is sanitized. Protected routes validate Microsoft Entra v2 access tokens and enforce delegated scope, staff app role, and local `tid + oid` identity matching. Route policies separate operational, practitioner, and finance access, with an example practitioner-owned resource route.

See [`../ENTRA_SETUP.md`](../ENTRA_SETUP.md) for both app registrations, role assignment, local configuration, and staff provisioning.
