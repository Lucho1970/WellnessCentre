ALTER TABLE services
  ADD COLUMN slug VARCHAR(120) NULL AFTER category_id,
  ADD COLUMN name_fr VARCHAR(150) NULL AFTER name,
  ADD COLUMN public_summary VARCHAR(500) NULL AFTER name_fr,
  ADD COLUMN public_summary_fr VARCHAR(500) NULL AFTER public_summary,
  ADD COLUMN description_fr TEXT NULL AFTER description,
  ADD COLUMN preparation_instructions_fr TEXT NULL AFTER preparation_instructions,
  ADD COLUMN published BOOLEAN NOT NULL DEFAULT FALSE AFTER recurrence_allowed,
  ADD COLUMN display_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER published;

-- These services were already visible through the anonymous booking catalogue.
-- Preserve that behavior while giving each one a stable, collision-free URL.
UPDATE services
   SET slug = CONCAT('service-', id),
       published = active;

ALTER TABLE services
  MODIFY slug VARCHAR(120) NOT NULL,
  ADD UNIQUE KEY uq_service_slug (clinic_id, slug),
  ADD INDEX ix_service_public_listing (clinic_id, published, display_order, name);
