-- Staff-owned On-Site service-area decisions. No Google distance, coordinates, or route data is retained.
CREATE TABLE onsite_area_approvals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clinic_id BIGINT UNSIGNED NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  practitioner_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  destination_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  radius_km SMALLINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NOT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_onsite_approval(clinic_id,client_id,location_id,practitioner_id,service_id,destination_hash,origin_hash,radius_km),
  INDEX ix_onsite_approval_client(client_id),
  FOREIGN KEY(clinic_id) REFERENCES clinics(id),
  FOREIGN KEY(client_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE,
  FOREIGN KEY(practitioner_id) REFERENCES practitioners(id) ON DELETE CASCADE,
  FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
  FOREIGN KEY(approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO permissions(code,name,description)
VALUES ('approve_onsite_service_area','Approve On-Site service area','Allow selected staff to approve a client visit address for a practitioner, service, and base location')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description);
