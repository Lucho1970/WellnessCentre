-- Versioned practitioner documents and immutable authenticated acceptance evidence.
-- No document is published or accepted by this migration.
CREATE TABLE practitioner_policy_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('services_agreement','records_agreement','confidentiality','departure') NOT NULL,
 version_code VARCHAR(40) NOT NULL,
 title_en VARCHAR(190) NOT NULL,
 title_fr VARCHAR(190) NOT NULL,
 body_en MEDIUMTEXT NOT NULL,
 body_fr MEDIUMTEXT NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('draft','published') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 published_by BIGINT UNSIGNED NULL,
 published_at DATETIME NULL,
 review_reference VARCHAR(120) NULL,
 UNIQUE KEY uq_practitioner_document_version(clinic_id,kind,version_code),
 KEY ix_practitioner_document_current(clinic_id,kind,status,published_at,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(created_by) REFERENCES users(id),
 FOREIGN KEY(published_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE practitioner_document_acceptances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 practitioner_user_id BIGINT UNSIGNED NOT NULL,
 identity_subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 displayed_language ENUM('en','fr') NOT NULL,
 accepted_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 correlation_id CHAR(36) NOT NULL,
 accepted_at DATETIME NOT NULL,
 UNIQUE KEY uq_practitioner_document_acceptance(document_id,practitioner_user_id),
 KEY ix_practitioner_acceptance_user(clinic_id,practitioner_user_id,accepted_at),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(document_id) REFERENCES practitioner_policy_documents(id),
 FOREIGN KEY(practitioner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
