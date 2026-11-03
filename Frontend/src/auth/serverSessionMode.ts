let enabled = false;
export function serverSessionsEnabled() { return enabled; }

export async function loadServerSessionMode() {
  if (import.meta.env.VITE_SERVER_SESSIONS_ENABLED !== 'true') return;
  const base = new URL(import.meta.env.VITE_API_BASE_URL ?? '/api/v1', location.origin);
  if (base.origin !== location.origin) throw new Error('Secure sessions require this portal’s own API.');
  const response = await fetch(`${base.href.replace(/\/$/, '')}/auth/session/options`, { cache: 'no-store', credentials: 'same-origin', referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(10000) });
  const body: unknown = await response.json();
  if (!response.ok || !body || typeof body !== 'object' || !('data' in body) || !body.data || typeof body.data !== 'object'
    || !('enabled' in body.data) || typeof body.data.enabled !== 'boolean') throw new Error('Secure session configuration is unavailable.');
  enabled = body.data.enabled;
}

/** The existing registered callback URI can also host the supported popup bridge.
 * No app, tokens, account cache or runtime configuration is loaded in that frame. */
export async function returnPopupResponse(): Promise<boolean> {
  if (import.meta.env.VITE_SERVER_SESSIONS_ENABLED !== 'true' || (!window.opener && window.self === window.top)) return false;
  const query = new URLSearchParams(location.search); const fragment = new URLSearchParams(location.hash.slice(1));
  if (![query, fragment].some(p => p.has('state') && (p.has('code') || p.has('error')))) return false;
  const { broadcastResponseToMainFrame } = await import('@azure/msal-browser/redirect-bridge');
  await broadcastResponseToMainFrame(); return true;
}

/** Remove only this application's old identity/token indexes and known action state.
 * Token values are never read. Failure is explicit; there is no storage fallback. */
export function eraseLegacyIdentityCache(clientIds: string[]) {
  if (!enabled) return;
  for (const storage of [window.localStorage, window.sessionStorage]) {
    for (const id of clientIds.filter(Boolean)) {
      const index = `msal.token.keys.${id}`;
      const raw = storage.getItem(index);
      if (raw) {
        const parsed: unknown = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Unable to clear the previous sign-in cache.');
        for (const group of Object.values(parsed)) {
          if (!Array.isArray(group) || group.length > 2048 || !group.every(key => typeof key === 'string' && key.length <= 2048)) throw new Error('Unable to clear the previous sign-in cache.');
          for (const key of group) storage.removeItem(key);
        }
      }
    }
    const accounts = storage.getItem('msal.account.keys');
    if (accounts) {
      const keys: unknown = JSON.parse(accounts);
      if (!Array.isArray(keys) || keys.length > 2048 || !keys.every(key => typeof key === 'string')) throw new Error('Unable to clear the previous sign-in cache.');
      for (const key of keys) storage.removeItem(key);
    }
    for (let n = storage.length - 1; n >= 0; n--) {
      const key = storage.key(n);
      const preference = key && ['wellness.language', 'wellness.staff.provider', 'wellness.staff.application-return', 'wellness.staff.showCanceledAppointments', 'wellness.client.showCanceledAppointments'].includes(key);
      if (key && (key.startsWith('msal.') || clientIds.some(id => id && key.toLowerCase().includes(id.toLowerCase()))
        || (key.startsWith('wellness.') && !preference))) storage.removeItem(key);
    }
  }
}
