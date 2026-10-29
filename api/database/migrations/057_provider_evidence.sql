-- Apply once after 056. No account credentials, exchange rates or live evidence seeded.
ALTER TABLE sms_notification_deliveries ADD KEY ix_provider_delivery(sender_did,provider_message_id,pool_id);
CREATE TABLE provider_evidence_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 administrator_id BIGINT UNSIGNED NOT NULL,
 statement_reference VARCHAR(120) NOT NULL,
 source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fx_rate_micros BIGINT UNSIGNED NULL,
 fx_date DATE NULL,
 fx_reference VARCHAR(190) NOT NULL DEFAULT '',
 rows_json JSON NOT NULL,
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('preview','applied') NOT NULL DEFAULT 'preview',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 applied_at DATETIME NULL,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(administrator_id) REFERENCES application_administrators(id),
 KEY ix_provider_batches(clinic_id,id)
) ENGINE=InnoDB;
CREATE TABLE provider_evidence_charges (
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 batch_id BIGINT UNSIGNED NOT NULL,
 notification_event_id BIGINT UNSIGNED NOT NULL,
 evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actual_cents INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_evidence_delivery(notification_event_id),
 FOREIGN KEY(batch_id) REFERENCES provider_evidence_batches(id),
 FOREIGN KEY(notification_event_id) REFERENCES notification_events(id)
) ENGINE=InnoDB;
CREATE TABLE provider_sender_stops (
 sender_did CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 number_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 last_stop_at DATETIME NOT NULL,
 cleared_after DATETIME NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(sender_did,number_hash),
 FOREIGN KEY(batch_id) REFERENCES provider_evidence_batches(id)
) ENGINE=InnoDB;
CREATE TABLE provider_stop_evidence (
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 evidence_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(batch_id) REFERENCES provider_evidence_batches(id)
) ENGINE=InnoDB;
