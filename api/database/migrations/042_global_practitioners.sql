-- Apply once after 026, 032, 039 and 040. Additive; no automatic identity or credential import.
CREATE TABLE global_practitioners (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identity_id BIGINT UNSIGNED NOT NULL,
 display_name VARCHAR(150) NOT NULL,
 email VARCHAR(190) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_global_practitioner_identity(identity_id),
 FOREIGN KEY(identity_id) REFERENCES product_identities(id)
) ENGINE=InnoDB;
CREATE TABLE global_practitioner_clinics (
 global_practitioner_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NOT NULL,
 practitioner_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','removed') NOT NULL DEFAULT 'active',
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(global_practitioner_id,clinic_id),
 UNIQUE KEY uq_global_local_practitioner(practitioner_id),
 FOREIGN KEY(global_practitioner_id) REFERENCES global_practitioners(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(practitioner_id) REFERENCES practitioners(id)
) ENGINE=InnoDB;
CREATE TABLE global_practitioner_credentials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_practitioner_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(150) NOT NULL,
 issuer VARCHAR(150) NOT NULL,
 issued_on DATE NOT NULL,
 expires_on DATE NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(global_practitioner_id) REFERENCES global_practitioners(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_credential_shares (
 credential_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NOT NULL,
 qualification_id BIGINT UNSIGNED NOT NULL,
 shared BOOLEAN NOT NULL DEFAULT TRUE,
 PRIMARY KEY(credential_id,clinic_id),
 UNIQUE KEY uq_shared_qualification(qualification_id),
 FOREIGN KEY(credential_id) REFERENCES global_practitioner_credentials(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(qualification_id) REFERENCES practitioner_qualifications(id)
) ENGINE=InnoDB;
CREATE TABLE global_practitioner_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 global_practitioner_id BIGINT UNSIGNED NOT NULL,
 application_administrator_id BIGINT UNSIGNED NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 clinic_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 correlation_id VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(global_practitioner_id) REFERENCES global_practitioners(id),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
