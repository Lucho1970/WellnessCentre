-- Adds an explicit local permission for practitioners who cover scheduling duties.
-- Safe to run once against an existing Wellness Centre database.

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permission(user_id,permission_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(permission_id) REFERENCES permissions(id),
    FOREIGN KEY(assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO permissions(code,name,description)
VALUES (
    'schedule_for_other_practitioners',
    'Schedule for other practitioners',
    'Book, reschedule, and cancel appointments assigned to another practitioner'
)
ON DUPLICATE KEY UPDATE
    name=VALUES(name),
    description=VALUES(description);
