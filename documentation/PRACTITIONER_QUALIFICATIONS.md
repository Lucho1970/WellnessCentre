# Practitioner qualifications — first increment

Migration `026_practitioner_qualifications.sql` adds clinic-scoped qualification types and append-only practitioner submissions. Apply it before deploying the API and portal archives.

In **Operations → Practitioners → Details**, an administrator adds a qualification type (with or without a required expiry), submits a record for a practitioner, and reviews pending records. A practitioner submits their own records or renewals from **My profile**. Changes to an existing verified record are not permitted: a renewal is a new pending record, preserving the old review history. Administrators can reject pending records or revoke verified ones; those decisions require a note. The submitting user cannot verify their own record. Every submission, review, and type creation is audit logged.

The practitioner's dashboard and profile show an in-app warning during the 90 days before a verified qualification expires, and afterward. These are **in-app warnings only**: no qualification reminder email or SMS is sent in this increment. A clinic administrator should review evidence before marking a record verified; the system does not independently confirm an issuer. Records are private to the clinic and are not shown on public practitioner pages.

This increment does **not** alter practitioner/service eligibility or booking. The next increment can map services and client needs to qualification types, then enforce that only current, verified, non-revoked records satisfy a requirement. Existing free-text `practitioners.credentials` remains display-only.

Pilot checks:

1. Admin creates a qualification type and confirms it appears on one practitioner's submission form.
2. Practitioner submits a record. It remains pending; the practitioner has no review controls.
3. A different admin verifies it; the practitioner sees the verified record. A self-submitted record cannot be self-verified.
4. Submit a renewal; verify that the prior record remains and the renewal is pending.
5. Verify a record expiring within 90 days and check the profile/dashboard warning.
6. Reject/revoke with a note and confirm the status is visible. Attempt to access another clinic's practitioner or record and expect 404.
