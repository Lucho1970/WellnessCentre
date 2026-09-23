-- Per-user dashboard layouts. Widget definitions and authorization remain in
-- application code; this table stores presentation preferences only.
CREATE TABLE dashboard_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    workspace ENUM('admin','practitioner') NOT NULL,
    preference_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    layout_json JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, workspace),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
