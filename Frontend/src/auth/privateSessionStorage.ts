import { serverSessionsEnabled } from './serverSessionMode';

const memory = new Map<string, string>();
const preferences = new Set(['wellness.staff.provider', 'wellness.staff.application-return']);
/** Pending action links, booking choices and account/session state remain in page
 * memory in the new mode. Legacy mode retains its existing behavior. */
export const privateSessionStorage = {
  getItem(key: string) { return serverSessionsEnabled() && !preferences.has(key) ? memory.get(key) ?? null : sessionStorage.getItem(key); },
  setItem(key: string, value: string) { if (serverSessionsEnabled() && !preferences.has(key)) memory.set(key, value); else sessionStorage.setItem(key, value); },
  removeItem(key: string) { memory.delete(key); if (!serverSessionsEnabled() || preferences.has(key)) sessionStorage.removeItem(key); },
};
export function clearPrivateSessionStorage() { memory.clear(); }
