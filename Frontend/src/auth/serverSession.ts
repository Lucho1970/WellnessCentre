import type { IPublicClientApplication, PopupRequest } from '@azure/msal-browser';
import { clearPrivateSessionStorage } from './privateSessionStorage';
import { serverSessionsEnabled } from './serverSessionMode';
import i18n from '../i18n';
import { reportStaffAccessFailure } from './staffAccessInvalidation';

export type SessionRealm = 'workforce' | 'practitioner' | 'central' | 'client';
export type SessionLease = { csrf: string; idle_expires_at: number; absolute_expires_at: number; policy_version: number };
type SessionState = 'signed-out' | 'starting' | 'active' | 'ended';
type Snapshot = { state: SessionState; lease: SessionLease | null; generation: number; ready: boolean; notice: 'sign-out-unconfirmed' | null };
type Identity = { instance: IPublicClientApplication; scopes: string[]; redirectUri: string };
const nativeFetch = window.fetch.bind(window);
let realm: SessionRealm = 'workforce';
let identity: Identity | null = null;
let lastBearer: string | null = null;
let snapshot: Snapshot = { state: 'signed-out', lease: null, generation: 0, ready: false, notice: null };
let challenge: { nonce: string; expires: number } | null = null;
let preparation: Promise<void> | null = null;
let aborter = new AbortController();
let timer: number | undefined;
let channel: BroadcastChannel | null = null;
let activityPending = false;
let lastActivity = 0;
const listeners = new Set<() => void>();
export class ServerSessionRequestError extends Error {
  readonly fields: Record<string, string> = {};
  constructor(readonly status: number, body: unknown) {
    let code = ''; let fields: unknown;
    if (body && typeof body === 'object' && 'error' in body && body.error && typeof body.error === 'object') {
      if ('code' in body.error && typeof body.error.code === 'string') code = body.error.code;
      if ('fields' in body.error) fields = body.error.fields;
    }
    const key = `API error: ${code}`;
    super(i18n.exists(key) ? i18n.t(key) : i18n.t('Your secure session has ended. Sign in again.'));
    if (fields && typeof fields === 'object' && !Array.isArray(fields)) for (const [name, value] of Object.entries(fields)) if (typeof value === 'string') this.fields[name] = value;
  }
}
export const sessionSnapshot = () => snapshot;
export const subscribeSession = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
function publish(next: Snapshot) { snapshot = next; for (const listener of listeners) listener(); }
async function boundedResponse(response: Response, limit: number, generation: number) {
  const reader = response.body?.getReader(); const chunks: Uint8Array[] = []; let total = 0;
  if (reader) for (;;) {
    const chunk = await reader.read(); if (chunk.done) break;
    total += chunk.value.length;
    if (total > limit) { await reader.cancel(); throw new Error('The secure response is too large.'); }
    chunks.push(chunk.value);
  }
  if (generation !== snapshot.generation) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  const bytes = new Uint8Array(total); let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
  // Fetch already decoded the transport. These headers must describe the buffered body.
  const headers = new Headers(response.headers); headers.delete('content-encoding'); headers.delete('content-length');
  return new Response([204, 304].includes(response.status) ? null : bytes.buffer, { status: response.status, statusText: response.statusText, headers });
}
function base() {
  const api = new URL(import.meta.env.VITE_API_BASE_URL ?? '/api/v1', location.origin);
  if (api.origin !== location.origin) throw new Error('Secure sessions require this portal’s own API.');
  const prefix = realm === 'client' ? 'customer/auth' : `${realm === 'central' ? 'application' : realm === 'workforce' ? 'staff' : 'practitioner'}/session`;
  return `${api.href.replace(/\/$/, '')}/${prefix}`;
}
function leaseFrom(body: unknown): SessionLease {
  if (!body || typeof body !== 'object' || !('data' in body)) throw new Error('Invalid secure session response.');
  let data: unknown = body.data;
  if (data && typeof data === 'object' && 'session' in data) data = data.session;
  if (!data || typeof data !== 'object' || !('csrf' in data) || typeof data.csrf !== 'string' || !/^[a-f0-9]{64}$/.test(data.csrf)
    || !('idle_expires_at' in data) || typeof data.idle_expires_at !== 'number' || !('absolute_expires_at' in data) || typeof data.absolute_expires_at !== 'number'
    || !('policy_version' in data) || typeof data.policy_version !== 'number' || !Number.isSafeInteger(data.policy_version) || data.policy_version < 1) throw new Error('Invalid secure session response.');
  return { csrf: data.csrf, idle_expires_at: data.idle_expires_at, absolute_expires_at: data.absolute_expires_at, policy_version: data.policy_version };
}
function activeLease(lease: SessionLease) {
  if (!Number.isSafeInteger(lease.idle_expires_at) || !Number.isSafeInteger(lease.absolute_expires_at)
    || Math.min(lease.idle_expires_at, lease.absolute_expires_at) * 1000 <= Date.now()) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
}
function remember(lease: SessionLease, generation: number) {
  if(generation!==snapshot.generation)throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  activeLease(lease); window.clearTimeout(timer);
  publish({ ...snapshot, state: 'active', lease });
  timer = window.setTimeout(() => endServerSession(), Math.max(0, Math.min(lease.idle_expires_at, lease.absolute_expires_at) * 1000 - Date.now()));
  window.dispatchEvent(new Event('server-session-times'));
}
export function endServerSession(broadcast = false) {
  window.clearTimeout(timer); aborter.abort(); aborter = new AbortController(); clearPrivateSessionStorage();
  challenge = null;
  lastBearer = null;
  publish({ state: 'ended', lease: null, generation: snapshot.generation + 1, ready: false, notice: null });
  window.dispatchEvent(new Event('customer-session-ended'));
  if (broadcast) channel?.postMessage({ type: 'logout', realm });
  if (identity) { identity.instance.setActiveAccount(null); void prepareServerSession().catch(() => { window.dispatchEvent(new Event('session-cache-clear-failed')); }); }
}
export function configureServerSession(value: SessionRealm, config: Identity) {
  if (!serverSessionsEnabled()) return;
  realm = value; identity = config;
  if (typeof BroadcastChannel !== 'undefined' && !channel) {
    channel = new BroadcastChannel('wellness-server-session');
    channel.onmessage = event => { if (event.data?.type === 'logout' && event.data.realm === realm) endServerSession(); };
  }
}
async function token() {
  if (!identity) throw new Error('Secure sign-in is unavailable.');
  const generation = snapshot.generation;
  const account = identity.instance.getActiveAccount();
  if (!account) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  const result = await identity.instance.acquireTokenSilent({ account, scopes: identity.scopes });
  if (generation !== snapshot.generation) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  lastBearer = result.accessToken;
  return result.accessToken;
}
async function call(action: string, bearer?: string, body?: object, csrf?: string) {
  const generation = snapshot.generation;
  const headers = new Headers(); if (bearer) headers.set('Authorization', `Bearer ${bearer}`);
  if (csrf) headers.set('X-Session-CSRF', csrf);
  headers.set('Content-Type', 'application/json');
  const raw = await nativeFetch(`${base()}/${action}`, { method: body === undefined ? 'GET' : 'POST', headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: AbortSignal.any([aborter.signal, AbortSignal.timeout(20000)]) });
  const response = await boundedResponse(raw, 65536, generation);
  if (generation !== snapshot.generation) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  if (!response.ok) { const body: unknown = await response.json().catch(() => null); throw new ServerSessionRequestError(response.status, body); }
  return response;
}
export async function prepareServerSession() {
  if (!serverSessionsEnabled() || !identity || snapshot.state === 'active' || snapshot.state === 'starting') return;
  if (preparation) return preparation;
  const generation = snapshot.generation;
  preparation = (async () => {
    if (!identity) return;
    await identity.instance.clearCache();
    const response = await call('challenge', undefined, {});
    const body: unknown = await response.json();
    if (!body || typeof body !== 'object' || !('data' in body) || !body.data || typeof body.data !== 'object'
      || !('nonce' in body.data) || typeof body.data.nonce !== 'string' || !/^[a-f0-9]{64}$/.test(body.data.nonce)
      || !('expires_at' in body.data) || typeof body.data.expires_at !== 'number') throw new Error('Unable to start secure sign-in.');
    if (generation !== snapshot.generation) return;
    challenge = { nonce: body.data.nonce, expires: body.data.expires_at };
    publish({ ...snapshot, ready: true });
  })().finally(() => { preparation = null; });
  return preparation;
}
export async function signInServerSession(parameters: Partial<PopupRequest> = {}) {
  if (!identity || !challenge || challenge.expires * 1000 <= Date.now()) { await prepareServerSession(); throw new Error(i18n.t('Secure sign-in is preparing. Please try again.')); }
  const nonce = challenge.nonce; challenge = null;
  aborter.abort(); aborter = new AbortController();
  publish({ state: 'starting', lease: null, generation: snapshot.generation + 1, ready: false, notice: null });
  const generation = snapshot.generation;
  // No awaited operation precedes loginPopup: preserve the browser user gesture.
  try {
    const result = await identity.instance.loginPopup({ ...parameters, scopes: identity.scopes, redirectUri: identity.redirectUri, nonce, prompt: 'login', extraQueryParameters: { ...parameters.extraQueryParameters, max_age: '0' } });
    if (generation !== snapshot.generation) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
    identity.instance.setActiveAccount(result.account);
    const started = await call(realm === 'client' ? 'session' : 'start', result.accessToken, { id_token: result.idToken });
    lastBearer = result.accessToken;
    remember(leaseFrom(await started.json()), generation); window.dispatchEvent(new Event('customer-session-started'));
  } catch (error) { if(generation===snapshot.generation)endServerSession(); throw error instanceof ServerSessionRequestError ? error : new Error(i18n.t('Sign-in could not be completed. Try again or choose another sign-in method.'), { cause: error }); }
}
export async function readServerSessionToken() {
  if (snapshot.state !== 'active' || !snapshot.lease) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  const generation=snapshot.generation;
  try { activeLease(snapshot.lease); return await token(); } catch (error) { if(generation===snapshot.generation)endServerSession(); throw new Error(i18n.t('Your secure session has ended. Sign in again.'), { cause: error }); }
}
async function currentLease() {
  const generation=snapshot.generation;
  const bearer = await readServerSessionToken(); const response = await call('me', bearer);
  const lease = leaseFrom(await response.json()); remember(lease,generation); return { bearer, lease };
}
export async function extendServerSession() {
  if (activityPending || snapshot.state !== 'active') return;
  activityPending = true;
  const generation=snapshot.generation;
  try { const { bearer, lease } = await currentLease(); const response = await call('activity', bearer, {}, lease.csrf); remember(leaseFrom(await response.json()),generation); }
  catch { if(generation===snapshot.generation)endServerSession(); }
  finally { activityPending = false; }
}
export async function signOutServerSession() {
  const target = base(); const originalCsrf = snapshot.lease?.csrf;
  const metadata = lastBearer ? nativeFetch(`${target}/me`, { headers: { Authorization: `Bearer ${lastBearer}` }, credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: AbortSignal.timeout(10000) }) : null;
  endServerSession(true); // Clear private UI before waiting for network sign-out.
  const generation = snapshot.generation; let csrf = originalCsrf; let metadataFailed = false;
  if (metadata) {
    try { const response = await boundedResponse(await metadata, 65536, generation); if (response.ok) csrf = leaseFrom(await response.json()).csrf; else metadataFailed = true; }
    catch { metadataFailed = true; } // Expired-token logout may use its already-bound CSRF; the server still validates the current cookie.
  }
  try {
    if (!csrf || generation !== snapshot.generation) throw new Error('Session changed before sign-out.');
    const response = await nativeFetch(`${target}/logout`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Session-CSRF': csrf }, body: '{}', credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: AbortSignal.timeout(10000) });
    if (!response.ok) throw new Error('Server sign-out was not confirmed.', { cause: metadataFailed ? new Error('Session metadata was unavailable.') : undefined });
  } catch(error) {
    if(generation===snapshot.generation)publish({...snapshot,notice:'sign-out-unconfirmed'});
    throw new Error(i18n.t('Server sign-out could not be confirmed. Close this page and contact the clinic.'),{cause:error});
  }
  // Logout clears the challenge cookie as well as the session cookie. A warm-up
  // started by local UI clearing must finish before issuing the next challenge.
  try { await preparation; } catch { window.dispatchEvent(new Event('session-cache-clear-failed')); }
  if(generation===snapshot.generation){
    challenge=null;publish({...snapshot,ready:false});
    try{await prepareServerSession();}catch{window.dispatchEvent(new Event('session-cache-clear-failed'));}
  }
}
export type SessionRevocation = { nonce: string; bearer: string; csrf: string; generation: number };
export async function beginSessionRevocation(): Promise<SessionRevocation> {
  const generation=snapshot.generation;
  try {
    const { bearer, lease } = await currentLease(); const response = await call('action-challenge',bearer,{},lease.csrf);
    const body: unknown = await response.json();
    if(!body || typeof body!=='object' || !('data' in body) || !body.data || typeof body.data!=='object' || !('nonce' in body.data) || typeof body.data.nonce!=='string' || !/^[a-f0-9]{64}$/.test(body.data.nonce))throw new Error('Unable to prepare session revocation.');
    return { nonce:body.data.nonce,bearer,csrf:lease.csrf,generation:snapshot.generation };
  } catch(error){if(generation===snapshot.generation)endServerSession();throw error;}
}
export async function confirmSessionRevocation(pending: SessionRevocation) {
  if(!identity || pending.generation!==snapshot.generation || snapshot.state!=='active')throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  try {
    const result=await identity.instance.loginPopup({ scopes:identity.scopes,redirectUri:identity.redirectUri,nonce:pending.nonce,prompt:'login',extraQueryParameters:{max_age:'0'} });
    if(pending.generation!==snapshot.generation)throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
    const response=await call('action-proof',pending.bearer,{id_token:result.idToken},pending.csrf);
    const body:unknown=await response.json();
    if(!body || typeof body!=='object' || !('data' in body) || !body.data || typeof body.data!=='object' || !('action_proof' in body.data) || typeof body.data.action_proof!=='string' || !/^[a-f0-9]{64}$/.test(body.data.action_proof))throw new Error('Invalid session action proof.');
    const closed=await nativeFetch(`${base()}/revoke-all`,{ method:'POST',credentials:'same-origin',redirect:'error',cache:'no-store',headers:{Authorization:`Bearer ${pending.bearer}`,'Content-Type':'application/json','X-Session-CSRF':pending.csrf,'X-Action-Proof':body.data.action_proof},body:'{}',signal:AbortSignal.timeout(20000) });
    if(!closed.ok)throw new Error('Server sign-out could not be confirmed. Close this page and contact the clinic.');
  }finally{if(pending.generation===snapshot.generation)endServerSession(true);}
}
export async function sessionFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
  if (!serverSessionsEnabled() || !headers.has('Authorization')) return nativeFetch(input, init);
  const target = new URL(input instanceof Request ? input.url : input.toString(), location.href);
  const api = new URL(import.meta.env.VITE_API_BASE_URL ?? '/api/v1', location.origin);
  if (target.origin !== location.origin || !target.pathname.startsWith(`${api.pathname.replace(/\/$/, '')}/`)) throw new Error('Secure requests must use this portal’s API.');
  const generation = snapshot.generation; const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
  if (snapshot.state !== 'active') throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
  try {
    if (!['GET', 'HEAD'].includes(method)) { const { lease } = await currentLease(); headers.set('X-Session-CSRF', lease.csrf); }
    const response = await nativeFetch(input, { ...init, headers, credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: AbortSignal.any([aborter.signal, ...(init?.signal ? [init.signal] : []), AbortSignal.timeout(30000)]) });
    if (generation !== snapshot.generation) throw new Error(i18n.t('Your secure session has ended. Sign in again.'));
    if (response.status === 401 || response.status >= 500 || response.headers.get('X-Session-Ended') === '1') { endServerSession(); throw new Error(i18n.t('Your secure session has ended. Sign in again.')); }
    if(response.status===403){
      await currentLease();
      if(realm==='client')window.dispatchEvent(new Event('customer-session-recheck'));
      else reportStaffAccessFailure(403,'forbidden');
    }
    return await boundedResponse(response, 32 * 1024 * 1024, generation);
  } catch (error) { if (generation === snapshot.generation && !init?.signal?.aborted) endServerSession(); throw error; }
}
window.addEventListener('pagehide', () => { if (serverSessionsEnabled()) { document.documentElement.style.visibility = 'hidden'; endServerSession(); } });
window.addEventListener('pageshow', event => { if (serverSessionsEnabled() && event.persisted) { endServerSession(); requestAnimationFrame(() => requestAnimationFrame(() => { document.documentElement.style.visibility = ''; })); } });
for (const name of ['pointerdown', 'keydown']) window.addEventListener(name, event => {
  if (serverSessionsEnabled() && event.isTrusted && snapshot.state === 'active' && Date.now() - lastActivity > 60000) { lastActivity = Date.now(); void extendServerSession(); }
}, { passive: true });
