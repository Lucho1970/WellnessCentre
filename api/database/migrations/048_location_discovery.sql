-- Select the application database before importing. Back up first; requires 044 and 047.
CREATE TABLE IF NOT EXISTS discovery_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 extra_km SMALLINT UNSIGNED NOT NULL DEFAULT 25,
 cap_km SMALLINT UNSIGNED NOT NULL DEFAULT 100,
 revision INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
INSERT IGNORE INTO discovery_settings(id,extra_km,cap_km) VALUES(1,25,100);
CREATE TABLE IF NOT EXISTS discovery_settings_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 administrator_id BIGINT UNSIGNED NOT NULL,
 extra_km SMALLINT UNSIGNED NOT NULL,
 cap_km SMALLINT UNSIGNED NOT NULL,
 correlation_id VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Separate table preserves opt-outs when administrators replace service assignments.
CREATE TABLE IF NOT EXISTS discovery_preferences (
 clinic_id BIGINT UNSIGNED NOT NULL,
 practitioner_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 allow_extra TINYINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(clinic_id,practitioner_id,service_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(practitioner_id) REFERENCES practitioners(id),
 FOREIGN KEY(service_id) REFERENCES services(id)
) ENGINE=InnoDB;
-- Private derived coordinates: no raw addresses; expiring server-side cache only.
CREATE TABLE IF NOT EXISTS discovery_geocodes (
 cache_key CHAR(64) PRIMARY KEY,
 latitude DOUBLE NOT NULL,
 longitude DOUBLE NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX ix_discovery_geo_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS discovery_distances (
 cache_key CHAR(64) PRIMARY KEY,
 distance_meters INT UNSIGNED NULL,
 expires_at DATETIME NOT NULL,
 INDEX ix_discovery_distance_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS discovery_limits (
 key_hash CHAR(64) NOT NULL,
 bucket VARCHAR(12) NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(key_hash,bucket)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS travel_enquiries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 practitioner_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 search_label VARCHAR(250) NOT NULL,
 client_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 practitioner_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('open','closed') NOT NULL DEFAULT 'open',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_travel_reference(clinic_id,client_id,reference),
 INDEX ix_travel_client(clinic_id,client_id,id),
 INDEX ix_travel_practitioner(clinic_id,practitioner_id,id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(client_id) REFERENCES users(id),
 FOREIGN KEY(practitioner_id) REFERENCES practitioners(id),
 FOREIGN KEY(service_id) REFERENCES services(id),
 FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS travel_enquiry_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 enquiry_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_travel_message(enquiry_id,reference),
 FOREIGN KEY(enquiry_id) REFERENCES travel_enquiries(id),
 FOREIGN KEY(sender_id) REFERENCES users(id)
) ENGINE=InnoDB;
