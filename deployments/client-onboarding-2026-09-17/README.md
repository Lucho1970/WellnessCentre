# Client onboarding — development deployment

Built from application source commit `d777703` on `codex/client-onboarding`.
This package is for **development acceptance**, not approval for real-client production use.
Onboarding is **disabled by default**. Existing sign-in and staff operations remain available.

## Included

- Explicit new-client registration with contact details and mobile-visit address.
- Staff-created invitation links, client acceptance, and separate staff verification/approval.
- Linked customer's own contact profile and read-only appointments.
- Server-enforced 30-minute idle / 8-hour absolute sessions with fresh signed login proof.
- Migration 005, no seeded records, and existing workforce/public features preserved.

Client appointment creation/cancellation/rescheduling, automatic invitation email,
multiple-provider linking, account merging/recovery and dependent booking are not included.

## Deploy in this order

1. Back up the database and private API `.env` and `var` directories.
2. In the existing database, run **sql-updates/005_customer_onboarding.sql**. Run whole
   SQL statements, not arbitrary line selections. Do not rerun migrations 001–004 if
   already applied. Do not run the base schema or seed-development.sql on this database.
3. Extract ZIP contents directly into these destinations (without an extra ZIP-named folder):

   | Archive | Destination |
   | --- | --- |
   | wellness-public.zip | `/public_html/wellness` |
   | wellness-portal.zip | `/public_html/wellness-portal` — includes its `/api` pointer |
   | wellness-api-private.zip | Account-root `/wellness-api` **outside public_html** |
   | wellness-api-public.zip | `/public_html/wellness/api` — two files is correct |

   Install the **complete matching vendor directory**, not selected vendor files.
   You can rename the existing private vendor folder as a temporary rollback backup,
   then upload the complete new one. Preserve existing `.env` and `var`; neither is
   included in these ZIPs. Public/portal `.htaccess` files must be uploaded too.
4. Add to the private `/wellness-api/.env`:

   ```dotenv
   CUSTOMER_ONBOARDING_ENABLED=false
   CUSTOMER_CLINIC_ID=YOUR_EXISTING_CLINIC_ID
   ```

   Find the correct ID with `SELECT id, name, status FROM clinics;`. Do not guess.
   Keep all existing CUSTOMER_ENTRA_* settings and the same-origin API pointers.
5. Refresh the public and portal pages, check staff sign-in, practitioner appointments
   and existing customer identity verification. With the flag false no customer record
   access is enabled and staff invitation controls report that onboarding is disabled.
6. For the development test, change `CUSTOMER_ONBOARDING_ENABLED=true`, then use
   **Sign in again** with a test customer. A previous cached login is insufficient.

## Hosted acceptance gate

The live External ID provider must issue a signed `auth_time`, matching object/tenant
claims and the requested nonce. `POST /api/v1/customer/auth/session` must succeed.
If it reports `fresh_sign_in_required`, turn the flag back off and investigate provider
configuration. Do not replace auth_time with token iat, remove signature/audience checks,
or copy tokens into chat/logs. Local tests cannot prove what the hosted provider emits.

Then test with synthetic development records:

1. New client registers, refreshes, edits their own profile and sees only their appointments.
2. Duplicate email fails without granting another record.
3. Staff: Clients → edit an existing active client → Client portal access → create invitation.
   Copy and manually send the link through an established contact channel; no email is sent.
4. Test customer accepts. No existing record is visible while pending.
5. Staff independently verify the client, enter the customer's review code and check the
   verification box, then approve. Customer clicks **Check approval status** to access their
   linked record. Expired/revoked links cannot be approved; replace or reject them.
6. Test logout and inactivity. Background requests must not keep the session alive.
7. Retest staff/practitioner permissions and booking after deployment. Validate on Netfirms
   MySQL 5.7 before using real client information.

## Verification performed locally

- 143 PHP unit/policy/JWT checks and PHP syntax checks passed.
- 44 database integration checks passed on isolated **MariaDB 10.11.14** with synthetic
  data, including concurrent registration, invitation acceptance and approval. This is
  not a claim of testing on the hosted MySQL 5.7 server.
- 31 browser tests (30-test regression run plus the additional staff approval test) passed.
- TypeScript and both release builds passed; three release-bundle browser smoke tests
  passed under locally intercepted production hostnames, without accessing live records.
- Two HTML-cache configuration tests passed. Archive hashes/entry counts and the
  extracted private Composer autoloader were verified. See manifest.json.

Optional maintenance SQL is in
`api/database/maintenance/purge_expired_customer_sessions.sql` in the repository.
Full design and activation guide: `documentation/CLIENT_ONBOARDING.md`.

Rollback: disable the flag, then restore matching prior code packages if needed.
Keep all additive tables, client records, links and audit data; do not drop them.
