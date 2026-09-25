-- Allow a Super Admin to grant selected practitioners client creation.
-- Apply once before deploying the matching API and portal. No practitioner is opted in.
INSERT INTO permissions(code,name,description)
VALUES ('add_clients','Add clients','Create a new client record for booking and invite that client to the portal; does not grant editing or identity-link approval')
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description);
