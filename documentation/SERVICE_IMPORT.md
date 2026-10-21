# Service spreadsheet import

Clinic-wide **Super Admins** can open **Services → Import services** in the staff portal. This uses the existing service-configuration authority: reception, practitioners, clinic administrators, location-scoped Super Admins, inactive accounts and application/global administrators without a current clinic staff Super Admin role cannot use it. Every request checks persisted clinic/account status and role grants. Import previews belong to their creating administrator and clinic.

## Workbook and review

Use an `.xlsx` workbook with a worksheet named exactly `All Services`. The importer reads the existing `Services-Library.xlsx` format:

| Column | Meaning |
| --- | --- |
| Category | Existing clinic category name, or a proposed new category |
| Service Name EN / Service Name FR | Bilingual service names |
| Type | `Consultation`, `Treatment` or `Assessment` |
| Durations | Comma-separated minutes, e.g. `30,45,60` |
| Initial Consultation | `Yes` or `No` |
| Summary EN / Summary FR | Bilingual public summaries |
| Detailed Description EN / Detailed Description FR | Bilingual descriptions |
| Service Code (optional) | Existing public URL slug, useful for matching a renamed service |
| Prices CAD (optional) | Duration-to-price pairs, e.g. `30:60.00,60:110.00` |

The supplied library has 64 services across six categories and no prices. Enter the price of **every duration of every new service** in the review screen, or add the optional Prices CAD column before uploading. A blank price never means free; explicit `0` does. Existing-service content updates require no replacement prices.

Upload, review content and prices, choose duplicate handling and whether to create missing categories, then select **Preview import**. Each source row shows its planned action or validation errors. Correct workbook content by uploading a corrected copy; price fields and import options can be changed in the screen. Changes require another preview. After checking the clinic, rows, prices and category changes, select the review checkbox and **Import reviewed services**. A completion report lists source row numbers, actions and service IDs and can be downloaded as CSV.

New services are **inactive and unpublished**, with no practitioner/location assignments. Review booking rules, room requirements and assignments before activation/publication. `Type` and `Initial Consultation` are retained as descriptive metadata in the restricted import record; they do not create consultation prerequisites or change booking eligibility.

Limits: 250 service rows, 2 MiB XLSX, 10 MiB total expanded ZIP entries, 1 MiB JSON API request. Text, duration and price limits are also enforced by the server. Durations must be distinct 15-minute increments from 15 to 480 minutes. Prices must be whole cents from 0 to 10,000,000 (CAD 100,000). All ten original fields are required. Formulas, macros, encrypted archives, external worksheet relationships, duplicate/unknown headers, malformed ZIP/XML and unsupported formats are refused. This reads workbook data only; no spreadsheet instructions, macros or formulas execute. The original workbook stays on the local computer; normalized service rows are sent to the clinic API.

## Duplicates and atomicity

- Default **Skip existing services** prevents accidental replacement on repeat imports.
- **Update names, descriptions and category only** preserves existing prices, duration option IDs, public URLs, booking rules, preparation instructions, active/published state and assignments.
- Matching uses the optional service code/public URL or English name within the current clinic. Case/accent-equivalent database names and collapsed whitespace are handled conservatively. Conflicting name/code matches and repeated rows require correction; the importer never guesses which record to overwrite.
- Missing category creation requires the explicit checkbox. Category spelling is reused within the batch, including case/accent equivalents.
- A preview expires after 30 minutes and is bound to the clinic catalogue version. Catalogue changes require another preview. Apply receives only the stored preview reference and confirmation hash, not editable replacement rows.
- Apply uses the existing clinic administration transaction and consent lock. Existing-service updates recheck all affected historical/current appointment and recurring-series consent restrictions. A restriction added after preview causes the entire batch to roll back.
- All categories, services, durations, business audit events and the applied marker commit together. Failure rolls everything back. Retrying an applied preview returns its stored result without duplicate writes. The same preview request key cannot be reused for different input.
- Access reads/denials use the existing access audit boundary. Business audit events retain preview references and counts; individual service/category changes retain their existing audit events. Uploaded descriptions/prices do not enter access/business audit metadata. Restricted batch storage contains the normalized plan, pricing and descriptive metadata for traceability; expiry prevents apply but does not delete this history. Follow the clinic's operational retention policy when maintaining these records.

## Deployment and validation

Apply `api/database/migrations/052_service_imports.sql` to each target clinic database before deploying the API/frontend. Fresh schema installs include the same table. No new permissions are automatically granted. The browser dynamically loads ZIP parsing; no PHP ZIP or spreadsheet extension is added. Keep the workbook, private configuration, generated builds and deployment packages out of Git.

API endpoints are `POST /api/v1/admin/service-imports/preview`, `GET /api/v1/admin/service-imports/{preview_id}` and `POST /api/v1/admin/service-imports/apply`. Preview accepts a UUID `request_key`, `file_name`, normalized `rows`, `duplicate_policy` (`skip` or `update_content`) and boolean `create_categories`. Apply accepts only the returned `preview_id` and `confirmation_hash`. All three require the same authenticated, current clinic-wide administrator; no arbitrary clinic or service IDs are accepted as row overrides.

Automated checks:

```powershell
# Synthetic authorization / transaction checks against a disposable local MariaDB runtime
./scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest service-imports.php
./scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-services.php

# Frontend validation and browser workflow
cd Frontend
npm run build
npx playwright test tests/portal.spec.ts --grep 'service import'
# Optional read-only acceptance with the private source workbook (no import request is sent):
$env:SERVICE_IMPORT_WORKBOOK = 'D:/Dev/WelnessCentre/documentation/Services-Library.xlsx'
npx playwright test tests/portal.spec.ts --grep 'supplied Services-Library'
```

For another disposable local SQL engine, set `RECURRING_TEST_ALLOW_CREATE=true`, `RECURRING_TEST_PORT`, `RECURRING_TEST_USER` and `RECURRING_TEST_PASSWORD`, then run `php api/tests/integration/service-imports.php`. The fixture connects only to `127.0.0.1`, creates a randomly named synthetic database and never reads `.env`.

Local checks are separate from hosted acceptance. Before production use, apply the migration on staging and verify a small synthetic import, role denial, a repeated apply and unchanged existing prices. Then preview the real library with approved prices in the intended clinic. This feature does not import real patient data or certify PHIPA compliance.

### Local validation, 21 October 2026

- Frontend build: content/i18n validation, TypeScript and all three production surfaces.
- Browser regression: 272/273 passed initially; the existing guest booking sign-in handoff hit its 5-second navigation timeout. That case passed unchanged on the focused rerun, alongside all seven import checks (8/8). Import checks include the supplied 64-row/six-category library, French UI, price validation, explicit confirmation, network retry, stale/denied access cleanup, malformed/formula/external/macro/oversized workbooks and discard handling.
- SQL import authorization: 150 checks on both local MySQL 5.7.44 and MariaDB 11.4.8, plus the shared 74-check booking access fixture on each.
- MariaDB authenticated service SQL/HTTP: 378 checks plus shared booking/access-audit fixtures. Restricted privacy-audit review regression: 69 checks plus shared fixtures.
- All 53 standalone PHP test scripts and syntax checks for 97 source/public/changed integration files passed.
- Dependency audit compared against unchanged `main`: identical existing high findings for `source-map-js` and `vite`; the ZIP dependency adds no audit finding. Those existing dependency upgrades remain separate maintenance work. Existing Vite chunk-size warnings also remain.
- No hosted database acceptance, production deployment or live service import was performed. The source workbook and generated output remain uncommitted.
