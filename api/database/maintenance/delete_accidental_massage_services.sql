-- One-time manual cleanup for two services created by mistake.
-- This is NOT a migration. Back up the database before running it.
--
-- Targets (clinic 1):
--   1 = Massage Session 1.5hr
--   5 = Massage Session 2hr
--
-- The DELETE is all-or-nothing: it removes both services only when both exact
-- inactive records still exist. It also removes appointment 2 only if it is
-- still the exact canceled test booking shown below and has no protected
-- downstream records. Assignment and duration rows use ON DELETE CASCADE.

SET FOREIGN_KEY_CHECKS = 1;
START TRANSACTION;

-- Preflight: confirm these are still the intended inactive records.
SELECT id, clinic_id, name, active
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5)
ORDER BY id;

-- Preflight: confirm the one permitted appointment reference is still the
-- exact canceled test booking.
SELECT id, clinic_id, service_id, client_id, practitioner_id, location_id,
       status, starts_at, ends_at, source
FROM appointments
WHERE id = 2;

-- Protected appointment dependencies must all be zero. Attendees and status
-- history are shown separately because they are part of this canceled test
-- booking and will be removed by their existing ON DELETE CASCADE rules.
SELECT
  (SELECT COUNT(*) FROM cancellation_adjustments WHERE appointment_id = 2) AS cancellation_adjustments,
  (SELECT COUNT(*) FROM waitlist_offers WHERE appointment_id = 2) AS waitlist_offers,
  (SELECT COUNT(*) FROM form_submissions WHERE appointment_id = 2) AS form_submissions,
  (SELECT COUNT(*) FROM practitioner_client_notes WHERE appointment_id = 2) AS practitioner_notes,
  (SELECT COUNT(*) FROM notification_events WHERE appointment_id = 2) AS notification_events,
  (SELECT COUNT(*) FROM operational_tasks WHERE appointment_id = 2) AS operational_tasks,
  (SELECT COUNT(*) FROM invoices WHERE appointment_id = 2) AS invoices,
  (SELECT COUNT(*) FROM appointment_attendees WHERE appointment_id = 2) AS cascading_attendees,
  (SELECT COUNT(*) FROM appointment_status_history WHERE appointment_id = 2) AS cascading_status_history;

-- No other service-level references may exist.
SELECT
  (SELECT COUNT(*) FROM appointments WHERE service_id IN (1, 5)) AS appointments_expected_to_be_one,
  (SELECT COUNT(*) FROM recurring_series WHERE service_id IN (1, 5)) AS recurring_series,
  (SELECT COUNT(*) FROM waitlist_entries WHERE service_id IN (1, 5)) AS waitlist_entries,
  (SELECT COUNT(*) FROM form_assignments WHERE service_id IN (1, 5)) AS form_assignments,
  (SELECT COUNT(*) FROM invoice_line_items WHERE service_id IN (1, 5)) AS invoice_lines;

-- Delete only the exact canceled test appointment supplied for this cleanup.
-- The nested aggregate also guarantees it is the only appointment attached to
-- either target service.
DELETE FROM appointments
WHERE id = 2
  AND clinic_id = 1
  AND service_id = 5
  AND status = 'canceled_by_clinic'
  AND starts_at = '2026-09-19 14:00:00'
  AND ends_at = '2026-09-19 16:00:00'
  AND source = 'admin'
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
    ) service_guard
  )
  AND 1 = (
    SELECT service_appointment_count
    FROM (
      SELECT COUNT(*) AS service_appointment_count
      FROM appointments
      WHERE service_id IN (1, 5)
    ) appointment_guard
  )
  AND NOT EXISTS (SELECT 1 FROM cancellation_adjustments WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM waitlist_offers WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM form_submissions WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM practitioner_client_notes WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM notification_events WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM operational_tasks WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM invoices WHERE appointment_id = 2)
  AND NOT EXISTS (SELECT 1 FROM recurring_series WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM waitlist_entries WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM form_assignments WHERE service_id IN (1, 5))
  AND NOT EXISTS (SELECT 1 FROM invoice_line_items WHERE service_id IN (1, 5));

SET @deleted_accidental_appointment = ROW_COUNT();

DELETE FROM services
WHERE clinic_id = 1
  AND active = 0
  AND @deleted_accidental_appointment = 1
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

SELECT @deleted_accidental_appointment AS deleted_appointments;
SELECT @deleted_accidental_services AS deleted_services;
-- Expected: deleted_appointments = 1 and deleted_services = 2.
-- A result of 0 means a target changed or has retained references. Do not
-- disable foreign keys or delete history to force removal.

SELECT id, status, service_id
FROM appointments
WHERE id = 2;
-- Expected after a successful cleanup: zero rows.

SELECT id, clinic_id, name, active
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5)
ORDER BY id;
-- Expected after a successful cleanup: zero rows.
