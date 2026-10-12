-- Private practitioner addresses; never join this table into public profiles.
CREATE TABLE practitioner_private_locations (
    practitioner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    home_address TEXT NULL,
    work_address TEXT NULL,
    work_same_as_home BOOLEAN NOT NULL DEFAULT FALSE,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
