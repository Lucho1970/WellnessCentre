-- Optional business-data cleanup, NOT a schema migration.
-- The application now calls visits at a client's location "On-Site". Internal
-- delivery_mode='mobile' values and mobile_* columns must NOT be renamed.
-- Back up first. Run the SELECT statements, verify each result, and only then
-- run the UPDATE statements if these exact legacy labels belong to this use.

SELECT 'locations' AS source_table, id, name FROM locations WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'rooms', id, name FROM rooms WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'service_categories', id, name FROM service_categories WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'services', id, name FROM services WHERE BINARY name = BINARY 'Remote';

-- Each update skips a row when "On-Site" already exists in the same unique
-- naming scope. Resolve any skipped duplicate manually instead of merging it.
UPDATE locations legacy
LEFT JOIN locations current_name
  ON current_name.clinic_id = legacy.clinic_id
 AND BINARY current_name.name = BINARY 'On-Site'
SET legacy.name = 'On-Site'
WHERE BINARY legacy.name = BINARY 'Remote'
  AND current_name.id IS NULL;

UPDATE rooms legacy
LEFT JOIN rooms current_name
  ON current_name.location_id = legacy.location_id
 AND BINARY current_name.name = BINARY 'On-Site'
SET legacy.name = 'On-Site'
WHERE BINARY legacy.name = BINARY 'Remote'
  AND current_name.id IS NULL;

UPDATE service_categories legacy
LEFT JOIN service_categories current_name
  ON current_name.clinic_id = legacy.clinic_id
 AND BINARY current_name.name = BINARY 'On-Site'
SET legacy.name = 'On-Site'
WHERE BINARY legacy.name = BINARY 'Remote'
  AND current_name.id IS NULL;

UPDATE services legacy
LEFT JOIN services current_name
  ON current_name.clinic_id = legacy.clinic_id
 AND BINARY current_name.name = BINARY 'On-Site'
SET legacy.name = 'On-Site'
WHERE BINARY legacy.name = BINARY 'Remote'
  AND current_name.id IS NULL;

-- Confirm no exact legacy labels remain. Descriptions are intentionally not
-- changed automatically because "remote" may refer to virtual care.
SELECT 'locations' AS source_table, id, name FROM locations WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'rooms', id, name FROM rooms WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'service_categories', id, name FROM service_categories WHERE BINARY name = BINARY 'Remote'
UNION ALL
SELECT 'services', id, name FROM services WHERE BINARY name = BINARY 'Remote';
