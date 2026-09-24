-- Separate legal/operational names from the friendly names used on the public site.
-- Existing public profiles retain their current visible name.

ALTER TABLE public_team_profiles
    ADD COLUMN public_name VARCHAR(150) NULL AFTER section,
    ADD COLUMN booking_name VARCHAR(100) NULL AFTER public_name;

UPDATE public_team_profiles t
JOIN users u ON u.id = t.user_id
SET t.public_name = u.display_name,
    t.booking_name = CASE
        WHEN t.section = 'practitioner' THEN COALESCE(NULLIF(u.given_name, ''), SUBSTRING_INDEX(TRIM(u.display_name), ' ', 1))
        ELSE NULL
    END
WHERE t.public_name IS NULL;

ALTER TABLE public_team_profiles
    MODIFY public_name VARCHAR(150) NOT NULL;
