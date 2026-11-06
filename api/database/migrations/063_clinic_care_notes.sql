-- Wellnexa clinic care-team authority and append-only notes. Review existing notes before applying.
CREATE TABLE client_care_assignments (
 clinic_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 practitioner_id BIGINT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 assigned_by BIGINT UNSIGNED NOT NULL,
 assigned_at DATETIME NOT NULL,
 ended_by BIGINT UNSIGNED NULL,
 ended_at DATETIME NULL,
 PRIMARY KEY(clinic_id,client_id,practitioner_id),
 INDEX ix_care_practitioner(practitioner_id,active,client_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(client_id) REFERENCES users(id),
 FOREIGN KEY(practitioner_id) REFERENCES practitioners(id),
 FOREIGN KEY(assigned_by) REFERENCES users(id),
 FOREIGN KEY(ended_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE practitioner_client_notes ADD COLUMN visibility ENUM('author','care_team') NOT NULL DEFAULT 'author',
 ADD COLUMN idempotency_key CHAR(36) NULL,
 ADD UNIQUE KEY uq_clinic_note_request(practitioner_id,idempotency_key),
 ADD INDEX ix_clinic_notes_client(client_id,id);
-- The clinical API exposes insert/read only. Existing reviewed client merges update
-- client_id, so a blanket update trigger would break that workflow. Runtime SQL
-- privileges and direct-operator access need separate release review.
