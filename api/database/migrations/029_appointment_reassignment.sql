-- Preserve who changed a clinic appointment's practitioner and why.
CREATE TABLE appointment_reassignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    old_practitioner_id BIGINT UNSIGNED NOT NULL,
    new_practitioner_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_appointment_reassignments (appointment_id, created_at),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id),
    FOREIGN KEY (old_practitioner_id) REFERENCES practitioners(id),
    FOREIGN KEY (new_practitioner_id) REFERENCES practitioners(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
