# Travel enquiry unread counts and browser polling

The shared travel badge reads only `GET /api/v1/travel-enquiries/unread-count` for staff and `GET /api/v1/customer/travel-enquiries/unread-count` for clients. The response data contains only `{ "unread_count": 0 }` (or another non-negative integer), without patient names, visit areas, messages or thread IDs. Opening the inbox still loads the ordinary authorized list and messages.

## Authorization and queries

The count uses the same production scope, unread expression and consent lock as the inbox. It counts authorized threads containing unread valid messages, not individual messages. Staff administrative roles retain clinic scope; practitioners retain their own assigned scope; clients retain self-access. Named/global staff-access restrictions, merged client filtering, actor/profile status, sender identity and clinic boundaries remain enforced. Submitted clinic, practitioner, client and page query parameters cannot change the count's scope. Missing consent storage fails closed.

The count runs one aggregate query without selecting list/detail columns. It shares the MySQL 5.7-compatible unread subquery with the list. Staff and customer routes require their existing authentication, set `Cache-Control: no-store`, and retain access-read/denial auditing. The audit vocabulary includes `unread-count`; responses do not create individual patient/enquiry audit targets or copy the count into audit metadata.

## Browser behavior

- Fetch immediately on mount when visible. Defer initial work to a microtask so development StrictMode cleanup can cancel its first effect before sending a duplicate request.
- After success, schedule the next check in 60 seconds, measured from request completion. Never start another request while the current one is pending within the mounted poller.
- On error or malformed count, clear the badge and retry after 2, 4, 8, then at most 15 minutes. Success resets the interval to 60 seconds. An absent badge after an error does not establish that the inbox has no unread messages.
- Clear the timer and badge when the document becomes hidden. An already-sent request can finish, but its stale result is ignored.
- Refresh immediately on return to a visible tab. If an older request is still pending, perform one fresh request after it finishes. Old responses cannot overwrite the new visibility generation.
- Unmounting removes the listener and timer and prevents pending responses from updating state or scheduling new checks. This is per mounted badge/tab; there is no shared cross-tab leader or Azure push service.

## Deployment

This branch is based on the restored MySQL 5.7 correction in PR #13. Review/merge that correction together with this extension before building a new full release; the historical 21 October service-import API archive omits both changes.

Deploy the updated private API before the frontend. The API changes are in `src/Api.php`, `src/Service/TravelEnquiryService.php`, `src/Service/ConsentAccess.php` and `src/Service/AccessAudit.php`. Then deploy the common portal frontend for both clinic hostnames. Preserve private configuration/runtime data and follow the usual source-revision/checksum release workflow. No SQL migration or new dependency is required. The earlier one-file MySQL hotfix alone does not add these new endpoints.

Hosted acceptance: sign in separately as authorized staff and a linked client; observe only count requests while outside the inbox, with no patient detail fields in their responses. Hide the tab and verify periodic checks stop, then return and verify an immediate fresh check. Verify a synthetic consent withdrawal removes affected counts and denied access discloses no counts. Local tests do not replace hosted acceptance. No hosted deployment or real-patient access is performed by this change.

## Local verification

```powershell
./scripts/test-mysql57-sql.ps1 -MySqlDirectory C:/path/to/mysql-5.7.44-winx64
./scripts/test-recurring-sql.ps1 -MariaDbDirectory C:/path/to/mariadb -IntegrationTest travel-enquiry-inbox.php
./scripts/test-recurring-sql.ps1 -MariaDbDirectory C:/path/to/mariadb -IntegrationTest consent-travel-enquiries.php
cd Frontend
npm run build
npx playwright test tests/portal.spec.ts tests/customer.spec.ts --grep 'travel badge|travel inbox'
```

SQL runners use disposable localhost-only synthetic schemas. On Windows, the local PHP runtime may require `OPENSSL_CONF` pointing to its bundled `extras/ssl/openssl.cnf` for the synthetic HTTP signing keys; this is test-process configuration, not a hosted application setting.

Validation on 24 October 2026:

- MySQL 5.7.44: 28 inbox/count checks and the shared 74 booking authorization checks passed.
- MariaDB 11.4.8: 105 travel/message SQL/HTTP checks passed, along with the inherited booking, audit, consent, dashboard/schedule and notification suites.
- 13 focused staff/client browser checks passed, covering visibility, return refresh, non-overlapping pending requests, stale responses, unmount, bounded retry/reset, 401/403/404 badge cleanup and inbox behavior.
- All 53 standalone PHP test scripts and six changed-file PHP syntax checks passed. Frontend content/i18n validation, TypeScript and all three production builds passed; existing chunk-size notices remain.
- No hosted acceptance, deployment or database migration was performed.
