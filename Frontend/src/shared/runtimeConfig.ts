let publicWebsiteUrl: string | undefined;
import { loadServerSessionMode } from '../auth/serverSessionMode';

export function runtimePublicWebsiteUrl() { return publicWebsiteUrl; }

export async function loadRuntimeConfig() {
  // Vite's development server does not serve PHP. Local development retains
  // its existing frontend env settings; deployed builds use the live private env.
  if (import.meta.env.DEV) { await loadServerSessionMode(); return; }
  const response = await fetch(`${import.meta.env.BASE_URL}api/runtime-config.php`, {
    cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(10000),
  });
  if (!response.ok) throw new Error('Unable to load website configuration.');
  const data = await response.json();
  if (typeof data.publicWebsiteUrl !== 'string') throw new Error('Invalid website configuration.');
  const url = new URL(data.publicWebsiteUrl);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash) {
    throw new Error('Invalid public website URL.');
  }
  publicWebsiteUrl = url.href;
  await loadServerSessionMode();
}
