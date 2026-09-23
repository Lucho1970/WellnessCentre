-- Read-only verification for the accidental massage service cleanup.
-- Compatible with MySQL 5.7. This script does not modify any data.
-- Every result should report PASS with remaining_rows = 0.

SELECT
  'target services removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5);

SELECT
  'test appointment removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM appointments
WHERE id = 2;

SELECT
  'test notifications removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM notification_events
WHERE appointment_id = 2;

SELECT
  'service durations removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM service_duration_options
WHERE service_id IN (1, 5);

SELECT
  'practitioner assignments removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM practitioner_services
WHERE service_id IN (1, 5);

SELECT
  'location assignments removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM service_locations
WHERE service_id IN (1, 5);

SELECT
  'room requirements removed' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM service_room_capability_requirements
WHERE service_id IN (1, 5);

SELECT
  'recurring series references absent' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM recurring_series
WHERE service_id IN (1, 5);

SELECT
  'waitlist references absent' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM waitlist_entries
WHERE service_id IN (1, 5);

SELECT
  'form assignments absent' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM form_assignments
WHERE service_id IN (1, 5);

SELECT
  'invoice line references absent' AS check_name,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
  COUNT(*) AS remaining_rows
FROM invoice_line_items
WHERE service_id IN (1, 5);

SELECT
  'foreign key checks enabled' AS check_name,
  IF(@@FOREIGN_KEY_CHECKS = 1, 'PASS', 'FAIL') AS result,
  IF(@@FOREIGN_KEY_CHECKS = 1, 0, 1) AS remaining_rows;
