-- Fill from private configuration and the reviewed report. Defaults refuse changes.
SET @invitation_id = 0;
SET @operator_user_id = 0;
SET @verification_code = '';
SET @expected_staff_tenant = '';
SET @expected_workforce_tenant = '';
SET @membership_id = 0;
SET @membership_version = 0;
SET @old_identity_id = 0;
SET @new_identity_id = 0;
SET @confirmation = '';
-- Confirm in private .env: membership pilot enabled for user 2; recovery admin outside it.
SET @pilot_configuration_checked = 0;
SET @expected_staff_issuer = CONCAT('https://',@expected_staff_tenant,'.ciamlogin.com/',@expected_staff_tenant,'/v2.0');
-- Back up first. Run the whole batch; stop on ANY SQL error, never manually COMMIT an errored batch.
START TRANSACTION;
UPDATE staff_memberships m
JOIN users u ON u.id=m.local_user_id AND u.clinic_id=m.clinic_id
JOIN clinics c ON c.id=m.clinic_id
JOIN practitioners p ON p.user_id=u.id
JOIN product_identities w ON w.id=@old_identity_id
JOIN identity_links l ON l.user_id=u.id AND l.provider='microsoft'
JOIN staff_invitations i ON i.id=@invitation_id AND i.clinic_id=m.clinic_id
JOIN staff_invitation_claims q ON q.invitation_id=i.id
JOIN users a ON a.id=@operator_user_id AND a.clinic_id=m.clinic_id
JOIN users inviter ON inviter.id=i.invited_by AND inviter.clinic_id=m.clinic_id
JOIN locations loc ON loc.id=i.location_id AND loc.clinic_id=m.clinic_id
JOIN product_identities n ON n.id=@new_identity_id AND n.adapter='entra-external-staff'
 AND n.issuer=q.issuer AND n.subject=q.subject AND n.status='active'
LEFT JOIN staff_memberships occupied ON occupied.identity_id=n.id AND occupied.clinic_id=1
SET m.identity_id=n.id,m.version=m.version+1,
 q.status='approved',q.reviewed_by=@operator_user_id,q.reviewed_at=UTC_TIMESTAMP(),
 i.accepted_at=UTC_TIMESTAMP(),i.accepted_membership_id=m.id
WHERE m.id=@membership_id AND m.version=@membership_version
AND m.local_user_id=2 AND m.clinic_id=1 AND m.status='active'
AND u.user_type='staff' AND u.status='active' AND c.status='active' AND p.active=1
AND a.id<>2 AND a.user_type='staff' AND a.status='active'
AND inviter.user_type='staff' AND inviter.status='active' AND loc.is_bookable=1
AND EXISTS(SELECT 1 FROM user_roles ar JOIN roles r ON r.id=ar.role_id WHERE ar.user_id=a.id AND r.code='super_admin')
AND EXISTS(SELECT 1 FROM user_roles ar JOIN roles r ON r.id=ar.role_id WHERE ar.user_id=inviter.id AND r.code='super_admin')
AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=2 AND r.code='practitioner')
AND NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=2 AND r.code<>'practitioner')
AND (SELECT COUNT(*) FROM practitioners WHERE user_id=2)=1
AND (SELECT COUNT(*) FROM identity_links WHERE user_id=2 AND provider='microsoft')=1
AND w.adapter='entra-workforce' AND w.status='active'
AND BINARY l.tenant_id=BINARY @expected_workforce_tenant
AND BINARY w.issuer=BINARY CONCAT('https://login.microsoftonline.com/',@expected_workforce_tenant,'/v2.0')
AND BINARY w.subject=BINARY l.provider_subject
AND @expected_staff_tenant REGEXP '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'
AND @expected_workforce_tenant REGEXP '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'
AND BINARY q.issuer=BINARY @expected_staff_issuer AND LENGTH(q.subject)>0
AND @verification_code REGEXP '^[A-F0-9]{12}$' AND BINARY q.verification_code=BINARY @verification_code
AND (i.existing_user_id IS NULL OR i.existing_user_id=2)
AND i.revoked_at IS NULL AND @pilot_configuration_checked=1
AND m.identity_id=w.id AND q.status='pending' AND i.accepted_at IS NULL AND i.expires_at>UTC_TIMESTAMP()
AND occupied.id IS NULL AND @confirmation='MIGRATE USER 2';
SET @changed=ROW_COUNT();
INSERT INTO audit_logs(clinic_id,actor_user_id,correlation_id,action,entity_type,entity_id,outcome,metadata)
SELECT 1,@operator_user_id,UUID(),'staff.identity.migrate','staff_membership',@membership_id,'success',
 JSON_OBJECT('user_id',2,'old_identity_id',@old_identity_id,'new_identity_id',@new_identity_id,'invitation_id',@invitation_id,'maintenance',true)
WHERE @changed=3;
SET @audited=ROW_COUNT();
SET @finish=IF(@changed=3 AND @audited=1,'COMMIT','ROLLBACK');
PREPARE finish_migration FROM @finish;
EXECUTE finish_migration;
DEALLOCATE PREPARE finish_migration;
SELECT @changed AS changed_rows,@audited AS audit_rows,@finish AS transaction_result;
SELECT id AS membership_id,local_user_id,clinic_id,identity_id,version,status FROM staff_memberships WHERE local_user_id=2;
