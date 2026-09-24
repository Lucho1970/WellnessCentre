# Staff appointment notifications

Staff sign-in identity (`users.email` and the Entra identity link) is separate from operational notification destinations. Each staff member can open **My profile → Appointment notifications** and opt into email notices for appointments assigned to them as a practitioner. They can use their work email, a verified personal email, or both. Creating, changing, or canceling an appointment queues minimal bilingual staff email alongside the existing client email. Staff email includes the appointment time and a staff-portal link, but no client name, address, service, notes, or calendar attachment. Existing client email behavior is unchanged.

## Deploy and test

1. Back up the database. Apply `api/database/migrations/018_staff_notification_preferences.sql` once in phpMyAdmin. This only creates a table; it does not opt anyone in or send old appointments.
2. Deploy the matching private API and portal frontend packages. Keep the existing private `.env`; no new credentials are needed for staff email because verification and appointment notices use the configured Microsoft Graph sender. Confirm `MAIL_ENABLED=true` only if the existing mail worker has already passed its normal tests.
3. Sign in as a practitioner. On **My profile**, save work-email notices. Create a *new* test booking assigned to that practitioner. Confirm the client email still arrives and the practitioner receives a separate staff email. Change and cancel test bookings and confirm the corresponding notices. No notice should be sent for a practitioner who has not opted in.
4. Save a personal email, request an eight-digit verification code, enter it within 15 minutes, then select personal or both addresses and save. Codes are rate-limited to one request per minute and five guesses. Change the personal email and confirm it becomes unverified again. Sign-in identity does not change.
5. Optionally save a North American mobile number and request SMS. The screen and API explicitly report **SMS delivery inactive**. No SMS event is queued or sent in this release, even when this preference is selected.

No historical appointments are backfilled. Queued staff emails are canceled by the worker if the staff member disables email, changes the destination, loses active access, or is no longer an active assigned practitioner before delivery. Replacing a personal email invalidates its verification. To stop new staff notices, staff can turn off email in their profile. To roll back code, first stop the mail worker and deploy the previous API/frontend; keep the new table for retained preferences and audit history. Do not drop it casually.

## VoIP.ms activation gate

The saved SMS request is **not** proof that delivery works. Before building/enabling the transport, verify with VoIP.ms that the account and sender DID may send this type of automated transactional SMS. Confirm the `sendSMS` method parameters in the account's API reference, enable API access using a dedicated API password, and allowlist the Netfirms server's **outbound** IP. Store API credentials only in the private Netfirms `.env`, never in Git, a browser bundle, query-string logs, or support chat. The provider and a safe worker path need a separate implementation and test release. Do not merely set a flag on this release expecting texts to flow.
