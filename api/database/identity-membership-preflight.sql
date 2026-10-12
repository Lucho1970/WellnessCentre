-- Read-only count report. Run against a backed-up test copy of the hosted schema.
-- No subjects, emails or contact details are emitted.
SELECT COUNT(*) legacy_staff_links FROM identity_links i JOIN users u ON u.id=i.user_id WHERE i.provider='microsoft' AND u.user_type='staff';
SELECT COUNT(*) incomplete_staff_links FROM identity_links i JOIN users u ON u.id=i.user_id WHERE i.provider='microsoft' AND u.user_type='staff' AND (i.tenant_id IS NULL OR i.tenant_id='' OR i.provider_subject='');
SELECT COUNT(*) staff_with_multiple_links FROM (SELECT i.user_id FROM identity_links i JOIN users u ON u.id=i.user_id WHERE i.provider='microsoft' AND u.user_type='staff' GROUP BY i.user_id HAVING COUNT(*)>1) conflicts;
SELECT COUNT(*) staff_without_links FROM users u WHERE u.user_type='staff' AND NOT EXISTS (SELECT 1 FROM identity_links i WHERE i.user_id=u.id AND i.provider='microsoft');
SELECT u.status,COUNT(*) staff_count FROM users u WHERE u.user_type='staff' GROUP BY u.status;
SELECT COUNT(*) legacy_customer_identities FROM customer_identities;
SELECT COUNT(*) legacy_customer_links FROM customer_client_links;
