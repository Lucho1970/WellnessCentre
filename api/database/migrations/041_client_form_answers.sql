-- Apply once after 036. Enable CLIENT_FORM_ANSWERS_ENABLED only after matching API/portal deployment.
-- Task ownership controls access and follows supervised client merges; no answers are copied to audit logs.
CREATE TABLE client_form_answer_drafts (
    task_id BIGINT UNSIGNED PRIMARY KEY,
    response_data JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY(task_id) REFERENCES client_form_tasks(id)
) ENGINE=InnoDB;

CREATE TABLE client_form_amendments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    submission_id BIGINT UNSIGNED NOT NULL,
    author_client_id BIGINT UNSIGNED NOT NULL,
    response_data JSON NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    KEY ix_form_amendment_history (task_id,id),
    FOREIGN KEY(task_id) REFERENCES client_form_tasks(id),
    FOREIGN KEY(submission_id) REFERENCES form_submissions(id),
    FOREIGN KEY(author_client_id) REFERENCES users(id),
    FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_form_answer_requests (
    task_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_data JSON NOT NULL,
    PRIMARY KEY(task_id,request_key),
    FOREIGN KEY(task_id) REFERENCES client_form_tasks(id)
) ENGINE=InnoDB;
