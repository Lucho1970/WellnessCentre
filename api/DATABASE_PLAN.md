# Database and API implementation plan

> Historical schema/design reference, superseded for cross-system decisions by [Master Requirements](../documentation/MASTER_REQUIREMENTS.md) and [System Design](../documentation/SYSTEM_DESIGN.md). Keep using actual schema/migrations and API runbooks for implementation. In particular, the observed Netfirms database is MySQL 5.7.44; the MySQL 8+ assumption below is not the current hosting baseline. Existing tables do not mean all described workflows are implemented.

## Core design

- MySQL 8.0+ with InnoDB, foreign keys, `utf8mb4`, and integer cents for money.
- Store `DATETIME` values in UTC; use `locations.timezone` for input and display.
- Keep identity, scheduling, clinical records, finance, and compliance data separated.
- Preserve legal and financial history through statuses and append-only events instead of destructive updates.
- Database constraints enforce data shape; the API enforces permissions and overlapping schedule rules transactionally.

## Tables and how they are used

| Domain | Tables | Responsibility |
| --- | --- | --- |
| Organization | `clinics`, `locations` | Clinic ownership, address, timezone, and location-specific scheduling. |
| Identity | `users`, `client_profiles`, `staff_accounts`, `identity_links` | Shared user identity, client/staff details, and links to immutable Microsoft/Google/Meta identity IDs. |
| Authorization | `roles`, `user_roles` | Multiple clinic-wide or location-scoped roles per user. |
| Practitioners | `practitioners`, `practitioner_locations`, `practitioner_services` | Practitioner profile, workplaces, booking mode, and offered services. |
| Services | `service_categories`, `services`, `service_duration_options` | Price, approved 15-minute durations, buffers, lead time, cancellation, room, and recurrence rules. |
| Rooms | `rooms`, `room_capabilities`, `room_capability_assignments`, `service_room_capability_requirements`, `room_practitioner_restrictions` | Prevent invalid room assignment and represent equipment/restrictions. |
| Availability | `availability_rules`, `availability_overrides`, `time_off`, `imported_calendar_entries` | Recurring hours, one-off changes, illness/vacation, and external conflict blocks. |
| Booking | `appointments`, `appointment_attendees`, `appointment_status_history`, `recurring_series`, `cancellation_adjustments` | Authoritative bookings, recurrence, lifecycle audit, fees, and authorized exceptions. |
| Waitlist | `waitlist_entries`, `waitlist_offers` | Client preferences and expiring offers when a matching slot opens. |
| Forms/notes | `form_templates`, `form_assignments`, `form_submissions`, `practitioner_client_notes` | Versioned forms and sensitive practitioner records, isolated from routine scheduling. |
| Messaging | `notification_templates`, `reminder_schedules`, `notification_events` | Editable templates, global schedules, durable send queue, retries, and delivery status. |
| Operations | `operational_tasks` | Human follow-ups, especially calls triggered by sick-day cancellations. |
| Billing | `taxes`, `invoices`, `invoice_line_items`, `payments`, `refunds` | Invoice snapshots, payment ledger, balances, and refunds. |
| Accounting | `accounting_connections`, `accounting_mappings`, `accounting_sync_records` | QuickBooks connection state, mappings, retries, and reconciliation. |
| Privacy | `consent_records`, `data_export_requests`, `retention_policies` | Purpose/versioned consent, PIPEDA access exports, and retention configuration. |
| Audit | `audit_logs` | Append-only sensitive access/actions with actor, result, correlation ID, and timestamp. |

## Booking transaction

Creating or rescheduling an appointment must execute in one transaction:

1. Validate identity, authorization, service, duration, location, lead time, and booking horizon.
2. Convert the requested local time to UTC and calculate service plus buffer intervals.
3. Lock the relevant practitioner and room schedule using `SELECT ... FOR UPDATE`.
4. Reject overlap with active appointments, time off, blocked overrides, or blocking imported events.
5. Confirm that recurring availability and room capability rules match.
6. Insert the appointment and its first status-history record.
7. Queue confirmation and reminder rows in `notification_events`.
8. Commit. A worker sends messages after the transaction.

Use appointment and payment idempotency keys so request retries return the original result rather than create duplicates.

## Planned API surface

All endpoints live under `/api/v1`, use cursor pagination for collections, and return errors as `{error:{code,message,fields?,correlation_id}}`.

| Area | Endpoints | Main tables |
| --- | --- | --- |
| Health | `GET /health`, `GET /health/database` | Connection check |
| Identity | `GET /auth/me`, `POST /auth/link`, `POST /auth/logout` | Users, identity links, roles |
| Public catalogue | `GET /locations`, `/practitioners`, `/services` | Sanitized catalogue fields |
| Availability | `GET /availability` with service/date/practitioner filters | Scheduling, bookings, rooms |
| Appointments | `POST /appointments`, `GET/PATCH /appointments/{id}`, `/cancel`, `/reschedule` | Appointment domain |
| Recurrence | `POST /recurring-series`, `DELETE /recurring-series/{id}` | Series and appointments |
| Waitlist | `POST/GET /waitlist`, `POST /waitlist-offers/{id}/accept` | Waitlist domain |
| Schedules | Practitioner availability, time-off, and calendar-import endpoints | Availability domain |
| Forms | Form-template and form-submission endpoints | Form domain |
| Clients | Profile, history, and permitted practitioner-note endpoints | Client and related records |
| Billing | Invoice, payment, refund, and receipt endpoints | Billing domain |
| Notifications | Template and reminder-schedule endpoints | Messaging domain |
| Reports | Appointment, utilization, cancellation, and financial aggregates | Read-only queries |
| Privacy | Export requests and consent endpoints | Privacy domain |
| Audit | `GET /audit-logs` | Audit logs; compliance/admin only |

## Authorization rules

- Anonymous users see only active sanitized catalogue data and computed availability.
- Clients see only their own appointments, forms, invoices, payments, consents, and exports.
- Practitioners see their schedule and clients who have booked with them; verify the relationship on every request.
- Reception manages bookings and routine contact data but not unrestricted clinical notes.
- Accountants see finance/accounting data but not clinical notes or form responses by default.
- Clinic admins manage one clinic; super-admin cross-clinic access must be deliberate and audited.
- The API validates every rule. Hiding controls in React is not authorization.

## Delivery sequence

1. Database configuration, PDO connection, migrations, database health check, structured errors, correlation IDs.
2. Microsoft Entra validation, identity linking, role/resource policies, and audit middleware.
3. CRUD for locations, practitioners, services, rooms, capabilities, and scheduling rules.
4. Transactional availability and create/cancel/reschedule engine with concurrency tests.
5. Time-off impact processing, waitlists, notification worker, and human follow-up tasks.
6. Versioned forms, submissions, notes, and client export workflow.
7. Appointment completion, invoices, payments/refunds, receipts, and reporting.
8. QuickBooks sync, calendar sync, SMS, and later integrations.

## Deployment and migrations

- `database/schema.sql` is the clean-install snapshot; create numbered migrations before the first shared environment.
- The migration identity may change schema. The runtime API identity should have only required data permissions.
- Require TLS. In production, use private networking or narrowly scoped firewall access to MySQL.
- Store credentials outside Git (`.env` locally; managed secrets in hosting).
- Back up before production migrations and test restore procedures.
- Development seeds must never contain real client or clinical information.
