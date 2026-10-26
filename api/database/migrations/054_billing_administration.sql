-- Apply once after 053. Rehearse/back up first; no automatic enrollment or activation.
-- Preflight: each account must have at most one non-canceled subscription.
-- SELECT billing_account_id,COUNT(*) FROM billing_subscriptions
-- WHERE status<>'canceled' GROUP BY billing_account_id HAVING COUNT(*)>1;
ALTER TABLE subscription_plan_versions ADD display_name VARCHAR(160) NOT NULL DEFAULT '';
UPDATE subscription_plan_versions SET display_name=code WHERE display_name='';
CREATE TABLE billing_plan_codes (
 code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY
) ENGINE=InnoDB;
INSERT INTO billing_plan_codes(code) SELECT DISTINCT code FROM subscription_plan_versions;

ALTER TABLE billing_subscriptions
 ADD trial_timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
 ADD resume_status VARCHAR(16) NULL,
 ADD open_account_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='canceled' THEN NULL ELSE billing_account_id END) STORED,
 ADD UNIQUE KEY uq_open_billing_subscription(open_account_id);
ALTER TABLE sms_budget_pools ADD revision BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE sms_budget_periods ADD member_limit_cents INT UNSIGNED NOT NULL DEFAULT 1000;
UPDATE sms_budget_periods p JOIN sms_budget_pools b ON b.id=p.pool_id SET p.member_limit_cents=b.member_limit_cents;

CREATE TABLE billing_admin_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 application_administrator_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NULL,
 action VARCHAR(64) CHARACTER SET ascii NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 correlation_id VARCHAR(128) NOT NULL,
 details JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 KEY ix_billing_events_clinic(clinic_id,id)
) ENGINE=InnoDB;

CREATE TABLE billing_admin_requests (
 application_administrator_id BIGINT UNSIGNED NOT NULL,
 reference CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 response JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(application_administrator_id,reference),
 FOREIGN KEY(application_administrator_id) REFERENCES application_administrators(id)
) ENGINE=InnoDB;
