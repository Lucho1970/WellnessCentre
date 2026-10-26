-- Apply once after 032 and the current 052 baseline, against a backed-up rehearsal first.
-- No clinic/account backfill, live prices, payment collection or SMS activation.
ALTER TABLE staff_memberships ADD UNIQUE KEY uq_billing_membership_clinic(id,clinic_id);

CREATE TABLE billing_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 membership_id BIGINT UNSIGNED NULL,
 subject_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(membership_id,0)) STORED,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CAD',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_billing_subject(clinic_id,subject_key),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id),
 FOREIGN KEY(membership_id,clinic_id) REFERENCES staff_memberships(id,clinic_id)
) ENGINE=InnoDB;

CREATE TABLE subscription_plan_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 scope ENUM('tenant','practitioner') NOT NULL,
 currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CAD',
 base_monthly_cents BIGINT UNSIGNED NULL,
 features JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_subscription_plan_version(code,version)
) ENGINE=InnoDB;

CREATE TABLE billing_subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 billing_account_id BIGINT UNSIGNED NOT NULL,
 plan_version_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','trialing','active','past_due','restricted','canceled','suspended') NOT NULL DEFAULT 'pending',
 starts_at DATETIME NULL,
 trial_ends_at DATETIME NULL,
 paid_through_at DATETIME NULL,
 grace_ends_at DATETIME NULL,
 cancel_at DATETIME NULL,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(billing_account_id) REFERENCES billing_accounts(id),
 FOREIGN KEY(plan_version_id) REFERENCES subscription_plan_versions(id),
 KEY ix_subscription_account(billing_account_id,status)
) ENGINE=InnoDB;

CREATE TABLE sms_budget_pools (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clinic_id BIGINT UNSIGNED NOT NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
 monthly_limit_cents INT UNSIGNED NOT NULL DEFAULT 10000,
 member_limit_cents INT UNSIGNED NOT NULL DEFAULT 1000,
 max_members INT UNSIGNED NOT NULL DEFAULT 10,
 status ENUM('inactive','active') NOT NULL DEFAULT 'inactive',
 UNIQUE KEY uq_sms_pool_clinic(clinic_id),
 UNIQUE KEY uq_sms_pool_scope(id,clinic_id),
 FOREIGN KEY(clinic_id) REFERENCES clinics(id)
) ENGINE=InnoDB;

CREATE TABLE sms_budget_members (
 pool_id BIGINT UNSIGNED NOT NULL,
 clinic_id BIGINT UNSIGNED NOT NULL,
 membership_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','removed') NOT NULL DEFAULT 'active',
 PRIMARY KEY(pool_id,membership_id),
 FOREIGN KEY(pool_id,clinic_id) REFERENCES sms_budget_pools(id,clinic_id),
 FOREIGN KEY(membership_id,clinic_id) REFERENCES staff_memberships(id,clinic_id)
) ENGINE=InnoDB;

CREATE TABLE sms_budget_periods (
 pool_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 limit_cents INT UNSIGNED NOT NULL,
 spent_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reserved_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(pool_id,period_start),
 FOREIGN KEY(pool_id) REFERENCES sms_budget_pools(id)
) ENGINE=InnoDB;

CREATE TABLE sms_budget_member_periods (
 pool_id BIGINT UNSIGNED NOT NULL,
 membership_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 limit_cents INT UNSIGNED NOT NULL,
 spent_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
 reserved_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(pool_id,membership_id,period_start),
 FOREIGN KEY(pool_id,membership_id) REFERENCES sms_budget_members(pool_id,membership_id),
 FOREIGN KEY(pool_id,period_start) REFERENCES sms_budget_periods(pool_id,period_start)
) ENGINE=InnoDB;

CREATE TABLE sms_budget_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 pool_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL,
 membership_id BIGINT UNSIGNED NULL,
 reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 estimated_cents INT UNSIGNED NOT NULL,
 actual_cents INT UNSIGNED NULL,
 status ENUM('reserved','submitted','settled','released') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_sms_budget_reference(pool_id,reference),
 FOREIGN KEY(pool_id,period_start) REFERENCES sms_budget_periods(pool_id,period_start),
 FOREIGN KEY(pool_id,membership_id,period_start) REFERENCES sms_budget_member_periods(pool_id,membership_id,period_start)
) ENGINE=InnoDB;
