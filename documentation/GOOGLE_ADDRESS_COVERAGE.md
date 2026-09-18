# Google address and mobile coverage validation

## Delivered behavior

Mobile staff/practitioner booking no longer relies on a person checking “I verify.” The
portal sends the selected base location, practitioner/service assignment and destination
to the private PHP API. The API:

1. verifies the actor can create the selected appointment;
2. confirms the base location and destination with Google Address Validation;
3. obtains driving distance from Google Routes;
4. compares that distance with `practitioner_services.mobile_radius_km`; and
5. returns a 15-minute signed proof bound to the actor, clinic, user-supplied destination,
   base location, practitioner and service.

Appointment creation verifies that proof again. Editing an address or changing any bound
booking choice invalidates it. Expired proofs must be renewed. Idempotent retrieval of an
appointment that was already created remains possible after expiry. Google API keys are
never sent to the React application.

Google receives only the two postal addresses needed to validate and route the trip. The
request does not include client name, email, phone, treatment, appointment time, access
instructions or clinical information. Coordinates are used transiently and are not stored.
The existing immutable appointment destination snapshot stores the user-supplied address;
Google's normalized response and coordinates are not persisted.

## Google Cloud setup

Use a billing-enabled Google Cloud project. Enable:

- Address Validation API
- Routes API

Create an API key dedicated to this server workflow. Under API restrictions, permit only
those two APIs. If Netfirms provides a stable outbound server IP, also apply an IP address
application restriction; otherwise retain the API restriction and monitor quotas/usage.
Do not use a browser-restricted key, and do not place this key in any `VITE_` setting.

Add these settings to the private API `.env` (the values in
`api/google-maps.env.example` are placeholders):

```dotenv
GOOGLE_MAPS_API_KEY=your-restricted-server-key
ADDRESS_VALIDATION_SIGNING_KEY=at-least-32-random-characters
ADDRESS_VALIDATION_TOKEN_TTL_SECONDS=900
```

Generate a signing key locally without posting it in chat or committing it:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The signing key is application-only and independent of the Google key. Preserve both
settings during deployment. Changing the signing key safely invalidates all outstanding
address proofs; users then validate again.

## Clinic configuration

Before validation can succeed:

- the selected base location needs street, city, province and postal code;
- the service must be active and assigned to that location;
- the practitioner/service assignment must offer mobile visits; and
- its coverage radius must be greater than zero.

The current rule compares the Google driving-route distance to the configured radius.
Travel buffer remains a separate fixed scheduling allowance before and after the visit;
distance validation does not yet calculate a dynamic travel-time buffer.

## Reusable address entry

The React `AddressEntry` control uses Google's current Place Autocomplete element with
Canada-only suggestions and automatic session handling. A separate browser key is limited
to approved HTTP referrers, Maps JavaScript API and Places API (New); it must never be the
private Address Validation/Routes key. The control fills structured fields but preserves
manual editing and a provider-outage fallback. It is used by mobile booking, staff client
create/edit, customer profile/onboarding and clinic location administration.

Google attribution remains visible beside Google-derived suggestions/validation results.
The public privacy notice and terms must disclose the Google Maps processing and link to
Google's applicable privacy policy and terms before production launch; business/privacy
review of the clinic's own wording remains required.

## Failure and acceptance behavior

The system fails closed when Google is unavailable: it does not restore a self-attestation
checkbox. Errors distinguish an unconfirmed address, no driving route, outside coverage,
missing radius, incomplete base address and unavailable provider. A future controlled
administrator override would require a reason and audit record; it must not be a client
self-attestation.

No SQL migration is required. Deploy the private API and portal together. Test one known
inside-radius address, one outside-radius address, an incomplete address, a practitioner
without a radius, an address edit after successful validation, proof expiry, and a final
mobile booking. Confirm clinic bookings remain unchanged.
