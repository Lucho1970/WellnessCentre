-- Optional manual cleanup, NOT a migration. Back up first.
-- Run this lookup first. Confirm the exact room and location, then fill in both
-- IDs below and execute the remaining statements together.
SELECT r.id, r.name, r.location_id, l.name AS location_name, l.clinic_id
FROM rooms r JOIN locations l ON l.id = r.location_id
WHERE r.name = 'Remote';

SET @cleanup_room_id = 0;
SET @cleanup_location_id = 0;
SET FOREIGN_KEY_CHECKS = 1;

-- A room used by any appointment, including a canceled appointment, is retained.
-- Its capability assignments and practitioner restrictions cascade on deletion.
DELETE FROM rooms
WHERE id = @cleanup_room_id
  AND location_id = @cleanup_location_id
  AND BINARY name = BINARY 'Remote'
  AND NOT EXISTS (SELECT 1 FROM appointments WHERE room_id = @cleanup_room_id);

SELECT ROW_COUNT() AS deleted_rooms;
-- Expected: 1. If 0, check the IDs or mark the room non-bookable instead.
-- A deleted room and its settings can only be recovered from your backup.
