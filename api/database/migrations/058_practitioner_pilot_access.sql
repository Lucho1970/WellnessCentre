-- Apply once after 032 product identities, 040 application administration,
-- and 049 access audit. Independent of the disabled communications features 056/057.
-- Back up and rehearse first. Does not seed approvals, memberships or feature flags.
CREATE TABLE practitioner_pilot_access (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 identity_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(160) NOT NULL,
 status ENUM('pending','approved','revoked') NOT NULL DEFAULT 'pending',
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 decided_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 approved_at DATETIME NULL,
 revoked_at DATETIME NULL,
 UNIQUE KEY uq_practitioner_pilot_identity(clinic_id,identity_id),
 UNIQUE KEY uq_practitioner_pilot_scope(id,clinic_id),
 KEY ix_practitioner_pilot_list(clinic_id,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(identity_id) REFERENCES product_identities(id),
 FOREIGN KEY(created_by) REFERENCES application_administrators(id),
 FOREIGN KEY(decided_by) REFERENCES application_administrators(id)
) ENGINE=InnoDB;

CREATE TABLE practitioner_pilot_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 access_id BIGINT UNSIGNED NOT NULL,
 application_administrator_id BIGINT UNSIGNED NOT NULL,
 action ENUM('record','approve','revoke') NOT NULL,
 reason VARCHAR(500) NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 correlation_id VARCHAR(128) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_practitioner_pilot_events(clinic_id,access_id,id),
 FOREIGN KEY(access_id,clinic_id) REFERENCES practitioner_pilot_access(id,clinic_id),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB;

CREATE TABLE practitioner_pilot_requests (
 clinic_id BIGINT UNSIGNED NOT NULL,
 application_administrator_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(clinic_id,application_administrator_id,reference),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB;
