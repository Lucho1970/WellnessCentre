-- Select the live application database in phpMyAdmin before running.
SELECT DATABASE() AS selected_database;
SELECT required.table_name,IF(t.table_name IS NULL,'MISSING - STOP','OK') AS prerequisite
FROM (
 SELECT 'clinics' table_name UNION ALL SELECT 'users' UNION ALL SELECT 'practitioners'
 UNION ALL SELECT 'services' UNION ALL SELECT 'service_duration_options'
 UNION ALL SELECT 'practitioner_services' UNION ALL SELECT 'practitioner_locations'
 UNION ALL SELECT 'service_locations' UNION ALL SELECT 'locations'
 UNION ALL SELECT 'public_team_profiles' UNION ALL SELECT 'practitioner_private_locations'
 UNION ALL SELECT 'audit_logs'
) required
LEFT JOIN information_schema.tables t ON t.table_schema=DATABASE() AND t.table_name=required.table_name;
SELECT required.column_name,IF(c.column_name IS NULL,'MISSING - STOP','OK') AS prerequisite
FROM (SELECT 'mobile_radius_km' column_name UNION ALL SELECT 'mobile_free_radius_km') required
LEFT JOIN information_schema.columns c ON c.table_schema=DATABASE() AND c.table_name='practitioner_services' AND c.column_name=required.column_name;
