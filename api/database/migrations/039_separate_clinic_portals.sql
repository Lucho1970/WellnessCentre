-- Apply once before CLINIC_MANAGEMENT_ENABLED=true. No clinic/client data is moved.
-- Requires migrations 005 and 032. Existing sessions must sign in again after activation.
ALTER TABLE clinics ADD website_url VARCHAR(2048) NULL;
CREATE TABLE clinic_hosts (
 host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_clinic_portal(clinic_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

ALTER TABLE customer_client_links DROP PRIMARY KEY, ADD PRIMARY KEY(identity_id,clinic_id);
ALTER TABLE customer_auth_challenges ADD clinic_id BIGINT UNSIGNED NULL,
 ADD FOREIGN KEY(clinic_id) REFERENCES clinics(id);
ALTER TABLE customer_sessions ADD clinic_id BIGINT UNSIGNED NULL,
 ADD INDEX ix_customer_session_clinic(clinic_id,identity_id),
 ADD FOREIGN KEY(clinic_id) REFERENCES clinics(id);
