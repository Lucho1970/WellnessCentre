-- Snapshot cancellation terms on appointments so later service edits do not alter an existing booking.
ALTER TABLE appointments
  ADD COLUMN cancellation_window_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER mobile_fee_cents,
  ADD COLUMN cancellation_fee_type ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none' AFTER cancellation_window_minutes,
  ADD COLUMN cancellation_fee_value INT UNSIGNED NOT NULL DEFAULT 0 AFTER cancellation_fee_type,
  ADD COLUMN cancellation_fee_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER cancellation_fee_value;

UPDATE appointments a
JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
SET a.cancellation_window_minutes=s.cancellation_window_minutes,
    a.cancellation_fee_type=s.cancellation_fee_type,
    a.cancellation_fee_value=s.cancellation_fee_value
WHERE a.status NOT IN ('canceled_by_client','canceled_by_clinic');

UPDATE appointments a
JOIN services s ON s.id=a.service_id AND s.clinic_id=a.clinic_id
LEFT JOIN service_duration_options d ON d.id=a.duration_option_id AND d.service_id=a.service_id
SET a.base_price_cents=COALESCE(d.price_cents,s.price_cents)
WHERE a.base_price_cents IS NULL
  AND a.status NOT IN ('canceled_by_client','canceled_by_clinic');
