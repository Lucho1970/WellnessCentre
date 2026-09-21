# Configurable Business Branding

Super Administrators can manage the operating name, contact details, business logo and browser favicon from **Administration → Business settings**.

## Asset roles

- **Business logo:** may be a wider transparent image and appears in the public and staff portal headers. The upload control converts it to a bounded WebP image before upload.
- **Favicon:** should be a simple square symbol that remains recognizable at 16–32 pixels. The upload control centre-crops it to a 128×128 PNG. It is separate from the logo because a detailed or wide logo usually becomes unreadable in a browser tab.
- **Fallback:** when either asset is absent, the application uses the built-in wellness icon. A clinic does not need to supply a favicon before using the application.

Only PNG and WebP output is accepted by the API. SVG uploads are not stored or served, avoiding active SVG content. Server-side byte, MIME and dimension checks remain authoritative even though the browser normalizes source images.

## Storage and delivery

Migration `011_clinic_brand_assets.sql` adds one `logo` and one `favicon` record per clinic. Assets are stored in the private application database and delivered through cacheable public endpoints:

- `GET /api/v1/brand/logo`
- `GET /api/v1/brand/favicon`

The public site and portal share those endpoints and content-hash versions. Upload and deletion endpoints require Super Administrator authorization and write audit events. Configuration responses expose only content hashes, not image data or private storage details.

## Deployment

1. Back up the database and run `api/database/migrations/011_clinic_brand_assets.sql` once.
2. Deploy the updated private API and both frontend builds.
3. Hard-refresh the public site and portal after deployment.
4. Upload the logo and favicon under Business settings.
5. Verify the public header, portal header, browser tab icon, fallback after deletion, English/French administration text, and mobile header sizing.

