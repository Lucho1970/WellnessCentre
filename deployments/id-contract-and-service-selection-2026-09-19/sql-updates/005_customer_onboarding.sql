-- MySQL 5.7+, additive, no seed data. Back up first. Execute in the existing database.
-- Run each complete CREATE statement if the SQL editor has a length limit.
CREATE TABLE IF NOT EXISTS customer_identities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 issuer VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_client_links (
 identity_id BIGINT UNSIGNED PRIMARY KEY,
 client_id BIGINT UNSIGNED NOT NULL UNIQUE,
 clinic_id BIGINT UNSIGNED NOT NULL,
 approved_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(identity_id) REFERENCES customer_identities(id),
 FOREIGN KEY(client_id) REFERENCES users(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_contact_addresses (
 client_id BIGINT UNSIGNED PRIMARY KEY,
 address_json JSON NOT NULL,
 FOREIGN KEY(client_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_auth_challenges (
 nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 INDEX ix_customer_challenge_expiry(expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 identity_id BIGINT UNSIGNED NOT NULL,
 authenticated_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 last_activity_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 INDEX ix_customer_sessions_identity(identity_id),
 FOREIGN KEY(identity_id) REFERENCES customer_identities(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_link_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 revoked_at DATETIME NULL,
 INDEX ix_client_invitations(client_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(client_id) REFERENCES users(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_link_claims (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invitation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 identity_id BIGINT UNSIGNED NOT NULL,
 claimant_name VARCHAR(150) NOT NULL,
 review_code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 reviewed_at DATETIME NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 INDEX ix_customer_claims(identity_id,status),
 FOREIGN KEY(invitation_id) REFERENCES client_link_invitations(id),
 FOREIGN KEY(identity_id) REFERENCES customer_identities(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_rate_limits (
 bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX ix_customer_rate_expiry(expires_at)
) ENGINE=InnoDB;
