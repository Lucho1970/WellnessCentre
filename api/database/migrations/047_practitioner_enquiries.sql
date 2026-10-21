CREATE TABLE practitioner_enquiry_settings (
 clinic_id BIGINT UNSIGNED PRIMARY KEY,
 recipient_email VARCHAR(254) NULL,
 enabled BOOLEAN NOT NULL DEFAULT TRUE,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE practitioner_enquiries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 full_name VARCHAR(160) NOT NULL,
 email VARCHAR(254) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',
 discipline VARCHAR(190) NOT NULL,
 credentials VARCHAR(500) NOT NULL DEFAULT '',
 service_area VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 consent_version VARCHAR(40) NOT NULL DEFAULT 'joining-contact-v1',
 status ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 notification_status ENUM('pending','sending','sent','failed','disabled','needs_review') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_enquiry_reference(clinic_id,reference),
 KEY ix_enquiry_inbox(clinic_id,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE practitioner_enquiry_limits (
 clinic_id BIGINT UNSIGNED NOT NULL,
 bucket CHAR(10) CHARACTER SET ascii NOT NULL,
 key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(clinic_id,bucket,key_hash),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
-- Initial recipient requested by the owner; change it later in Application administration.
INSERT INTO practitioner_enquiry_settings(clinic_id,recipient_email)
 SELECT clinic_id,'info@copihue.ca' FROM clinic_hosts WHERE host='wellnexa.copihue.ca';
