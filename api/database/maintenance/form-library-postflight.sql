-- Read-only. Expect three OK rows with InnoDB and utf8mb4 collation.
SELECT required.table_name,
 CASE WHEN t.table_name IS NULL THEN 'MISSING - STOP' ELSE 'OK' END result,
 t.engine,t.table_collation
FROM (SELECT 'form_library_entries' table_name UNION ALL SELECT 'form_library_versions'
 UNION ALL SELECT 'form_library_events') required
LEFT JOIN information_schema.tables t ON t.table_schema='wellness_centre' AND t.table_name=required.table_name;
