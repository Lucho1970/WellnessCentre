import { customerToken, selectCustomerAccount } from './auth';
import { apiErrorMessage, normalizeNumericIds } from '../shared/api';
import i18n from '../i18n';

const api = import.meta.env.VITE_API_BASE_URL ?? '/api/v1';
const key = 'wellness.customer.session.v1';
export const challengeKey = 'wellness.customer.challenge.v1';
export type SessionTimes = { idle_expires_at: number; absolute_expires_at: number };
type StoredSession = SessionTimes & { session_token: string; account: string };
export class CustomerRequestError extends Error {
  constructor(message: string, readonly status: number, readonly code: string) { super(message); }
}
export function clearCustomerSession() { sessionStorage.removeItem(key); }
export function savedSession(): StoredSession | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(key) ?? 'null');
    return value?.account === selectCustomerAccount()?.homeAccountId && typeof value.session_token === 'string' ? value : null;
  } catch { return null; }
}
export async function customerFetch(path: string, init: RequestInit = {}, authenticated = true) {
  const headers = new Headers(init.headers);
  headers.set('Content-Type', 'application/json');
  if (authenticated) {
    headers.set('Authorization', `Bearer ${await customerToken()}`);
    const session = savedSession();
    if (session) headers.set('X-Customer-Session', session.session_token);
  }
  const response = await fetch(`${api}/customer${path}`, { ...init, headers, credentials: 'omit', cache: 'no-store', signal: init.signal ?? AbortSignal.timeout(20000) });
  const body = await response.json().catch(() => null);
  if (!response.ok) {
    if (response.status === 401) { clearCustomerSession(); window.dispatchEvent(new Event('customer-session-ended')); }
    throw new CustomerRequestError(apiErrorMessage(body, response.status, i18n.t('The client service is unavailable. Please retry.')), response.status, body?.error?.code ?? 'request_failed');
  }
  if (!body?.data) throw new Error(i18n.t('The client service returned an unexpected response.'));
  return normalizeNumericIds(body.data);
}
export async function beginCustomerLogin(): Promise<string | null> {
  const options = await customerFetch('/auth/options', {}, false);
  if (!options.onboarding_enabled) return null;
  if (savedSession()) await endCustomerSession();
  clearCustomerSession();
  const challenge = await customerFetch('/auth/challenge', { method: 'POST', body: '{}' }, false);
  if (!/^[a-f0-9]{64}$/.test(challenge.nonce)) throw new Error(i18n.t('Unable to start a secure sign-in.'));
  sessionStorage.setItem(challengeKey, 'pending');
  return challenge.nonce;
}
export async function finishCustomerLogin(idToken: string, accessToken: string) {
  if (!sessionStorage.getItem(challengeKey)) return;
  sessionStorage.removeItem(challengeKey);
  const data = await customerFetch('/auth/session', { method: 'POST', headers: { Authorization: `Bearer ${accessToken}` }, body: JSON.stringify({ id_token: idToken }) }, false);
  sessionStorage.setItem(key, JSON.stringify({ ...data, account: selectCustomerAccount()?.homeAccountId }));
}
export function updateSessionTimes(times: SessionTimes) {
  const current = savedSession();
  if (current) sessionStorage.setItem(key, JSON.stringify({ ...current, ...times }));
}
export async function endCustomerSession() {
  const session = savedSession();
  if (session) await customerFetch('/auth/logout', { method: 'POST', headers: { 'X-Customer-Session': session.session_token }, body: '{}' }, false);
  clearCustomerSession();
}
