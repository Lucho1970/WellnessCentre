# Session receipts

## First workflow

The clinic maintains the receipt template and business/issuer details. Each practitioner has reusable professional name, designation, registration number where applicable, and optional logo for each clinic/service combination. A practitioner working in different disciplines can therefore use the appropriate details for each service without exposing or automatically importing their private global credential library.

Clinic administrators open an appointment's details and select **Clinic receipt template**. Save the issuer name, business address, contact details and optional clinic logo. The initial layout is a maintained application template, not arbitrary user-supplied HTML. Templates are versioned when their fields change.

An assigned practitioner or clinic administrator selects **Practitioner receipt details** in the appointment panel and saves the professional fields applicable to that service. These details are reusable across that practitioner's appointments for the same service in the same clinic. Practitioners cannot change the clinic template or another practitioner's details. Reception and accounting-only roles do not receive receipt access in this first slice.

For a completed session:

1. Open its appointment details. The actual appointment client, treating practitioner, service, date/duration, historical treatment price and On-Site fee supply the receipt context.
2. Review the saved issuer and professional details. Enter applicable tax in CAD, the full amount actually received, payment method and local payment date. Supported manual methods are cash, debit, credit, e-transfer and other. Recording a card payment does not charge a card.
3. Confirm the details and select **Record payment and issue receipt**. The server requires full payment, a completed session, current appointment/settings versions and a historical price. It never falls back to today's catalogue price. Payment dates must be from the service date through today; advance payments and partial payments are separate work.
4. One transaction records the invoice/line item, completed manual payment, numbered receipt, original PDF, financial audit and appointment status change to paid. Identical retries return the same receipt; concurrent requests cannot create a second active receipt/payment for that session.
5. The client signs into the clinic portal and selects **My receipts**, then **Download receipt PDF**. The PDF can be saved or printed using the device's PDF viewer. On-Site issuance needs no printer. Portal delivery is available immediately after commit; email delivery notices are not implemented in this slice.

PDFs support English and French labels and accented text. The receipt language is selected from the issuing user's portal language and retained. Clinic and practitioner logos accept validated PNG/JPEG files up to 150,000 bytes and 1600 pixels per side. PNG rendering requires PHP GD; JPEG is available without that optional extension. No external image URLs or SVG uploads are accepted.

## Historical integrity and corrections

Issued PDFs and their JSON snapshots retain the client/professional name, applicable designation/registration, clinic template fields/logos and versions, service details, prices, tax and manual payment information. Editing a profile or template affects future receipts only. A reviewed client merge transfers receipt access with the other client relationships and does not rewrite the original receipt snapshot.

**Void receipt** requires an explicit reason and confirmation. Choose either a payment recording error (mark the original payment failed and invoice void) or a full refund already completed outside the application (record the full refund and mark invoice/payment refunded). The action does not send money. Retain the original receipt, PDF, payment, invoice, reason, actor and time; never delete or silently overwrite them. Repeating the void is idempotent. A replacement requires a new request and creates a new number after returning the session to completed. A financial refund does not by itself authorize a new payment entry.

Clients see a voided status and cannot download a voided receipt as a currently valid PDF. Internal correction reasons are omitted from the client response. A PDF downloaded earlier cannot be recalled. Partial refunds and insurer claim submission are not included.

## Authorization and API

Every read/write uses trusted clinic context. Clients can list/download only their own clinic-linked receipts. Practitioners can operate only on their own assigned sessions/receipts; clinic administrators may maintain clinic billing records. Global practitioner identity grants no cross-clinic access. Client routes use the existing verified identity and server-tracked customer session. Downloads require authentication and return a bounded base64 PDF payload; there are no public file URLs or tokens in a download link. Responses use `Cache-Control: no-store`; downloads and writes are audited without message bodies, PDF bytes or credentials in audit metadata.

| Endpoint under `/api/v1` | Purpose |
| --- | --- |
| `GET /appointments/{id}/receipt` | Authorized context, saved settings and receipt history |
| `PUT /receipts/template` | Clinic administrator maintains the shared template fields/logo |
| `PUT /appointments/{id}/receipt-profile` | Assigned practitioner or clinic administrator maintains service-specific professional details/logo |
| `POST /appointments/{id}/receipt` | Atomically record full manual payment and issue receipt |
| `GET /receipts/{id}/pdf` | Authorized staff download of an issued receipt |
| `POST /receipts/{id}/void` | Audited correction or recording of a completed full refund |
| `GET /customer/receipts?page=1` | Paginated client receipt list, including void status |
| `GET /customer/receipts/{id}/pdf` | Own-client authenticated PDF download |

Settings writes require their current integer `version` (zero for first save). Issuance requires integer appointment `version`, `template_version`, `profile_version`, `tax_cents`, `amount_cents`, a supported `method`, `paid_on` as `YYYY-MM-DD`, `language`, `confirmed: true` and a fresh `idempotency_key`. Reuse the exact key and payload only for an uncertain-outcome retry. Issuer and professional content is loaded from server settings, not browser-supplied issue fields. Same-key changed requests fail with a conflict.

## Deployment and acceptance

1. Back up the hosted database. Deploy the API source **and updated Composer dependencies** with the portal build. This adds `dompdf/dompdf` and its locked dependencies; include `vendor` in the deployment artifact or run `composer install --no-dev --prefer-dist --optimize-autoloader` from the lock file on the host. Never deploy a development `.env` over the private hosted configuration.
2. Confirm PHP 8.2+, DOM and mbstring alongside existing API extensions, a writable PHP temporary directory and access to the bundled PDF fonts. GD is needed for PNG logos. Dompdf remote/PHP/JavaScript execution is disabled.
3. For an existing schema through migration 042, apply [043 session receipts](../api/database/migrations/043_session_receipts.sql) once. Fresh installations use the maintained full schema instead; do not apply 043 again after that schema already creates its tables.
4. Set private `SESSION_RECEIPTS_ENABLED=true` only for the reviewed pilot. The example/default is false; the feature does not query its new tables while disabled. An enabled clinic must save its template and service-specific practitioner settings before issuing.
5. Validate the clinic's issuer information, each profession's receipt fields and applicable tax handling before real issuance. The application does not certify reimbursement eligibility or determine tax automatically.
6. Rehearse completed in-clinic and On-Site sessions, correct designation selection, logo/font rendering, manual payment/date/tax, downloads/printing on mobile, client/practitioner/clinic isolation, duplicate submission, corrections/refunds, and unchanged old PDFs after template edits. Record hosted schema and portal acceptance separately from local tests.

Rollback: disable the flag and revert application deployment if necessary. Preserve financial/receipt tables and records; do not drop them or roll back issued financial history.

## Local validation

Run `php api/tests/session-receipts.php` for input/access policies and a French PDF sample. The synthetic real SQL rehearsal is:

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13322 -IntegrationTest session-receipts.php
```

It uses only a random localhost database, never loads `.env`, retains synthetic data and stops its temporary server. It exercises the additive migration, price/payment validation, authorization, idempotency, snapshot preservation, void/refund/reissue and simultaneous retry workers. The existing recurrence fixture also runs as its setup regression.

Frontend validation uses `npm run build` and `npx playwright test tests/portal.spec.ts tests/customer.spec.ts` from `Frontend`. Added browser coverage exercises clinic template/professional setup, full-payment validation, issuance and authenticated mobile client downloads/void status. Local tests and synthetic PDF inspection do not establish hosted database, printer or insurer acceptance.

Local acceptance on 15 October 2026: frontend build and bilingual validation passed; the final full browser run passed 118 tests, and the receipt/appointment flows also passed nine repeated checks. MariaDB 11.4.8 passed 31 receipt checks including concurrent replay, alongside 34 recurrence setup checks. Receipt policies/PDF generation passed 20 checks without GD and 22 with GD, including PNG/JPEG logos; English and French PDFs and desktop/mobile portal screenshots were inspected. PHP syntax passed for 78 source/test files; existing client, appointment access and practitioner visit policy checks passed. Composer validation and the locked dependency audit passed. No hosted migration, live receipt issuance or insurer/printer acceptance was performed.
