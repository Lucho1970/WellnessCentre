-- Apply once before deploying service import endpoints. No existing service or grant changes.
CREATE TABLE service_import_batches (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 confirmation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_json MEDIUMTEXT NOT NULL,
 result_json MEDIUMTEXT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 applied_at DATETIME NULL,
 UNIQUE KEY uq_service_import_request(clinic_id,created_by,request_key),
 KEY ix_service_import_history(clinic_id,created_by,created_at),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
