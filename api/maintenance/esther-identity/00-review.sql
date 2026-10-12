-- READ ONLY. Select the pending invitation created for Esther; DO NOT approve it in the app.
SET @invitation_id = 0;
SELECT m.id AS membership_id,m.version AS membership_version,m.identity_id AS old_identity_id,
 p.id AS practitioner_id,m.local_user_id,m.clinic_id,m.status AS membership_status,
 w.adapter AS current_adapter,w.status AS current_identity_status,
 i.id AS invitation_id,i.invited_by,i.existing_user_id,i.location_id,i.expires_at,i.revoked_at,i.accepted_at,
 q.status AS claim_status,
 n.id AS new_identity_id,n.status AS new_identity_status,
 (SELECT COUNT(*) FROM staff_memberships occupied WHERE occupied.identity_id=n.id AND occupied.clinic_id=1) AS target_clinic_memberships
FROM staff_memberships m JOIN product_identities w ON w.id=m.identity_id
JOIN practitioners p ON p.user_id=m.local_user_id
JOIN staff_invitations i ON i.id=@invitation_id AND i.clinic_id=m.clinic_id
JOIN staff_invitation_claims q ON q.invitation_id=i.id
LEFT JOIN product_identities n ON n.adapter='entra-external-staff' AND n.issuer=q.issuer AND n.subject=q.subject
WHERE m.local_user_id=2 AND m.clinic_id=1;
