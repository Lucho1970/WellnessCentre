# Temporary Netfirms preflight probe

Upload `preflight-probe.php` to `/public_html/wellness/api/preflight-probe.php`.
This is a new diagnostic file, not a replacement application file. It loads no
dependencies or secrets, accesses no database, and makes no changes to data.
It allows only the exact portal origin to read its constant response.

Open https://wellness.copihue.ca/api/preflight-probe.php and confirm it displays
`Standalone PHP probe reached.` Then notify the developer to test OPTIONS with
both the default 200 response and `?status=204`.

Interpretation:

- GET 200 with marker header, but OPTIONS 500 without marker: evidence of a hosting
  request-handling problem independent of the application. Hosting support may
  need to inspect proxy/web-server/PHP-handler logs.
- OPTIONS 200 works but OPTIONS 204 fails: investigate hosting handling of 204.
- Both work: investigate application bootstrap/routing or stale deployed code.

Marker: `X-Wellness-Preflight-Probe: standalone-v1`. These results isolate the
failure; they do not alone identify the exact faulty hosting component.

Delete this diagnostic file from the server after testing. No SQL, configuration,
vendor or frontend update is needed.
