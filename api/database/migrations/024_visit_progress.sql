-- MySQL 5.7+. Apply before deploying the practitioner Today's Visits API.
-- Append-only event actions preserve corrections for audit and future analytics.
CREATE TABLE appointment_visit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NOT NULL,
  practitioner_user_id BIGINT UNSIGNED NOT NULL,
  event_code VARCHAR(32) NOT NULL,
  event_action ENUM('record','undo') NOT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_visit_events_appointment (appointment_id,id),
  INDEX ix_visit_events_clinic_time (clinic_id,occurred_at),
  FOREIGN KEY (clinic_id) REFERENCES clinics(id),
  FOREIGN KEY (appointment_id) REFERENCES appointments(id),
  FOREIGN KEY (practitioner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
