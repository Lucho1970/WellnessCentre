-- Apply once after 059 and the existing 032/039/040/058 foundations.
-- Development integration only; no hosted execution approved. No grants or feature flags.
CREATE TABLE browser_authority_barriers (
 scope_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;
INSERT INTO browser_authority_barriers(scope_key) VALUES('global');
INSERT INTO browser_authority_barriers(scope_key) SELECT CONCAT('clinic:',id) FROM clinics;
ALTER TABLE browser_sessions
 ADD local_user_id BIGINT UNSIGNED NULL,
 ADD product_identity_id BIGINT UNSIGNED NULL,
 ADD application_administrator_id BIGINT UNSIGNED NULL,
 ADD KEY ix_browser_local_user(local_user_id,revoked_at),
 ADD KEY ix_browser_product_identity(product_identity_id,clinic_id,revoked_at),
 ADD KEY ix_browser_application_admin(application_administrator_id,revoked_at),
 ADD FOREIGN KEY(local_user_id) REFERENCES users(id),
 ADD FOREIGN KEY(product_identity_id) REFERENCES product_identities(id),
 ADD FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id);

-- Earlier unbound core sessions cannot participate in the integrated guard.
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND revoked_at IS NULL;

CREATE TRIGGER browser_clinic_insert AFTER INSERT ON clinics FOR EACH ROW BEGIN
INSERT INTO browser_authority_barriers(scope_key) VALUES(CONCAT('clinic:',NEW.id));
END;

CREATE TRIGGER browser_clinic_status AFTER UPDATE ON clinics FOR EACH ROW BEGIN
IF NOT(OLD.status <=> NEW.status) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key=CONCAT('clinic:',OLD.id);
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE clinic_id=OLD.id AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND clinic_id=OLD.id AND revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_user_authority AFTER UPDATE ON users FOR EACH ROW BEGIN
IF NOT(OLD.status <=> NEW.status) OR NOT(OLD.user_type <=> NEW.user_type) OR NOT(OLD.clinic_id <=> NEW.clinic_id) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id),CONCAT('clinic:',NEW.clinic_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=OLD.id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=OLD.id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_user_roles_insert AFTER INSERT ON user_roles FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=NEW.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=NEW.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_user_roles_update AFTER UPDATE ON user_roles FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id,NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=OLD.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=OLD.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=NEW.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=NEW.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_user_roles_delete AFTER DELETE ON user_roles FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=OLD.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=OLD.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_user_permissions_insert AFTER INSERT ON user_permissions FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=NEW.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=NEW.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_user_permissions_update AFTER UPDATE ON user_permissions FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id,NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=OLD.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=OLD.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=NEW.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=NEW.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_user_permissions_delete AFTER DELETE ON user_permissions FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id=OLD.user_id AND revoked_at IS NULL;
UPDATE customer_sessions s JOIN customer_client_links l ON l.identity_id=s.identity_id SET s.revoked_at=UTC_TIMESTAMP() WHERE l.client_id=OLD.user_id AND (s.clinic_id=l.clinic_id OR s.clinic_id IS NULL) AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END;

CREATE TRIGGER browser_membership_insert AFTER INSERT ON staff_memberships FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',NEW.clinic_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE (product_identity_id=NEW.identity_id AND clinic_id=NEW.clinic_id OR local_user_id=NEW.local_user_id) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_membership_update AFTER UPDATE ON staff_memberships FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id),CONCAT('clinic:',NEW.clinic_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE (product_identity_id=OLD.identity_id AND clinic_id=OLD.clinic_id OR local_user_id=OLD.local_user_id) AND revoked_at IS NULL;
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE (product_identity_id=NEW.identity_id AND clinic_id=NEW.clinic_id OR local_user_id=NEW.local_user_id) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_membership_delete AFTER DELETE ON staff_memberships FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE (product_identity_id=OLD.identity_id AND clinic_id=OLD.clinic_id OR local_user_id=OLD.local_user_id) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_product_authority AFTER UPDATE ON product_identities FOR EACH ROW BEGIN
IF NOT(OLD.status <=> NEW.status) OR NOT(OLD.adapter <=> NEW.adapter) OR NOT(OLD.issuer <=> NEW.issuer) OR NOT(OLD.subject <=> NEW.subject) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE product_identity_id=OLD.id AND revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_admin_authority AFTER UPDATE ON application_administrators FOR EACH ROW BEGIN
IF NOT(OLD.status <=> NEW.status) OR NOT(OLD.issuer <=> NEW.issuer) OR NOT(OLD.subject <=> NEW.subject) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE application_administrator_id=OLD.id AND revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_pilot_authority AFTER UPDATE ON practitioner_pilot_access FOR EACH ROW BEGIN
IF NOT(OLD.status <=> NEW.status) OR NOT(OLD.clinic_id <=> NEW.clinic_id) OR NOT(OLD.identity_id <=> NEW.identity_id) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id),CONCAT('clinic:',NEW.clinic_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE product_identity_id=OLD.identity_id AND clinic_id=OLD.clinic_id AND revoked_at IS NULL;
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE product_identity_id=NEW.identity_id AND clinic_id=NEW.clinic_id AND revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_customer_client_links_insert AFTER INSERT ON customer_client_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',NEW.clinic_id));
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND identity_id=NEW.identity_id AND (clinic_id=NEW.clinic_id OR clinic_id IS NULL) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_customer_client_links_update AFTER UPDATE ON customer_client_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id),CONCAT('clinic:',NEW.clinic_id));
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND identity_id=OLD.identity_id AND (clinic_id=OLD.clinic_id OR clinic_id IS NULL) AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND identity_id=NEW.identity_id AND (clinic_id=NEW.clinic_id OR clinic_id IS NULL) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_customer_client_links_delete AFTER DELETE ON customer_client_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (CONCAT('clinic:',OLD.clinic_id));
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND identity_id=OLD.identity_id AND (clinic_id=OLD.clinic_id OR clinic_id IS NULL) AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_clinic_hosts_insert AFTER INSERT ON clinic_hosts FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE clinic_id=NEW.clinic_id AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND clinic_id=NEW.clinic_id AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_clinic_hosts_update AFTER UPDATE ON clinic_hosts FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE clinic_id=OLD.clinic_id AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND clinic_id=OLD.clinic_id AND revoked_at IS NULL;
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE clinic_id=NEW.clinic_id AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND clinic_id=NEW.clinic_id AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_clinic_hosts_delete AFTER DELETE ON clinic_hosts FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE clinic_id=OLD.clinic_id AND revoked_at IS NULL;
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND clinic_id=OLD.clinic_id AND revoked_at IS NULL;

END;

CREATE TRIGGER browser_roles_authority AFTER UPDATE ON roles FOR EACH ROW BEGIN
IF NOT(OLD.code <=> NEW.code) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions s JOIN user_roles g ON g.user_id=s.local_user_id SET s.revoked_at=UTC_TIMESTAMP() WHERE g.role_id=OLD.id AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_permissions_authority AFTER UPDATE ON permissions FOR EACH ROW BEGIN
IF NOT(OLD.code <=> NEW.code) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE browser_sessions s JOIN user_permissions g ON g.user_id=s.local_user_id SET s.revoked_at=UTC_TIMESTAMP() WHERE g.permission_id=OLD.id AND s.policy_version IS NOT NULL AND s.revoked_at IS NULL;
END IF;
END;

CREATE TRIGGER browser_identity_link_insert AFTER INSERT ON identity_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id IN (NEW.user_id) AND revoked_at IS NULL;
END;

CREATE TRIGGER browser_identity_link_update AFTER UPDATE ON identity_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id,NEW.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id IN (OLD.user_id,NEW.user_id) AND revoked_at IS NULL;
END;

CREATE TRIGGER browser_identity_link_delete AFTER DELETE ON identity_links FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key IN (SELECT CONCAT('clinic:',clinic_id) FROM users WHERE id IN (OLD.user_id));
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE local_user_id IN (OLD.user_id) AND revoked_at IS NULL;
END;

CREATE TRIGGER browser_pilot_insert AFTER INSERT ON practitioner_pilot_access FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key=CONCAT('clinic:',NEW.clinic_id);
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE product_identity_id=NEW.identity_id AND clinic_id=NEW.clinic_id AND revoked_at IS NULL;
END;

CREATE TRIGGER browser_pilot_delete AFTER DELETE ON practitioner_pilot_access FOR EACH ROW BEGIN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key=CONCAT('clinic:',OLD.clinic_id);
UPDATE browser_sessions SET revoked_at=UTC_TIMESTAMP() WHERE product_identity_id=OLD.identity_id AND clinic_id=OLD.clinic_id AND revoked_at IS NULL;
END;

CREATE TRIGGER browser_customer_identity_authority AFTER UPDATE ON customer_identities FOR EACH ROW BEGIN
IF NOT(OLD.identity_hash <=> NEW.identity_hash) OR NOT(OLD.issuer <=> NEW.issuer) OR NOT(OLD.subject <=> NEW.subject) THEN
UPDATE browser_authority_barriers SET version=version+1 WHERE scope_key='global';
UPDATE customer_sessions SET revoked_at=UTC_TIMESTAMP() WHERE policy_version IS NOT NULL AND identity_id=OLD.id AND revoked_at IS NULL;
END IF;
END;
