import { generateKeyPairSync } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { realms, type Realm } from '../prototypes/session-security/protocol';
import { IdentityFixture, api, backend, challengeFor, control, cookieHeader, sessionFor, signClaims, tokens, type Mode } from './helpers/sessionPrototype';
import { runtimeDirectory } from './helpers/sessionPrototypeSetup';

test.beforeEach(async ({ request }) => { await control(request, 'reset'); });
test.afterEach(async ({ context }) => {
  // Wait for application cleanup, rather than tearing down its in-flight routes.
  for (const page of context.pages()) {
    if (new URL(page.url()).pathname === '/') await expect(page.locator('#login')).toBeEnabled({ timeout: 10000 });
  }
  await context.unrouteAll({ behavior: 'wait' });
});

test('real MSAL popup and PKCE establish a guarded practitioner session', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request);
  await identity.install(context);
  await identity.visit(page);
  expect((await context.cookies('https://clinic-a.session.test')).map(cookie => cookie.name)).toContain('__Host-wc-poc-challenge-practitioner');
  await identity.login(page);
  expect(identity.tokenCalls).toBe(1);
  expect(identity.startCalls).toBe(1);
  expect(identity.unexpected).toEqual([]);
  const pair = identity.pair;
  expect(pair).not.toBeNull();
  const cookie = await cookieHeader(context);
  const response = await api(request, 'practitioner', 'me', { cookie, bearer: pair?.access });
  expect(response.status()).toBe(200);
  test.info().annotations.push({ type: 'browser', description: `${context.browser()?.browserType().name()} ${context.browser()?.version()}; cookie-transport-bridges=${identity.bridgedCookieRequests}` });
});

for (const realm of Object.keys(realms) as Realm[]) {
  test(`${realm}: memory-only durable cache, fresh proof, cookies and reload`, async ({ page, context, request }) => {
    const identity = new IdentityFixture(request); identity.profile = realm === 'practitioner' ? 'google' : 'microsoft';
    await identity.install(context);
    const host = realm === 'central' ? 'admin.session.test' : 'clinic-a.session.test';
    await identity.visit(page, realm, host);
    await page.evaluate(() => localStorage.setItem('harmless-theme', 'dark'));
    await identity.login(page);
    const authorize = identity.authorizations[0];
    expect(authorize.searchParams.get('code_challenge_method')).toBe('S256');
    expect(authorize.searchParams.get('max_age')).toBe('0');
    expect(authorize.searchParams.get('prompt')).toBe('login');
    for (const key of ['login_hint', 'domain_hint', 'client_secret']) expect(authorize.searchParams.has(key)).toBe(false);
    expect(authorize.searchParams.get('nonce')).toMatch(/^[a-f0-9]{64}$/);
    const persisted = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }));
    test.info().annotations.push({ type: 'post-login-key-names', description: (await page.evaluate(() => Object.keys(sessionStorage))).join(', ') });
    const pair = identity.pair;
    if (!pair) throw new Error('Synthetic token missing');
    for (const value of [pair.access, pair.id, pair.refresh]) expect(persisted.includes(value)).toBe(false);
    const cookies = await context.cookies(`https://${host}`);
    const cookie = cookies.find(item => item.name === `__Host-wc-poc-${realm}`);
    expect(cookie).toMatchObject({ domain: host, path: '/', secure: true, httpOnly: true, sameSite: 'Lax', expires: -1 });
    expect(await page.evaluate(() => document.cookie)).not.toContain('__Host-wc-poc');
    expect(await context.cookies('https://clinic-b.session.test')).toEqual([]);
    const saved = await cookieHeader(context, `https://${host}`);
    await page.reload();
    await expect(page.locator('#login')).toBeEnabled();
    await expect(page.locator('#workspace')).toBeHidden();
    await page.locator('#check').click();
    await expect(page.locator('#status')).toHaveText('Session ended');
    expect((await api(request, realm, 'me', { host, cookie: saved })).status()).toBe(401);
    expect(await page.evaluate(() => localStorage.getItem('harmless-theme'))).toBe('dark');
    expect(identity.unexpected).toEqual([]);
  });
}

async function noTransactionSecrets(page: Page) {
  const keys = await page.evaluate(() => Object.keys(sessionStorage));
  expect(keys.filter(key => /request\.params|nonce\.id_token|request\.state|interaction\.status|code\.verifier/.test(key))).toEqual([]);
}

for (const mode of ['denied', 'wrong-state', 'nonce-mismatch', 'token-failure'] satisfies Mode[]) {
  test(`popup ${mode} fails closed, cleans transaction and permits a fresh retry`, async ({ page, context, request }) => {
    const identity = new IdentityFixture(request); identity.mode = mode;
    await identity.install(context); await identity.visit(page);
    await page.locator('#login').click();
    await expect(page.locator('#status')).toHaveText('Sign-in failed; try again');
    await expect(page.locator('#login')).toBeEnabled();
    await expect(page.locator('#workspace')).toBeHidden();
    await noTransactionSecrets(page);
    expect(Object.keys((await control(request)).state.sessions)).toHaveLength(0);
    identity.mode = 'success'; await identity.login(page);
    expect(identity.unexpected).toEqual([]);
  });
}

test('cancelled popup inventories temporary storage and clears it with the supported SDK', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); identity.mode = 'hold';
  await identity.install(context); await identity.visit(page);
  const popupPromise = context.waitForEvent('page'); await page.locator('#login').click(); const popup = await popupPromise;
  await expect(popup.getByRole('link', { name: 'Complete synthetic sign-in' })).toBeVisible();
  const temporary = await page.evaluate(() => Object.keys(sessionStorage));
  expect(temporary.some(key => key.includes('interaction.status'))).toBe(true);
  // Inventory names only. A residual transaction item is still AUTH-04 review scope.
  test.info().annotations.push({ type: 'temporary-key-names', description: temporary.join(', ') });
  await popup.close();
  await expect(page.locator('#status')).toHaveText('Sign-in failed; try again');
  await noTransactionSecrets(page);
  identity.mode = 'success'; await identity.login(page);
});

for (const failure of ['unavailable', 'throttled', 'malformed', 'invalid-lease'] as const) {
  test(`${failure} protected response clears the mounted dashboard`, async ({ page, context, request }) => {
    const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
    await context.route('**/__session-prototype/practitioner/me', route => {
      if (failure === 'malformed') return route.fulfill({ contentType: 'application/json', body: '{' });
      if (failure === 'invalid-lease') return route.fulfill({ json: { data: { csrf: 'a'.repeat(64), now: -1, idle_expires_at: -1, absolute_expires_at: -1 } } });
      return route.fulfill({ status: failure === 'throttled' ? 429 : 503, json: { error: { code: 'synthetic_failure' } } });
    });
    await page.locator('#check').click();
    await expect(page.locator('#workspace')).toBeHidden();
    await expect(page.locator('#status')).toHaveText('Session ended');
    await noTransactionSecrets(page);
  });
}

test('corrupt session store fails closed without disclosing diagnostics', async ({ request }) => {
  const session = await sessionFor(request);
  const path = join(runtimeDirectory, 'state.json');
  const original = readFileSync(path);
  try {
    writeFileSync(path, '{');
    const response = await api(request, 'practitioner', 'me', { cookie: session.cookie, bearer: session.pair.access });
    expect(response.status()).toBe(500);
    expect(await response.json()).toEqual({ error: { code: 'prototype_failure' } });
  } finally { writeFileSync(path, original); }
});

test('concurrent reuse of one fresh proof creates exactly one synthetic session', async ({ request }) => {
  const challenge = await challengeFor(request);
  const pair = tokens('practitioner', challenge.nonce, 'microsoft', (await control(request)).now);
  const responses = await Promise.all(Array.from({ length: 6 }, () => api(request, 'practitioner', 'start', { cookie: challenge.cookie, bearer: pair.access, body: { id_token: pair.id } })));
  expect(responses.map(response => response.status()).sort()).toEqual([200, 401, 401, 401, 401, 401]);
  expect(Object.keys((await control(request)).state.sessions)).toHaveLength(1);
});

test('challenge storage is bounded and invalid shapes and hosts are rejected', async ({ request }) => {
  expect((await api(request, 'practitioner', 'challenge', { host: 'attacker.example.test' })).status()).toBe(404);
  expect((await api(request, 'practitioner', 'challenge', { body: [] })).status()).toBe(400);
  expect((await api(request, 'practitioner', 'challenge', { method: 'GET' })).status()).toBe(404);
  for (let index = 0; index < 512; index++) expect((await api(request, 'practitioner', 'challenge')).status()).toBe(200);
  expect((await api(request, 'practitioner', 'challenge')).status()).toBe(429);
});

test('blocked popup fails safely without creating a server session', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page);
  await page.evaluate(() => { window.open = () => null; });
  await page.locator('#login').click();
  await expect(page.locator('#status')).toHaveText('Sign-in failed; try again');
  await noTransactionSecrets(page);
  expect(identity.startCalls).toBe(0);
});

test('popup bridge timeout is bounded and clears temporary state', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); identity.mode = 'timeout';
  await identity.install(context); await identity.visit(page);
  await page.locator('#login').click();
  await expect(page.locator('#status')).toHaveText('Sign-in failed; try again', { timeout: 10000 });
  await noTransactionSecrets(page); expect(identity.startCalls).toBe(0);
});

test('cookie, bearer, principal, host and realm are independent requirements', async ({ request }) => {
  const { cookie, pair } = await sessionFor(request);
  for (const options of [
    { cookie }, { bearer: pair.access }, { cookie, bearer: 'malformed' },
    { cookie, bearer: tokens('practitioner', 'other', 'google').access },
    { cookie, bearer: pair.access, host: 'clinic-b.session.test' },
  ]) expect((await api(request, 'practitioner', 'me', options)).status()).toBe(401);
  expect((await api(request, 'client', 'me', { cookie, bearer: pair.access })).status()).toBe(401);
  expect((await api(request, 'central', 'challenge')).status()).toBe(403);
  expect((await api(request, 'practitioner', 'me', { cookie, bearer: pair.access })).status()).toBe(200);
});

for (const [name, claim, value, status] of [
  ['issuer', 'iss', 'https://unapproved.example.test/', 401], ['tenant', 'tid', 'wrong', 401],
  ['audience', 'aud', realms.client.api, 401], ['SPA client', 'azp', realms.client.client, 401],
  ['scope', 'scp', 'unrelated_scope', 403], ['expiry', 'exp', 1, 401],
  ['future issue time', 'iat', 9999999999, 401], ['version', 'ver', '1.0', 401],
] as const) {
  test(`PHP JWT guard rejects signed wrong ${name}`, async ({ request }) => {
    const { cookie, pair } = await sessionFor(request);
    const bearer = signClaims({ ...pair.apiClaims, [claim]: value });
    expect((await api(request, 'practitioner', 'me', { cookie, bearer })).status()).toBe(status);
  });
}

test('PHP verifies the signature, bounds input and returns redacted errors', async ({ request }) => {
  const { cookie, pair } = await sessionFor(request);
  const other = generateKeyPairSync('rsa', { modulusLength: 2048, privateKeyEncoding: { type: 'pkcs8', format: 'pem' }, publicKeyEncoding: { type: 'spki', format: 'pem' } });
  const forged = await api(request, 'practitioner', 'me', { cookie, bearer: signClaims(pair.apiClaims, other.privateKey) });
  expect(forged.status()).toBe(401);
  expect(await forged.json()).toEqual({ error: { code: 'token_invalid' } });
  const malformed = await request.post(`${backend}/__session-prototype/practitioner/start`, { headers: { host: 'clinic-a.session.test', Origin: 'https://clinic-a.session.test', 'Content-Type': 'application/json' }, data: '{' });
  expect(malformed.status()).toBe(400);
  const oversize = await api(request, 'practitioner', 'start', { body: { padding: 'x'.repeat(65536) } });
  expect(oversize.status()).toBe(413);
  expect(forged.headers()['cache-control']).toBe('no-store');
  expect((await request.post(`${backend}/__session-prototype/control`, { data: { action: 'reset' } })).status()).toBe(403);
});

test('fresh authentication proof is nonce bound, one-use, time bounded and account bound', async ({ request }) => {
  const { challenge, cookie, pair } = await sessionFor(request);
  expect((await api(request, 'practitioner', 'start', { cookie: challenge.cookie, bearer: pair.access, body: { id_token: pair.id } })).status()).toBe(401);
  for (const kind of ['wrong-nonce', 'wrong-account', 'stale-auth', 'future-auth', 'missing-auth'] as const) {
    const fresh = await challengeFor(request); const now = (await control(request)).now;
    const id = { ...tokens('practitioner', fresh.nonce, 'microsoft', now).idClaims };
    if (kind === 'wrong-nonce') id.nonce = 'wrong';
    if (kind === 'wrong-account') id.oid = 'aaaaaaaa-aaaa-4aaa-8aaa-000000000001';
    if (kind === 'stale-auth') id.auth_time = now - 601;
    if (kind === 'future-auth') id.auth_time = now + 61;
    if (kind === 'missing-auth') delete id.auth_time;
    expect((await api(request, 'practitioner', 'start', { cookie: fresh.cookie, bearer: pair.access, body: { id_token: signClaims(id) } })).status()).toBe(401);
  }
  const stale = await challengeFor(request);
  await control(request, 'advance', { seconds: 601 });
  const refreshed = tokens('practitioner', stale.nonce, 'microsoft', (await control(request)).now);
  expect((await api(request, 'practitioner', 'start', { cookie: stale.cookie, bearer: refreshed.access, body: { id_token: refreshed.id } })).status()).toBe(401);
  expect((await api(request, 'practitioner', 'me', { cookie, bearer: pair.access })).status()).toBe(200);
});

test('same-origin POST and session CSRF are mandatory for activity and logout', async ({ request }) => {
  const { cookie, pair, lease } = await sessionFor(request);
  for (const action of ['activity', 'logout']) {
    for (const origin of ['https://attacker.example.test', 'https://clinic-b.session.test', 'null', ''])
      expect((await api(request, 'practitioner', action, { cookie, bearer: pair.access, csrf: lease.csrf, origin })).status()).toBe(403);
    for (const csrf of ['', 'invalid']) expect((await api(request, 'practitioner', action, { cookie, bearer: pair.access, csrf })).status()).toBe(403);
  }
  expect((await api(request, 'practitioner', 'activity', { cookie, bearer: pair.access, csrf: lease.csrf })).status()).toBe(200);
  expect((await api(request, 'practitioner', 'logout', { cookie, csrf: lease.csrf })).status()).toBe(200);
  expect((await api(request, 'practitioner', 'me', { cookie, bearer: pair.access })).status()).toBe(401);
});

for (const realm of ['practitioner', 'client'] as const) {
  test(`${realm}: idle boundary is enforced independently of valid JWT expiry`, async ({ request }) => {
    const { cookie, pair, lease } = await sessionFor(request, realm);
    const idle = realm === 'client' ? 1800 : 900;
    await control(request, 'advance', { seconds: idle - 1 });
    expect((await api(request, realm, 'me', { cookie, bearer: pair.access })).status()).toBe(200);
    await control(request, 'advance', { seconds: 1 });
    expect((await api(request, realm, 'activity', { cookie, bearer: pair.access, csrf: lease.csrf })).status()).toBe(401);
  });
}

test('activity slides idle deadline but cannot slide or revive the eight-hour absolute deadline', async ({ request }) => {
  const { cookie, pair, lease } = await sessionFor(request);
  for (let step = 0; step < 35; step++) {
    await control(request, 'advance', { seconds: 800 });
    const response = await api(request, 'practitioner', 'activity', { cookie, bearer: pair.access, csrf: lease.csrf });
    expect(response.status()).toBe(200);
    expect((await response.json()).data.absolute_expires_at).toBe(lease.absolute_expires_at);
  }
  await control(request, 'advance', { seconds: 800 });
  expect((await api(request, 'practitioner', 'activity', { cookie, bearer: pair.access, csrf: lease.csrf })).status()).toBe(401);
});

for (const ending of ['revoke', 'idle'] as const) {
  test(`${ending}: silent MSAL token renewal cannot restore a terminated app session`, async ({ page, context, request }) => {
    const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
    if (ending === 'revoke') await control(request, 'revoke', { host: 'clinic-a.session.test', realm: 'practitioner' });
    else await control(request, 'advance', { seconds: 900 });
    await page.locator('#renew').click();
    await expect(page.locator('#workspace')).toBeHidden();
    await expect(page.locator('#status')).toHaveText('Session ended');
    expect(identity.tokenCalls).toBe(2);
    await noTransactionSecrets(page);
    await page.locator('#check').click();
    await expect(page.locator('#workspace')).toBeHidden();
    await expect(page.locator('#login')).toBeEnabled();
    await identity.login(page); // A new interactive, nonce-bound ceremony is required.
    expect(identity.authorizations).toHaveLength(2);
  });
}

test('logout clears the mounted dashboard and signals a second tab without sending identity data', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
  const second = await context.newPage(); await identity.visit(second); await identity.login(second);
  const logoutResponse = page.waitForResponse('**/__session-prototype/practitioner/logout');
  await page.locator('#logout').click();
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(second.locator('#workspace')).toBeHidden();
  await expect(second.locator('#status')).toHaveText('Session ended in another tab');
  await noTransactionSecrets(page); await noTransactionSecrets(second);
  expect((await logoutResponse).status()).toBe(200);
  const rows = Object.values((await control(request)).state.sessions);
  expect(rows.every(row => row.revoked === true)).toBe(true);
});

test('logout transport failure clears local state and reports unconfirmed server cleanup', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
  await context.route('**/__session-prototype/practitioner/logout', route => route.abort('failed'));
  await page.locator('#logout').click();
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#status')).toHaveText('Locally locked; server logout unconfirmed');
  await noTransactionSecrets(page);
  await page.locator('#check').click(); await expect(page.locator('#workspace')).toBeHidden();
});

test('a late protected response cannot resurrect the dashboard after logout', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
  const responsePromise = page.waitForRequest('**/__session-prototype/practitioner/me');
  let release: (() => void) | undefined;
  const gate = new Promise<void>(resolve => { release = resolve; });
  const pair = identity.pair;
  if (!pair) throw new Error('Synthetic token missing');
  const cookie = await cookieHeader(context);
  const stale = await api(request, 'practitioner', 'me', { cookie, bearer: pair.access });
  expect(stale.status()).toBe(200);
  await context.route('**/__session-prototype/practitioner/me', async route => { await gate; await route.fulfill({ status: 200, json: await stale.json() }); });
  try {
    await page.locator('#check').click(); await responsePromise;
    await page.locator('#logout').click();
    await expect(page.locator('#workspace')).toBeHidden(); release?.();
    await expect(page.locator('#status')).toHaveText('Session ended');
    await expect(page.locator('#workspace')).toBeHidden();
  } finally { release?.(); }
});

test('a completed popup cannot restore a dashboard after logout during sign-in', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); identity.mode = 'hold';
  await identity.install(context); await identity.visit(page);
  const popupPromise = context.waitForEvent('page'); await page.locator('#login').click(); const popup = await popupPromise;
  await expect(popup.getByRole('link')).toBeVisible();
  await page.locator('#logout').click();
  await popup.getByRole('link').click();
  await expect(page.locator('#login')).toBeEnabled();
  await expect(page.locator('#workspace')).toBeHidden();
  expect(identity.startCalls).toBe(0);
});

test('switching accounts in another tab cannot pair an old bearer with the new session cookie', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page); await identity.login(page);
  identity.profile = 'google';
  const second = await context.newPage(); await identity.visit(second); await identity.login(second);
  await page.locator('#check').click();
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#status')).toHaveText('Session ended');
  await expect(second.locator('#workspace')).toBeHidden();
});

test('local expiry timer removes private UI without a network request', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context); await identity.visit(page);
  await page.clock.install(); await identity.login(page);
  await page.clock.fastForward(900001);
  await expect(page.locator('#workspace')).toBeHidden();
  await expect(page.locator('#status')).toHaveText('Session ended');
});

test('French keyboard flow announces denial and restores focus at narrow width', async ({ page, context, request }) => {
  const identity = new IdentityFixture(request); await identity.install(context);
  await page.setViewportSize({ width: 320, height: 640 });
  await identity.visit(page, 'practitioner', 'clinic-a.session.test', 'fr');
  await expect(page.getByRole('button', { name: 'Se connecter', exact: true })).toBeVisible();
  await page.locator('#check').focus(); await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Session terminée');
  await expect(page.getByRole('status')).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('disposable store retains only session digests and bounded redacted event metadata', async ({ request }) => {
  const { cookie, pair, lease } = await sessionFor(request);
  await api(request, 'practitioner', 'logout', { cookie, csrf: lease.csrf });
  const state = (await control(request)).state;
  const encoded = JSON.stringify(state);
  for (const value of [pair.access, pair.id, pair.refresh, cookie.split('=')[1], lease.csrf, 'microsoft@example.test']) expect(encoded.includes(value)).toBe(false);
  expect(state.events).toEqual([
    { action: 'start', realm: 'practitioner', host: 'clinic-a.session.test' },
    { action: 'logout', realm: 'practitioner', host: 'clinic-a.session.test' },
  ]);
});
