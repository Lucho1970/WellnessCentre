"""Check local web/API routing without printing synthetic database records."""

import urllib.error
import urllib.request


CASES = (
    ("portal shell", 18080, "/", 200, "text/html"),
    ("practitioner entry", 18080, "/staff/external", 200, "text/html"),
    ("Wellnexa shell", 18081, "/", 200, "text/html"),
    ("central Admin shell", 18082, "/admin/", 200, "text/html"),
    ("public shell", 18083, "/", 200, "text/html"),
    ("runtime configuration", 18080, "/api/runtime-config.php", 200, "application/json"),
    ("API health", 18080, "/api/v1/health", 200, "application/json"),
    ("database health", 18080, "/api/v1/health/database", 200, "application/json"),
    ("Wellnexa clinic configuration", 18080, "/api/v1/site-config", 200, "application/json"),
    ("Livin Lively clinic configuration", 18083, "/api/v1/site-config", 200, "application/json"),
    ("Wellnexa services", 18081, "/api/public/services", 200, "application/json"),
    ("Wellnexa practitioners", 18081, "/api/public/practitioners", 200, "application/json"),
    ("protected calendar denial", 18080, "/api/v1/practitioner/calendar", 401, "application/json"),
    ("unmapped Admin host denial", 18082, "/api/v1/site-config", 404, "application/json"),
)

failures = 0
for name, port, path, expected_status, expected_type in CASES:
    request = urllib.request.Request(
        f"http://127.0.0.1:{port}{path}",
        headers={"Host": f"localhost:{port}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            status = response.status
            content_type = response.headers.get("Content-Type", "").split(";")[0]
    except urllib.error.HTTPError as error:
        status = error.code
        content_type = error.headers.get("Content-Type", "").split(";")[0]
    passed = status == expected_status and content_type == expected_type
    print(f"{name}: {status} {content_type} {'PASS' if passed else 'FAIL'}")
    failures += not passed

raise SystemExit(bool(failures))
