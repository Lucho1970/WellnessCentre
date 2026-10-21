-- Read-only. Select wellness_centre; all prerequisites must be OK.
SELECT required.table_name,
 CASE WHEN t.table_name IS NULL THEN 'MISSING - STOP' ELSE 'OK' END prerequisite
FROM (SELECT 'clinics' table_name UNION ALL SELECT 'users' UNION ALL SELECT 'practitioners'
 UNION ALL SELECT 'form_templates' UNION ALL SELECT 'form_template_drafts'
 UNION ALL SELECT 'application_administrators') required
LEFT JOIN information_schema.tables t ON t.table_schema='wellness_centre' AND t.table_name=required.table_name;
-- 0 = not installed; 3 = already installed (use postflight); 1 or 2 = STOP, partial update.
SELECT COUNT(*) library_tables_present FROM information_schema.tables
WHERE table_schema='wellness_centre' AND table_name IN ('form_library_entries','form_library_versions','form_library_events');
-- Expect 6: the full API package requires the preceding travel-fee migration 044.
SELECT COUNT(*) travel_columns_present FROM information_schema.columns
WHERE table_schema='wellness_centre' AND
 ((table_name='practitioner_services' AND column_name='mobile_free_radius_km') OR
  (table_name='appointments' AND column_name IN ('mobile_distance_meters','mobile_assessed_fee_cents','mobile_fee_waived_by','mobile_fee_waived_at','mobile_fee_waiver_reason')));
