# Limited external testing readiness

This is a **development pilot**, not production launch approval. The deployed public site, portal, and API support testing with approved synthetic or otherwise authorized records. Do not ask testers to enter sensitive clinical histories, payment details, or information that depends on unfinished forms, notes, billing, or messaging workflows.

## Before inviting testers

1. Record the deployed package manifest and verify that the private API, public site, and portal point to the same release. Confirm the site's HTTPS, branding, client sign-in, and staff sign-in in a fresh browser session.
2. Back up the development database. Confirm test practitioner assignments, working hours, service durations/prices, travel radius, booking horizon, and cancellation policy. Use test-only clients and appointments where possible.
3. Complete the hosted MySQL booking race acceptance in [Booking validation tests](BOOKING_VALIDATION_TESTS.md). Record database/server versions and the result; do not treat local browser mocks as this test.
4. Confirm the mail scheduler is healthy, a test confirmation arrives, and the Email status page shows no unresolved `needs_review` events. `sent` means accepted by Graph, not necessarily delivered. Verify a change/cancellation email and its calendar attachment with a test mailbox.
5. Create a short feedback channel outside the app. Ask testers to report the page, time, steps, expected versus actual result, and any on-screen correlation/reference ID. Do **not** ask them to send passwords, tokens, clinical details, or screenshots showing another person's data.

## Suggested pilot journeys

| Tester | Journey | Expected result |
| --- | --- | --- |
| New client | Sign in, register, review profile, find and book a time | Only their own data appears; price, visit type and appointment number are clear. |
| Returning client | Sign in, view, reschedule, cancel, download calendar file | Their own appointment changes are reflected in the portal and email. |
| Practitioner | Sign in, view day/week/month calendar, manage own availability and booking | Only authorized schedule and client details appear; privacy mode hides names when screen sharing. |
| Super Admin/reception | Manage service assignments and book for a test client | Duration/price choices, practitioner, base location and room constraints are enforced. |

For each journey, check a narrow/mobile viewport, refresh, sign-out/sign-in, expired session behavior, and one failed or canceled action. Record failures with correlation IDs before changing data; this makes API log review possible.

## Known scope limits

Forms/clinical notes, secure messaging, waitlists, invoicing/payments, external practitioner calendar synchronization, and full launch/security/accessibility sign-off are not complete. Calendar `.ics` attachments may require recipients to accept or import the event. Do not present these modules as available to pilot users. Keep the pilot small and supervised until booking concurrency, mail, and role boundaries are verified on the hosted system.
