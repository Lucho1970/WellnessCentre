-- MySQL 5.7+. Apply once before deploying the notification review API.
ALTER TABLE notification_events
  MODIFY status ENUM('queued','sending','sent','delivered','failed','canceled','needs_review','resolved') NOT NULL DEFAULT 'queued';

CREATE TABLE notification_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  notification_event_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  decision ENUM('resolve','retry') NOT NULL,
  outcome ENUM('provider_not_sent','provider_accepted','handled_manually','no_longer_needed') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_notification_reviews_event(notification_event_id,created_at),
  FOREIGN KEY(clinic_id) REFERENCES clinics(id),
  FOREIGN KEY(notification_event_id) REFERENCES notification_events(id),
  FOREIGN KEY(reviewer_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
