-- Local implementation only. Apply once after 060, with separate release approval.
-- No grants, scheduler, delivery activation, or clinical-record changes.
CREATE TABLE commit_safe_emails (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 clinic_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('staff_verification','client_invitation','enquiry_retry') NOT NULL,
 resource_id BIGINT UNSIGNED NOT NULL,
 actor_realm ENUM('workforce','practitioner','central') NOT NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 application_admin_id BIGINT UNSIGNED NULL,
 correlation_id CHAR(36) NOT NULL,
 authority_global BIGINT UNSIGNED NOT NULL,
 authority_clinic BIGINT UNSIGNED NOT NULL,
 ciphertext MEDIUMTEXT NULL,
 expires_at DATETIME NOT NULL,
 status ENUM('queued','sending','accepted','failed','canceled','needs_review') NOT NULL DEFAULT 'queued',
 reason_code VARCHAR(40) NULL,
 lease_until DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 KEY ix_commit_safe_email_pending(status,id),
 KEY ix_commit_safe_email_resource(clinic_id,kind,resource_id,status),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE practitioner_enquiries MODIFY notification_status
 ENUM('pending','queued','sending','accepted','sent','failed','disabled','needs_review') NOT NULL DEFAULT 'pending';
