import { expect, test, type Page } from '@playwright/test';
import { selectAccount } from '../src/auth/accountSelection';
import type { AccountInfo } from '@azure/msal-browser';

async function fixture(page: Page, signedIn = false, callbackFails = false) {
  // Only Playwright intercepts this module; no production bypass or fake tokens.
  await page.route('**/src/customer/auth.ts', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account=${signedIn ? "{homeAccountId:'customer',name:'Test Client'}" : 'null'};
    export const customerConfigured=true;
    export const customerHome='http://localhost:5184/client';
    export const customerInstance={initialize:async()=>{},handleRedirectPromise:async()=>{${callbackFails ? "throw new Error('test callback failure');" : 'return null;'}},getActiveAccount:()=>account?{...account}:null,getAllAccounts:()=>account?[{...account}]:[],setActiveAccount:()=>{}};
    export const selectCustomerAccount=()=>account?{...account,idTokenClaims:{exp:Math.floor(Date.now()/1000)+3600}}:null;
    export const customerToken=async()=>'customer-test-token';
    export const customerSignIn=async()=>{window.customerSignInCalled=true;};
    export const customerSignOut=async()=>{window.customerSignOutCalled=true;};
  ` }));
  await page.route('**/api/v1/site-config', route => route.fulfill({ json: { data: { name: 'Test Wellness' } } }));
}
test('customer route offers sign-in without running staff authentication', async ({ page }) => {
  const requests: string[] = []; page.on('request', r => requests.push(r.url()));
  await fixture(page);
  await page.goto('http://localhost:5184/client');
  await expect(page.getByRole('heading', { name: 'Client portal' })).toBeVisible();
  await page.screenshot({ path: test.info().outputPath('client-sign-in.png'), fullPage: true });
  await page.getByRole('button', { name: 'Sign in or create client account' }).click();
  expect(await page.evaluate(() => (window as any).customerSignInCalled)).toBe(true);
  expect(requests.some(url => /\/api\/v1\/(auth\/me|profile\/avatar)/.test(url))).toBe(false);
  await expect(page.getByRole('button', { name: 'Create appointment', exact: true })).toHaveCount(0);
});
test('verified identity remains unlinked with no client records or staff roles', async ({ page }) => {
  await fixture(page, true);
  let checks = 0;
  await page.route('**/api/v1/customer/auth/me', route => {
    checks++;
    expect(route.request().headers().authorization).toBe('Bearer customer-test-token');
    return route.fulfill({ json: { data: { authenticated: true, authentication_context: 'customer', onboarding_status: 'not_linked', capabilities: [] } } });
  });
  await page.goto('http://localhost:5184/client/auth/callback?code=synthetic#state=synthetic');
  await expect(page).toHaveURL('http://localhost:5184/client');
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toBeVisible();
  await expect(page.getByText(/has not been linked to a clinic record/)).toBeVisible();
  await page.screenshot({ path: test.info().outputPath('client-verified.png'), fullPage: true });
  await page.getByRole('button', { name: 'Open client account menu' }).click();
  expect(checks).toBe(1);
  await page.getByRole('menuitem', { name: 'Sign out', exact: true }).click();
  expect(await page.evaluate(() => (window as any).customerSignOutCalled)).toBe(true);
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toHaveCount(0);
});

test('account restoration separates staff and customers even with a wrong active account', () => {
  const staff = { homeAccountId: 'staff', tenantId: 'staff-tenant', environment: 'login.windows.net' } as AccountInfo;
  const client = { homeAccountId: 'client', tenantId: 'client-tenant', environment: 'clients.ciamlogin.com' } as AccountInfo;
  expect(selectAccount([client, staff], client, 'staff-tenant', ['login.windows.net'])).toBe(staff);
  expect(selectAccount([staff, client], staff, 'client-tenant', ['clients.ciamlogin.com'])).toBe(client);
  expect(selectAccount([client], client, 'staff-tenant', ['login.windows.net'])).toBeNull();
  expect(selectAccount([{ ...client, environment: 'untrusted.test' }], null, 'client-tenant', ['clients.ciamlogin.com'])).toBeNull();
});

test('client verification stays stable across rerenders, refresh and public navigation', async ({ page }) => {
  await fixture(page, true);
  let checks = 0;
  await page.route('**/api/v1/customer/auth/me', route => { checks++; return route.fulfill({ json: { data: { authenticated: true, authentication_context: 'customer', onboarding_status: 'not_linked', capabilities: [] } } }); });
  await page.goto('http://localhost:5184/client');
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Open client account menu' }).click();
  await page.keyboard.press('Escape');
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toBeVisible();
  expect(checks).toBe(1);
  await page.reload();
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toBeVisible();
  expect(checks).toBe(2);
  await page.getByRole('link', { name: 'Public website' }).click();
  await expect(page.getByRole('link', { name: 'Open client account', exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('link', { name: 'Open client account', exact: true })).toBeVisible();
  expect(checks).toBe(2); // Display bridge never requests tokens or API verification.
  await page.getByRole('link', { name: 'Open client account', exact: true }).click();
  await expect(page).toHaveURL('http://localhost:5184/client');
  await expect(page.getByText('Customer sign-in verified.', { exact: true })).toBeVisible();
  expect(checks).toBe(3);
});

test('generic login is client-first and staff login remains separate', async ({ page }) => {
  await fixture(page);
  await page.goto('http://localhost:5184/login');
  await expect(page).toHaveURL('http://localhost:5184/client');
  await expect(page.getByRole('heading', { name: 'Client portal' })).toBeVisible();
  await page.getByRole('link', { name: 'Staff login', exact: true }).click();
  await expect(page).toHaveURL('http://localhost:5184/staff/login');
  await expect(page.getByRole('heading', { name: 'Staff portal', exact: true })).toBeVisible();
});

test('public login ignores spoofed account messages and works without the bridge', async ({ page }) => {
  await fixture(page);
  await page.route('**/client/session', route => route.abort());
  await page.goto('http://localhost:5183/');
  await page.evaluate(() => window.postMessage({ type: 'wellness:account-display', initials: 'XX', nonce: '' }, '*'));
  await expect(page.getByRole('link', { name: 'Login', exact: true })).toHaveAttribute('href', 'http://localhost:5184/client');
  await expect(page.getByRole('link', { name: 'Open client account' })).toHaveCount(0);
});
for (const status of [401, 403, 500]) {
  test(`customer API ${status} is readable and never loops or grants access`, async ({ page }) => {
    await fixture(page, true); let requests = 0;
    await page.route('**/api/v1/customer/auth/me', route => { requests++; return route.fulfill({ status, body: '', contentType: 'text/html' }); });
    await page.goto('http://localhost:5184/client');
    await expect(page.getByRole('button', { name: 'Retry verification' })).toBeVisible();
    await expect(page.getByText('Customer sign-in verified.', { exact: true })).toHaveCount(0);
    expect(requests).toBe(1);
    await page.getByRole('button', { name: 'Retry verification' }).click();
    await expect.poll(() => requests).toBe(2);
    await expect(page.getByText(/Unexpected end of JSON/)).toHaveCount(0);
  });
}
test('callback failure strips response and offers deliberate recovery', async ({ page }) => {
  await fixture(page, false, true);
  await page.goto('http://localhost:5184/client/auth/callback?code=synthetic');
  await expect(page).toHaveURL('http://localhost:5184/client');
  await expect(page.getByText(/Client sign-in could not be completed/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign in or create client account' })).toBeEnabled();
});

test('real customer MSAL starts code flow with PKCE and customer-only scope', async ({ page }) => {
  await page.route('**/api/v1/site-config', route => route.fulfill({ json: { data: { name: 'Test Wellness' } } }));
  const authority = 'https://testcustomers.ciamlogin.com/44444444-4444-4444-4444-444444444444';
  await page.route('https://testcustomers.ciamlogin.com/**', route => {
    if (route.request().url().includes('.well-known')) return route.fulfill({ json: {
      issuer: `${authority}/v2.0`, authorization_endpoint: `${authority}/oauth2/v2.0/authorize`,
      token_endpoint: `${authority}/oauth2/v2.0/token`, end_session_endpoint: `${authority}/oauth2/v2.0/logout`,
      jwks_uri: `${authority}/discovery/v2.0/keys`, response_types_supported: ['code'],
      subject_types_supported: ['pairwise'], id_token_signing_alg_values_supported: ['RS256'],
    } });
    return route.fulfill({ contentType: 'text/html', body: 'Test identity provider' });
  });
  await page.goto('http://localhost:5184/client');
  const request = page.waitForRequest(r => r.url().includes('/oauth2/v2.0/authorize'));
  await page.getByRole('button', { name: 'Sign in or create client account' }).click();
  const url = new URL((await request).url());
  expect(url.searchParams.get('client_id')).toBe('55555555-5555-5555-5555-555555555555');
  expect(url.searchParams.get('response_type')).toBe('code');
  expect(url.searchParams.get('code_challenge_method')).toBe('S256');
  expect(url.searchParams.get('code_challenge')).toBeTruthy();
  expect(url.searchParams.get('state')).toBeTruthy();
  expect(url.searchParams.get('redirect_uri')).toBe('http://localhost:5184/client/auth/callback');
  expect(url.searchParams.get('scope')).toContain('api://66666666-6666-6666-6666-666666666666/access_as_client');
  expect(url.searchParams.has('client_secret')).toBe(false);
});
