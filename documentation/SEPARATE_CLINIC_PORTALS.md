# Separate clinics and locations

Implemented on `feature/separate-clinic-portals`.

**Administration update:** clinic creation and hostname registration now move to the central authenticated `portal.copihue.ca/admin/` area. The clinic-local Super Admin management steps below describe the previous checkpoint; use [Application administration](APPLICATION_ADMINISTRATION.md) for migration 040, explicit global grants and current deployment. Clinic data/location isolation remains unchanged.

A clinic owns its services, staff/client accounts, appointments, forms, branding and locations. A location is an address or service area inside one clinic. Creating a second location does not create another clinic. Clinics may each have multiple locations, and names may repeat across clinics.

## Administration

Super Admin → **Clinics** lists the current clinic and other clinics where the same immutable workforce identity has an active Super Admin membership. It does not list unrelated clinics. Ordinary Clinic Admin and practitioner roles cannot provision clinics.

**Create clinic** asks for a clinic name, portal hostname, optional HTTPS public website URL, first location name and timezone. Provisioning atomically creates an empty clinic, its first location, a clinic-local administrator account and an explicit active membership for the acting workforce identity. It does not copy clients, practitioners, services, roles belonging to other staff, or test data. Duplicate hosts fail without a partial clinic. The administrator's original membership is retained; removing a membership blocks access to that clinic even with a valid provider token.

**Edit clinic** configures only the currently open clinic's name, website destination and initial portal host registration. An established portal host is immutable in this screen. Open the other clinic's portal to change its business settings or add locations. **Manage locations** opens the existing Locations screen, scoped to the current clinic. All new locations use the authenticated clinic ID, never a browser-supplied clinic ID.

The hostname determines the server clinic context. Normalized exact database host registrations take precedence; the existing trusted `CLINIC_HOST_MAP` remains for legacy/public-site aliases. Forwarded host headers, query/body clinic IDs and matching emails do not select a clinic or create membership.

## Client sessions and links

One trusted customer identity can register a distinct client record in each clinic. Its clinic/client link uses `(identity_id, clinic_id)` uniqueness; client IDs remain unique. Contact details, appointments and responses are not shared or merged across clinics. Authentication challenges and application sessions are bound to the clinic that issued them. A session/challenge from another clinic is rejected, and logging out through the wrong clinic cannot revoke the original session. Provider SSO may reuse the provider login, but each portal establishes its own application session.

Client invitation emails, appointment-action links, notification emails/SMS and calendar links use the recipient appointment's clinic portal. When enabled, a missing clinic portal registration stops link preparation rather than falling back to another clinic's URL. The portal's Public website link uses that clinic's optional website URL; without one, it returns to that clinic's own host. Production builds continue to use same-origin `/api/v1`, so one portal bundle can be installed on both hosts.

This does not implement cross-practitioner clinical-record sharing, practitioner handover, payouts, or a private-client isolation policy between independent practitioners inside the same virtual clinic. Those remain separate requirements.

## Deployment and initial setup

1. Back up the schema and apply **039_separate_clinic_portals.sql once**. Prerequisites: existing customer onboarding tables (005) and identity/membership foundation (032). This migration changes link uniqueness and adds host/session configuration; it moves or deletes no clinic, practitioner or client records. Existing client sessions must sign in again after activation.
2. Deploy the matching private API and rebuilt portal. Keep private `.env` and runtime directories intact. Enable `CLINIC_MANAGEMENT_ENABLED=true` in the private API `.env` only after SQL and code are in place. Keep the current provider/customer onboarding settings. With the flag disabled, existing clinic routing and client onboarding behavior remain unchanged.
3. Register the existing portal host in **Clinics → Edit clinic**. This enables correct notification URLs for the existing clinic. If desired, rename that current clinic to **Willow Wellness Virtual** and enter its own public website URL. Renaming a clinic does not move its existing test locations or records; deactivate unwanted test locations in Locations. There is no automatic conversion or migration of those records.
4. Create **Livin' Lively** with `livinlively.copihue.ca` and the first actual location, for example Holland Landing. Enter `https://livinlively.ca/` as its public website. Create its practitioners, services and additional locations inside its own portal.
5. In Netfirms, configure the new subdomain, HTTPS certificate and a separate document root at the same level as the Willow portal; deploy the portal contents there. Both thin public API entry points may use the same private backend/database. Creating a clinic in the application does not provision Netfirms DNS or certificates.
6. Add the new host's staff, invited-practitioner and client callback/logout URLs to the existing applicable identity-provider SPA registrations, using the paths already used on Willow (`/`, `/staff/external`, `/client/auth/callback`, as applicable). Add its origin to the server CORS allowlist if cross-origin requests are used; normal portal requests stay same-origin.
7. Configure each public marketing host in `CLINIC_HOST_MAP` to the correct clinic ID if it serves this application's public API. In particular, existing `livinlively.ca`/`.com` aliases must point to the newly created Livin' Lively clinic, not the Willow clinic. Use the ID returned by clinic creation or `SELECT id,name FROM clinics`; do not guess IDs. Registering the portal host does not silently take over existing aliases.
8. Test both portals: only their own locations/services/practitioners are listed; add a second location to one clinic and confirm it remains invisible in the other; sign in as a clinic-specific practitioner and client; try foreign record IDs and clinic/session reuse; verify notification and public website destinations. Hosted DNS, provider callbacks and database acceptance require this deployed rehearsal.

No deployment or identity-provider changes are performed by local implementation. Clinic creation does not require a separate identity directory or duplicate codebase.

## Local validation

`api/tests/integration/separate-clinics.php` creates a random synthetic localhost database, loads the maintained baseline plus 005 and 039, and verifies actual MariaDB provisioning, duplicate rollback, multiple locations, host/query isolation, separate staff memberships with signed JWTs, revocation, separate client records under one identity, wrong-clinic sessions/challenges/logout, cross-clinic client denial and URL selection. Set `CLINIC_TEST_ALLOW_CREATE=true`; default port 13319. It never loads private `.env`; databases are retained for inspection. `api/tests/clinic-management.php` checks invalid hostname/name/website fields. Browser regressions check creation, field error focus, current-clinic locations and restricted management access. Local validation is recorded at the completed checkpoint in the development plan; hosted acceptance is still required.
