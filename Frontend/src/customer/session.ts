import { sessionFetch } from '../auth/serverSession';
import { privateSessionStorage } from '../auth/privateSessionStorage';
import { customerToken, selectCustomerAccount } from './auth';
import { apiErrorMessage, normalizeNumericIds, validationFields } from '../shared/api';
import i18n from '../i18n';
import { serverSessionsEnabled } from '../auth/serverSessionMode';
import { sessionSnapshot, signOutServerSession } from '../auth/serverSession';

const api = import.meta.env.VITE_API_BASE_URL ?? '/api/v1';
const key = 'wellness.customer.session.v1';
export const challengeKey = 'wellness.customer.challenge.v1';
export type SessionTimes = { idle_expires_at: number; absolute_expires_at: number };
type StoredSession = SessionTimes & { session_token?: string; account: string };
export class CustomerRequestError extends Error {
  readonly fields: Record<string, string>;
  constructor(message: string, readonly status: number, readonly code: string, fields?: unknown) { super(message); this.fields = validationFields(fields); }
}
export function clearCustomerSession() { privateSessionStorage.removeItem(key); }
export function savedSession(): StoredSession | null {
  if(serverSessionsEnabled()){const state=sessionSnapshot();const account=selectCustomerAccount()?.homeAccountId;return state.state==='active' && state.lease && account ? {...state.lease,account}:null;}
  try {
    const value = JSON.parse(privateSessionStorage.getItem(key) ?? 'null');
    return value?.account === selectCustomerAccount()?.homeAccountId && typeof value.session_token === 'string' ? value : null;
  } catch { return null; }
}
export async function customerFetch(path: string, init: RequestInit = {}, authenticated = true) {
  const headers = new Headers(init.headers);
  headers.set('Content-Type', 'application/json');
  if (authenticated) {
    headers.set('Authorization', `Bearer ${await customerToken()}`);
    const session = savedSession();
    if (session?.session_token) headers.set('X-Customer-Session', session.session_token);
  }
  const response = await sessionFetch(`${api}/customer${path}`, { ...init, headers, credentials: 'omit', cache: 'no-store', signal: init.signal ?? AbortSignal.timeout(20000) });
  const body = await response.json().catch(() => null);
  if (!response.ok) {
    if (response.status === 401) { clearCustomerSession(); window.dispatchEvent(new Event('customer-session-ended')); }
    throw new CustomerRequestError(apiErrorMessage(body, response.status, i18n.t('The client service is unavailable. Please retry.')), response.status, body?.error?.code ?? 'request_failed', body?.error?.fields);
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
  privateSessionStorage.setItem(challengeKey, 'pending');
  return challenge.nonce;
}
export async function finishCustomerLogin(idToken: string, accessToken: string) {
  if(serverSessionsEnabled())return;
  if (!privateSessionStorage.getItem(challengeKey)) return;
  privateSessionStorage.removeItem(challengeKey);
  const data = await customerFetch('/auth/session', { method: 'POST', headers: { Authorization: `Bearer ${accessToken}` }, body: JSON.stringify({ id_token: idToken }) }, false);
  privateSessionStorage.setItem(key, JSON.stringify({ ...data, account: selectCustomerAccount()?.homeAccountId }));
}
export function updateSessionTimes(times: SessionTimes) {
  if(serverSessionsEnabled())return;
  const current = savedSession();
  if (current) privateSessionStorage.setItem(key, JSON.stringify({ ...current, ...times }));
}
export async function endCustomerSession() {
  if(serverSessionsEnabled()){await signOutServerSession();return;}
  const session = savedSession();
  if (session?.session_token) await customerFetch('/auth/logout', { method: 'POST', headers: { 'X-Customer-Session': session.session_token }, body: '{}' }, false);
  clearCustomerSession();
}
