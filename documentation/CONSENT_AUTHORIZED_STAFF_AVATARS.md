# Consent-authorized staff avatars

## Priority and scope

Administrative avatar routes were blocked by unrelated patient consent instructions. Their shared service also accepted any local user as a moderation target, including clients, and treated an explicit self-target as ordinary self-service even on an admin route. The read/upload/delete moderation routes now have explicit staff-only authorization instead of the blanket consent fallback.

The service requires an active staff account, active clinic and a permitted current role in both the supplied principal and database. Self-service requires a current supported staff role and always targets the acting user. Every explicit target requires current Super Admin authority, including the administrator's own ID. Clinic Admin, practitioner, reception, accountant, client, application/global administrator status and privacy-review permission do not grant moderation authority.

Moderation targets must be staff accounts in the current clinic. Client, foreign and missing users return the same 404 without photo contents or confirming photo existence. Active moderators can still remove an inactive staff member's photo. Caller-supplied clinic/user IDs cannot redirect a self-service operation. No patient-image workflow is authorized by these staff endpoints; any future client avatar feature requires its own patient boundary.

## Transactions, auditing and portal

Every operation acquires the shared clinic consent lock, owns its transaction, locks the active clinic coordination row and checks current actor and target accounts. Writes and successful business audits commit together. An audit failure rolls back image replacement or deletion; lock contention and nested caller transactions cannot leave partial changes. Reads retain the API's no-store policy and central fail-closed disclosure audit.

Read and mutation responses now include the authorized `user_id`, allowing central audits to identify self-service image access as well as moderation. Audit metadata does not copy image bytes/base64. Existing WebP-only, size and dimension validation remains, with malformed non-string upload values rejected explicitly. This is authorization and transactional storage work, not a new image decoder or malware scanner.

The profile screen and header discard cached photos after authoritative 401/403/404 responses. Upload/removal controls stay unavailable until a successful profile-image reload. The Retry action provides recovery without retaining a stale image; successful upload/removal still refreshes the header. Images may be used by existing published staff profiles; publication controls and sign-in-provider image synchronization behavior remain unchanged. These changes do not retract information already viewed.

Staff image operations do not change patient photos, accounts, bookings, practitioner assignments or consent instructions. Patient restrictions remain independent and are never treated as an administrator override.

## Validation and rollout

No new production migration or environment variable is introduced. Deploy matching API and portal with existing staff identity, image, consent and access-audit prerequisites. Review legacy avatar ownership and callers of administrative avatar routes: non-Super-Admin callers must use the self-service endpoint for their own photo. Drain older API instances for consistent authorization and atomic writes. Patient photos already stored outside this staff workflow are not deleted or migrated by this task.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-staff-avatars.php` for disposable local SQL/HTTP tests. It retains booking-contact and central access-audit regressions. Fixtures use small synthetic solid-color WebP images embedded in the test source; no real images, hosted records, private environment or external provider operations are used.

Local validation on 19 October 2026:

- 158 new SQL/HTTP authorization checks passed on MariaDB 11.4.8, including current account/role/clinic checks, all three moderation operations, explicit-self admin-route denial, client/foreign/missing targets, own upload/body-ID isolation, inactive staff moderation, photo/audit rollback, own-read audit references, content-free denial auditing, patient-photo preservation, lock contention and nested transactions. The same run passed 74 booking-contact and 28 central access-audit checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new integration source and updated PowerShell runner passed syntax checks.
- Three selected browser scenarios passed: 401 and 403 deletion-denial cleanup of both profile/header images, recovery through reload, and actual browser image normalization/upload/removal with header updates.
- Full public, portal and application-admin production build passed, including TypeScript, content and 2,111 English/French translations. Existing bundle-size warnings remain. The complete browser suite was not run.

All checks are local. Hosted identity/database/concurrency acceptance and the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain outstanding. Remaining conservative blocks include practitioner enquiries, receipt templates, clinic/room/location/service configuration and assignments, branding/themes and booking settings, plus reviewed consent-history merge reconciliation.
