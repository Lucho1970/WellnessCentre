-- One-time manual cleanup for two services created by mistake.
-- This is NOT a migration. Back up the database before running it.
--
-- Targets (clinic 1):
--   1 = Massage Session 1.5hr
--   5 = Massage Session 2hr
--
-- The DELETE is all-or-nothing: it removes both services only when both exact
-- inactive records still exist and neither service is referenced by retained
-- business/history data. Assignment and duration rows use ON DELETE CASCADE.

SET FOREIGN_KEY_CHECKS = 1;
START TRANSACTION;

-- Preflight: confirm these are still the intended inactive records.
SELECT id, clinic_id, name, active
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5)
ORDER BY id;

-- Preflight: every count must be zero. If any count is non-zero, the DELETE
-- below deliberately affects zero rows and the services should remain inactive.
SELECT
  (SELECT COUNT(*) FROM appointments WHERE service_id IN (1, 5)) AS appointments,
  (SELECT COUNT(*) FROM appointments a JOIN service_duration_options d
     ON d.id = a.duration_option_id WHERE d.service_id IN (1, 5)) AS appointments_by_duration,
  (SELECT COUNT(*) FROM recurring_series WHERE service_id IN (1, 5)) AS recurring_series,
  (SELECT COUNT(*) FROM waitlist_entries WHERE service_id IN (1, 5)) AS waitlist_entries,
  (SELECT COUNT(*) FROM form_assignments WHERE service_id IN (1, 5)) AS form_assignments,
  (SELECT COUNT(*) FROM invoice_line_items WHERE service_id IN (1, 5)) AS invoice_lines;

DELETE FROM services
WHERE clinic_id = 1
  AND active = 0
  AND (
    (id = 1 AND BINARY name = BINARY 'Massage Session 1.5hr')
    OR
    (id = 5 AND BINARY name = BINARY 'Massage Session 2hr')
  )
  AND 2 = (
    SELECT matched_targets
    FROM (
      SELECT COUNT(*) AS matched_targets
      FROM services
      WHERE clinic_id = 1
        AND active = 0
        AND (
          (id = 1 AND BINARY name = BINARY 'Massage Session 1.5hr')
          OR
          (id = 5 AND BINARY name = BINARY 'Massage Session 2hr')
        )
    ) exact_targets
  )
  AND NOT EXISTS (SELECT 1 FROM appointments WHERE service_id IN (1, 5))
  AND NOT EXISTS (
    SELECT 1
    FROM appointments a
    JOIN service_duration_options d ON d.id = a.duration_option_id
    WHERE d.service_id IN (1, 5)
  )
  AND NOT EXISTS (SELECT 1 FROM recurring_series WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM waitlist_entries WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM form_assignments WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM invoice_line_items WHERE service_id IN (1, 5));

SET @deleted_accidental_services = ROW_COUNT();
COMMIT;

SELECT @deleted_accidental_services AS deleted_services;
-- Expected: 2.
-- A result of 0 means a target changed or has retained references. Do not
-- disable foreign keys or delete history to force removal.

SELECT id, clinic_id, name, active
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5)
ORDER BY id;
-- Expected after a successful cleanup: zero rows.
