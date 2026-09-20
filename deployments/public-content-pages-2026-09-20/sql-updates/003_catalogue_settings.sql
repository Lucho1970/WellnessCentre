CREATE TABLE clinic_booking_settings (
 clinic_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 default_lead_time_minutes INT UNSIGNED NOT NULL DEFAULT 60,
 default_booking_horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
 default_cancellation_window_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
 slot_increment_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
 currency CHAR(3) NOT NULL DEFAULT 'CAD',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO clinic_booking_settings(clinic_id) SELECT id FROM clinics
ON DUPLICATE KEY UPDATE clinic_id=VALUES(clinic_id);
