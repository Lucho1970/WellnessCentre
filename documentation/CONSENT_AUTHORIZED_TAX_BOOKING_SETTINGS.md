# Consent-authorized tax creation and clinic booking settings

Tax creation and clinic booking-default updates now require a staff principal with an active local account, an active clinic and a persisted clinic-wide Super Admin role. Token roles alone, location-scoped Super Admin grants, Clinic Admin, practitioner, reception, accountant, privacy-review permission, clients, application administrators and foreign-clinic context do not qualify. Catalogue reads already use this authority boundary. Only the two exact command route names are newly classified in ConsentAccess.

## Configuration effect boundary

These commands store clinic configuration and disclose no patient records. Current runtime source does not use the tax catalogue or clinic defaults to recalculate bookings, invoices, paid lines or payments. BookingService and AvailabilityService use service-level lead time, horizon and cancellation rules; existing booking cancellation terms and billing amounts are stored separately. The frontend service editor currently supplies its own initial service values. This change does not connect clinic defaults to those consumers or automatically apply a newly created tax. Such behavior would require a separately reviewed change with its actual booking/billing effects classified.

Accordingly, these commands do not query, filter, enumerate, rewrite or grant access to patient records. Global, actor-specific and practitioner-specific restrictions remain effective for patient routes and do not block patient-free configuration. Existing services, duration options, appointments, recurring series, approvals, notifications, invoice lines, payments and consent directives remain unchanged. Even an unresolved existing service tax code is not rewritten when its catalogue tax is inserted. No external provider is called.

## Validation and transactions

Tax codes are normalized by trimming and ASCII uppercasing, limited to 1–40 letters/numbers/underscores/hyphens with a leading letter or number. Tax names must be nonempty text, at most 100 Unicode characters, without control characters. Rates must be JSON integers from 0 to 10,000 basis points. Booleans, numeric strings, floats, arrays and nulls are rejected. Duplicate codes, including inactive existing taxes, return `409 tax_already_exists` without changing a rate or revealing another clinic's catalogue. The same code may independently exist in another clinic. The server selects the clinic and initial active state; request fields cannot redirect ownership or reactivate/update an existing tax.

Booking settings accept one or more explicit defaults: lead time 0–525,600 minutes; horizon 0–3,650 days; cancellation window 0–525,600 minutes. Values must be JSON integers. Omitted defaults are preserved. If no settings row exists, omitted defaults initialize to the established schema defaults of 60 minutes, 90 days and 1,440 minutes. New rows use server-controlled CAD and 15-minute slot increments. Existing currency and slot increments are preserved. Only the three declared default columns are writable; extra tenant/currency/slot fields are ignored, and an unsupported-only payload is rejected. This preserves compatibility with older clients that submit read-only catalogue metadata.

The shared clinic consent advisory lock and an owned transaction protect persisted authority, validation, configuration writes and business audits. The clinic row serializes same-clinic configuration and booking writers. Audit failure rolls back creation, updates and first-time settings insertion. A busy consent lock returns a retryable refusal. Caller-owned transactions are rejected without rolling back the caller. Missing directive contents do not prevent these patient-free operations. Tax creation receives a `tax.create` audit, and booking defaults receive a clinic-scoped `booking.settings.update` audit containing only writable field names. Central API read/response/denial logging remains fail closed for successful responses and excludes raw names and drafts.

## Editor behavior

All catalogue editing controls are disabled until the initial authorized load succeeds and while a request is pending. Failed catalogue reloads or 401/403/404 mutations clear category/tax lists, all drafts, defaults, selections, previous success messages and dirty-form guards. Retry stays available outside the disabled fieldset. A successful mutation followed by a denied reload cannot show a false success. Validation and duplicate conflicts retain drafts for correction. Only the three writable defaults are sent by the current editor, as numbers. Tax code/name/rate and default inputs expose matching bounds. Duplicate-tax messages are available in English and French.

## Validation and remaining gates

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-booking-settings.php` for disposable synthetic SQL/HTTP acceptance. Coverage includes current and revoked authority, cross-clinic spoofing, strict types and boundaries, duplicate/inactive taxes, missing and partial settings rows, untouched paid billing history, rollback on audit failure, lock contention, caller transactions, exact API route classification, access/denial audits, and independent patient restrictions. The preceding service/room/location fallback probes now target the still-unclassified clinic-profile command.

Local validation on 20 October 2026:

- Tax/settings SQL/HTTP suite: 222 checks. Service configuration regression: 365 checks. Each fixture also passed 74 inherited booking-contact and 28 access-audit checks.
- All 53 root PHP scripts passed. Access-audit policy coverage passed 58 checks after the new canonical route assertion. PHP syntax passed for 95 files; the PowerShell SQL runner parsed successfully.
- Full public, portal and application-admin frontend build passed, including TypeScript, four bilingual content entries and 2,115 English/French translations. Existing bundle-size warnings remain.
- Eight selected browser cases passed: four tax/default denial and reload cases, initial denial/retry, successful tax/default save with writable-only payloads, duplicate conflict recovery, and category/welcome editing regression.

Review base: `feature/consent-authorized-services` (`6b19eb5`). This task builds on that unmerged checkpoint. No migration is required. Before real patient data, rehearse current roles, authorized configuration, audit failure and concurrent locking on the hosted database and complete the [readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md) release gates. Local checks do not establish hosted acceptance or PHIPA certification.

Remaining conservative clinic configuration includes clinic profile, branding, themes and welcome content. Client merges with any consent history still require reviewed reconciliation. Unknown authenticated staff routes retain the fallback.

Subsequent presentation work: [clinic profile, branding, themes and welcome content](CONSENT_AUTHORIZED_CLINIC_BRANDING.md) now require current clinic-wide authority and atomic audits. Public presentation maintenance grants no patient access; unknown staff routes retain the fallback and client merges with consent history still need reviewed reconciliation.
