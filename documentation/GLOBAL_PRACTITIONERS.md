# Global practitioners and practitioner-owned credentials

The external practitioner self-service joining flow now has a separate
[application/review and activation workflow](PRACTITIONER_APPLICATIONS.md). It reuses an exact
verified global identity without overwriting existing names/private credentials or other
clinic data. Existing workforce users continue to use the import/assignment workflow here;
contact emails are never used to merge identities. Activation remains held for rollout checks.

Application Administration (`https://portal.copihue.ca/admin/`) has separate **Clinics** and **Global practitioners** tabs. An explicit active application-administrator grant is required. Clinic Super Admin roles alone do not grant central access.

## Add the existing practitioner to Wellnexa

1. Open **Global practitioners** and select the person and their original clinic under **Import existing practitioner**. Import uses the selected local practitioner ID and its immutable, trusted identity binding; email never links accounts. An active workforce legacy link can be brought into the membership model without changing the source user, practitioner, roles, qualifications or appointments. External staff with an existing active identity membership can also be imported.
2. Choose **Assign a clinic**, select Wellnexa and its Virtual Clinic location, and assign. This creates a separate local staff/practitioner record and membership for the same identity. It does not create another Microsoft/Google account or copy clients, services, schedules, qualifications, public biographies or credential text.
3. The practitioner signs into the Wellnexa portal with the same identity. Workforce accounts still require `Wellness.Practitioner` from the provider as well as the local practitioner role. External staff continue to use the established External ID flow.
4. Configure services, availability and public profile publication inside Wellnexa. A new assignment starts with the generic discipline **Practitioner**, no credentials and no published public card.

For a brand-new workforce practitioner, **Register new practitioner** records their display name, email, configured workforce tenant and immutable Microsoft Object ID globally, before any clinic assignment. It does not provision or assign roles in Microsoft Entra. Already-linked identities must be imported. New external identities continue through the invitation/acceptance flow before their existing practitioner profile can be imported; this screen does not bind external identities by email.

## Credentials belong to the practitioner

After global registration/import, the practitioner's account profile has **My global credentials**. They add named credentials, issuing organization, issue date and optional expiry date to a private library. Nothing is shared on creation or clinic assignment. A new immutable entry represents a renewal; the practitioner can withdraw the old entry and share the new one.

Only the practitioner can read their library or select sharing, through their own active clinic membership. Neither central administrators nor clinic administrators receive the unshared library. The sharing API takes only a credential ID and a boolean; the authenticated portal fixes the owner and destination clinic. It cannot share another practitioner's credential or select another clinic in the request body.

The practitioner switches on **Share [credential] with this clinic** independently in each clinic portal. For example, share Registered Massage Therapist in Livin Lively and Holistic Nutritionist in Wellnexa. The selected names set the clinic's practitioner discipline/credential summary and existing public-card title, if a card exists. Sharing does not publish a card automatically, authorize a service, or verify a professional claim. The clinic receives a pending qualification record through the established review process. Each clinic reviews its own shared records; another clinic's verification is not copied. Administrators cannot overwrite a globally registered practitioner's selected discipline/credential summary or submit qualifications on their behalf.

Withdrawing sharing removes the credential from the current clinic summary and revokes its review record. The private library and other clinic selections remain intact. Prior clinic submissions/review history are retained for audit; withdrawal does not erase information that was previously disclosed. Sharing again submits for a fresh review. Existing clinic-only qualifications are retained as historical records and are not automatically imported into or disclosed from the global library.

## Transfer, removal and scheduling

Assigning another clinic leaves the source assignment active. To transfer exclusively, add the destination, handle future source appointments and active recurring series in the source clinic, then remove the source assignment centrally. Removal refuses outstanding future appointments and active series. It disables that local practitioner, withdraws current credential selections, unpublishes the public card and removes the local practitioner role. Other local staff roles are preserved; if none remain, the local membership is revoked. Historical appointments and records are never moved or deleted.

Removed assignments can be explicitly restored by selecting that clinic again; the current assignment version is required. The original local user/practitioner IDs are reused. Inactive identities/users and conflicting membership states require separate review. Restoring does not publish the card or restore credential sharing automatically.

Appointments and blocking imported calendar entries for the same identity block availability across its local practitioner records. Time off applies only to the clinic where it is recorded, across that clinic's locations. Practitioners record time off separately in every clinic where they want to be unavailable. Existing time-off records stay in their originating clinic and are not copied or deleted. Booking, rescheduling and reassignment use shared identity locks and current busy-time reads. Only a busy/not-busy result crosses clinic boundaries; calendars and client details remain clinic scoped. Location-specific availability overrides remain local. This change does not provide a consolidated calendar or automated travel planning between clinics.

## Deployment and validation

Back up and rehearse the hosted database first. Apply `api/database/migrations/042_global_practitioners.sql` once after 026, 032, 039 and 040. Migration 041 is independent and is not required by this feature. The migration is additive and performs no automatic import. Deploy the shared API, central administration bundle and clinic portal bundle together; keep private `.env` and provider configuration. Existing `APPLICATION_ADMIN_ENABLED=true` and `CLINIC_MANAGEMENT_ENABLED=true` enable the central workflow. With central management enabled, the old clinic onboarding endpoint directs administrators to central registration/assignment. No new global login bypass is introduced.

Local validation commands:

```powershell
$env:OPENSSL_CONF = 'C:/Program Files/Git/usr/ssl/openssl.cnf'
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13319 -IntegrationTest global-practitioners.php
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13319 -IntegrationTest application-administration.php
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13320 -IntegrationTest recurring-bookings.php
cd Frontend
npm run build
npx playwright test --config playwright.application-admin.config.ts
npx playwright test tests/portal.spec.ts
```

These are localhost checks with synthetic data, not hosted acceptance. Before rollout acceptance, verify the real practitioner's immutable identity, original and Wellnexa sign-in, independent credential selections, review status, public cards, services/availability, busy-time protection and removal behavior. No production practitioner was imported or assigned by development tests.

Local completion evidence: frontend production builds and bilingual resources passed; 92 existing portal checks passed, both new portal flows passed, and all five central administration checks passed (targeted reruns covered credential sharing, clinic admin restrictions and legacy qualification/onboarding screens). All 49 PHP test files and syntax for 151 PHP files passed. Real synthetic MariaDB acceptance passed 43 global practitioner/credential assertions, 38 central administration assertions and 34 recurring booking assertions. These checks do not replace hosted database/provider acceptance.

Once the intended source branches are reviewed and integrated, the maintained package builder accepts `-DeploymentGuide GLOBAL_PRACTITIONERS.md` and records the source commit in its manifest. Keep the standalone Wellnexa marketing-site deployment separate; this feature changes the shared API and central/clinic portals.
