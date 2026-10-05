-- MySQL 5.7+. Apply before deploying appointment logistics notes.
-- Separate from treatment records and client-wide administrative notes.
CREATE TABLE appointment_logistics_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NOT NULL,
  author_user_id BIGINT UNSIGNED NOT NULL,
  note_text VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_appointment_logistics_notes (clinic_id,appointment_id,id),
  FOREIGN KEY (clinic_id) REFERENCES clinics(id),
  FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
