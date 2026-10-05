-- MySQL 5.7+. Apply once before deploying scheduler health tracking.
-- One global scheduler state contains no clinic, recipient, or message details.
CREATE TABLE notification_scheduler_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  status ENUM('running','succeeded','failed') NOT NULL,
  last_started_at DATETIME NOT NULL,
  last_completed_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_failure_at DATETIME NULL,
  last_error_class VARCHAR(190) NULL,
  sent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  review_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  canceled_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
