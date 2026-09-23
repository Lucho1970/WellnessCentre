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

For a current or past appointment, choose **Add to calendar** in **My appointments** to download its `.ics` file. Open the file with your preferred calendar and confirm the addition if prompted. Booking, change, and cancellation emails also include a calendar update. Whether an email automatically appears in Google, Outlook, or another calendar depends on that provider and the recipient's settings. Canceled appointments cannot be added from the portal.

The client portal derives the client record from the signed-in account; a client cannot choose or request another client's record. Linked clients can book, review, reschedule, and cancel their own eligible upcoming appointments. The portal rechecks availability before a reschedule. Before cancellation, the portal shows the server-calculated fee, if any, from the policy accepted when the appointment was booked. Confirming records the assessed fee but does not itself collect payment.

After a duplicate client is merged, only the surviving client appears in client lists and booking searches. The merged record remains in protected history for audit purposes and is not deleted.

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

### My calendar

Practitioners can open **My calendar** in their workspace to see their own clinic appointments by day, week, or month. Use the arrows or **Today** to move between periods; **Refresh** reloads current booking data. Select an event for its status, time, service, and base location. On-Site visits are labelled, but their destination address is not shown on the calendar. Use **Appointments** for the full booking workflow and authorized details.

The calendar displays times in the device's timezone, which is shown above the grid. Turn on **Privacy mode** before sharing your screen to hide client names from calendar events and the detail panel. This is a display safeguard, not a change to account permissions. Google/Outlook connections for practitioners remain future features; this page does not put events into an external calendar.

## Quick verification after changing a service

- Reopen **Assignments** and confirm the saved practitioner and location are listed.
- Start a test booking and confirm the service appears only for the expected practitioner, location, and delivery mode.
- Check each configured duration shows the correct price.
- Confirm an inactive service or a service without assignments cannot be booked.
