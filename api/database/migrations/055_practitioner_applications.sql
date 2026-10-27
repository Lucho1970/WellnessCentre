-- Apply once after 054, 042 global practitioners, 047 enquiries and 049 access audit.
-- No terms, campaign, applications, memberships or live settings are seeded.
CREATE TABLE practitioner_application_terms (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 content TEXT NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 published_by BIGINT UNSIGNED NOT NULL,
 published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_application_terms_version(clinic_id,version),
 UNIQUE KEY uq_application_terms_scope(id,clinic_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(published_by) REFERENCES application_administrators(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_application_settings (
 clinic_id BIGINT UNSIGNED PRIMARY KEY,
 enabled BOOLEAN NOT NULL DEFAULT FALSE,
 activation_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 terms_id BIGINT UNSIGNED NULL,
 plan_version_id BIGINT UNSIGNED NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(terms_id,clinic_id) REFERENCES practitioner_application_terms(id,clinic_id),
 FOREIGN KEY(plan_version_id) REFERENCES subscription_plan_versions(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_applications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 identity_id BIGINT UNSIGNED NOT NULL,
 status ENUM('submitted','changes_requested','approved','rejected','withdrawn','activated') NOT NULL DEFAULT 'submitted',
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 submission_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 given_name VARCHAR(70) NOT NULL,
 family_name VARCHAR(70) NOT NULL,
 email VARCHAR(190) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',
 discipline VARCHAR(190) NOT NULL,
 credentials VARCHAR(2000) NOT NULL,
 service_area VARCHAR(190) NOT NULL,
 services TEXT NOT NULL,
 enquiry_reference VARCHAR(36) NOT NULL DEFAULT '',
 verification_code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 accepted_terms_id BIGINT UNSIGNED NOT NULL,
 review_message VARCHAR(2000) NOT NULL DEFAULT '',
 approved_location_id BIGINT UNSIGNED NULL,
 approved_plan_version_id BIGINT UNSIGNED NULL,
 approved_timezone VARCHAR(64) NULL,
 allow_existing_membership BOOLEAN NOT NULL DEFAULT FALSE,
 source_enquiry_id BIGINT UNSIGNED NULL,
 membership_id BIGINT UNSIGNED NULL,
 subscription_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_practitioner_application(clinic_id,identity_id),
 UNIQUE KEY uq_application_enquiry(source_enquiry_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(identity_id) REFERENCES product_identities(id),
 FOREIGN KEY(accepted_terms_id,clinic_id) REFERENCES practitioner_application_terms(id,clinic_id),
 FOREIGN KEY(approved_location_id) REFERENCES locations(id),
 FOREIGN KEY(approved_plan_version_id) REFERENCES subscription_plan_versions(id),
 FOREIGN KEY(source_enquiry_id) REFERENCES practitioner_enquiries(id),
 FOREIGN KEY(membership_id,clinic_id) REFERENCES staff_memberships(id,clinic_id),
 FOREIGN KEY(subscription_id) REFERENCES billing_subscriptions(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_application_submissions (
 application_id BIGINT UNSIGNED NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 terms_id BIGINT UNSIGNED NOT NULL,
 details JSON NOT NULL,
 submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(application_id,version),
 FOREIGN KEY(application_id) REFERENCES practitioner_applications(id),
 FOREIGN KEY(terms_id) REFERENCES practitioner_application_terms(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_application_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 application_id BIGINT UNSIGNED NULL,
 application_administrator_id BIGINT UNSIGNED NULL,
 applicant_identity_id BIGINT UNSIGNED NULL,
 action VARCHAR(64) CHARACTER SET ascii NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 details JSON NOT NULL,
 correlation_id VARCHAR(128) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(application_id) REFERENCES practitioner_applications(id),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id),
 FOREIGN KEY(applicant_identity_id) REFERENCES product_identities(id),
 KEY ix_application_events(clinic_id,application_id,id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_application_requests (
 clinic_id BIGINT UNSIGNED NOT NULL,
 principal VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reference CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response JSON NOT NULL,
 PRIMARY KEY(clinic_id,principal,reference),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
CREATE TABLE practitioner_application_limits (
 clinic_id BIGINT UNSIGNED NOT NULL,
 bucket CHAR(10) CHARACTER SET ascii NOT NULL,
 key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(clinic_id,bucket,key_hash),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;
ALTER TABLE access_audit_events MODIFY actor_type ENUM('staff','client','customer_identity','application_admin','anonymous','practitioner_applicant') NOT NULL;
