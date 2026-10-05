-- MySQL 5.7+. Apply before deploying configurable portal colours and font.
CREATE TABLE clinic_portal_themes (
  clinic_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  primary_color CHAR(7) NOT NULL DEFAULT '#176b62',
  secondary_color CHAR(7) NOT NULL DEFAULT '#d8754c',
  font_family ENUM('Inter','Arial','Georgia') NOT NULL DEFAULT 'Inter',
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
