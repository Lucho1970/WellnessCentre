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
    export const selectStaffAccount=()=>account;
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

test('staff client invitation approval requires review code and verification checkbox', async ({page})=>{
  await fixtures(page,['super_admin']);
  let approved=false, posts=0;
  const client={id:7,display_name:'Existing Client',given_name:'Existing',family_name:'Client',email:'existing@example.test',phone:'555-0100',status:'active',preferred_contact:'email',revision:'rev'};
  await page.route('**/api/v1/clients**',route=>{
    const path=new URL(route.request().url()).pathname;
    let data:unknown={items:[client],has_more:false};
    if(path.endsWith('/7'))data=client;
    if(path.includes('/invitations')){
      if(route.request().method()==='POST'){
        const body=route.request().postDataJSON();expect(body.action).toBe('approve');expect(body.identity_verified).toBe(true);expect(body.review_code).toBe('ABCDEF123456');approved=true;posts++;
      }
      data={linked:approved,items:[{id:9,expires_at:'2026-10-01 12:00:00',consumed_at:'2026-09-17 12:00:00',revoked_at:null,claim_status:approved?'approved':'pending',claimant_name:'Unverified Claimant'}]};
    }
    return route.fulfill({json:{data}});
  });
  await page.goto(`${portalHost}/admin/clients`);
  await page.getByRole('button',{name:'Edit Existing Client'}).click();
  const approve=page.getByRole('button',{name:'Approve client link'});
  await expect(approve).toBeDisabled();
  await page.getByRole('textbox',{name:'Review code from the verified client'}).fill('ABCDEF123456');
  await expect(approve).toBeDisabled();
  await page.getByRole('checkbox',{name:/I independently verified/}).check();
  await expect(approve).toBeEnabled();page.on('dialog',dialog=>dialog.accept());
  await approve.click();
  await expect(page.getByText('This client record has an approved customer identity link.')).toBeVisible();
  expect(posts).toBe(1);
});

test('new services can be assigned without reloading or losing assignment selections', async ({ page }) => {
  await fixtures(page, ['super_admin']);
  const services = [{ id: 1, name: 'Existing massage', price_cents: 10000, durations: [60], duration_options: [{ minutes: 60, price_cents: 10000 }], active: 1, requires_room: 1 }];
  let createdService: Record<string, any> | undefined;
  let savedAssignment: unknown;
  await page.route('**/api/v1/admin/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = [];
    if (path.endsWith('/services')) {
      if (route.request().method() === 'POST') { createdService = route.request().postDataJSON(); services.push({ ...services[0], ...createdService, id: 2 }); }
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
  await page.getByRole('spinbutton', { name: 'Price 1 (CAD)' }).fill('120');
  await page.getByRole('button', { name: 'Add duration and price' }).click();
  await page.getByRole('spinbutton', { name: 'Duration 2 (minutes)' }).fill('90');
  await page.getByRole('spinbutton', { name: 'Price 2 (CAD)' }).fill('165');
  await page.getByRole('button', { name: 'Add service', exact: true }).click();
  await expect(page.getByText('New massage was created.')).toBeVisible();
  expect(createdService?.duration_options).toEqual([{ minutes: 60, price_cents: 12000 }, { minutes: 90, price_cents: 16500 }]);
  await expect(page.getByRole('checkbox', { name: 'Test Therapist', exact: true })).toBeChecked();
  await page.getByRole('combobox', { name: /^Service / }).click();
  await page.getByRole('option', { name: 'New massage', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Test Therapist', exact: true }).check();
  await page.getByRole('checkbox', { name: 'Test location', exact: true }).check();
  await page.getByRole('button', { name: 'Save assignments' }).click();
  await expect(page.getByText('Service assignments saved.')).toBeVisible();
  expect(savedAssignment).toMatchObject({ location_ids: [1], practitioners: [{ practitioner_id: 3, service_id: 2 }] });
});

test('mobile-only booking captures destination and price without requesting a room', async ({ page }) => {
  await fixtures(page, ['super_admin']);
  let booking: Record<string, any> | undefined;
  let availabilityMode = '';
  await page.route('**/api/v1/booking-options', route => route.fulfill({ json: { data: { rooms: [], combinations: [{ location_id: 1, location_name: 'Mobile service area', timezone: 'America/Toronto', service_id: 2, service_name: 'Massage', requires_room: 1, offers_mobile: 1, offers_clinic: 0, travel_buffer_minutes: 30, mobile_fee_cents: 2500, base_price_cents: 12000, practitioner_id: 3, practitioner_name: 'Therapist', duration_option_id: 4, duration_minutes: 60 }] } } }));
  await page.route('**/api/v1/booking-clients?**', route => route.fulfill({ json: { data: { items: [{ id: 5, display_name: 'Test Client', email: 'test@example.test', phone: '905-555-0100' }], has_more: false } } }));
  await page.route('**/api/v1/availability?**', route => {
    availabilityMode = new URL(route.request().url()).searchParams.get('delivery_mode') ?? '';
    return route.fulfill({ json: { data: { availability: [{ duration_option_id: 4, starts_at: '2030-10-01T10:00:00-04:00', ends_at: '2030-10-01T11:00:00-04:00', available_room_ids: [] }] } } });
  });
  await page.route('**/api/v1/appointments', route => { booking=route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 99 } } }); });
  await page.goto(`${portalHost}/admin/appointments`);
  await page.getByRole('button', { name: 'Book appointment', exact: true }).click();
  const select = async (label: RegExp, option: string) => { await page.getByRole('combobox', { name: label }).click(); await page.getByRole('option', { name: option, exact: true }).click(); };
  await page.getByRole('textbox', { name: 'Find an active client' }).fill('Test');
  await page.getByRole('button', { name: 'Select Test Client' }).click();
  await expect(page.getByText('Selected client')).toBeVisible();
  await select(/^Base location/, 'Mobile service area');
  await select(/^Service/, 'Massage');
  await select(/^Practitioner/, 'Therapist');
  await select(/^Duration/, '60 minutes — $120.00');
  await expect(page.getByRole('button', { name: 'Find a time', exact: true })).toBeDisabled();
  await page.getByRole('textbox', { name: 'Street address' }).fill('123 Test Street');
  await page.getByRole('textbox', { name: 'City', exact: true }).fill('Test City');
  await page.getByRole('textbox', { name: 'Postal code' }).fill('A1A 1A1');
  await page.getByRole('checkbox', { name: /I verified this address/ }).check();
  await page.getByRole('button', { name: 'Find a time', exact: true }).click();
  await page.getByLabel('Appointment date').fill('2030-10-01');
  await page.getByRole('button', { name: 'Find times', exact: true }).click();
  await page.getByRole('button', { name: /Oct 1, 2030/ }).click();
  await expect(page.getByRole('combobox', { name: /Available room/ })).toHaveCount(0);
  await page.getByRole('button', { name: 'Review appointment' }).click();
  await expect(page.getByText(/Subtotal:.*145/)).toBeVisible();
  await page.getByRole('button', { name: 'Confirm appointment', exact: true }).click();
  await expect(page.getByText(/Appointment #99 confirmed/)).toBeVisible();
  expect(availabilityMode).toBe('mobile');
  expect(booking).toMatchObject({ delivery_mode: 'mobile', destination: { address_line1: '123 Test Street' }, coverage_confirmed: true, quoted_base_price_cents: 12000, quoted_mobile_fee_cents: 2500 });
  expect(booking).not.toHaveProperty('room_id');
});

test('appointment client finder debounces name, email, or phone searches and uses a details list', async ({ page }) => {
  await fixtures(page, ['super_admin']);
  await page.route('**/api/v1/booking-options', route => route.fulfill({ json: { data: { rooms: [], combinations: [{ location_id: 1, location_name: 'Test area', timezone: 'America/Toronto', service_id: 2, service_name: 'Massage', requires_room: 0, offers_mobile: 1, offers_clinic: 0, travel_buffer_minutes: 0, mobile_fee_cents: 0, base_price_cents: 10000, practitioner_id: 3, practitioner_name: 'Therapist', duration_option_id: 4, duration_minutes: 60 }] } } }));
  const terms: string[] = [];
  await page.route('**/api/v1/booking-clients?**', route => {
    terms.push(new URL(route.request().url()).searchParams.get('q') ?? '');
    return route.fulfill({ json: { data: { items: [{ id: 5, display_name: 'Test Client', email: 'test@example.test', phone: '905-555-0100' }], has_more: false } } });
  });
  await page.goto(`${portalHost}/admin/appointments`);
  await page.getByRole('button', { name: 'Book appointment', exact: true }).click();
  const search = page.getByRole('textbox', { name: 'Find an active client' });
  await search.fill('T'); await page.waitForTimeout(400); expect(terms).toEqual([]);
  await search.fill('Te'); await page.waitForTimeout(100); expect(terms).toEqual([]);
  await search.fill('Test');
  await expect(page.getByRole('button', { name: 'Select Test Client' })).toBeVisible();
  expect(terms).toEqual(['Test']);
  await expect(page.getByText('test@example.test')).toBeVisible();
  await expect(page.getByText('905-555-0100')).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Client', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Select Test Client' }).click();
  await expect(page.getByText('Selected client')).toBeVisible();
  await page.getByRole('button', { name: 'Change client' }).click();
  await expect(search).toHaveValue('');
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

test('public home has client-first login and no workforce authentication or fake address', async ({ page }) => {
  const requests: string[] = []; page.on('request', request => requests.push(request.url()));
  await fixtures(page); await page.goto(publicHost);
  await expect(page.getByRole('heading', { name: 'Feel better, on your schedule.' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Login', exact: true })).toHaveAttribute('href', `${portalHost}/client?lang=en`);
  await expect(page.getByRole('link', { name: 'Staff login', exact: true })).toHaveAttribute('href', `${portalHost}/staff/login?lang=en`);
  await expect(page.getByText('240 Queen Street')).toHaveCount(0);
  expect(requests.some(url => url.includes('AuthProvider') || url.includes('login.microsoftonline.com') || url.includes('/auth/me'))).toBe(false);
});

test('public booking hands off preferences without reserving or creating an appointment', async ({ page }) => {
  const writes: string[] = []; page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
  await fixtures(page); await page.goto(`${publicHost}/book`);
  await expect(page.getByText('60 min — $100.00')).toBeVisible();
  await expect(page.getByRole('button', { name: /Oct 1.*60 min.*\$100\.00/ })).toBeVisible();
  await page.getByRole('button', { name: /Oct 1.*60 min/ }).click();
  await page.getByRole('link', { name: 'View client booking information' }).click();
  await expect(page).toHaveURL(/localhost:5184\/client\/book\?delivery_mode=mobile&location_id=1/);
  await expect(page.getByText('No appointment has been requested or reserved.', { exact: false })).toBeVisible();
  expect(writes).toEqual([]);
});

test('public infrastructure errors have a readable retry state', async ({ page }) => {
  await fixtures(page);
  await page.route('**/api/v1/services', route => route.fulfill({ status: 500, body: '', contentType: 'text/html' }));
  await page.goto(`${publicHost}/book`);
  await expect(page.getByRole('alert').filter({ hasText: 'The service is temporarily unavailable. Please try again.' })).toBeVisible();
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

test('practitioner mobile navigation can book and change only the scoped schedule', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 }); await fixtures(page, ['practitioner']);
  const scopedRequests: string[] = []; const changes: Record<string, unknown>[] = [];
  const appointment = { id: 10, client_id: 5, practitioner_id: 3, service_id: 2, duration_option_id: 4, room_id: null, delivery_mode: 'mobile', destination_snapshot: null, travel_buffer_minutes: 30, base_price_cents: 10000, mobile_fee_cents: 0, client_name: 'Existing Client', service_name: 'Massage', practitioner_name: 'Test Practitioner', location_name: 'Holland Landing', timezone: 'America/Toronto', room_name: null, starts_at: '2030-10-01 14:00:00', ends_at: '2030-10-01 15:00:00', status: 'confirmed', version: 2 };
  await page.route('**/api/v1/appointments?**', route => { scopedRequests.push(route.request().url()); return route.fulfill({ json: { data: [appointment] } }); });
  await page.route('**/api/v1/booking-options?**', route => { scopedRequests.push(route.request().url()); return route.fulfill({ json: { data: { rooms: [], combinations: [{ location_id: 1, location_name: 'Mobile area', timezone: 'America/Toronto', service_id: 2, service_name: 'Massage', requires_room: 0, offers_mobile: 1, offers_clinic: 0, travel_buffer_minutes: 30, mobile_fee_cents: 0, base_price_cents: 10000, practitioner_id: 3, practitioner_name: 'Test Practitioner', duration_option_id: 4, duration_minutes: 60 }] } } }); });
  await page.route('**/api/v1/booking-clients?**', route => { scopedRequests.push(route.request().url()); return route.fulfill({ json: { data: { items: [{ id: 6, display_name: 'New Clinic Client', email: 'new-client@example.test', phone: '905-555-0110' }], has_more: false } } }); });
  await page.route('**/api/v1/appointments/10/availability?**', route => route.fulfill({ json: { data: { availability: [{ duration_option_id: 4, starts_at: '2030-10-02T10:00:00-04:00', ends_at: '2030-10-02T11:00:00-04:00', available_room_ids: [] }] } } }));
  await page.route('**/api/v1/appointments/10', route => { changes.push(route.request().postDataJSON()); return route.fulfill({ json: { data: { ...appointment, version: 3 } } }); });
  await page.goto(portalHost); await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.getByRole('button', { name: 'Open portal menu' }).click();
  await page.getByRole('link', { name: /Appointments Bookings/ }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner/schedule`);
  await expect(page.getByRole('button', { name: 'Book appointment', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Book appointment', exact: true }).click();
  await expect.poll(() => scopedRequests.some(url => url.includes('/booking-options?scope=practitioner'))).toBe(true);
  await page.getByRole('textbox', { name: 'Find an active client' }).fill('New Clinic');
  await expect(page.getByRole('button', { name: 'Select New Clinic Client' })).toBeVisible();
  expect(scopedRequests.some(url => url.includes('/booking-clients?') && url.includes('scope=practitioner'))).toBe(true);
  await page.getByRole('button', { name: 'Select New Clinic Client' }).click();
  await expect(page.getByText('Selected client')).toBeVisible();
  await expect(page.getByText('New Clinic Client')).toBeVisible();
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Change appointment' }).click();
  await page.getByRole('button', { name: 'Reschedule', exact: true }).click();
  await page.getByLabel('Appointment date').fill('2030-10-02');
  await page.getByRole('button', { name: 'Find times', exact: true }).click();
  await page.getByRole('button', { name: /Oct 2, 2030/ }).click();
  await page.getByRole('button', { name: 'Confirm reschedule' }).click();
  await expect(page.getByText('Appointment #10 was rescheduled.')).toBeVisible();
  expect(changes[0]).toMatchObject({ action: 'reschedule', version: 2, starts_at: '2030-10-02T10:00:00-04:00' });
  await page.getByRole('button', { name: 'Change appointment' }).click();
  await page.getByRole('button', { name: 'Cancel appointment' }).click();
  await page.getByRole('button', { name: 'Confirm cancellation' }).click();
  await expect(page.getByText('Appointment #10 was canceled.')).toBeVisible();
  expect(changes[1]).toMatchObject({ action: 'cancel', version: 2 });
  expect(scopedRequests.some(url => url.includes('/appointments?') && url.includes('scope=practitioner'))).toBe(true);
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
  await expect(page.getByRole('alert')).toContainText('Your sign-in is no longer valid. Please sign in again.');
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

test('signed-in staff login returns to the authorized workspace', async ({ page }) => {
  await fixtures(page, ['practitioner']);
  await page.goto(`${portalHost}/staff/login`);
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await expect(page.getByText('Your clinic workspace', { exact: true })).toBeVisible();
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
