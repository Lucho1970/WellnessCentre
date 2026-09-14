# Netfirms API deployment

This deployment targets:

```text
/wellness-api/
/public_html/wellness/api/
```

## Private application

Extract `wellness-api-private.zip` into `/wellness-api`. The result must contain:

```text
/wellness-api/composer.json
/wellness-api/composer.lock
/wellness-api/src/
/wellness-api/vendor/
/wellness-api/bin/
```

Keep the production `.env` at `/wellness-api/.env`. The archive intentionally does not contain this file, so extracting an update cannot overwrite production secrets.

## Public API front controller

Extract `wellness-api-public.zip` into `/public_html/wellness/api`. The result must contain only:

```text
/public_html/wellness/api/index.php
/public_html/wellness/api/.htaccess
```

The public front controller resolves the private application relative to this exact directory structure.

After extraction, verify that directories use permissions `755` and files use `644`. If an extracted PHP file produces a generic server error, recreate it through Netfirms File Manager so the hosting platform assigns the correct metadata.

## Smoke test

After both archives are uploaded, request:

```text
https://wellness.copihue.ca/api/v1/health
```

The expected response is JSON with `data.status` equal to `ok`.
