-- Apply once before deploying recurring-appointment code. Existing series are retained.
ALTER TABLE recurring_series
    ADD COLUMN clinic_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN timezone VARCHAR(64) NULL AFTER occurrence_limit,
    ADD KEY ix_recurring_series_clinic (clinic_id),
    ADD CONSTRAINT fk_recurring_series_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id);

UPDATE recurring_series s JOIN users u ON u.id=s.client_id SET s.clinic_id=u.clinic_id WHERE s.clinic_id IS NULL;

CREATE TABLE recurring_booking_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recurring_request (clinic_id,idempotency_key),
    FOREIGN KEY (clinic_id) REFERENCES clinics(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;
