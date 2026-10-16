-- Apply once after 037 (drafts), 040 (application administrators) and 044.
CREATE TABLE form_library_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('global','clinic') NOT NULL,
 clinic_id BIGINT UNSIGNED NULL,
 created_by_user_id BIGINT UNSIGNED NULL,
 created_by_admin_id BIGINT UNSIGNED NULL,
 creation_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 creation_actor VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 creation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 latest_version INT UNSIGNED NOT NULL DEFAULT 1,
 approved_version_id BIGINT UNSIGNED NULL,
 archived BOOLEAN NOT NULL DEFAULT FALSE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_library_creation(creation_actor,creation_key),
 KEY ix_library_scope(scope,clinic_id,archived),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(created_by_user_id) REFERENCES users(id),
 FOREIGN KEY(created_by_admin_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE form_library_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 entry_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 name VARCHAR(190) NOT NULL,
 description TEXT NOT NULL,
 form_type ENUM('intake','consent','follow_up','questionnaire') NOT NULL,
 definition LONGTEXT NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 review_note VARCHAR(1000) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_library_version(entry_id,version),
 UNIQUE KEY uq_library_request(entry_id,request_key),
 FOREIGN KEY(entry_id) REFERENCES form_library_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE form_library_entries ADD FOREIGN KEY(approved_version_id) REFERENCES form_library_versions(id);
CREATE TABLE form_library_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 actor_admin_id BIGINT UNSIGNED NULL,
 action VARCHAR(50) NOT NULL,
 correlation_id VARCHAR(100) NOT NULL,
 metadata LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(version_id) REFERENCES form_library_versions(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 FOREIGN KEY(actor_admin_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
