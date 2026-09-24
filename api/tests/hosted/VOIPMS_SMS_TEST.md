# One-time VoIP.ms SMS API test on Netfirms

This diagnostic sends **one** non-clinical SMS from DID `2892975234` to a
Canadian mobile phone you control. US numbers are blocked. It does not enable appointment SMS. VoIP.ms API
acceptance does not prove carrier delivery or A2P approval. If VoIP.ms tells
you not to use automated API messaging until verification, wait for approval.

1. Deploy the current `wellness-api-private.zip` first. The diagnostic uses
   its `Wellness\Service\VoipMsSmsClient` class. No database migration is needed.
2. In the existing **private** `/wellness-api/.env`, add or check:

   ```ini
   SMS_ENABLED=false
   VOIPMS_API_USERNAME=your-voipms-account-login-email
   VOIPMS_API_PASSWORD=your-existing-dedicated-api-password
   VOIPMS_FROM_DID=2892975234
   SMS_TEST_ENABLED=true
   SMS_TEST_SECRET=generate-a-new-random-secret-at-least-32-characters
   SMS_TEST_TO=+1your-own-Canadian-mobile-number
   ```

   Do not put credentials in a public directory, URL, Git, or chat. The API
   password is **not** the portal login password. If the existing API password
   is also used by another site, do not reset it without updating that site.
   On a local PHP installation, `php -r "echo bin2hex(random_bytes(32));"`
   generates a suitable temporary test secret.
3. Copy `voipms-sms-test-web.php` from this directory to exactly
   `/public_html/wellness/api/sms-test.php`. This is a temporary manual copy;
   the diagnostic is not included in normal deployment archives.
4. Browse to `https://wellness.copihue.ca/api/sms-test.php`. Check that the
   displayed last four digits match your receiving mobile. Enter the temporary
   **test secret**, confirm that you control the number, and submit once.
5. An “accepted” page means VoIP.ms returned API success. Confirm the SMS
   actually arrives on your phone and, if necessary, check its message history
   in VoIP.ms. A failed or timed-out request is **not** retried automatically:
   its delivery outcome may be uncertain.
6. Immediately remove `/public_html/wellness/api/sms-test.php` and set
   `SMS_TEST_ENABLED=false`. The private `/wellness-api/.sms-test-once` marker
   records that an attempt happened. Do not remove it to retry until you have
   checked that the first attempt was not delivered.

Leave `SMS_ENABLED=false` until the clinic deliberately activates staff SMS
after checking applicable VoIP.ms account conditions. The test does not require
turning it on.
