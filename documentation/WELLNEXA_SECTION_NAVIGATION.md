# Wellnexa section navigation

The website menu highlights Services, Practitioners or FAQ as the visitor scrolls. The highlight follows the section passing below the sticky header, clears above Services, and selects FAQ at the bottom of the page. Scrolling does not rewrite the URL or add browser history entries. Detail pages highlight their parent menu; Join and Contact highlight their own links. Current links expose `aria-current` for assistive technology.

The anchor offset now follows the actual header height plus 8px, replacing the fixed 11rem offset. A ResizeObserver handles menu wrapping and viewport changes. Direct section links stay aligned while the initial catalogue loads; user interaction releases that initial alignment. Existing smooth scrolling and the reduced-motion preference remain supported.

## Deployment

Build from `Wellnexa` with `npm run build`, using `VITE_PORTAL_URL=https://wellnexa.copihue.ca/`. Package the **contents** of `Wellnexa/dist`, including `.htaccess` and `api/.htaccess`, as `wellnexa-website.zip`. Record the source commit and SHA-256 in the release manifest.

Extract the archive contents into `/public_html/wellnexa.ca/`, replacing matching files. Retain the existing server configuration and any unrelated files. Refresh the browser after upload. This change requires no database migration, private API patch or shared portal update. The website build includes the existing FAQ and enquiry feature; its separate migration 047 and API rollout remain prerequisites for receiving enquiries.

## Validation

- Website TypeScript/Vite production build passed.
- All 20 website browser tests passed, including desktop/mobile alignment, wrapped-header resize, manual scrolling in both directions, parent page highlighting, delayed catalogue loading and reduced motion.
- Desktop/mobile screenshots reviewed locally.
- Website lint completed with four existing warnings in App.tsx and catalogue/Details.tsx; no new warnings.
- Hosted upload and acceptance remain operator checks: click Services, scroll to Practitioners and FAQ, then scroll back up. Each current menu item should be violet and underlined; Services should begin 8px below the menu.
