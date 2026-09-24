-- Temporary, bounded diagnostic for Netfirms' URL-based scheduler.
-- Run once. The bridge updates only this one row while MAIL_ENABLED=false.
CREATE TABLE notification_scheduler_probe (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_seen_at DATETIME NOT NULL,
  last_source_ip VARCHAR(45) NOT NULL,
  last_method VARCHAR(12) NOT NULL,
  hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
