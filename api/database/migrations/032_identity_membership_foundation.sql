-- Additive foundation only. No backfill and no login cutover.
-- MySQL 5.7+: reconcile live schema and back up before applying.
CREATE TABLE product_identities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 adapter VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 issuer VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_identity(adapter,issuer,subject)
) ENGINE=InnoDB;

CREATE TABLE staff_memberships (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identity_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NOT NULL,
 local_user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_staff_identity_clinic(identity_id,clinic_id),
 UNIQUE KEY uq_staff_local_user(local_user_id),
 FOREIGN KEY(identity_id) REFERENCES product_identities(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(local_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Existing user_roles/user_permissions remain the authoritative local grants.
-- No role copying, email linking, customer session changes or invitation grants.
