# Consent-authorized clinic profile, branding, theme and welcome content

The six clinic-presentation administration routes now require a staff principal with an active local account, an active clinic and a persisted clinic-wide Super Admin role. This applies to profile patches, branding metadata reads, logo/favicon replacement/removal, portal themes and bilingual welcome content. Token roles alone, location-only grants, Clinic Admin, practitioner, reception, accountant, privacy-review permission, clients, application administrators and foreign-clinic context do not qualify. Only exact route names are classified in ConsentAccess; no URL-prefix or blanket administrator exemption is introduced.

## Public presentation boundary

These fields already serve the public site and guest/client/staff portal. Their administration reads and writes public clinic presentation rather than patient records. Public configuration remains an explicit projection of clinic contacts, theme, welcome copy and asset versions. Public image delivery selects the host-resolved active clinic and a fixed logo/favicon type; administrative reads return metadata only. Host switching cannot transfer a staff member's authority to another clinic.

Changing the clinic name also changes the public clinic label that NotificationWorker reads when preparing a future notification. The worker retains its independent ConsentAccess and staff-recipient checks under the same consent advisory lock before sending; configuration saves do not run the worker, rewrite pending payloads, alter recipients, release queued notices or restore patient access. Existing clinical records, receipt/billing snapshots, approvals, booking rules, consent directives and notifications remain unchanged. No identity, maps, mail or SMS provider is called by these commands.

Only public business contact details, public welcome text and public brand imagery belong in these fields. Upload authorization and raster validation do not detect patient details in image pixels, embedded image metadata or free text. This change preserves the existing public image formats and is not a general patient-document upload workflow.

## Writes, validation and audits

The shared clinic consent advisory lock and owned transaction cover current authority, input checks, profile/image/theme writes and business audit insertion. Profile, binary asset replacement/removal, theme and welcome changes roll back if business auditing fails. First theme/welcome insertion is also atomic. Caller-owned transactions are refused without rolling back the caller; lock contention returns a retryable refusal. Patient-free maintenance does not enumerate or depend on directive contents.

Profile patches whitelist operating name, legal name, public email and public phone. Omitted contacts are preserved; optional contacts can be explicitly cleared. Names and contacts are bounded Unicode text without control characters, with a validated email format. Empty or unsupported-only profile commands are refused. Clinic ID, status, host, ownership, permissions and other body fields cannot redirect or expand the mutation.

Brand commands accept fixed logo/favicon types and strictly typed PNG/WebP base64 inputs, bounded encoded/decoded size, matching raster signatures and the existing dimension limits. SVG and other MIME types, malformed payloads and out-of-range dimensions are refused. The stored actor, byte size, dimensions and hash are computed by the server. Removal is idempotent. Business audits contain the asset type, not image bytes or raw request JSON.

Themes accept exactly six-digit hexadecimal colours and an allowed font. Welcome commands require English and French titles/bodies, bounded to 160/3,000 Unicode characters respectively; titles reject control characters and bodies permit ordinary multiline text. Theme and welcome upserts update disjoint columns so saving either preserves the other. These values are rendered as text and constrained theme values by the portal. The audit trail uses canonical clinic presentation routes and excludes contacts, image bytes and welcome drafts from access-audit metadata.

## Editor authorization and recovery

Business Settings verifies the privileged branding-read route before enabling edits, then refreshes the public projection. Public config alone cannot enable administration. Pending loads and mutations disable every editor and file input. Permission/target refusals and failed privileged/public reloads clear editable contacts, all welcome/theme drafts, local image previews, success messages and dirty guards. Retry remains outside the disabled fieldset. The public header remains governed by its public configuration provider.

A successful mutation followed by a failed metadata or public-config refresh cannot show save success. ClinicConfigProvider now returns a success result to this caller while preserving its existing public fallback behavior. Generation checks prevent stale loads from repopulating the local editor. Validations retain the active draft; different sections have independent dirty guards, and successful refreshes preserve unrelated unsaved sections.

## Validation and remaining release gates

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-clinic-branding.php` for disposable synthetic SQL/HTTP acceptance. It exercises all six routes, persisted/revoked/inactive authority, cross-clinic isolation, strict inputs and image dimensions, public host projections and binary delivery, unchanged patient data, atomic business-audit rollback, first insert, lock contention, caller transactions, explicit API/denial logging and patient access remaining restricted. The preceding configuration fixtures now exercise the unknown-route consent fallback directly because their previous clinic-profile probe is intentionally classified.

Local validation on 20 October 2026:

- Clinic presentation SQL/HTTP acceptance: 350 checks; tax/booking-default regression: 222 checks. Each fixture also passed 74 inherited booking-contact and 28 access-audit checks.
- All 53 root PHP scripts and syntax checks for 95 PHP files passed. The access-audit policy suite passed 64 checks after the six canonical clinic-route assertions. The SQL runner parsed successfully.
- Full public, portal and application-admin frontend build passed, including TypeScript, four bilingual content entries and 2,115 English/French translations. Existing bundle-size warnings remain.
- Fourteen selected browser cases passed: ten new authorization/reload/validation cases plus four existing unsaved-work, logo/favicon, Wellnexa-theme and bilingual-content regressions. The validation case was rerun after correcting its expected generic UI message.

Review base: `feature/consent-authorized-booking-settings` (`532a139`). This task builds on that unmerged checkpoint. No new migration is required; the existing profile/brand schema and portal-theme/welcome migrations must already be present as required by the earlier features. Before real patient data, rehearse current role resolution, host isolation, audit failure and concurrency on the hosted database and complete the original [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md) operational gates. Local checks do not establish hosted acceptance or PHIPA certification.

The reviewed conservative clinic configuration groups are now explicitly authorized. Unknown staff routes still retain the consent fallback, and client merges with any consent history still require reviewed reconciliation. Broader release gates remain, including finer consent purposes, representative authority, reviewed exceptions, retention, secure data-subject requests and operational/vendor acceptance.
