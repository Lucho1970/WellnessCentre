-- Disabled invitation pilot. Back up and rehearse before applying.
CREATE TABLE staff_invitations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 invited_by BIGINT UNSIGNED NOT NULL,
 recipient_email VARCHAR(190) NOT NULL,
 given_name VARCHAR(100) NOT NULL,
 family_name VARCHAR(100) NOT NULL,
 discipline VARCHAR(100) NOT NULL,
 location_id BIGINT UNSIGNED NOT NULL,
 existing_user_id BIGINT UNSIGNED NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 accepted_at DATETIME NULL,
 accepted_membership_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX ix_staff_invites(clinic_id,created_at),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(invited_by) REFERENCES users(id),
 FOREIGN KEY(location_id) REFERENCES locations(id),
 FOREIGN KEY(existing_user_id) REFERENCES users(id),
 FOREIGN KEY(accepted_membership_id) REFERENCES staff_memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_invitation_claims (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invitation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 issuer VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 claimant_name VARCHAR(150) NOT NULL,
 verification_code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(invitation_id) REFERENCES staff_invitations(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
