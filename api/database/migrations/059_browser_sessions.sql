-- Local development implementation. NOT approved for hosted execution.
-- Prerequisites: 005, 039 and 049. Apply once with separate migration credentials.
-- Additive only: no identities, roles, grants, sessions or feature flags are seeded.
CREATE TABLE browser_session_epochs (
 scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 principal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(scope_hash,principal_hash)
) ENGINE=InnoDB;
CREATE TABLE browser_sessions (
 secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 principal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 realm ENUM('workforce','practitioner','central') NOT NULL,
 host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 clinic_id BIGINT UNSIGNED NULL,
 authenticated_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 last_activity_at DATETIME NOT NULL,
 absolute_expires_at DATETIME NOT NULL,
 policy_version INT UNSIGNED NOT NULL,
 issued_epoch BIGINT UNSIGNED NOT NULL,
 revoked_at DATETIME NULL,
 KEY ix_browser_session_principal(scope_hash,principal_hash,revoked_at),
 KEY ix_browser_session_expiry(absolute_expires_at),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
CREATE TABLE browser_auth_challenges (
 secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose ENUM('login','action') NOT NULL,
 session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 action_name VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 target_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 target_version BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 KEY ix_browser_challenge_expiry(expires_at),
 KEY ix_browser_challenge_scope(scope_hash,created_at)
) ENGINE=InnoDB;
CREATE TABLE browser_action_proofs (
 secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 principal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_name VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_version BIGINT UNSIGNED NOT NULL,
 authenticated_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 KEY ix_browser_action_session(session_hash,expires_at),
 KEY ix_browser_action_expiry(expires_at)
) ENGINE=InnoDB;
-- Legacy rows stay NULL and cannot satisfy the new policy. Never invent auth times.
ALTER TABLE customer_sessions
 ADD scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD principal_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD policy_version INT UNSIGNED NULL,
 ADD issued_epoch BIGINT UNSIGNED NULL,
 ADD KEY ix_customer_browser_principal(scope_hash,principal_hash,revoked_at);
