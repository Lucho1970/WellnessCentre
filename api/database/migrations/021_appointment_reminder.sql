-- Enable one 24-hour client email reminder for existing clinics. New clinics can
-- use reminder_schedules to select their own global timings. No historical
-- appointments are queued by this migration; only future booking writes do so.
INSERT INTO reminder_schedules(clinic_id,event_code,minutes_before,channel,active)
SELECT c.id,'appointment_reminder',1440,'email',1
FROM clinics c
WHERE NOT EXISTS (
    SELECT 1 FROM reminder_schedules r
    WHERE r.clinic_id=c.id AND r.event_code='appointment_reminder'
      AND r.minutes_before=1440 AND r.channel='email'
);
