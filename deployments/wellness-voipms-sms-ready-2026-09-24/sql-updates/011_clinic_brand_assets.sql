-- Configurable public logo and browser favicon. Binary assets remain in the
-- application database so both public and portal hosts use one source.

CREATE TABLE clinic_brand_assets (
    clinic_id BIGINT UNSIGNED NOT NULL,
    asset_type ENUM('logo','favicon') NOT NULL,
    mime_type VARCHAR(40) NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    width_px SMALLINT UNSIGNED NOT NULL,
    height_px SMALLINT UNSIGNED NOT NULL,
    content_hash CHAR(64) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clinic_id, asset_type),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
