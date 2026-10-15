-- Read-only. Expect all three rows, each using InnoDB.
SELECT table_name, engine FROM information_schema.tables
WHERE table_schema=DATABASE()
 AND table_name IN ('receipt_templates','practitioner_receipt_profiles','session_receipts')
ORDER BY table_name;

-- Expect this STORED GENERATED column and all three unique indexes below.
SELECT column_name, extra FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='session_receipts' AND column_name='active_appointment_id';
SELECT DISTINCT index_name, non_unique FROM information_schema.statistics
WHERE table_schema=DATABASE() AND table_name='session_receipts'
 AND index_name IN ('uq_session_receipt_active','uq_session_receipt_request','uq_session_receipt_number')
ORDER BY index_name;
