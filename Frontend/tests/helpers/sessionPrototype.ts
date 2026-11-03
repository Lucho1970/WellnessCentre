import { createHash, createSign, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type APIRequestContext, type APIResponse, type BrowserContext, type Page } from '@playwright/test';
import { apiPrefix, authority, hosts, isLease, issuer, realmNames, realms, tenant, type Realm } from '../../prototypes/session-security/protocol';
import { runtimeDirectory } from './sessionPrototypeSetup';

export const backend = 'http://127.0.0.1:5197';
export const frontend = 'http://127.0.0.1:5196';
export type Claims = Record<string, unknown>;
export type TokenPair = { access: string; id: string; refresh: string; apiClaims: Claims; idClaims: Claims };
type Grant = { client: string; nonce: string; challenge: string; scope: string; profile: string; authTime: number };
export type Mode = 'success' | 'hold' | 'denied' | 'wrong-state' | 'token-failure' | 'nonce-mismatch' | 'timeout';
function base64(value: string | Buffer) { return Buffer.from(value).toString('base64url'); }
export function signClaims(claims: Claims, key?: string): string {
  const body = `${base64(JSON.stringify({ typ: 'JWT', alg: 'RS256', kid: 'prototype-key' }))}.${base64(JSON.stringify(claims))}`;
  return `${body}.${createSign('RSA-SHA256').update(body).sign(key ?? readFileSync(join(runtimeDirectory, 'private.pem'), 'utf8')).toString('base64url')}`;
}
function realmFor(client: string): Realm {
  const match = realmNames.find(realm => realms[realm].client === client);
  if (!match) throw new Error('Unknown synthetic client.');
  return match;
}
function oid(profile: string) { return `aaaaaaaa-aaaa-4aaa-8aaa-${createHash('sha256').update(profile).digest('hex').slice(0, 12)}`; }
export function tokens(realm: Realm, nonce: string, profile = 'microsoft', now = Math.floor(Date.now() / 1000), overrides: { api?: Claims; id?: Claims } = {}): TokenPair {
  const config = realms[realm];
  const common = { iss: issuer, tid: tenant, oid: oid(profile), azp: config.client, ver: '2.0', iat: now - 5, nbf: now - 5, exp: now + 86400 };
  const apiClaims: Claims = { ...common, aud: config.api, sub: `api-${profile}`, scp: config.scope, ...overrides.api };
  const idClaims: Claims = { ...common, aud: config.client, sub: `spa-${profile}`, nonce, auth_time: now, name: `Synthetic ${profile}`, preferred_username: `${profile}@example.test`, ...overrides.id };
  return { access: signClaims(apiClaims), id: signClaims(idClaims), refresh: `synthetic-refresh-${randomBytes(16).toString('hex')}`, apiClaims, idClaims };
}
export async function control(request: APIRequestContext, action = 'snapshot', fields: Claims = {}): Promise<{ now: number; state: { sessions: Record<string, Claims>; challenges: Record<string, Claims>; events: Claims[] } }> {
  const response = await request.post(`${backend}${apiPrefix}/control`, { headers: { 'X-Prototype-Control': readFileSync(join(runtimeDirectory, 'control-secret'), 'utf8') }, data: { action, ...fields } }).catch(() => { throw new Error('Local fixture control transport failed.'); });
  expect(response.status()).toBe(200);
  return (await response.json()).data;
}
function responseHeaders(response: APIResponse): Record<string, string> {
  const result: Record<string, string> = {};
  for (const { name, value } of response.headersArray()) {
    const key = name.toLowerCase();
    if (['content-length', 'content-encoding', 'transfer-encoding', 'connection'].includes(key)) continue;
    result[key] = result[key] ? `${result[key]}${key === 'set-cookie' ? '\n' : ', '}${value}` : value;
  }
  return result;
}
function escapeAttribute(value: string) { return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

/** All browser HTTPS requests are synthetic, fulfilled from fake IdP or loopback. */
export class IdentityFixture {
  mode: Mode = 'success';
  profile = 'microsoft';
  pair: TokenPair | null = null;
  readonly authorizations: URL[] = [];
  readonly unexpected: string[] = [];
  tokenCalls = 0;
  startCalls = 0;
  bridgedCookieRequests = 0;
  readonly apiResponses: { action: string; status: number; code: string; cookieNames: string[] }[] = [];
  private readonly codes = new Map<string, Grant>();
  private readonly refreshes = new Map<string, Grant>();
  constructor(private readonly request: APIRequestContext) {}
  async install(context: BrowserContext) {
    await context.route('**/*', async route => {
      const incoming = route.request(); const target = new URL(incoming.url());
      if (target.protocol !== 'https:' || (!hosts.includes(target.hostname) && target.hostname !== 'login.session.test')) {
        this.unexpected.push(target.origin); await route.abort('blockedbyclient'); return;
      }
      if (target.hostname === 'login.session.test') {
        if (target.pathname.endsWith('/authorize')) {
          this.authorizations.push(target);
          const client = target.searchParams.get('client_id') ?? '';
          realmFor(client);
          const redirect = new URL(target.searchParams.get('redirect_uri') ?? 'https://invalid.session.test/');
          if (!hosts.includes(redirect.hostname) || redirect.pathname !== '/prototypes/session-security/popup.html') throw new Error('Unapproved synthetic redirect.');
          const now = (await control(this.request)).now;
          const code = randomBytes(16).toString('hex');
          const grant = { client, nonce: target.searchParams.get('nonce') ?? '', challenge: target.searchParams.get('code_challenge') ?? '', scope: target.searchParams.get('scope') ?? '', profile: this.profile, authTime: now };
          this.codes.set(code, grant);
          const state = this.mode === 'wrong-state' ? 'invalid-state' : target.searchParams.get('state') ?? '';
          if (this.mode === 'denied') redirect.searchParams.set('error', 'access_denied');
          else redirect.searchParams.set('code', code);
          redirect.searchParams.set('state', state);
          if (this.mode === 'hold' || this.mode === 'timeout') {
            await route.fulfill({ contentType: 'text/html', body: `<html><body><a href="${escapeAttribute(redirect.href)}">Complete synthetic sign-in</a></body></html>` }); return;
          }
          // WebKit's route.fulfill rejects redirect statuses. A synthetic IdP
          // navigation exercises the same real SDK callback bridge in both engines.
          await route.fulfill({ contentType: 'text/html', body: `<html><head><meta http-equiv="refresh" content="0;url=${escapeAttribute(redirect.href)}"></head><body>Synthetic identity response</body></html>` }); return;
        }
        if (target.pathname.endsWith('/token')) {
          this.tokenCalls++;
          const form = new URLSearchParams(incoming.postData() ?? '');
          let grant: Grant | undefined;
          if (form.get('grant_type') === 'authorization_code') {
            grant = this.codes.get(form.get('code') ?? ''); this.codes.delete(form.get('code') ?? '');
            if (!grant || createHash('sha256').update(form.get('code_verifier') ?? '').digest('base64url') !== grant.challenge || form.get('client_id') !== grant.client) throw new Error('Synthetic PKCE verification failed.');
          } else grant = this.refreshes.get(form.get('refresh_token') ?? '');
          if (!grant || this.mode === 'token-failure') { await route.fulfill({ status: 400, json: { error: 'invalid_grant' } }); return; }
          const now = (await control(this.request)).now;
          const nonce = this.mode === 'nonce-mismatch' ? 'invalid-nonce' : grant.nonce;
          this.pair = tokens(realmFor(grant.client), nonce, grant.profile, now, { id: { auth_time: grant.authTime } });
          this.refreshes.set(this.pair.refresh, grant);
          await route.fulfill({ json: { token_type: 'Bearer', scope: grant.scope, expires_in: 86400, ext_expires_in: 86400, access_token: this.pair.access, id_token: this.pair.id, refresh_token: this.pair.refresh, client_info: base64(JSON.stringify({ uid: oid(grant.profile), utid: tenant })) } }); return;
        }
        if (target.pathname.includes('.well-known')) {
          await route.fulfill({ json: { issuer, authorization_endpoint: `${authority}/oauth2/v2.0/authorize`, token_endpoint: `${authority}/oauth2/v2.0/token`, end_session_endpoint: `${authority}/oauth2/v2.0/logout`, jwks_uri: `${authority}/discovery/v2.0/keys`, response_types_supported: ['code'], subject_types_supported: ['pairwise'], id_token_signing_alg_values_supported: ['RS256'] } }); return;
        }
        this.unexpected.push(target.origin + target.pathname); await route.abort('blockedbyclient'); return;
      }
      if (target.pathname.startsWith(apiPrefix)) {
        if (target.pathname.endsWith('/start')) this.startCalls++;
        const incomingHeaders = await incoming.allHeaders();
        // WebKit's synthetic interception exposes stored cookies in its native
        // jar but omits Cookie request headers. Bridge only this loopback fixture;
        // this does NOT establish WebKit/Safari HTTPS cookie-dispatch acceptance.
        if (context.browser()?.browserType().name() === 'webkit' && !incomingHeaders.cookie) {
          const cookies = await context.cookies(target.href);
          if (cookies.length) {
            incomingHeaders.cookie = cookies.map(cookie => `${cookie.name}=${cookie.value}`).join('; ');
            this.bridgedCookieRequests++;
          }
        }
        const response = await this.request.fetch(`${backend}${target.pathname}`, { method: incoming.method(), headers: { ...incomingHeaders, host: target.host }, data: incoming.postDataBuffer() ?? undefined, maxRedirects: 0 }).catch(() => { throw new Error('Local fixture API transport failed.'); });
        const json: unknown = await response.json();
        const error = json && typeof json === 'object' && 'error' in json ? json.error : null;
        const code = error && typeof error === 'object' && 'code' in error && typeof error.code === 'string' && /^[a-z_]{1,80}$/.test(error.code) ? error.code : '';
        this.apiResponses.push({ action: target.pathname.split('/').pop() ?? '', status: response.status(), code, cookieNames: (incomingHeaders.cookie ?? '').split(';').map(value => value.trim().split('=')[0]).filter(Boolean) });
        await route.fulfill({ status: response.status(), headers: responseHeaders(response), body: await response.body() }); return;
      }
      const path = target.pathname === '/' ? '/prototypes/session-security/index.html' : target.pathname;
      const response = await this.request.get(`${frontend}${path}`);
      await route.fulfill({ status: response.status(), headers: responseHeaders(response), body: await response.body() });
    });
  }
  async visit(page: Page, realm: Realm = 'practitioner', host = 'clinic-a.session.test', lang = 'en') {
    await page.goto(`https://${host}/?realm=${realm}&lang=${lang}`);
    await expect(page.locator('#status')).toHaveText(lang === 'fr' ? 'Connexion requise' : 'Sign in required');
    await expect(page.locator('#login')).toBeEnabled();
  }
  async login(page: Page) {
    await page.locator('#login').click();
    await expect(page.locator('#workspace')).toBeVisible().catch(async error => {
      throw new Error(`Synthetic login failed: ${await page.locator('#status').getAttribute('data-failure')}; ${JSON.stringify(this.apiResponses)}; ${error instanceof Error ? error.name : 'assertion'}`);
    });
    await expect(page.locator('#status')).toHaveText('Session active');
  }
}

export async function cookieHeader(context: BrowserContext, origin = 'https://clinic-a.session.test') { return (await context.cookies(origin)).map(cookie => `${cookie.name}=${cookie.value}`).join('; '); }
export async function api(request: APIRequestContext, realm: Realm, action: string, options: { host?: string; cookie?: string; bearer?: string; csrf?: string; origin?: string; method?: string; body?: unknown } = {}) {
  const host = options.host ?? 'clinic-a.session.test';
  const method = options.method ?? (action === 'me' ? 'GET' : 'POST');
  const headers: Record<string, string> = { host, 'Content-Type': 'application/json', Origin: options.origin ?? `https://${host}` };
  if (options.cookie) headers.Cookie = options.cookie;
  if (options.bearer) headers.Authorization = `Bearer ${options.bearer}`;
  if (options.csrf) headers['X-Prototype-CSRF'] = options.csrf;
  return request.fetch(`${backend}${apiPrefix}/${realm}/${action}`, { method, headers, ...(method === 'GET' ? {} : { data: options.body ?? {} }) }).catch(() => { throw new Error('Local fixture API transport failed.'); });
}

async function responseData(response: APIResponse): Promise<unknown> {
  const body: unknown = await response.json();
  if (!body || typeof body !== 'object' || !('data' in body)) throw new Error('Invalid synthetic response.');
  return body.data;
}

export async function challengeFor(request: APIRequestContext, realm: Realm = 'practitioner', host = 'clinic-a.session.test') {
  const response = await api(request, realm, 'challenge', { host });
  expect(response.status()).toBe(200);
  const cookie = response.headersArray().filter(header => header.name.toLowerCase() === 'set-cookie').map(header => header.value.split(';')[0]).join('; ');
  const data = await responseData(response);
  if (!data || typeof data !== 'object' || !('nonce' in data) || typeof data.nonce !== 'string' || !/^[a-f0-9]{64}$/.test(data.nonce)) throw new Error('Invalid synthetic challenge.');
  return { nonce: data.nonce, cookie };
}

export async function sessionFor(request: APIRequestContext, realm: Realm = 'practitioner', host = 'clinic-a.session.test') {
  const challenge = await challengeFor(request, realm, host);
  const now = (await control(request)).now;
  const pair = tokens(realm, challenge.nonce, 'microsoft', now);
  const response = await api(request, realm, 'start', { host, cookie: challenge.cookie, bearer: pair.access, body: { id_token: pair.id } });
  expect(response.status()).toBe(200);
  const policies = response.headersArray().filter(header => header.name.toLowerCase() === 'set-cookie').map(header => header.value.split(';').slice(1).map(attribute => attribute.trim().toLowerCase()));
  expect(policies.length).toBeGreaterThan(0);
  for (const policy of policies) {
    for (const required of ['path=/', 'secure', 'httponly', 'samesite=lax']) expect(policy.includes(required)).toBe(true);
    expect(policy.some(attribute => attribute.startsWith('domain='))).toBe(false);
  }
  const cookie = response.headersArray().filter(header => header.name.toLowerCase() === 'set-cookie' && header.value.startsWith(`__Host-wc-poc-${realm}=`)).map(header => header.value.split(';')[0]).join('; ');
  const lease = await responseData(response);
  if (!isLease(lease)) throw new Error('Invalid synthetic session lease.');
  return { cookie, pair, lease, challenge };
}
