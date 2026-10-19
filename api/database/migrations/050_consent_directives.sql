-- Explicit negative directives. Existing form consent is not an access grant.
CREATE TABLE client_consent_directives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    scope ENUM('staff_access','patient_communications') NOT NULL,
    target_staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    restricted TINYINT(1) NOT NULL,
    version INT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_client_consent(client_id,scope,target_staff_id),
    KEY ix_consent_enforcement(clinic_id,scope,restricted,target_staff_id,client_id),
    FOREIGN KEY(clinic_id) REFERENCES clinics(id),
    FOREIGN KEY(client_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Immutable application history; no contact data or form answers belong here.
CREATE TABLE client_consent_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    directive_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    restricted TINYINT(1) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    source ENUM('patient_portal','verified_instruction') NOT NULL,
    instruction_reference VARCHAR(120) NULL,
    correlation_id VARCHAR(64) NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_consent_event_version(directive_id,version),
    FOREIGN KEY(directive_id) REFERENCES client_consent_directives(id),
    FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO permissions(code,name,description) VALUES
('manage_client_consent','Record verified consent instructions','Record verified patient instructions without granting access to patient records.');
