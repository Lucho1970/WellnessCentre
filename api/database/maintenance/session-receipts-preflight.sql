-- Read-only. Select the existing hosted application's database in phpMyAdmin first.
SELECT DATABASE() AS selected_database, VERSION() AS database_server_version;

-- Every row must be OK. This release assumes the existing deployed schema through 042.
SELECT required.table_name, IF(t.table_name IS NULL, 'MISSING - STOP', 'OK') AS prerequisite
FROM (
 SELECT 'clinics' AS table_name UNION ALL SELECT 'users' UNION ALL SELECT 'practitioners'
 UNION ALL SELECT 'services' UNION ALL SELECT 'locations' UNION ALL SELECT 'appointments'
 UNION ALL SELECT 'appointment_status_history' UNION ALL SELECT 'invoices'
 UNION ALL SELECT 'invoice_line_items' UNION ALL SELECT 'payments' UNION ALL SELECT 'refunds'
 UNION ALL SELECT 'audit_logs' UNION ALL SELECT 'global_practitioners'
) required
LEFT JOIN information_schema.tables t ON t.table_schema=DATABASE() AND t.table_name=required.table_name;

SELECT required.column_name, IF(c.column_name IS NULL, 'MISSING - STOP', 'OK') AS appointment_prerequisite
FROM (SELECT 'base_price_cents' AS column_name UNION ALL SELECT 'mobile_fee_cents'
 UNION ALL SELECT 'delivery_mode' UNION ALL SELECT 'version') required
LEFT JOIN information_schema.columns c ON c.table_schema=DATABASE()
 AND c.table_name='appointments' AND c.column_name=required.column_name;

-- 0: migration 043 not yet applied. 3: already present; do NOT import again.
-- 1 or 2: partial migration; stop and review before proceeding.
SELECT COUNT(*) AS receipt_tables_present
FROM information_schema.tables WHERE table_schema=DATABASE()
 AND table_name IN ('receipt_templates','practitioner_receipt_profiles','session_receipts');
