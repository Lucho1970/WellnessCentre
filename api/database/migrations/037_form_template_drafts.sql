-- Apply once after migration 036. Drafts are private to their author and cannot be assigned.
CREATE TABLE form_template_drafts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    creation_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_template_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_draft_creation (clinic_id,created_by,creation_key),
    KEY ix_author_drafts (clinic_id,created_by,status,id),
    FOREIGN KEY(clinic_id) REFERENCES clinics(id),
    FOREIGN KEY(created_by) REFERENCES users(id),
    FOREIGN KEY(published_template_id) REFERENCES form_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
