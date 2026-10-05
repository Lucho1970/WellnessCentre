# Wellness Centre portal user manual

This is the living, non-technical guide for staff using the Wellness Centre portal. Screens may be available only when the signed-in user has the required role or permission.

## Client appointments

After a linked client signs in, **My appointments** opens by default. The client can switch between **Upcoming**, **Past**, and **All appointments**, and can refresh the list. Each item shows the service, start and end time in the clinic location's timezone, status, practitioner, visit type or clinic location, and appointment number.

Choose **Book appointment** to select a visit type, service, practitioner and duration. For
an On-Site visit, review the saved profile address (or replace it for this appointment),
then validate the address and service radius. Choose a date, search current availability,
select a time and any required room, and review the price before confirming. A selected
time is not reserved until confirmation succeeds. Keep the appointment number for reference.
Use the same confirmation button to retry an uncertain
network result; do not start a second booking until the appointment list has been checked.

If a client selects a time on the public website before signing in, the portal keeps that service, practitioner, duration, and time in the same browser tab. After sign-in, it checks the time again and selects it if still available. For an On-Site visit, the client must first confirm the visit address and service-area coverage. No time is reserved until the client confirms the appointment.

For a current or past appointment, choose **Add to calendar** in **My appointments** to download its `.ics` file. Open the file with your preferred calendar and confirm the addition if prompted. Booking and change calendar files request reminders 24 hours and one hour before the appointment; check them after import because your calendar may override them. Booking, change, and cancellation emails also include a calendar update. Whether an email automatically appears in Google, Outlook, or another calendar depends on that provider and the recipient's settings. Canceled appointments cannot be added from the portal.

The client portal derives the client record from the signed-in account; a client cannot choose or request another client's record. Linked clients can book, review, reschedule, and cancel their own eligible upcoming appointments. The portal rechecks availability before a reschedule. Before cancellation, the portal shows the server-calculated fee, if any, from the policy accepted when the appointment was booked. Confirming records the assessed fee but does not itself collect payment.

After a duplicate client is merged, only the surviving client appears in client lists and booking searches. The merged record remains in protected history for audit purposes and is not deleted.

When staff book an appointment, they may select the service or the practitioner first after choosing the base location. Each choice narrows the other menu to compatible assignments; use **Clear service** or **Clear practitioner** to switch paths. For an On-Site visit, an earlier Google distance check by itself does not create a reusable approval. Authorized clinic staff must click **Approve this address for future On-Site bookings** after a successful check. Future bookings with the same client address, base location, service, practitioner, and service-area settings can reuse that approval without another Google distance request. Changing any of those details requires a new check and approval.

## Common administration pattern

Administration pages are moving to a list-first layout:

1. Use the command bar at the top to create a new record or act on the selected record.
2. Select a row in the list. The selected row is highlighted and actions such as **Details**, **Edit**, and **Assignments** become available.
3. **Details** opens a read-only panel. **Edit** opens the same information as editable fields.
4. Close or cancel a panel to return to the list. If information has changed but has not been saved, the portal asks before discarding it.

## Clients

The **Clients** page uses the list-first administration pattern. Use the search field in the command bar to find a client by name, email, or phone, then select the matching row. **Details** opens the complete contact profile and portal-access controls without making the fields editable. **Edit** opens the same client in an editable panel. Use **New client** to create a record.

Super Administrators can select an active duplicate and choose **Merge**. The merge remains a separate confirmation workflow because it reassigns related records. After a successful merge, the duplicate disappears from the list and the surviving client remains selected. Deactivate a client when future booking must be prevented without merging or deleting their history.

## Services

Open **Administration → Services** to manage the treatments or other services the clinic offers.

### View or change a service

1. Select the service in the list.
2. Choose **Details** to review its status, duration and price choices, room requirement, booking rules, buffers, and recurring-booking setting.
3. Choose **Edit** to change those settings, then choose **Save changes**.

Changes to a service affect future booking choices. Existing appointments retain their saved appointment details, price snapshots, and cancellation policy. A service cancellation policy can have no fee, a fixed fee, or a percentage fee inside its configured window.

Duration choices use one system-wide display rule: shortest to longest (for example, 60, 90, then 120 minutes). Set each duration and its price when editing the service; there is no separate duration display-order field. This order applies to the public catalogue and the staff and client booking selectors. On the public booking page, clients choose a duration before seeing times; services with multiple durations require an explicit choice.

### Create a service

1. Choose **New service**.
2. Enter its name and at least one duration and price.
3. Add other duration and price choices when clients can book different lengths of the same service.
4. Configure its booking limits, buffers, preparation instructions, room requirement, recurrence setting, and active status.
5. Choose **Add service**.

A new service is not bookable until its assignments are configured.

### Assign practitioners and locations

An assignment answers two questions: who provides this service, and from which base location or service area can it be booked?

1. Select the service in the Services list.
2. Choose **Assignments**.
3. Review **Current assignments**. This summary shows base locations, practitioners, In-Clinic/On-Site delivery modes, travel radius, travel time, and the On-Site fee.
4. Choose **Edit assignments** (or **Add assignment** when none exist).
5. Select at least one base location or service area and the practitioners who provide the service.
6. For each practitioner, enable **Clinic visits**, **On-Site visits**, or both. On-Site means the practitioner travels to the client’s location. On-Site visits also require a coverage radius; review the travel buffer and On-Site fee.
7. Choose **Save assignments**.

If the summary says the service has no current assignments, clients and staff cannot book it yet. Assigning only a location or only a practitioner is incomplete: a bookable service needs a compatible service, practitioner, location, delivery mode, working schedule, and—when applicable—room.

### Deactivate rather than delete

Use the service's **Active** setting to stop future bookings while preserving historical appointment records. Permanent deletion is intentionally not the normal workflow.

## Locations and rooms

The **Locations** and **Rooms** administration pages use the same list-first pattern as Services. Select a record to enable **Details** and **Edit**, or use **New location** and **New room** from the command bar. The details panel is read-only; choose **Edit** when a value must change.

Locations store the clinic or service-area name, address, timezone, phone number, and whether bookings are accepted. Rooms belong to a location and store the room type, equipment notes, turnover time, and booking status. A location must exist before a room can be created. Use **Capabilities** from the Rooms command bar to manage capability definitions and room/service capability assignments without cluttering the room list. Deactivate booking instead of removing records that may be referenced by appointment history.

## Practitioners

The **Practitioners** page is list-first. Select a practitioner to enable **Details** and **Edit**, or choose **New practitioner** to link a new Microsoft Entra staff identity. The details panel shows the practitioner’s availability status, discipline, credentials, active location, booking-management mode, and Microsoft sign-in email.

Create the Microsoft Entra account and assign the required practitioner application role before linking it in the portal. The immutable Entra tenant and object IDs are required only when creating the link. Editing the portal email does not rename or relink the Microsoft account. Use the two status controls carefully: the account status controls portal access, while **Available as a practitioner** controls whether the practitioner is available operationally.

### My appointment notifications

Open **My profile → Appointment notifications** to opt into email notices when an appointment is booked, changed, or canceled on your schedule. Choose the work sign-in email, a verified personal email, or both. To use a personal email, save it, request a verification code, enter the eight-digit code from that inbox, then select it as a destination and save again. Changing the personal address requires re-verification and never changes your Microsoft sign-in.

Practitioners can open **My notifications** in the Practitioner workspace to see booking notices addressed to them. Filter by status, Email/SMS, or activity date, and select a notice for its destination and provider-acceptance time. The page is read-only. “Accepted by provider” is not a delivery receipt; if a notice says **Needs review**, a clinic administrator must check it. The appointment link opens the practitioner-scoped appointment view, which may deny access if the appointment is no longer assigned to you.

For a client follow-up, open the assigned booking in **Appointments → More details**. The single-appointment details panel loads the client's contact email, phone, and preferred contact method. Click the email address to compose an email, the phone number to call, or **Text client** to compose an SMS in the device's default app. These links do not send through the clinic's notification queue or VoIP.ms account. This information is not included in the broader appointment-list response, and opening it is audited. A practitioner does not gain access to the clinic's general client directory by viewing an assigned booking.

**My clients** in the Practitioner workspace lists clients with an appointment assigned to you, plus clients you added for booking. Search the list, open a client for contact details and the latest appointment, or select **Book appointment** to start with that client already selected. The list is not a clinic-wide client directory. Booking is also available from the dashboard and calendar. In Operations, a selected client or service has a **Book appointment** action that carries that selection into the booking form.

You can save a Canadian mobile number and request SMS notices. The profile shows whether SMS delivery is active for the clinic; when active, opted-in practitioners receive appointment texts. Appointment emails include the time and a portal link, not client or visit details. Disabling email or changing an address before a queued notice is delivered cancels that pending staff notice.

### My calendar

Practitioners can open **My calendar** in their workspace to see their own appointments and time off by day, week, or month. Time off appears as a distinct **Time off** block; select it to see the reason, dates, and location. Use the arrows or **Today** to move between periods; **Refresh** reloads current schedule data. Select an appointment for its status, time, service, and base location. On-Site visits are labelled, but their destination address is not shown on the calendar. Use **Appointments** for the full booking workflow and authorized details.

The calendar remembers your last **Day**, **Week**, or **Month** choice for your signed-in account on this browser, including after closing and reopening it. It still opens at today's date. A different browser or device starts with the default view until you choose one there.

The calendar displays times in the device's timezone, which is shown above the grid. Turn on **Privacy mode** before sharing your screen to hide client names from calendar events and the detail panel. This is a display safeguard, not a change to account permissions. Google/Outlook connections for practitioners remain future features; this page does not put events into an external calendar.

When adding or editing **Time off** in Availability, choose **Review affected appointments** before saving. Review any overlapping bookings, then save the block if it is correct. Saving does not cancel, reschedule, or notify those clients. A follow-up count appears in the Time off list; select the item and open **Details** to see the current affected appointments. Choose **Review appointment #…** to open that exact booking, even when it is not on the first appointment-list page, then reschedule or cancel it using the permitted actions. The impact list updates as appointments are rescheduled or canceled.

The Practitioner dashboard also shows a warning while any upcoming, active appointments overlap your time off. It counts each appointment once, even if multiple time-off blocks overlap it, and links directly to the first five bookings. Use **Refresh** after resolving one; the warning disappears when no future overlaps remain. This is a follow-up aid, not an automatic cancellation or client notification.

### Travel to the next On-Site visit

The Practitioner dashboard shows the next confirmed or rescheduled On-Site appointment starting within 24 hours. Choose **Check from my location** to ask your device for location permission and calculate a driving estimate using current traffic, or **Check from clinic** if the clinic has a complete address. The check is manual; opening the dashboard does not request location or call Google Routes. The device coordinates are sent to the private API and Google only for that estimate, and are not saved in the clinic database or audit metadata. The clinic-origin option does not use device location. The card shows distance, approximate driving time, a suggested leave time with a 10-minute arrival margin, the time checked, and a Google Maps directions link. Traffic can change, so check again before leaving. The estimate does not alter the appointment or travel buffer reserved for scheduling. Only the signed-in practitioner can request this appointment's estimate. Google Maps Platform billing and Routes display requirements apply.

### Today's visits

The Practitioner dashboard lists your own appointments for the current day, in local clinic time. Each card has **Call client** when a usable phone number is on file, **View appointment**, and **Book next visit** with that client and service carried into the booking form. A phone link opens your device's dialer; it does not place a call through the clinic's VoIP.ms account.

The optional visit steps are **En route**, **Arrived**, **Started [service]**, **Finished [service]**, and **Left residence**. Travel/residence steps appear only on On-Site appointments; in-clinic visits have start and finish. Steps can be skipped, but cannot be recorded after a later active step. **Undo last step** records a correction without deleting the history. Each action is timestamped in UTC, scoped to the assigned practitioner, and auditable. These are manually entered workflow events, not proof of physical location or treatment. They do not automatically notify the client or change the booking status.

To review steps after the day-of card is gone, open the appointment in **Appointments → More details**. The practitioner-only **Visit step history** lists recorded and undone steps, newest first. It shows the latest 100 entries if an appointment has more; the complete event record remains in the database. This is an activity history, not clinical documentation or a safety-monitoring system.

Once the scheduled start has passed, use **Mark completed** or **Mark no-show** to close a confirmed appointment. Both require confirmation and update its booking status; a session already started cannot be marked no-show. A completed On-Site visit can still record **Left residence**. The booking continues to reserve its original time and buffers; closing it does not open the slot for a new booking. Pending reminders are canceled on closure. The dashboard shows today's visits, with milestone buttons enabled from four hours before each appointment. **Undo outcome** restores the prior confirmed/rescheduled status within two days after the scheduled end; it does not recreate reminders. All status corrections remain in appointment history. Do not use visit steps to store clinical notes.

If you missed the day-of outcome, open the visit from the **Past** appointments list and use **More details → Visit outcome**. The same completion, no-show, and eligible undo checks apply. This lets a practitioner close their own past visit without changing its original booking time or reserved buffers.

The Practitioner dashboard also has **Visit activity · last 7 days**. It counts the practitioner's own visits by scheduled date and current booking status: completed, no-show, or past visits still awaiting an outcome. It separately counts appointments with active optional steps and On-Site arrival/departure steps. Undone steps do not count as active. Use **Review visits awaiting outcome** to open the **Needs visit outcome** appointment filter; a visit leaves that list after you close it. These are operational counts, not a clinical or performance measure; a missing optional step does not prove that care was missed.

## Quick verification after changing a service

- Reopen **Assignments** and confirm the saved practitioner and location are listed.
- Start a test booking and confirm the service appears only for the expected practitioner, location, and delivery mode.
- Check each configured duration shows the correct price.
- Confirm an inactive service or a service without assignments cannot be booked.

## Practitioner website or social page

In the practitioner workspace, open Profile → Public practitioner card. Enter the full address in Website or social page URL, for example `https://www.facebook.com/yourbusiness`, and click Save public card. This is an optional public link: visitors can open it from your published card or profile. Clear it and save to remove the link. Administrators can maintain the same field under Team profiles. Your existing publication status is unchanged.
