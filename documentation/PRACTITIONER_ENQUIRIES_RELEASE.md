# Wellnexa FAQ and practitioner enquiries

The homepage FAQ covers booking, delivery location, starting prices, travel fees, client accounts, receipts, preparation and joining. It uses keyboard-accessible disclosure controls and is linked from the sticky menu. The joining page now has an enquiry form rather than sending applicants to a generic contact page.

The form asks for name, email, optional phone, discipline, optional credentials, service area, a short introduction and explicit permission to review/contact. It requests no clinical records or identification documents, and does not create an account, approve membership or subscribe the applicant to marketing. After a database commit it returns a receipt reference, not a claim that an email was delivered. Unconfirmed network/server outcomes retain an immutable payload and reference for safe retry. Validation errors keep editable fields. Form details are not persisted in browser storage.

## Recipient and inbox

The initial Wellnexa notification recipient is **info@copihue.ca**, as requested by the owner. It is private configuration, separate from public clinic contact information.

Change it at **portal.copihue.ca/admin/ → Practitioner enquiries → select Wellnexa → Enquiry notification email → Save enquiry settings**. Only an authenticated application administrator can change the recipient or pause new enquiries. Each clinic has its own settings; the seed identifies Wellnexa by `wellnexa.copihue.ca`, with no hardcoded clinic ID.

Clinics without enquiry settings are closed to submissions by default. Only the configured Wellnexa host is enabled by this migration; an application administrator can explicitly enable another clinic later.

Clinic administrators can review their own inbox at **clinic portal → Practitioner enquiries** (`/admin/enquiries`). Application administrators can review each clinic's inbox in central administration. Reception, accountant, practitioner and client roles cannot read this inbox. Mark enquiries **new**, **contacted** or **closed**; closed is a workflow status, not deletion. Settings and status updates detect stale revisions and are audited without copying application text into audit logs.

## Database update

Back up the database. Select **wellness_centre** in phpMyAdmin. Requires the current multi-clinic/application-administration baseline (039/040 and existing feature migrations through 046).

Check the three new tables:

```sql
SELECT table_name FROM information_schema.tables
WHERE table_schema=DATABASE()
AND table_name IN ('practitioner_enquiries','practitioner_enquiry_settings','practitioner_enquiry_limits');
```

With **zero rows**, import **047_practitioner_enquiries.sql once**. With three rows, verify the existing installation rather than rerunning CREATE TABLE. With one or two rows, stop and resolve the partial update first. The script adds three tables and seeds the configured Wellnexa recipient; it does not change existing practitioners, clients, bookings or accounts.

Afterward verify:

```sql
SELECT c.name,s.recipient_email,s.enabled,s.revision
FROM practitioner_enquiry_settings s JOIN clinics c ON c.id=s.clinic_id;
```

Wellnexa should show `info@copihue.ca`. If its portal host was not configured when the migration ran, select Wellnexa in central administration and save the recipient there.

## Deploy in this order

1. Apply migration **047**.
2. Extract **wellness-api-enquiries-patch.zip** into **`/wellness-api/`**. Merge `src/` and replace the included files, preserving other source and the private `.env`. This includes the current compatible catalogue pricing code.
3. Extract **wellness-common-portal.zip** into **`/public_html/wellness-common-portal/`**, including hidden files.
4. Extract **copihue-portal-landing.zip** into **`/public_html/wellness-portal/`**, including the `admin/` directory and hidden files. This updates the main application-admin screen.
5. Extract **wellnexa-website.zip** into **`/public_html/wellnexa.ca/`**, including root `.htaccess`, `api/.htaccess` and the new enquiry PHP bridge. Upload ZIP contents without an extra nesting directory.

The source revision and ZIP hashes are in `manifest.json`. No new environment settings are required. Email uses the existing private Microsoft Graph `MAIL_ENABLED`, `MAIL_TENANT_ID`, `MAIL_CLIENT_ID`, `MAIL_CLIENT_SECRET` and `MAIL_FROM_ADDRESS` configuration. Leave those credentials in the private API configuration; never put them in Wellnexa or browser settings.

## Mail behaviour and operational limits

The enquiry is committed before a best-effort email notification. Email contains the clinic name and reference, directing the administrator to the authenticated inbox; applicant contact details and introduction are not included in the notification. When mail is disabled or no recipient is configured, the enquiry remains saved and the inbox shows that notification is disabled. Failed delivery also preserves the enquiry, with an administrator action to retry after configuration is corrected. Sent notices are not automatically resent.

An ambiguous Graph response is marked **needs_review**; a process interrupted during sending may remain **sending**. Check the recipient mailbox before support explicitly resets either state. Automatic resend is intentionally suppressed because the original email may already have been accepted. No response-time or delivery guarantee is shown to applicants.

Public submissions have bounded bodies and fields, a honeypot, server-side validation and per-clinic hourly quotas: 3 new enquiries per normalized email, 20 per observed source IP and 60 total. Marketing bridge requests share its server's observed IP; IP quotas therefore also cap the website pool. Client-provided forwarding headers are not trusted. Retry of an already saved reference succeeds without spending another quota. Quota keys are digests rather than raw IP/email; old quota rows are cleaned as new submissions arrive. No public enquiry-list or lookup endpoint exists. Administrators should include inbox records in their normal business-data retention process.

The website PHP bridge posts only to the fixed Wellnexa public enquiry endpoint over verified HTTPS; it forwards no browser cookies, bearer tokens, recipient choices or arbitrary destination URL. It exposes only a receipt acknowledgement or a bounded error. The website catalogue bridge remains read-only.

## Acceptance

Local checks cover website and all portal builds, FAQ keyboard use, confirmed receipt, unconfirmed retry, validation recovery and mobile layout; central recipient changes and review status; clinic-admin inbox access; and real SQL persistence, duplicates, consent/validation, clinic/role isolation, quotas, stale revisions and notification failures. Mail tests use a stub and never send to the real recipient.

Validated on 18 October 2026: website and all shared frontend surfaces built; 1,981 EN/FR UI translations validated; 17 website browser scenarios, seven central-admin scenarios and one focused clinic-inbox scenario passed. MariaDB 11.4.8 passed 37 enquiry checks plus 198 existing booking/form/library checks. Public projection assertions, response/preflight checks, response ID normalization, changed-PHP lint and packaging-script parsing passed. The mobile form screenshot was inspected. One existing practitioner-discovery scenario timed out in an intermediate run; the complete subsequent website run passed all 17 scenarios.

After upload, inspect the FAQ and joining form, verify the central recipient is correct, and submit a controlled enquiry once. Confirm the reference in the clinic inbox, check email delivery using the configured Graph sender, mark it contacted/closed and verify it never appears as a practitioner or client account. Hosted database, Apache routing and actual Graph delivery remain operator acceptance checks.

Rebuild from committed source with `scripts/build-practitioner-enquiries-deployment.ps1 -ReleaseName practitioner-enquiries-2026-10-18`.
