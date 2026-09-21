-- One-time MySQL 5.7-compatible cleanup for confirmed test data.
-- This is NOT a migration. Back up the database before running it.
--
-- Confirmed targets in clinic 1:
--   appointment 2 = canceled admin test booking on service 5
--   service 1     = Massage Session 1.5hr (inactive)
--   service 5     = Massage Session 2hr (inactive)
--   two notification_events belong to appointment 2
--
-- Previous preflight confirmed there are no invoices, adjustments, forms,
-- notes, tasks, waitlist records, recurring series or invoice lines attached.
-- Existing foreign keys remain enabled and will stop this transaction if an
-- unrecognized protected reference has appeared since that preflight.

SET FOREIGN_KEY_CHECKS = 1;
START TRANSACTION;

DELETE FROM notification_events
WHERE appointment_id = 2;
SET @deleted_test_notifications = ROW_COUNT();

DELETE FROM appointments
WHERE id = 2
  AND clinic_id = 1
  AND service_id = 5
  AND status = 'canceled_by_clinic'
  AND starts_at = '2026-09-19 14:00:00'
  AND ends_at = '2026-09-19 16:00:00'
  AND source = 'admin';
SET @deleted_accidental_appointment = ROW_COUNT();

DELETE FROM services
WHERE clinic_id = 1
  AND active = 0
  AND (
    (id = 1 AND BINARY name = BINARY 'Massage Session 1.5hr')
    OR
    (id = 5 AND BINARY name = BINARY 'Massage Session 2hr')
  );
SET @deleted_accidental_services = ROW_COUNT();

COMMIT;

SELECT @deleted_test_notifications AS deleted_notifications;
SELECT @deleted_accidental_appointment AS deleted_appointments;
SELECT @deleted_accidental_services AS deleted_services;
-- Expected: 2, 1, and 2 respectively.

SELECT id, status, service_id
FROM appointments
WHERE id = 2;

SELECT id, clinic_id, name, active
FROM services
WHERE clinic_id = 1
  AND id IN (1, 5)
ORDER BY id;
-- Both verification queries should return zero rows.
