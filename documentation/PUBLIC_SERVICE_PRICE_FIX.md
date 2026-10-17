# Public services starting-price correction — 17 October 2026

After the discovery release, the hosted Wellnexa public services endpoint returned HTTP 500 and the marketing bridge consequently returned HTTP 503 (`Public catalogue is temporarily unavailable`). The practitioner endpoint still returned HTTP 200, including social links. The hosted SQL exception is not exposed publicly; its exact text was not available during local diagnosis.

The correction removes the newly introduced correlated `MIN` inside `JSON_ARRAYAGG`. Service descriptions and duration defaults use the previous service query, and a separate ordinary grouped query calculates published practitioners' minimum prices. Those prices are overlaid by service slug and duration. This avoids the nested aggregate dependency while preserving practitioner overrides, per-duration fallback prices and clinic isolation. A zero price remains valid.

## Deploy this patch after the discovery release

Extract `wellness-api-catalog-fix.zip` into **`/wellness-api/`**, merging folders and replacing only:

```text
/wellness-api/src/Service/CatalogService.php
```

No database migration, website build, shared portal build or environment change is needed. Do not copy this file into the marketing site's `api/` bridge folder. The original discovery ZIPs remain historical; apply this correction after them, or build a newer release from the corrected source. A generated manifest records the source commit and package hash.

## Verify after uploading

1. `https://wellnexa.copihue.ca/api/v1/public/services` should return HTTP 200 with `data` containing published services.
2. `https://wellnexa.ca/api/public/services` should then return HTTP 200.
3. Refresh the website or click **Try again**; check service details, duration prices and practitioner-specific booking links.

If the direct portal API still fails, use its new correlation ID to locate the exception in the private API log. Local database validation cannot substitute for the operator's hosted acceptance.

The real SQL regression runs the public catalogue with `ONLY_FULL_GROUP_BY`, covering practitioner prices below/above service defaults, unpublished practitioner fallback, an explicit zero override, multiple durations and an empty second clinic. Existing profile/social-link and booking checks run alongside it. PHP lint and the existing catalogue duration-order test also apply.
