import { BrowserCacheLocation, PublicClientApplication, type AuthenticationResult } from '@azure/msal-browser';
import { freshCustomerLoginParameters } from '../../src/customer/providerRouting';
import { practitionerLoginParameters } from '../../src/auth/practitionerIdentity';
import { apiPrefix, authority, hosts, inventory, isLease, isRealm, issuer, prototypeMarker, realms, type Lease } from './protocol';

if (location.protocol !== 'https:' || !hosts.includes(location.hostname)) throw new Error('Synthetic HTTPS origin required.');
const requestedRealm = new URL(location.href).searchParams.get('realm') ?? 'practitioner';
if (!isRealm(requestedRealm)) throw new Error('Unknown synthetic realm.');
const realm = requestedRealm;
const config = realms[realm];
const root = `${location.origin}/prototypes/session-security/`;
const sdk = new PublicClientApplication({
  auth: {
    clientId: config.client, authority, knownAuthorities: ['login.session.test'], redirectUri: `${root}popup.html`,
    authorityMetadata: JSON.stringify({ issuer, authorization_endpoint: `${authority}/oauth2/v2.0/authorize`, token_endpoint: `${authority}/oauth2/v2.0/token`, end_session_endpoint: `${authority}/oauth2/v2.0/logout`, jwks_uri: `${authority}/discovery/v2.0/keys` }),
  },
  cache: { cacheLocation: BrowserCacheLocation.MemoryStorage },
  system: { popupBridgeTimeout: 4000, iframeBridgeTimeout: 4000 },
});
function node(id: string): HTMLElement { const value = document.getElementById(id); if (!value) throw new Error('Prototype markup missing.'); return value; }
function button(id: string): HTMLButtonElement { const value = node(id); if (!(value instanceof HTMLButtonElement)) throw new Error('Prototype button missing.'); return value; }
node('realm').textContent = `${prototypeMarker}: ${realm}`;
const french = new URL(location.href).searchParams.get('lang') === 'fr';
if (french) {
  document.documentElement.lang = 'fr';
  document.title = 'Prototype local de sécurité des sessions';
  node('heading').textContent = document.title;
  node('notice').textContent = "Identités synthétiques seulement. Ce prototype ne fait pas partie de l’application déployée.";
  node('dashboard-heading').textContent = 'Tableau de bord synthétique'; node('no-patients').textContent = 'Aucune donnée de patient.';
  node('storage-heading').textContent = 'Métadonnées des clés de stockage (valeurs masquées)';
  button('login').textContent = 'Se connecter'; button('check').textContent = 'Vérifier la session';
  button('activity').textContent = 'Enregistrer une activité'; button('logout').textContent = 'Fermer la session locale';
  button('renew').textContent = 'Renouveler le jeton et vérifier la session';
}
const message = (en: string, fr: string) => french ? fr : en;
let credential: AuthenticationResult | null = null;
let lease: Lease | null = null;
let generation = 0;
let expirationTimer: ReturnType<typeof setTimeout> | undefined;
let challenge: string | null = null;
let busy = false;
const channel = new BroadcastChannel(`session-prototype:${realm}`);

function metadata() { node('diagnostics').textContent = JSON.stringify(inventory()); }
function errorCode(error: unknown): string {
  const code = error && typeof error === 'object' && 'errorCode' in error ? error.errorCode : error instanceof Error ? error.message : '';
  return typeof code === 'string' && /^[a-z_0-9]{1,80}$/.test(code) ? code : 'unclassified_failure';
}
function hide() { node('workspace').hidden = true; node('account').textContent = ''; lease = null; clearTimeout(expirationTimer); }
async function lock(status: string, broadcast = false) {
  const revision = ++generation; hide(); credential = null; challenge = null; button('login').disabled = true;
  node('status').textContent = status; node('status').focus();
  if (broadcast) channel.postMessage({ type: 'ended', realm });
  await sdk.clearCache(); metadata();
  if (revision !== generation) return;
  await prepare(); if (revision === generation) button('login').disabled = challenge === null;
}
function show(next: Lease) {
  lease = next; node('workspace').hidden = false;
  node('account').textContent = credential?.account?.name ?? message('Synthetic account', 'Compte synthétique');
  node('status').textContent = message('Session active', 'Session active');
  clearTimeout(expirationTimer);
  const remaining = Math.max(0, Math.min(next.idle_expires_at, next.absolute_expires_at) - next.now) * 1000;
  expirationTimer = setTimeout(() => { void lock(message('Session ended', 'Session terminée'), true).catch(failClosed); }, remaining);
}
function failClosed() { generation++; hide(); credential = null; node('status').textContent = message('Local cleanup failed; sign-in remains locked', 'Échec du nettoyage local; connexion verrouillée'); }

async function request(action: string, init: RequestInit = {}, authorize = true): Promise<unknown> {
  const headers = new Headers(init.headers); headers.set('Content-Type', 'application/json');
  if (authorize) {
    if (!credential?.account) throw new Error('no_credential');
    const result = await sdk.acquireTokenSilent({ account: credential.account, scopes: [`api://${config.api}/${config.scope}`] });
    headers.set('Authorization', `Bearer ${result.accessToken}`);
    if (lease) headers.set('X-Prototype-CSRF', lease.csrf);
  }
  const response = await fetch(`${apiPrefix}/${realm}/${action}`, { ...init, headers, credentials: 'same-origin', cache: 'no-store', signal: init.signal ?? AbortSignal.timeout(8000) });
  const body: unknown = await response.json();
  if (!response.ok) throw new Error(`request_${response.status}`);
  if (!body || typeof body !== 'object' || !('data' in body)) throw new Error('invalid_response');
  return body.data;
}
async function prepare() {
  const revision = generation;
  challenge = null;
  const data = await request('challenge', { method: 'POST', body: '{}' }, false);
  if (!data || typeof data !== 'object' || !('nonce' in data) || typeof data.nonce !== 'string' || !/^[a-f0-9]{64}$/.test(data.nonce)) throw new Error('invalid_challenge');
  if (revision === generation) challenge = data.nonce;
}
async function login() {
  if (!challenge || busy) return;
  busy = true; button('login').disabled = true;
  const revision = ++generation; hide();
  const nonce = challenge; challenge = null;
  try {
    const result = await sdk.loginPopup({ scopes: [`api://${config.api}/${config.scope}`], ...practitionerLoginParameters(), ...freshCustomerLoginParameters(nonce) });
    if (generation !== revision) return;
    sdk.setActiveAccount(result.account); credential = result;
    const data = await request('start', { method: 'POST', body: JSON.stringify({ id_token: result.idToken }) });
    if (generation !== revision) return;
    if (!isLease(data)) throw new Error('invalid_lease');
    show(data);
  } catch (error) {
    node('status').dataset.failure = errorCode(error);
    await lock(message('Sign-in failed; try again', 'Échec de connexion; réessayez'));
  } finally {
    busy = false; metadata();
    if (revision === generation) {
      try { if (!challenge) await prepare(); if (revision === generation) button('login').disabled = challenge === null; }
      catch { if (revision === generation) node('status').textContent = message('Sign-in unavailable', 'Connexion indisponible'); }
    }
  }
}
async function check(activity = false) {
  const revision = generation;
  try {
    const data = await request(activity ? 'activity' : 'me', activity ? { method: 'POST', body: '{}' } : {});
    if (revision !== generation) return;
    if (!isLease(data)) throw new Error('invalid_lease');
    show(data);
  } catch { if (revision === generation) await lock(message('Session ended', 'Session terminée'), true); }
}
async function logout() {
  const account = credential?.account;
  let csrf = lease?.csrf;
  const revision = ++generation; hide(); credential = null; challenge = null;
  busy = true; button('login').disabled = true;
  node('status').textContent = message('Session ended', 'Session terminée');
  channel.postMessage({ type: 'ended', realm });
  try {
    // Another tab may have rotated the shared cookie. Obtain its CSRF proof using
    // the same verified account, while all private UI remains locked.
    if (account) {
      const token = await sdk.acquireTokenSilent({ account, scopes: [`api://${config.api}/${config.scope}`] });
      const current = await request('me', { headers: { Authorization: `Bearer ${token.accessToken}` } }, false);
      if (!isLease(current)) throw new Error('invalid_lease');
      csrf = current.csrf;
    }
    await request('logout', { method: 'POST', headers: { 'X-Prototype-CSRF': csrf ?? '' }, body: '{}' }, false);
  }
  catch { node('status').textContent = message('Locally locked; server logout unconfirmed', 'Verrouillé localement; déconnexion serveur non confirmée'); }
  finally { await sdk.clearCache(); busy = false; metadata(); }
  if (revision === generation) { await prepare(); if (revision === generation) button('login').disabled = challenge === null; }
}
async function renew() {
  if (!credential?.account) return;
  const revision = generation;
  try {
    const renewed = await sdk.acquireTokenSilent({ account: credential.account, scopes: [`api://${config.api}/${config.scope}`], forceRefresh: true });
    if (revision !== generation) return;
    credential = renewed;
    await check();
  } catch { if (revision === generation) await lock(message('Session ended', 'Session terminée'), true); }
}
channel.addEventListener('message', event => {
  if (event.data?.type === 'ended' && event.data.realm === realm) void lock(message('Session ended in another tab', 'Session terminée dans un autre onglet')).catch(failClosed);
});
button('login').disabled = true;
button('login').addEventListener('click', () => { void login().catch(failClosed); });
button('check').addEventListener('click', () => { void check().catch(failClosed); });
button('activity').addEventListener('click', () => { void check(true).catch(failClosed); });
button('renew').addEventListener('click', () => { void renew().catch(failClosed); });
button('logout').addEventListener('click', () => { void logout().catch(failClosed); });
window.addEventListener('pagehide', () => { generation++; hide(); credential = null; challenge = null; void sdk.clearCache().catch(failClosed); });
void sdk.initialize().then(prepare).then(() => { button('login').disabled = false; node('status').textContent = message('Sign in required', 'Connexion requise'); metadata(); }).catch(() => { node('status').textContent = message('Sign-in unavailable', 'Connexion indisponible'); });
