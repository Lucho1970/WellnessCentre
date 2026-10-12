-- Apply once, before enabling APPOINTMENT_ACTION_LINKS_ENABLED.
-- Raw link tokens are never stored. Links are navigation aids requiring client sign-in.
CREATE TABLE appointment_action_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clinic_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    appointment_version BIGINT UNSIGNED NOT NULL,
    notification_event_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointment_action_token (token_hash),
    KEY ix_appointment_action_scope (clinic_id, appointment_id, revoked_at),
    KEY ix_appointment_action_expiry (expires_at),
    CONSTRAINT fk_action_link_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    CONSTRAINT fk_action_link_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    CONSTRAINT fk_action_link_client FOREIGN KEY (client_id) REFERENCES users(id),
    CONSTRAINT fk_action_link_notification FOREIGN KEY (notification_event_id) REFERENCES notification_events(id)
) ENGINE=InnoDB;
