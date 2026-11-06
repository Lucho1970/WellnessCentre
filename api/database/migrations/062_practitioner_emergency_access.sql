-- Separate, reversible emergency access hold. Apply only after a reviewed backup and release approval.
-- The existing staff status is set to locked while held; no clinical records are changed.
CREATE TABLE practitioner_emergency_holds (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 reason_code ENUM('security_concern','departure_review','other') NOT NULL,
 held_by BIGINT UNSIGNED NOT NULL,
 held_at DATETIME NOT NULL,
 released_by BIGINT UNSIGNED NULL,
 released_at DATETIME NULL,
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(held_by) REFERENCES users(id),
 FOREIGN KEY(released_by) REFERENCES users(id),
 INDEX ix_practitioner_emergency_clinic(clinic_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO permissions(code,name,description) VALUES
('hold_practitioner_access','Urgently hold practitioner access','Allow a clinic-wide reception/office administrator to stop, but not restore, a practitioner account');
