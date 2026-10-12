-- Apply once after 039. No existing clinic role receives central access automatically.
CREATE TABLE application_administrators (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 issuer VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(160) NOT NULL,
 email VARCHAR(254) NOT NULL,
 status ENUM('active','revoked') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_application_administrator(issuer,subject)
) ENGINE=InnoDB;
