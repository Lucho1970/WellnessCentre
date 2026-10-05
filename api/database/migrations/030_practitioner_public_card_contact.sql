-- Contact details for published practitioner cards are explicit, separate from
-- private staff notification destinations. Existing profiles share nothing.
ALTER TABLE public_team_profiles
    ADD COLUMN public_contact_email VARCHAR(254) NULL AFTER summary_fr,
    ADD COLUMN public_contact_phone VARCHAR(16) NULL AFTER public_contact_email,
    ADD COLUMN public_contact_sms BOOLEAN NOT NULL DEFAULT FALSE AFTER public_contact_phone;
