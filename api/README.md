# Wellness Centre internal API

PHP 8.2+ / MySQL 8 internal API. Copy `.env.example` to `.env`, import `database/schema.sql`, then serve the `public` directory.

```powershell
php -S localhost:8080 -t public
```

Public availability is sanitized. All protected routes require a bearer token in production; the included middleware is intentionally a development-ready integration point for the chosen OAuth/staff identity provider.
