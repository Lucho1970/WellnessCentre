-- Optional website or social page. Existing profiles have no external link.
ALTER TABLE public_team_profiles ADD COLUMN public_website_url VARCHAR(2048) NULL AFTER summary_fr;
