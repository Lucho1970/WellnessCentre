-- MySQL 5.7+. Back up first; run once before deploying the matching API.
-- Existing assignments/appointments retain clinic behavior. No records are seeded.
ALTER TABLE practitioner_services
 ADD COLUMN offers_clinic BOOLEAN NOT NULL DEFAULT TRUE AFTER offers_mobile;

ALTER TABLE appointments
 ADD COLUMN delivery_mode ENUM('clinic','mobile') NOT NULL DEFAULT 'clinic',
 ADD COLUMN destination_snapshot JSON NULL,
 ADD COLUMN travel_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN base_price_cents INT UNSIGNED NULL,
 ADD COLUMN mobile_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN coverage_confirmed_by BIGINT UNSIGNED NULL,
 ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'CAD';
