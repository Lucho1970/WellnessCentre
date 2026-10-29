-- Apply once after 055. No live senders, tariffs, delivery, funding or notices are seeded.
CREATE TABLE clinic_communications (
 clinic_id BIGINT UNSIGNED PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 email_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 sms_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 mail_from VARCHAR(190) NOT NULL DEFAULT '',
 reply_to VARCHAR(190) NOT NULL DEFAULT '',
 alert_email VARCHAR(190) NOT NULL DEFAULT '',
 sms_from_did VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
 cad_micros_per_segment INT UNSIGNED NULL,
 fixed_cad_micros INT UNSIGNED NOT NULL DEFAULT 0,
 tariff_valid_until DATETIME NULL,
 opt_out_checked_at DATETIME NULL,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
CREATE TABLE sms_suppressions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 number_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sms_suppression(clinic_id,number_hash),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_reply_addresses (
 membership_id BIGINT UNSIGNED PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 email VARCHAR(190) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT FALSE,
 FOREIGN KEY(membership_id,clinic_id) REFERENCES staff_memberships(id,clinic_id)
) ENGINE=InnoDB;
CREATE TABLE sms_notification_deliveries (
 notification_event_id BIGINT UNSIGNED PRIMARY KEY,
 pool_id BIGINT UNSIGNED NOT NULL,
 membership_id BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 settings_revision BIGINT UNSIGNED NOT NULL,
 segments SMALLINT UNSIGNED NOT NULL,
 estimated_cents INT UNSIGNED NOT NULL,
 message_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_did VARCHAR(10) CHARACTER SET ascii NOT NULL,
 provider_message_id VARCHAR(191) NULL,
 UNIQUE KEY uq_sms_delivery_reference(pool_id,reference),
 FOREIGN KEY(notification_event_id) REFERENCES notification_events(id),
 FOREIGN KEY(pool_id) REFERENCES sms_budget_pools(id),
 FOREIGN KEY(membership_id) REFERENCES staff_memberships(id)
) ENGINE=InnoDB;
CREATE TABLE communication_notices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind ENUM('application','trial_ending','trial_expired','budget') NOT NULL,
 recipient_kind ENUM('applicant','member','operator') NOT NULL,
 application_id BIGINT UNSIGNED NULL,
 application_version BIGINT UNSIGNED NULL,
 subscription_id BIGINT UNSIGNED NULL,
 membership_id BIGINT UNSIGNED NULL,
 pool_id BIGINT UNSIGNED NULL,
 period_start DATE NULL,
 threshold SMALLINT UNSIGNED NULL,
 trial_ends_at DATETIME NULL,
 status ENUM('queued','sending','sent','failed','canceled','needs_review','resolved') NOT NULL DEFAULT 'queued',
 attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NULL,
 lease_token CHAR(32) NULL,
 leased_until DATETIME NULL,
 recipient_address VARCHAR(190) NULL,
 last_error VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL,
 UNIQUE KEY uq_communication_notice(clinic_id,reference),
 KEY ix_communication_queue(status,next_attempt_at,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(application_id) REFERENCES practitioner_applications(id),
 FOREIGN KEY(subscription_id) REFERENCES billing_subscriptions(id),
 FOREIGN KEY(membership_id) REFERENCES staff_memberships(id),
 FOREIGN KEY(pool_id) REFERENCES sms_budget_pools(id)
) ENGINE=InnoDB;
CREATE TABLE communication_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 application_administrator_id BIGINT UNSIGNED NULL,
 action VARCHAR(64) CHARACTER SET ascii NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 reason VARCHAR(1000) NOT NULL,
 details JSON NOT NULL,
 correlation_id VARCHAR(128) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_communication_events(clinic_id,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB;
CREATE TABLE communication_requests (
 application_administrator_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response JSON NOT NULL,
 PRIMARY KEY(application_administrator_id,reference),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB;
