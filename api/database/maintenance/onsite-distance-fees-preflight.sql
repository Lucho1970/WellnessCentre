-- Read-only; explicit schema name avoids phpMyAdmin DATABASE() context differences.
SELECT required.table_name,
       CASE WHEN t.table_name IS NULL THEN 'MISSING - STOP' ELSE 'OK' END prerequisite
FROM (SELECT 'practitioner_services' table_name UNION ALL SELECT 'appointments'
      UNION ALL SELECT 'invoices' UNION ALL SELECT 'audit_logs'
      UNION ALL SELECT 'onsite_area_approvals' UNION ALL SELECT 'practitioner_private_locations'
      UNION ALL SELECT 'session_receipts' UNION ALL SELECT 'receipt_templates'
      UNION ALL SELECT 'practitioner_receipt_profiles') required
LEFT JOIN information_schema.tables t ON t.table_schema='wellness_centre' AND t.table_name=required.table_name;
-- Expected 0 before migration; 6 means already applied; 1..5 means STOP (partial).
SELECT COUNT(*) new_columns_present
FROM information_schema.columns
WHERE table_schema='wellness_centre' AND
 ((table_name='practitioner_services' AND column_name='mobile_free_radius_km') OR
  (table_name='appointments' AND column_name IN ('mobile_distance_meters','mobile_assessed_fee_cents','mobile_fee_waived_by','mobile_fee_waived_at','mobile_fee_waiver_reason')));
