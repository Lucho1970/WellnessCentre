# Practitioner booking contact access

18 October 2026. Follow-up to the [PHIPA readiness review](PHIPA_READINESS_REVIEW_2026-10-18.md). This is the first authorization remediation slice, not approval to use real patient data.

## Behavior

Ordinary practitioners may search booking contacts and read saved booking addresses only for active, unmerged clients in their current clinic with at least one existing relationship:

- An appointment with that practitioner, including historical appointments, consistent with the existing practitioner directory.
- A successful `client.create` audit event attributable to that practitioner.
- A non-revoked form task assigned to that practitioner while client forms are enabled.

The practitioner profile and local staff account must be active in the current clinic. Administrator/reception roles retain clinic-wide booking access. An active practitioner with the explicit `schedule_for_other_practitioners` permission also retains clinic-wide booking access. Permission alone does not grant this to accountants, clients, or users with no eligible staff role. Identity and clinic membership validation remains the responsibility of the authenticated API entry path.

The API derives access from its authenticated actor. A browser `scope`, selected practitioner, client ID, date of birth, or workspace cannot widen it. Unavailable address lookups return the same 404 as a missing client. A permitted client with no saved address still returns `address: null`.

Single-booking creation and recurring creation/preview validate this client boundary before coverage processing or writing new appointments. This prevents a guessed client ID from creating the very relationship needed to read their contact/address data. Client self-booking retains its separate identity-derived own-record policy.

Booking searches emit an audit event with the request correlation, actor and disclosed client IDs. Search text, DOB, phone, email and address content are excluded from the event. Existing saved-address view auditing remains. The client-overview access summary uses appointment, creation and enabled/non-revoked form relationships rather than claiming all practitioners have booking-contact access.

## Operational effect and remaining scope

A practitioner cannot look up or book an existing unrelated client merely by knowing their name, DOB or database ID. Reception or an authorized scheduler must establish an appropriate booking/assignment after verification, or the client may book for themselves. A practitioner who is authorized to create clients can still book the new record they created. Do not create duplicates to work around the boundary or grant clinic-wide scheduling merely to bypass it.

This slice deliberately uses existing relationship evidence. It does not implement consent restrictions, time-limited care relationships, comprehensive read/denial auditing, professional-record stewardship or practitioner departure workflows. Historical appointments and creation audit records continue to confer relationship eligibility while the practitioner account/profile remains active. Those limitations remain open PHIPA release gates.

## Deployment and acceptance

No new migration or environment setting is introduced. Deploy `BookingClientAccess.php`, the updated booking/recurrence services, API route and overview service together. Existing client-merge, onboarding/address and enabled form migrations are prerequisites, as for the existing released modules. No hosted deployment is part of this change.

Before hosted acceptance, use synthetic clients and separate accounts for an ordinary practitioner, another practitioner, delegated scheduler, reception, client and another clinic. Verify search, DOB-only lookup, direct address requests, guessed-ID booking and recurring preview. Confirm authorized existing/new-client booking, shared roles and client self-booking still work. Remove a permission or deactivate the profile/account and verify subsequent authenticated requests. Save source revision, migration/configuration state and results; local SQL does not establish hosted identity/membership acceptance.

## Local validation

The SQL test uses a disposable local MariaDB database, the booking/forms schema baseline and required migrations, with native prepared statements. It exercises service authorization against real SQL rather than mocked row filtering. It includes unrelated and foreign clients, forged browser hints, inactive accounts/profiles, wrong actor roles, delegation and revocation, form enablement/revocation, direct-write bypass attempts, permitted writers and audit contents.

```powershell
./scripts/test-recurring-sql.ps1 -MariaDbDirectory .tmp/recurrence-db-runtime/package/mariadb-11.4.8-winx64 -Port 13327 -IntegrationTest booking-client-access.php
```

Validation completed locally:

- New real-SQL authorization suite: 74 checks passed on MariaDB 11.4.8, including denied single/recurring writes and authorized single bookings/recurring preview.
- All 50 root PHP policy/test scripts passed; the expanded client-overview script passed 62 checks.
- PHP syntax passed for 86 source/public files and the new integration test; the SQL runner passed PowerShell parsing and Git whitespace checks passed.
- Frontend public, portal and application-admin builds passed, including bilingual content/translation validation. Existing bundle-size warnings remain.
- Nine focused booking/delegation/recurrence browser tests and all five client-overview browser tests passed.
- Existing real-SQL recurrence regression: 34 checks passed, including concurrent confirmations and competing series.

The broad browser suite was not repeated for this focused change. Its previously recorded practitioner `travel` navigation expectation failure remains outside this authorization change. Hosted identity, membership and database acceptance remain pending; no production or patient database was accessed.
