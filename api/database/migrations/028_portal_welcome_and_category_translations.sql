-- Apply after 027_clinic_portal_theme.sql and before deploying the portal catalogue.
ALTER TABLE clinic_portal_themes
  ADD COLUMN welcome_title_en VARCHAR(160) NULL,
  ADD COLUMN welcome_title_fr VARCHAR(160) NULL,
  ADD COLUMN welcome_body_en TEXT NULL,
  ADD COLUMN welcome_body_fr TEXT NULL;

ALTER TABLE service_categories
  ADD COLUMN name_fr VARCHAR(120) NULL,
  ADD COLUMN description_fr VARCHAR(500) NULL;
