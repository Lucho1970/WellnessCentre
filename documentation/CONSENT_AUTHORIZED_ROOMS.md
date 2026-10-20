# Consent-authorized room maintenance

Subsequent work: [room capabilities and practitioner assignments](CONSENT_AUTHORIZED_ROOM_ASSIGNMENTS.md) now replaces the assignment fallback described below with explicit authority and booking-scope checks. The fallback regression now targets service configuration. This document records the earlier core-room checkpoint.


## Scope and authority

Room creation and updates previously inherited the conservative patient-consent block. Their service also trusted supplied roles and wrote configuration separately from its business audit. The room list, create and update methods now require an active staff account in an active clinic, Super Admin authority in the supplied principal, and a current persisted clinic-wide Super Admin grant. A grant confined to one location is insufficient. Clinic Admin, practitioner, reception, accountant, client, privacy-review permission and application/global-administrator labels alone do not grant this authority.

Only the existing `createRoom` and `updateRoom` route names are newly exempted. The already classified room list now checks current authority as well. Room capabilities, capability assignments and practitioner restrictions retain their existing conservative consent boundary and require a separate review. This task does not reinterpret or rewrite those assignment policies.

Reads return only rooms whose owning location belongs to the acting clinic. Both the current room and any proposed target location must belong to that clinic. Foreign and missing room/location targets return uniform 404 responses. Submitted clinic, patient and room IDs do not redirect a routed operation or create patient/staff relationships. Creating inventory or maintaining ordinary room metadata does not grant clinical access.

## Turnover and relocation effects

The live availability query applies the room's current turnover to existing noncanceled room occupancy. A turnover change therefore checks the complete affected appointment set before saving, including completed history and entries beyond a normal results page. Both the acting administrator and each appointment's associated practitioner must be permitted by the patient's current staff-access directives. Global, actor-specific or recipient-specific restrictions reject the whole change with 403.

Patient, practitioner/staff, service, clinic and appointment venue must resolve consistently within the room's current clinic/location. Inactive patients or practitioners are not dropped from the check. Malformed or cross-clinic dependencies require review with a generic 409. The query selects relationship IDs rather than clinical notes or destination snapshots, and uses outer joins so invalid records cannot disappear. Missing directive storage fails closed before turnover effects. No patient identities, record counts or partial appointment inventories are returned on refusal.

Canceled bookings are excluded from turnover effects because the availability conflict query excludes them too. A canceled-only room can therefore change its turnover even if a canceled patient's directive restricts staff access. This is effect filtering, not a consent override. Name, room type, operational notes and future bookability maintenance do not rewrite existing bookings.

Relocation has a stricter integrity boundary. Any booking reference, including canceled history, prevents moving the room to a different location through configuration. Permission to view or change every patient does not remove this safeguard. Moving an in-use room requires a reviewed migration that preserves appointment venues and history; this task adds no migration or override tool. An unused room may move within the clinic if its existing capability and practitioner references are also clinic-owned and resolvable. Those assignment rows are preserved, and no practitioner/location relationship is granted by moving inventory. Future booking still uses its independent location, practitioner, room and patient authorization checks.

## Transactions, validation and auditing

All three methods use the shared clinic administration transaction helper with the clinic-wide role requirement enabled. They acquire the clinic consent lock, lock the active clinic/current account, check the stored role and own their transaction. Updates lock the room, target location and relevant dependency rows. The clinic coordination row serializes app booking operations with room changes. Lock contention returns 503, and nested calls refuse to commit or roll back the caller's transaction.

Room creation/update and the corresponding business audit commit together or roll back together. Mutation audits identify the room and, for updates, the owning location and whether relocation/turnover changed. They do not copy room notes or patient content. The API retains no-store and fail-closed disclosure auditing, with typed references for all returned rooms and locations. Supplied patient IDs can appear separately as requested references under the existing audit policy; they are never treated as disclosed patient records by this configuration command.

Optional update fields retain their stored values when omitted; location and name remain required by the existing contract. Empty optional text can still be cleared. Creation honors an explicit false bookability choice. Validation rejects coerced IDs, numeric-string/boolean turnover, invalid five-minute increments, out-of-range turnover, oversized names/types/notes, binary controls and false-like bookability strings. Operational notes retain multiline text support, with the existing SQL TEXT byte limit. Unicode name/type lengths match the database character limits.

Successful maintenance does not rewrite appointment venues/times/buffers/statuses, practitioner assignments, capabilities, availability rules, patient addresses, coverage approvals, consent instructions or notification rows. Changing turnover affects future availability calculations; it does not automatically reschedule or reconcile existing appointments. No external provider is called.

## Portal and rollout

Room-list or location-list reload failure clears cached rooms, location choices and editing drafts. A 401/403/404 save refusal closes the drawer and capabilities dialog, clears selections and disables room/capability actions until a successful authorized reload. Validation and review conflicts retain the draft and display their error in the drawer. A committed save followed by a denied reload does not display a false success message. Request generation checks prevent older loads/saves from repopulating the current view. New English/French review messages explain the relocation/privacy review requirement.

No production migration or environment variable is introduced. Existing clinic roles/identity, room/booking, consent and audit foundations remain prerequisites. Reconcile current clinic-wide role grants and review legacy cross-clinic or venue-inconsistent room dependencies before rollout. Deploy matching API and portal after review and drain older API instances so configuration and booking share the same boundary. Hosted acceptance and the broader [PHIPA readiness gates](PHIPA_READINESS_REVIEW_2026-10-18.md) remain outstanding.

Run `scripts/test-recurring-sql.ps1 -MariaDbDirectory <portable-runtime> -IntegrationTest consent-rooms.php` for disposable local SQL/HTTP acceptance. The location regression's fallback probe now targets the still-unclassified capability command, since room creation is intentionally classified by this change.

Local validation on 20 October 2026:

- 246 new SQL/HTTP checks passed on MariaDB 11.4.8: current role/account/clinic and clinic-wide scope; foreign/missing targets; complete 31-appointment turnover effects; actor/global/recipient restrictions; inactive dependencies; canceled-only effect filtering; in-use and malformed relocation safeguards; malformed appointment links; audit rollback; missing directive storage; body-ID isolation; preserved clinical/configuration records; lock contention and nested transaction refusal. The same run passed 74 booking-contact and 28 access-audit checks. An integration check also verifies the actual availability room-conflict query changes after an authorized turnover update.
- The updated location regression passed 197 SQL/HTTP checks, retaining its 74 booking-contact and 28 access-audit regressions.
- All 53 root PHP scripts and syntax checks for 94 source/public files passed. The new/updated integration PHP sources and maintained PowerShell runner passed syntax checks.
- Six selected browser scenarios passed: existing location/room panels, drawer field errors, 403/404 room-denial cleanup/recovery, denied reload after save without false success, and review conflicts retaining the draft with an actionable message.
- Full public, portal and application-admin production build passed, including TypeScript, content checks and 2,113 English/French translations. Existing bundle-size warnings remain. The complete browser suite was not run.

All checks are local and synthetic. No real patient data, hosted database, private environment or external service was used. Remaining conservative blocks include room capability/practitioner assignment workflows, service configuration/assignments, clinic profile/branding/themes/booking settings, and reviewed reconciliation for client merges with any consent history. Unknown authenticated staff routes retain their fail-closed fallback.

Subsequent service authorization: [service configuration and assignments](CONSENT_AUTHORIZED_SERVICES.md) now have explicit authority; the room/location fallback probes now target tax creation.
