-- Optional manual cleanup, NOT a migration. Back up first.
-- Run this lookup first, then fill in the three variables below and execute
-- the remaining statements together. Defaults intentionally match nothing.
SELECT id, clinic_id, name, active FROM services ORDER BY clinic_id, name;

SET @cleanup_service_id = 0;
SET @cleanup_clinic_id = 0;
SET @cleanup_service_name = 'REPLACE WITH EXACT SERVICE NAME';
SET FOREIGN_KEY_CHECKS = 1;

-- Refuse deletion if any appointment (including canceled), recurring series,
-- waitlist, form assignment or invoice line references this service.
-- Associated durations and practitioner/location/capability assignments cascade.
DELETE FROM services
WHERE id = @cleanup_service_id
  AND clinic_id = @cleanup_clinic_id
  AND BINARY name = BINARY @cleanup_service_name
  AND NOT EXISTS (SELECT 1 FROM appointments WHERE service_id = @cleanup_service_id)
  AND NOT EXISTS (SELECT 1 FROM appointments a JOIN service_duration_options d
                  ON d.id = a.duration_option_id WHERE d.service_id = @cleanup_service_id)
  AND NOT EXISTS (SELECT 1 FROM recurring_series WHERE service_id = @cleanup_service_id)
  AND NOT EXISTS (SELECT 1 FROM waitlist_entries WHERE service_id = @cleanup_service_id)
  AND NOT EXISTS (SELECT 1 FROM form_assignments WHERE service_id = @cleanup_service_id)
  AND NOT EXISTS (SELECT 1 FROM invoice_line_items WHERE service_id = @cleanup_service_id);

SELECT ROW_COUNT() AS deleted_services;
-- Expected: 1. If 0, the target did not match or is referenced. Do not disable
-- foreign keys or delete history to force removal. Deactivate it in the app.
