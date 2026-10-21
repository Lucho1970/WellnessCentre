-- Apply once before deploying the social-links API code. Existing profiles stay empty.
ALTER TABLE public_team_profiles ADD COLUMN social_links TEXT NULL;
