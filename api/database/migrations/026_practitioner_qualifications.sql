-- MySQL 5.7+. Apply before deploying the qualification profile feature.
CREATE TABLE qualification_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  requires_expiry BOOLEAN NOT NULL DEFAULT TRUE,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_qualification_type_name (clinic_id,name),
  UNIQUE KEY uq_qualification_type_clinic_id (clinic_id,id),
  FOREIGN KEY (clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

CREATE TABLE practitioner_qualifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  practitioner_id BIGINT UNSIGNED NOT NULL,
  qualification_type_id BIGINT UNSIGNED NOT NULL,
  issuer VARCHAR(150) NOT NULL,
  issued_on DATE NOT NULL,
  expires_on DATE NULL,
  status ENUM('pending','verified','rejected','revoked') NOT NULL DEFAULT 'pending',
  submitted_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_qualification_practitioner (clinic_id,practitioner_id,status,expires_on),
  INDEX ix_qualification_expiry (clinic_id,status,expires_on),
  FOREIGN KEY (clinic_id) REFERENCES clinics(id),
  FOREIGN KEY (practitioner_id) REFERENCES practitioners(id),
  FOREIGN KEY (clinic_id,qualification_type_id) REFERENCES qualification_types(clinic_id,id),
  FOREIGN KEY (submitted_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;
