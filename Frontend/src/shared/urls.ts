import { runtimePublicWebsiteUrl } from './runtimeConfig';

function appUrl(configured: string | undefined, fallback: string) {
  const url = new URL(configured || fallback, window.location.origin);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
    throw new Error('Application URLs must be HTTP(S) base URLs without credentials, query or fragment.');
  }
  url.pathname = url.pathname.replace(/\/?$/, '/');
  return url;
}
export const publicUrl = appUrl(runtimePublicWebsiteUrl() ?? import.meta.env.VITE_PUBLIC_URL, import.meta.env.DEV ? 'http://localhost:5173/' : '/');
// The same portal build serves several clinics. Keep its links on the current
// clinic host and base path even when the public website targets another portal.
export const portalUrl = appUrl(__APP_SURFACE__ === 'portal'
  ? new URL(import.meta.env.BASE_URL, window.location.origin).href
  : import.meta.env.VITE_PORTAL_URL, import.meta.env.DEV ? 'http://localhost:5174/' : '/portal/');

function localizedLink(path: string, base: URL) {
  const url = new URL(path.replace(/^\/+/, ''), base);
  const language = document.documentElement.lang.toLowerCase().startsWith('fr') ? 'fr' : 'en';
  url.searchParams.set('lang', language);
  return url.href;
}

export const portalLink = (path = '') => localizedLink(path, portalUrl);
export const publicLink = (path = '') => localizedLink(path, publicUrl);
