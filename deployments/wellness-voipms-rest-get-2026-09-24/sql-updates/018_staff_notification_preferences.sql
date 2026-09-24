-- Staff operational-notice preferences. Sign-in identity remains in users/identity_links.
-- SMS is recorded as a request only; no SMS is queued until provider approval and a
-- separate, explicitly enabled transport are deployed.
CREATE TABLE staff_notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  email_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  email_destination ENUM('work','personal','both') NOT NULL DEFAULT 'work',
  personal_email VARCHAR(190),
  personal_email_verified_at DATETIME,
  email_code_hash VARCHAR(255),
  email_code_expires_at DATETIME,
  email_code_sent_at DATETIME,
  email_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  mobile_phone VARCHAR(20),
  sms_requested BOOLEAN NOT NULL DEFAULT FALSE,
  sms_requested_at DATETIME,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_staff_notice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
