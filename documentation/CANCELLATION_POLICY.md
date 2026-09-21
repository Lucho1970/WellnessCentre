# Cancellation policy and fee deployment

This release adds cancellation-policy configuration, policy snapshots on appointments, fee previews, and audited administrator adjustments. It does not collect a payment or create an invoice.

## Deployment order

1. Back up the production database.
2. Run `api/database/migrations/015_appointment_cancellation_policy.sql` once.
3. Deploy the private API package.
4. Deploy the portal package.
5. In **Administration → Services**, edit each active service and review its cancellation window and fee.

The migration copies each active appointment's current service policy into the appointment. New bookings snapshot the policy automatically, so later service changes do not retroactively change an accepted appointment.

## Rules

- A cancellation outside the configured window has no fee.
- A fixed or percentage fee inside the window is calculated from the snapshotted treatment price plus any On-Site fee, and cannot exceed that total.
- A client canceling their own appointment receives the calculated fee preview before confirmation. The API recalculates it transactionally.
- A clinic-initiated staff cancellation is fee-free by default.
- Staff may identify a cancellation as client-requested. Reception can assess the calculated policy fee; a Super Admin or Clinic Admin can reduce or waive it.
- Reductions and waivers require a reason and create a `cancellation_adjustments` record. The final assessed amount is also stored on the appointment and status history.
- This release records an assessed amount only. It does not charge a card, generate an invoice line, or claim that payment was collected.

## Acceptance checks

1. Configure a service with a 1,440-minute window and a fixed fee.
2. Book an appointment more than 24 hours away and verify its cancellation preview is zero.
3. Book an appointment inside 24 hours and verify the client sees the fee before confirming cancellation.
4. Cancel from staff without selecting **requested by the client** and verify the assessed fee is zero.
5. Cancel a test appointment as client-requested and verify the calculated fee is stored.
6. As Super Admin, reduce the fee, provide a reason, and verify both `appointments.cancellation_fee_cents` and `cancellation_adjustments`.
7. Verify a practitioner or client cannot submit a fee override.

Useful verification query:

```sql
SELECT a.id,
       a.status,
       a.cancellation_window_minutes,
       a.cancellation_fee_type,
       a.cancellation_fee_value,
       a.cancellation_fee_cents,
       h.fee_triggered_cents,
       ca.original_fee_cents,
       ca.adjusted_fee_cents,
       ca.reason
FROM appointments a
LEFT JOIN appointment_status_history h
  ON h.appointment_id = a.id
 AND h.to_status IN ('canceled_by_client', 'canceled_by_clinic')
LEFT JOIN cancellation_adjustments ca ON ca.appointment_id = a.id
ORDER BY a.id DESC, h.id DESC, ca.id DESC
LIMIT 25;
```
