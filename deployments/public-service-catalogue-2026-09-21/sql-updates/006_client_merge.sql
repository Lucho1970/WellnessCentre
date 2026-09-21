-- MySQL 5.7+, additive. Back up first and run once before deploying matching code.
-- Existing user emails are copied as primary client email aliases. No clients are merged automatically.
CREATE TABLE IF NOT EXISTS client_email_addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NOT NULL,
 is_primary BOOLEAN NOT NULL DEFAULT FALSE,
 verified_at DATETIME NULL,
 source ENUM('staff','customer','merge') NOT NULL DEFAULT 'staff',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_client_email_clinic(clinic_id,email),
 UNIQUE KEY uq_client_email_address(client_id,email),
 INDEX ix_client_email_client(client_id,is_primary),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(client_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_merge_records (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 survivor_client_id BIGINT UNSIGNED NOT NULL,
 duplicate_client_id BIGINT UNSIGNED NOT NULL,
 merged_by BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 primary_email_source ENUM('survivor','duplicate') NOT NULL,
 profile_source ENUM('survivor','duplicate') NOT NULL,
 address_source ENUM('survivor','duplicate') NOT NULL,
 relationship_counts JSON NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_client_merge_duplicate(duplicate_client_id),
 INDEX ix_client_merge_survivor(survivor_client_id,created_at),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(survivor_client_id) REFERENCES users(id),
 FOREIGN KEY(duplicate_client_id) REFERENCES users(id),
 FOREIGN KEY(merged_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO client_email_addresses(clinic_id,client_id,email,is_primary,source)
 SELECT u.clinic_id,u.id,LOWER(u.email),TRUE,'staff' FROM users u
 WHERE u.user_type='client' AND NOT EXISTS(SELECT 1 FROM client_merge_records m WHERE m.duplicate_client_id=u.id)
 ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary);
