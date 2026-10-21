-- Apply after 049 and 050. Explicit opt-in only: no users or roles receive a grant.
INSERT INTO permissions(code,name,description) VALUES
('review_privacy_audit','Review clinic privacy audit','Review minimal access history for this clinic for authorized privacy oversight. Does not grant clinical-record access.');

CREATE INDEX ix_access_clinic_id ON access_audit_events(clinic_id,id);
