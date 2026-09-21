CREATE TABLE service_locations (
 service_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 PRIMARY KEY(service_id,location_id),
 FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
 FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE practitioner_services
 ADD COLUMN offers_mobile BOOLEAN NOT NULL DEFAULT FALSE AFTER active,
 ADD COLUMN mobile_radius_km SMALLINT UNSIGNED NULL AFTER offers_mobile,
 ADD COLUMN travel_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER mobile_radius_km,
 ADD COLUMN mobile_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER travel_buffer_minutes;
