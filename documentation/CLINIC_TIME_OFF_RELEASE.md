# Clinic-specific practitioner time off

Time off applies to the clinic where the practitioner records it, across that clinic's locations. It does not remove their availability in another clinic. Practitioners who want a full vacation record time off separately in every clinic where they work.

Actual appointments continue to block overlapping bookings across the practitioner's linked clinic identities. Explicitly blocking imported calendar events also retain their existing shared busy-time behavior. Location-specific schedule overrides remain local. Calendars, time-off editing, impact reviews and dashboard follow-ups already use the current clinic's records.

## Deployment

The focused `wellness-api-time-off-patch.zip` contains only `src/Service/AvailabilityService.php`. Back up that file in the existing private `wellness-api/` folder, then extract the ZIP contents directly into that folder, preserving its `src/Service/` layout. Do not upload it to the wellnexa.ca marketing website or the portal's public `api/` folder. Preserve the private .env, vendor files, and other application files.

No database migration, portal upload or environment setting is required for this patch. It can be applied to the currently deployed shared API without installing the separate distance-fee release. If hosting retains old PHP code, reset PHP OPcache through the hosting control panel.

The shared API serves all clinics: the revised rule takes effect for each clinic. Existing time-off rows are not deleted, moved or copied. For Esther, the time-off record on practitioner #1 continues to block that original clinic; it no longer blocks her Wellnexa practitioner #8. That does not guarantee a slot if another appointment or block occupies the time.

## Hosted acceptance

1. Refresh the On-Site booking page for Wellnexa's Esther and Deep Tissue Massage (location 8, service 13, practitioner 8). Check the remaining times today, allowing the 15-minute notice and service/travel buffers. Earlier days cannot be booked retroactively.
2. Confirm that the original clinic still honors its existing time off.
3. For a controlled test, add time off in clinic A and verify clinic B still offers an otherwise free time. Repeat in the other direction. No new test appointment needs to be confirmed in production merely to check availability.
4. Verify that a genuinely booked session at one clinic still removes overlapping times in the other. Do not delete real appointments or time-off records to perform acceptance.

Local regression checks use synthetic two-clinic identities and validate availability and actual booking confirmations, including transaction-locked reads. Hosted acceptance remains separate. The release manifest identifies the source commit and the hashes of the patch and patched PHP file.

To roll back, restore the backed-up AvailabilityService.php. This restores the previous shared time-off policy; there is no database rollback.
