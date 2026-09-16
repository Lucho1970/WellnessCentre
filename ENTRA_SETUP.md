# Microsoft Entra staff authentication setup

> Public/portal split: see [the deployment checkpoint](documentation/PORTAL_SEPARATION.md). Staff login now runs on the portal origin, locally `http://localhost:5174/`; register that exact SPA callback and the chosen HTTPS portal URL. Public browsing remains on port 5173 and does not initialize MSAL.

Staff authentication is intentionally separate from future client social sign-in. The SPA is a public client and uses MSAL's authorization-code flow with PKCE; it has no client secret. The PHP API accepts only single-tenant v2 access tokens issued for the API.

## Values to collect

From **Microsoft Entra ID > Overview**, copy the **Tenant ID**. From each app registration's **Overview**, copy its **Application (client) ID**:

- Tenant ID -> `VITE_ENTRA_TENANT_ID` and `ENTRA_TENANT_ID`
- “Wellness Centre Staff Portal (Dev)” client ID -> `VITE_ENTRA_SPA_CLIENT_ID`
- “Wellness Centre API (Dev)” client ID -> `VITE_ENTRA_API_CLIENT_ID` and `ENTRA_API_CLIENT_ID`

Use IDs, not object IDs. Do not create or configure a client secret for the SPA.

## 1. Register the API

Create **Wellness Centre API (Dev)** as **Accounts in this organizational directory only** with no redirect URI.

Under **Expose an API**:

1. Accept Application ID URI `api://<API_CLIENT_ID>`.
2. Add delegated scope `access_as_user`, enabled, with **Admins and users** allowed to consent.
3. Use a description such as “Access the Wellness Centre API as the signed-in staff user.”

Under **App roles**, create these roles with **Users/Groups** as allowed member types and matching values:

| Display name | Value                   |
| ------------ | ----------------------- |
| Super Admin  | `Wellness.SuperAdmin`   |
| Clinic Admin | `Wellness.ClinicAdmin`  |
| Reception    | `Wellness.Reception`    |
| Practitioner | `Wellness.Practitioner` |
| Accountant   | `Wellness.Accountant`   |

Keep the requested access-token version at v2 (the default for a newly exposed API).

## 2. Register the SPA

Create **Wellness Centre Staff Portal (Dev)** as **Accounts in this organizational directory only** (or update the existing registration). Under **Authentication**, add the **Single-page application** redirect URI `http://localhost:5174/` and the exact HTTPS portal callback for the deployment. Leave implicit grant disabled; MSAL uses authorization code with PKCE. Do not add a frontend client secret or treat a front-channel logout setting as a replacement for implementing sign-out.

Under **API permissions**, add **My APIs > Wellness Centre API (Dev) > Delegated permissions > access_as_user**, then grant tenant admin consent if your tenant requires it. Do not add a secret.

## 3. Assign staff

Open **Enterprise applications > Wellness Centre API (Dev) > Users and groups**. Assign each staff user exactly one Wellness role. The API requires the resulting `roles` claim.

Link the same person to the local staff record using the user's immutable Entra **Object ID**, not their email. The supported bootstrap command creates the local identity link and role assignment transactionally:

```powershell
cd api
php bin/provision-admin.php --tenant="<TENANT_ID>" --oid="<USER_OBJECT_ID>" --email="you@example.com" --name="Your Name" --clinic="Back To Balance Wellness Centre" --location="Toronto Clinic" --timezone="America/Toronto"
```

The database role and Entra app role must match. An active `staff` user with `clinic_admin`, for example, must have `Wellness.ClinicAdmin`. This prevents an email rename or recycled address from changing identity and prevents either directory alone from silently elevating privileges.

## 4. Configure local environment files

Copy `Frontend/.env.example` to `Frontend/.env.local` and replace all three GUIDs. Copy `api/.env.example` to `api/.env`, replace the tenant/API GUIDs, and set the local MySQL connection. Both files are ignored by Git.

Run public browsing at `http://localhost:5173`, the staff portal at `http://localhost:5174` (separate terminal), and the API at `http://localhost:8080` when using a local backend:

```powershell
cd Frontend
npm run dev

# In a second terminal, also from Frontend:
npm run dev:portal

cd ..\api
php -S localhost:8080 -t public public/index.php
```

The protected `GET /api/v1/auth/me` endpoint is the sign-in authorization check. It verifies the RS256 signature against Microsoft's rotating JWKS, issuer, tenant, API audience, lifetime, `access_as_user`, the app role, and the `tid + oid` database link before the dashboard is shown.
