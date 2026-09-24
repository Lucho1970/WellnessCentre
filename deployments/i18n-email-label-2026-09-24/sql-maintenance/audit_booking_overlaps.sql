-- Read-only booking overlap audit for phpMyAdmin. Set the clinic ID first.
-- A zero-row result means no stored overlaps; it does NOT prove race safety.
SET @audit_clinic_id = 1;

-- Two non-canceled appointments occupying the same practitioner's buffered time.
SELECT a.id AS appointment_a, b.id AS appointment_b,
       a.practitioner_id, a.buffer_starts_at AS a_from_utc, a.buffer_ends_at AS a_to_utc,
       b.buffer_starts_at AS b_from_utc, b.buffer_ends_at AS b_to_utc
FROM appointments a
JOIN appointments b ON b.clinic_id = a.clinic_id AND b.id > a.id
                   AND b.practitioner_id = a.practitioner_id
                   AND b.status NOT IN ('canceled_by_client', 'canceled_by_clinic')
                   AND a.buffer_starts_at < b.buffer_ends_at
                   AND a.buffer_ends_at > b.buffer_starts_at
WHERE a.clinic_id = @audit_clinic_id
  AND a.status NOT IN ('canceled_by_client', 'canceled_by_clinic')
ORDER BY a.buffer_starts_at, a.id, b.id;

-- Two non-canceled appointments occupying the same room's buffered time.
SELECT a.id AS appointment_a, b.id AS appointment_b,
       a.room_id, a.buffer_starts_at AS a_from_utc, a.buffer_ends_at AS a_to_utc,
       b.buffer_starts_at AS b_from_utc, b.buffer_ends_at AS b_to_utc
FROM appointments a
JOIN appointments b ON b.clinic_id = a.clinic_id AND b.id > a.id
                   AND b.room_id = a.room_id
                   AND b.status NOT IN ('canceled_by_client', 'canceled_by_clinic')
                   AND a.buffer_starts_at < b.buffer_ends_at
                   AND a.buffer_ends_at > b.buffer_starts_at
WHERE a.clinic_id = @audit_clinic_id
  AND a.room_id IS NOT NULL
  AND a.status NOT IN ('canceled_by_client', 'canceled_by_clinic')
ORDER BY a.buffer_starts_at, a.id, b.id;

-- Inspect the winning test appointment after a two-browser attempt.
-- Replace 0 with the appointment ID shown in the portal.
SET @audit_appointment_id = 0;
SELECT a.id, a.clinic_id, a.practitioner_id, a.room_id, a.starts_at, a.ends_at, a.status,
       (SELECT COUNT(*) FROM appointment_status_history h WHERE h.appointment_id = a.id) AS history_rows,
       (SELECT COUNT(*) FROM notification_events n WHERE n.appointment_id = a.id) AS notification_rows
FROM appointments a
WHERE a.id = @audit_appointment_id AND a.clinic_id = @audit_clinic_id;
