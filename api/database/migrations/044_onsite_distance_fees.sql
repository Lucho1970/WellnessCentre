-- Apply once to the selected portal database after migration 043.
-- NULL preserves the legacy flat fee for existing assignments.
ALTER TABLE practitioner_services
  ADD COLUMN mobile_free_radius_km SMALLINT UNSIGNED NULL AFTER mobile_radius_km;
ALTER TABLE appointments
  ADD COLUMN mobile_distance_meters INT UNSIGNED NULL,
  ADD COLUMN mobile_assessed_fee_cents INT UNSIGNED NULL,
  ADD COLUMN mobile_fee_waived_by BIGINT UNSIGNED NULL,
  ADD COLUMN mobile_fee_waived_at DATETIME NULL,
  ADD COLUMN mobile_fee_waiver_reason VARCHAR(300) NULL;
