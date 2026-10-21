# Consent-authorized dashboard widget management

Widget list, publication, version history, restore and enabled-state routes now have an explicit configuration boundary. They remain available under unrelated or global patient `staff_access` restrictions because they configure fixed projections without selecting patient records. Dashboard reads continue to apply consent, workspace capability and practitioner ownership at query time.

## Authority and transactions

Only an active staff account with a current, persisted `super_admin` role in the active clinic may manage widgets. Both the supplied principal and current database authority are checked; stale roles, inactive accounts/clinics, clinic mismatch, clients, practitioners, reception, accountants and Clinic Admins are refused. Application/global administrator status and privacy-review permission confer no widget authority. The body cannot select another clinic; matching widget keys and their versions are scoped to the actor's clinic.

All five operations acquire the clinic consent lock and use an independent transaction with the active clinic coordination row locked. This serializes publication/version allocation and coordinates with privacy updates. Mutations and their business audits commit together; audit failure rolls back publication, restoration or toggling. Nested caller-controlled transactions are refused without rolling back the caller. The existing native-PDO duplicate actor placeholders in first-publication and built-in toggle inserts were corrected.

## Definitions cannot grant patient access

Definitions use only the approved appointment-count, next-appointment and notification-summary projections, matching renderers, fixed destination pages, known icons, supported parameters and workspace capabilities. Unsupported schema versions, extra top-level fields, extra label/destination keys and malformed option types are rejected. No SQL, executable code, patient IDs or arbitrary query filters are accepted. Titles/descriptions remain administrator-written configuration: use generic bilingual labels, never patient information.

Stored active definitions and returned historical versions are validated again, including the stored SHA-256 hash, widget-key binding, supported schema, capability/workspace pairing and built-in projection/capability invariants. Restore and enable validate the selected version before activation. Unsafe or missing active definitions fail closed with generic `widget_definition_unavailable`; no projected patient data is returned. Disabling an unsafe widget remains possible through its PATCH API and excludes it from dashboard rendering. Repair by uploading a valid replacement, restoring a known valid version, or restoring version zero for a built-in. Invalid historical versions are retained; version history fails closed until repaired through a reviewed maintenance process. Review legacy definitions before rollout: formerly ignored extra fields are now rejected.

Publishing a custom widget does not grant a recipient a capability, clinic membership or practitioner relationship. The existing consent-filtered SQL executes independently for each viewer. A practitioner next-appointment widget cannot return another practitioner's appointment or a restricted patient, even when those rows sort first.

## Audit and portal

Central access events record numeric `dashboard_widget` references from successful management responses. Configuration labels, raw JSON and staff display names are not copied into central audit metadata. String widget keys are sanitized in route history; restore-version ordinals are not mistaken for widget IDs. Business audits retain the configuration key, changed version/enabled state and numeric widget ID. The restricted privacy-history filter includes `dashboard_widget`.

After authoritative 401/403/404 responses, the portal clears cached widget details, uploaded previews and version dialogs, removing stale toggle/restore controls. Server authorization remains authoritative.

## Validation and rollout

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-dashboard-widgets.php` with a disposable local MariaDB runtime. It reuses the booking-contact and central access-audit regressions, synthetic authentication and loopback HTTP; no hosted records, production credentials or external messages are used.

No new migration or environment variable is required. Deploy matching API and portal after reviewing legacy widget JSON and existing consent/access-audit prerequisites. Hosted identity, shared-lock behavior, concurrent publication/role revocation, database compatibility and audit retention acceptance remain required. This task does not authorize deployment or real-patient-data use; the [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain open.

Local validation on 19 October 2026:

- 204 new widget SQL/HTTP checks passed on MariaDB 11.4.8, including current authority, all five role boundaries, clinic isolation, consent-filtered custom projections, invalid definition/hash rejection, native-PDO creation/toggling, business-audit rollback, shared-lock contention and nested-transaction refusal. The same final run passed 74 booking-contact and 28 central access-audit checks.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed; the new integration source and PowerShell SQL runner passed syntax checks.
- Four selected browser scenarios passed: existing widget publication/replacement/restoration, two denied-operation cleanup scenarios and privacy-history filtering/pagination/revocation. Full browser regression was not run.
- Full public, portal and application-admin production build passed, including TypeScript, content and 2,110 English/French translations. Existing bundle-size warnings remain.
- The first SQL run exposed the existing duplicate-placeholder error; it was fixed and the final SQL suite passed. Initial browser assertions were corrected to expect the existing translated permission message rather than untranslated synthetic server text.

All checks are local. Hosted acceptance and real-data readiness remain outstanding.
