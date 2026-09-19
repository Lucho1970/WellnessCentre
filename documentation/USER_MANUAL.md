# Wellness Centre portal user manual

This is the living, non-technical guide for staff using the Wellness Centre portal. Screens may be available only when the signed-in user has the required role or permission.

## Common administration pattern

Administration pages are moving to a list-first layout:

1. Use the command bar at the top to create a new record or act on the selected record.
2. Select a row in the list. The selected row is highlighted and actions such as **Details**, **Edit**, and **Assignments** become available.
3. **Details** opens a read-only panel. **Edit** opens the same information as editable fields.
4. Close or cancel a panel to return to the list. If information has changed but has not been saved, the portal asks before discarding it.

## Services

Open **Administration → Services** to manage the treatments or other services the clinic offers.

### View or change a service

1. Select the service in the list.
2. Choose **Details** to review its status, duration and price choices, room requirement, booking rules, buffers, and recurring-booking setting.
3. Choose **Edit** to change those settings, then choose **Save changes**.

Changes to a service affect future booking choices. Existing appointments retain their saved appointment details and price snapshots.

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
3. Review **Current assignments**. This summary shows base locations, practitioners, clinic/mobile delivery modes, travel radius, travel time, and mobile fee.
4. Choose **Edit assignments** (or **Add assignment** when none exist).
5. Select at least one base location or service area and the practitioners who provide the service.
6. For each practitioner, enable **Clinic visits**, **Mobile visits**, or both. Mobile visits also require a coverage radius; review the travel buffer and mobile fee.
7. Choose **Save assignments**.

If the summary says the service has no current assignments, clients and staff cannot book it yet. Assigning only a location or only a practitioner is incomplete: a bookable service needs a compatible service, practitioner, location, delivery mode, working schedule, and—when applicable—room.

### Deactivate rather than delete

Use the service's **Active** setting to stop future bookings while preserving historical appointment records. Permanent deletion is intentionally not the normal workflow.

## Quick verification after changing a service

- Reopen **Assignments** and confirm the saved practitioner and location are listed.
- Start a test booking and confirm the service appears only for the expected practitioner, location, and delivery mode.
- Check each configured duration shows the correct price.
- Confirm an inactive service or a service without assignments cannot be booked.

