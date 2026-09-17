import { expect, test, type Page } from '@playwright/test';
import { pageAt, pagePath, pagesFor, workspacesFor } from '../src/portal/access';

const publicHost = 'http://localhost:5183';
const portalHost = 'http://localhost:5184';
const errors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => { const list: string[] = []; errors.set(page, list); page.on('pageerror', error => list.push(error.message)); });
test.afterEach(({ page }) => { expect(errors.get(page)).toEqual([]); });
async function fixtures(page: Page, roles?: string[]) {
  // Test-only network substitution. No production flag or authentication bypass.
  if (roles) await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account={homeAccountId:'test-user',name:'Test Staff',username:'staff@example.test'};
    const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-only-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};
    export const StaffAuthProvider=({children})=>children;
    export const useStaffAuth=()=>auth;
  ` }));
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
    let data: unknown = [];
    if (path === '/site-config') data = { name: 'Test Wellness', legal_name: null, email: 'clinic@example.test', phone: '905-555-0100' };
    if (path === '/auth/me') data = { roles: roles ?? [] };
    if (path === '/profile/avatar') data = { image_base64: null };
    if (path === '/clients') data = { items: [], has_more: false };
    if (path === '/locations') data = [{ id: 1, name: 'Holland Landing', timezone: 'America/Toronto' }];
    if (path === '/services') data = [{ id: 2, name: 'Massage', description: 'Therapeutic care', price_cents: 10000, durations: [{ id: 4, minutes: 60, price_cents: 10000 }] }];
    if (path === '/practitioners') data = [{ id: 3, display_name: 'Test Practitioner', discipline: 'Massage', credentials: 'RMT' }];
    if (path === '/availability') data = { timezone: 'America/Toronto', availability: [{ duration_option_id: 4, starts_at: '2026-10-01T14:00:00Z', ends_at: '2026-10-01T15:00:00Z' }] };
    return route.fulfill({ json: { data } });
  });
}

test('new services can be assigned without reloading or losing assignment selections', async ({ page }) => {
  await fixtures(page, ['super_admin']);
  const services = [{ id: 1, name: 'Existing massage', price_cents: 10000, durations: [60], active: 1, requires_room: 1 }];
  let savedAssignment: unknown;
  await page.route('**/api/v1/admin/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = [];
    if (path.endsWith('/services')) {
      if (route.request().method() === 'POST') services.push({ ...services[0], ...route.request().postDataJSON(), id: 2 });
      data = services;
    } else if (path.endsWith('/service-assignments')) data = { practitioners: [], locations: [] };
    else if (path.endsWith('/locations')) data = [{ id: 1, name: 'Test location' }];
    else if (path.endsWith('/practitioners')) data = [{ practitioner_id: 3, display_name: 'Test Therapist' }];
    else if (path.endsWith('/assignments')) savedAssignment = route.request().postDataJSON();
    else data = {};
    await route.fulfill({ json: { data } });
  });
  await page.goto(`${portalHost}/admin/services`);
  await expect(page.getByRole('combobox', { name: /^Service / })).toHaveText('Existing massage');
  await page.getByRole('checkbox', { name: 'Test Therapist', exact: true }).check();
  await page.getByRole('textbox', { name: 'Service name', exact: true }).fill('New massage');
  await page.getByRole('spinbutton', { name: 'Price (CAD)' }).fill('120');
  await page.getByRole('button', { name: 'Add service', exact: true }).click();
  await expect(page.getByText('New massage was created.')).toBeVisible();
  await expect(page.getByRole('checkbox', { name: 'Test Therapist', exact: true })).toBeChecked();
  await page.getByRole('combobox', { name: /^Service / }).click();
  await page.getByRole('option', { name: 'New massage', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Test Therapist', exact: true }).check();
  await page.getByRole('checkbox', { name: 'Test location', exact: true }).check();
  await page.getByRole('button', { name: 'Save assignments' }).click();
  await expect(page.getByText('Service assignments saved.')).toBeVisible();
  expect(savedAssignment).toMatchObject({ location_ids: [1], practitioners: [{ practitioner_id: 3, service_id: 2 }] });
});

test('role policies preserve current access without broadening permissions', () => {
  expect(workspacesFor(['client'])).toEqual([]);
  expect(workspacesFor(['reception'])).toEqual(['admin']);
  expect(pagesFor(['accountant'], 'admin')).toEqual(['dashboard', 'profile']);
  expect(pagesFor(['reception'], 'admin')).toContain('clients');
  expect(pagesFor(['clinic_admin'], 'admin')).not.toContain('staff');
  expect(pagesFor(['practitioner'], 'admin')).toEqual([]);
  expect(pagesFor(['super_admin'], 'admin')).toContain('business');
  expect(pagesFor(['practitioner'], 'practitioner')).toEqual(['dashboard', 'appointments', 'profile']);
  expect(workspacesFor(['super_admin', 'practitioner'])).toEqual(['admin', 'practitioner']);
  expect(pageAt('/admin/unknown', 'admin')).toBeUndefined();
  expect(pagePath('practitioner', 'appointments')).toBe('/practitioner/schedule');
});

test('public home has no staff workspace, MSAL initialization or fake address', async ({ page }) => {
  const requests: string[] = []; page.on('request', request => requests.push(request.url()));
  await fixtures(page); await page.goto(publicHost);
  await expect(page.getByRole('heading', { name: 'Feel better, on your schedule.' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Login / portal' })).toHaveAttribute('href', `${portalHost}/login`);
  await expect(page.getByText('240 Queen Street')).toHaveCount(0);
  expect(requests.some(url => url.includes('AuthProvider') || url.includes('login.microsoftonline.com') || url.includes('/auth/me'))).toBe(false);
});

test('public booking hands off preferences without reserving or creating an appointment', async ({ page }) => {
  const writes: string[] = []; page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
  await fixtures(page); await page.goto(`${publicHost}/book`);
  await page.getByRole('button', { name: /Oct 1.*60 min/ }).click();
  await page.getByRole('link', { name: 'View client booking information' }).click();
  await expect(page).toHaveURL(/localhost:5184\/client\/book\?location_id=1/);
  await expect(page.getByText('No appointment has been requested or reserved.', { exact: false })).toBeVisible();
  expect(writes).toEqual([]);
});

test('public infrastructure errors have a readable retry state', async ({ page }) => {
  await fixtures(page);
  await page.route('**/api/v1/services', route => route.fulfill({ status: 500, body: '', contentType: 'text/html' }));
  await page.goto(`${publicHost}/book`);
  await expect(page.getByRole('alert').filter({ hasText: 'unexpected response (500)' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
  await expect(page.getByText('Unexpected end of JSON')).toHaveCount(0);
});

test('anonymous portal deep link is gated behind staff sign-in', async ({ page }) => {
  await fixtures(page); await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole('heading', { name: 'Staff portal', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add client' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Feel better, on your schedule.' })).toHaveCount(0);
});

test('reception routes support refresh, back, profile menu and restricted deep links', async ({ page }) => {
  await fixtures(page, ['reception']); await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole('heading', { name: 'Clients', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add client' })).toBeVisible();
  await page.getByRole('link', { name: /Appointments Bookings/ }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/appointments`);
  await expect(page.getByRole('button', { name: 'Book appointment', exact: true })).toBeVisible();
  await page.reload(); await expect(page.getByRole('heading', { name: 'Appointments', exact: true, level: 1 })).toBeVisible();
  await page.goBack(); await expect(page).toHaveURL(`${portalHost}/admin/clients`);
  await page.getByRole('button', { name: 'Open account menu for Test Staff' }).click();
  await page.getByRole('menuitem', { name: 'My profile' }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/profile`);
  await expect(page.getByText('Account profile')).toBeVisible();
  await page.goto(`${portalHost}/admin/users`);
  await expect(page.getByText('You do not have permission to access this page.')).toBeVisible();
});

test('practitioner mobile navigation retains own schedule, not client administration', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); await fixtures(page, ['practitioner']);
  await page.goto(portalHost); await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.getByRole('button', { name: 'Open portal menu' }).click();
  await page.getByRole('link', { name: /Appointments Bookings/ }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner/schedule`);
  await expect(page.getByRole('button', { name: 'Book appointment', exact: true })).toHaveCount(0);
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByText('You do not have permission to access this page.')).toBeVisible();
});

test('dual roles can switch eligible workspaces and retain that preference', async ({ page }) => {
  await fixtures(page, ['super_admin', 'practitioner']); await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await page.getByRole('link', { name: 'Practitioner', exact: true }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.goto(portalHost); await expect(page).toHaveURL(`${portalHost}/practitioner`);
});

test('legacy public staff bookmarks migrate to guarded portal routes', async ({ page }) => {
  await fixtures(page, ['super_admin']); await page.goto(`${publicHost}/?portal=clients#portal`);
  await expect(page).toHaveURL(`${portalHost}/admin/clients`);
  await expect(page.getByRole('heading', { name: 'Clients', exact: true })).toBeVisible();
});

test('failed authorization never renders protected screens and allows retry', async ({ page }) => {
  await fixtures(page, ['super_admin']);
  await page.route('**/api/v1/auth/me', route => route.fulfill({ status: 401, json: { error: { message: 'Token expired', correlation_id: 'test-reference' } } }));
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole('alert')).toContainText('Token expired');
  await expect(page.getByRole('button', { name: 'Add client' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
});

test('accountant has only currently released authorized screens', async ({ page }) => {
  await fixtures(page, ['accountant']); await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await expect(page.getByRole('link', { name: /Clients Contact/ })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /Appointments Bookings/ })).toHaveCount(0);
  await page.goto(`${portalHost}/admin/appointments`);
  await expect(page.getByText('You do not have permission to access this page.')).toBeVisible();
});

test('unknown portal paths show a safe not-found screen', async ({ page }) => {
  await fixtures(page, ['reception']); await page.goto(`${portalHost}/admin/not-a-page`);
  await expect(page.getByText('This portal page was not found.')).toBeVisible();
  await page.getByRole('link', { name: 'Return to your workspace' }).click();
  await expect(page).toHaveURL(`${portalHost}/admin`);
});

test('screenshots: public and mobile portal layouts', async ({ page }) => {
  await fixtures(page, ['super_admin']); await page.goto(publicHost);
  await expect(page.getByRole('heading', { name: 'Feel better, on your schedule.' })).toBeVisible();
  await page.screenshot({ path: '../.tmp/public-home.png', fullPage: true });
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole('button', { name: 'Add client' })).toBeVisible();
  await page.screenshot({ path: '../.tmp/portal-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'Open portal menu' }).click();
  await expect(page.getByRole('button', { name: 'Close portal menu' })).toBeVisible();
  await expect.poll(async () => Math.round((await page.locator('.MuiDrawer-paper').boundingBox())!.x)).toBe(0);
  await page.screenshot({ path: '../.tmp/portal-mobile.png', fullPage: true });
});
