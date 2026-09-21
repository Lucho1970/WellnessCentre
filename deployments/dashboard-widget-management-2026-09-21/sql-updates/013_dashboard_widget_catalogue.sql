-- Versioned runtime dashboard widget definitions. Definitions contain only
-- allowlisted presentation metadata and projection parameters; never SQL/code.
CREATE TABLE dashboard_widgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id BIGINT UNSIGNED NOT NULL,
    widget_key VARCHAR(100) NOT NULL,
    active_version SMALLINT UNSIGNED NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dashboard_widget_key (clinic_id, widget_key),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE dashboard_widget_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    version_number SMALLINT UNSIGNED NOT NULL,
    definition_json JSON NOT NULL,
    definition_hash CHAR(64) NOT NULL,
    validation_result JSON NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dashboard_widget_version (widget_id, version_number),
    FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
