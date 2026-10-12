# Trusted clinic routing

Implemented on `feature/trusted-clinic-routing` as the next MT0 implementation slice. This replaces first-active-clinic selection in public catalogue, branding and availability with an explicit server-owned host mapping. It is a prerequisite for memberships, not approval to enable another real clinic.

## Configuration and deployment

Set `CLINIC_HOST_MAP` in the private `wellness-api/.env` **before uploading this API version**. It is a JSON object whose keys are exact request authorities and whose values are positive integer database clinic IDs. Verify the existing Willow ID against the live `clinics` table and `CUSTOMER_CLINIC_ID`; do not assume it is 1.

Example, only if the verified Willow ID is 1:

```dotenv
CLINIC_HOST_MAP={"livinlively.com":1,"www.livinlively.com":1,"livinlively.ca":1,"www.livinlively.ca":1,"willowwellness.copihue.ca":1,"localhost:8080":1}
```

Include every hostname that actually serves the clinic API. Ports are part of the authority, so local development must use the API server's port. Hostnames are case insensitive and a trailing dot is normalized. Wildcards, URLs with schemes or paths, forwarded host headers and browser-supplied clinic IDs cannot select a clinic. Leave the generic standalone `portal.copihue.ca` landing out of Willow's mapping unless it intentionally serves Willow's API.

Missing or invalid mappings fail closed; unknown hosts and inactive clinics return 404. Health endpoints and CORS preflight remain available without clinic routing. CORS configuration remains a separate origin policy.

Deploy the changed API classes together: `Config`, `ClinicContext`, `Api`, `Service/CatalogService`, `Service/AvailabilityService`, and `Service/CustomerOnboarding`. Update the portal's `share-preview.php` from `hosting/netfirms/portal/` as well; share metadata uses the validated request host. No SQL migration or frontend rebuild is required for this slice. Preserve the prior source version and private configuration for rollback.

## Current boundaries

- Public service, practitioner, team, location, image and branding reads use the selected clinic ID; public availability validates against that clinic.
- Existing Entra workforce authentication remains in place. Authenticated staff must belong to the host's clinic before a protected handler runs.
- Customer onboarding and session-backed actions remain restricted to `CUSTOMER_CLINIC_ID`. A synthetic second clinic reports onboarding disabled and cannot use Willow's legacy customer links/sessions.
- `PUBLIC_WEBSITE_URL`, identity-provider settings, mail/SMS settings and other runtime configuration remain global. This change does not provision independent clinics or provide complete multi-clinic isolation for workers, caches and every operational relationship.

## Validation and acceptance

All 39 top-level PHP test scripts pass, including 114 routing checks and 15 HTTP preflight/response checks. Changed PHP files pass syntax checks. Routing tests cover two synthetic host mappings, invalid/unknown/inactive hosts, spoofed proxy/query context, staff mismatch, customer compatibility gating, catalogue SQL scopes and public availability parameters.

The routing test uses a PDO double; it does not prove isolation against a live MySQL database. Before deployment acceptance, use synthetic records in a backed-up test database to confirm each host returns only its own catalogue and availability, wrong-clinic staff are denied, Willow sign-in still works, share metadata uses the correct host, and unmapped hosts fail closed. Do not enable a second real clinic before MT1/MT2 identity and isolation gates pass.

## Next development step

Design and implement additive global identity and clinic-membership tables plus the authentication adapter interface. Reconcile the hosted schema and existing links first; preserve legacy user IDs and Entra login, report conflicts without automatically merging accounts by email, and keep new membership/invitation behavior behind a disabled gate.
