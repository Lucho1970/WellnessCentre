-- Preserve the location/timezone used to enter time off so it can be viewed and edited accurately.
ALTER TABLE time_off
  ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER practitioner_id;

UPDATE time_off t
SET t.location_id = (
  SELECT MIN(pl.location_id)
  FROM practitioner_locations pl
  WHERE pl.practitioner_id = t.practitioner_id
    AND pl.active = 1
)
WHERE t.location_id IS NULL;

ALTER TABLE time_off
  ADD CONSTRAINT fk_time_off_location
  FOREIGN KEY (location_id) REFERENCES locations(id);
