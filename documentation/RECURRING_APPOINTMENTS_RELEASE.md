# Recurring appointments release — 6 October 2026

This release adds weekly, biweekly and monthly appointment series, per-date conflict previews, atomic confirmation, and explicit cancellation/rescheduling of future visits. Ordinary appointment actions still change one visit. The manifest records the exact source revision and ZIP hashes. Package creation does not update Netfirms.

## Required files and destinations

| File | Destination or action |
|---|---|
| `wellness-api-private.zip` | Extract its contents into the existing private `/wellness-api`, including `src`, `bin`, `vendor` and Composer files |
| `wellness-portal.zip` | Extract into the actual document root assigned to `willowwellness.copihue.ca`, including `index.html`, `assets`, `.htaccess` and `api` |
| `sql-updates/035_recurring_booking_requests.sql` | Apply once in the existing API database using phpMyAdmin, before uploading the new API |

These two ZIPs and migration 035 are the recurrence update. The builder also creates public website, neutral portal landing, API-public-only and mail-bridge archives for the standard release layout; uploading them is not necessary for this feature. Confirm Willow's physical document root in Netfirms rather than deriving it from the domain name. Do not nest another `wellness-api` directory inside the private directory or upload PHP classes into a public document root.

## Upload order

1. Back up the development database and current matching private API/Willow portal files. Preserve the live private `.env`, runtime data and uploads.
2. Confirm the previous release migrations are already applied, including appointment action links (034). This package contains earlier source changes too; if an earlier release was skipped, follow its documented migration prerequisites before proceeding. Do not import the entire `sql-updates` directory or full schema.
3. In phpMyAdmin, select the database configured in private `/wellness-api/.env`. Run:

   ```sql
   SHOW COLUMNS FROM recurring_series;
   SHOW TABLES LIKE 'recurring_booking_requests';
   ```

   If both new `clinic_id` and `timezone` columns and the request table are absent, import **035_recurring_booking_requests.sql** once. If all are present, verify `SHOW CREATE TABLE recurring_series;` and `SHOW CREATE TABLE recurring_booking_requests;` against the migration and skip reapplying it. If only part is present, stop and inspect the migration error/backup; the migration contains DDL and cannot be assumed to have rolled back completely. Do not blindly rerun it.
4. Upload/extract the private API archive, then the matching Willow portal archive. Arrange a quiet testing window so users do not book while the files are being replaced. Preserve the existing private `.env`; no new environment settings, identity-provider callbacks or frontend flags are needed. Keep the working staff membership settings and disabled practitioner invitation settings as they are.
5. Hard-refresh staff and client tabs. Confirm administrator and Esther's existing Microsoft sign-in, client sign-in, normal appointment lists and ordinary single bookings still work.
6. In **Services**, enable **Recurring bookings** for a selected test service. This existing service setting controls whether the Repeat option is available. Use synthetic clients for the checks below.

## Hosted checks

- Create a weekly three-visit series as staff for a selected client and as that client. Review every date before confirmation and verify exactly three appointments afterward. Try biweekly, monthly month-end and a date range crossing daylight saving time; check local times and count/end limits.
- Occupy a future date before preview: it must show a conflict and save nothing. Take a slot after preview but before confirmation: no partial series may be saved. Check existing room, time-off, mobile coverage, price and booking-horizon rules.
- Retry a successful or uncertain confirmation using the same request key. It must return the original IDs without duplicate bookings, histories or notification events. Use independent connections for the broader single/series concurrency acceptance.
- Change or cancel **one appointment**, then verify other visits remain unchanged. Open **Manage future series**, review every remaining future visit, and explicitly confirm cancellation or per-visit rescheduling. Client cancellation must acknowledge each displayed fee. A changed version, fee or conflicting reschedule must require review and leave the whole proposed change unapplied.
- Verify other clients, other clinic hosts and unauthorized staff cannot view/change/replay a series. Include practitioner-managed and clinic-managed schedules and an individually reassigned visit. Check mobile English/French screens, history, reminders and stale email links after appointment changes.

The recurrence implementation passed local frontend builds/browser regression. The follow-up SQL rehearsal passed **34 checks on MariaDB 11.4.8**, including migration, rollback, replay, changed fees and simultaneous duplicate/competing confirmations; all 46 PHP fixture files passed. Those results do not prove compatibility with the exact Netfirms MySQL version, live notification delivery or hosted authorization. Save hosted acceptance results separately. Full behavior and local evidence are in `RECURRING_APPOINTMENTS.md`, supplied beside this guide.

## Rollback

Disable recurrence eligibility on the affected services, then restore the previous matching API and portal files if needed, preserving `.env`, runtime files and uploads. Retain migration 035, existing series, appointments and the request ledger; restoring files must not delete booking data. Keep the release manifest with the backup.
