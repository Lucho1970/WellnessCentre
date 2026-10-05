# Temporary Netfirms outbound IP diagnostic

Upload `api/tests/hosted/outbound-ip-test-web.php` as `outbound-ip-test.php` in the Willow portal's public `api` folder (alongside its API entry point). The script uses the same private `wellness-api` directory layout as the existing Netfirms entry point. It is not included automatically in normal deployment.

Add to the private `wellness-api/.env`:

```dotenv
OUTBOUND_IP_TEST_ENABLED=true
OUTBOUND_IP_TEST_SECRET=replace-with-a-random-secret-at-least-32-characters
```

Generate a secret privately with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Do not post it in chat. Open `https://willowwellness.copihue.ca/api/outbound-ip-test.php`, enter the secret in the password field, and click **Run one IP test**. Each click makes one IPv4 HTTPS request to ipify and one to AWS Check IP. The providers see the server's network IP; no application keys, client addresses or identity values are transmitted. TLS verification stays enabled.

Results show on the page and in the existing PHP error log:

```text
Outbound IP diagnostic run=... provider=ipify ip=66.96.183.123
Outbound IP diagnostic run=... provider=aws-checkip ip=66.96.183.124
```

Run several times, including at different times of day. Keep the distinct observed IPs and timestamps. Different destinations/workers may use different NAT addresses; these observations do not establish Google's exact source IP, a complete range, or stability. Do not infer an entire /24 from a few samples. Netfirms support should still confirm the stable outbound range when available.

After collecting results, set `OUTBOUND_IP_TEST_ENABLED=false`, remove the public PHP diagnostic file, and remove the temporary secret. This test does not change either Google key or the app's address-validation behavior.
