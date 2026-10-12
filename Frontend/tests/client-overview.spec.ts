import { expect, test, type Page } from '@playwright/test';

const host = 'http://localhost:5184';
const client = { id: '8', display_name: 'Avery Client', given_name: 'Avery', family_name: 'Client', email: 'avery@example.test', phone: '905-555-0188', preferred_contact: 'email', date_of_birth: '1991-04-12', emergency_contact_name: 'Morgan Client', emergency_contact_phone: '905-555-0189', administrative_notes: 'Prefers afternoon calls', status: 'active', revision: 'client-rev', address: null };
const appointment = { id: '71', client_id: '8', practitioner_id: '2', starts_at: '2026-09-01 14:00:00', ends_at: '2026-09-01 15:00:00', created_at: '2026-08-01 12:00:00', status: 'completed', delivery_mode: 'clinic', service_name: 'Massage therapy', service_name_fr: 'Massothérapie', practitioner_name: 'Esther Practitioner', location_name: 'Main clinic', timezone: 'America/Toronto', room_name: 'Room A', base_price_cents: '10000', mobile_fee_cents: '0', cancellation_fee_cents: null, currency: 'CAD', source: 'portal', duration_option_id: '1', room_id: '1', version: '1', destination_snapshot: null, travel_buffer_minutes: '0', client_name: 'Avery Client' };
const access = { practitioner_id: '2', user_id: '2', display_name: 'Esther Practitioner', discipline: 'Massage', account_status: 'active', practitioner_active: true, membership_required: false, membership_status: null, identity_status: null, appointment_count: 2, created_client: true, first_appointment_at: '2026-08-01 14:00:00', last_appointment_at: '2026-09-01 14:00:00', booking_mode: 'practitioner_managed', roles: ['practitioner'], permissions: [], configured_access: { staff_configuration_active: true, client_directory: true, booking_contact: true, client_administration: false, appointments: 'own', appointment_changes: 'own', logistics_notes: 'own' } };

async function fixtures(page: Page, roles = ['super_admin']) {
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account={homeAccountId:'test-staff',name:'Staff',username:'staff@example.test'};
    const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-only-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};
    export const StaffAuthProvider=({children})=>children;export const selectStaffAccount=()=>account;export const useStaffAuth=()=>auth;
  ` }));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: 'https://public.example.test/' } }));
  await page.route('**/api/v1/**', route => {
    const url = new URL(route.request().url()), path = url.pathname.replace('/api/v1', '');
    const data = path === '/site-config' ? { name: 'Test Clinic' } : path === '/auth/me' ? { roles, permissions: [] }
      : path === '/profile/avatar' ? { image_base64: null } : path === '/clients/8' ? client
        : path === '/clients/8/invitations' ? { items: [], linked: false }
          : path === '/clients' ? { items: [client], has_more: false }
            : path === '/clients/8/appointments' ? { client, items: [appointment], page: 1, has_more: false, counts: { total: 1, upcoming: 0, past: 1, canceled: 0 } }
              : path === '/clients/8/practitioner-access' ? { client, items: [access], page: 1, has_more: false, basis: 'local_configuration' }
                : path === '/appointments/71' ? appointment : path === '/appointments/71/logistics-notes' ? { notes: [], truncated: false } : [];
    return route.fulfill({ json: { data } });
  });
}

test('client details open history and appointment details with a return to the same client', async ({ page }) => {
  await fixtures(page);
  await page.goto(`${host}/admin/clients`);
  await page.getByRole('button', { name: /Avery Client.*avery@example.test/ }).click();
  await page.getByRole('button', { name: 'Details', exact: true }).click();
  await expect(page.getByText('Prefers afternoon calls', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'View appointments', exact: true }).click();
  await expect(page.getByText('Massage therapy', { exact: true })).toBeVisible();
  await expect(page.getByText('America/Toronto', { exact: true })).toBeVisible();
  await expect(page.getByText('Appointment price: $100.00')).toBeVisible();
  await page.route('**/api/v1/clients/8/appointments/71/history?**', route => route.fulfill({ json: { data: {
    appointment: { id: 71 }, has_more: false, page: 1, items: [
      { id: 1, kind: 'status', created_at: '2026-09-01 16:00:00', from_status: 'confirmed', to_status: 'completed', actor_name: 'Esther Practitioner', reason: 'Visit finished', fee_triggered_cents: 0 },
      { id: 2, kind: 'reassignment', created_at: '2026-08-10 12:00:00', previous_practitioner: 'Previous Practitioner', next_practitioner: 'Esther Practitioner', actor_name: 'Clinic Admin', reason: 'Coverage change' },
      { id: 3, kind: 'fee_adjustment', created_at: '2026-08-02 12:00:00', actor_name: 'Clinic Admin', original_fee_cents: 2500, adjusted_fee_cents: 0, reason: 'Fee waived' },
    ],
  } } }));
  await page.getByRole('button', { name: 'View recorded changes' }).click();
  await expect(page.getByText('Reason: Visit finished')).toBeVisible();
  await expect(page.getByText('Previous Practitioner → Esther Practitioner')).toBeVisible();
  await expect(page.getByText('$25.00 → $0.00')).toBeVisible();
  await page.getByRole('link', { name: 'Open appointment' }).click();
  await expect(page).toHaveURL(`${host}/admin/appointments?appointment_id=71&return_client_id=8`);
  await expect(page.getByText('Appointment details', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Close panel', exact: true }).click();
  await page.getByRole('link', { name: 'Back to client history' }).click();
  await expect(page.getByText('Client overview', { exact: true })).toBeVisible();
  await expect(page.getByText('Massage therapy', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Back to client details' }).click();
  await expect(page.getByText('Prefers afternoon calls', { exact: true })).toBeVisible();
});

test('history filters retain cancelled appointments, pagination and an empty view', async ({ page }) => {
  await fixtures(page, ['reception']);
  const requests: string[] = [];
  await page.route('**/api/v1/clients/8/appointments?**', route => {
    const url = new URL(route.request().url()); requests.push(url.search);
    const view = url.searchParams.get('view'), next = url.searchParams.get('page') === '2';
    const items = view === 'upcoming' ? [] : [{ ...appointment, id: next ? '72' : '71', status: 'canceled_by_client', cancellation_fee_cents: '2500' }];
    return route.fulfill({ json: { data: { client, items, page: next ? 2 : 1, has_more: view === 'all' && !next, counts: { total: 26, upcoming: 0, past: 26, canceled: 26 } } } });
  });
  await page.goto(`${host}/admin/clients?client_id=8`);
  await expect(page.getByText('canceled by client', { exact: true })).toBeVisible();
  await expect(page.getByText('Cancellation fee: $25.00')).toBeVisible();
  await page.getByRole('button', { name: 'Next', exact: true }).last().click();
  await expect(page.getByText('Appointment #72', { exact: false })).toBeVisible();
  await page.getByRole('combobox', { name: 'Appointment history filter' }).click();
  await page.getByRole('option', { name: 'Upcoming appointments', exact: true }).click();
  await expect(page.getByText('No appointments match this view.')).toBeVisible();
  expect(requests).toContain('?view=upcoming&page=1');
  await page.getByRole('combobox', { name: 'Appointment history filter' }).click();
  await page.getByRole('option', { name: 'Cancelled appointments', exact: true }).click();
  await expect(page.getByText('canceled by client', { exact: true })).toBeVisible();
});

test('access report distinguishes relationships, broad booking access and inactive accounts', async ({ page }) => {
  await fixtures(page, ['clinic_admin']);
  await page.route('**/api/v1/clients/8/practitioner-access?**', route => route.fulfill({ json: { data: { client, page: 1, has_more: false, items: [access,
    { ...access, practitioner_id: 3, display_name: 'Unrelated Practitioner', created_client: false, appointment_count: 0, first_appointment_at: null, last_appointment_at: null, configured_access: { ...access.configured_access, client_directory: false } },
    { ...access, practitioner_id: 4, display_name: 'Inactive Practitioner', account_status: 'inactive', practitioner_active: false, configured_access: { staff_configuration_active: false, client_directory: false, booking_contact: false, client_administration: false, appointments: 'none', appointment_changes: 'none', logistics_notes: 'none' } },
  ] } } }));
  await page.goto(`${host}/admin/clients?client_id=8`);
  await page.getByRole('tab', { name: 'Practitioner access', exact: true }).click();
  await expect(page.getByText('This practitioner created the client record.').first()).toBeVisible();
  await expect(page.getByText('Appointments with this client: 2').first()).toBeVisible();
  const unrelated = page.locator('.MuiPaper-root').filter({ has: page.getByText('Unrelated Practitioner', { exact: true }) }).last();
  await expect(unrelated.getByText('Client directory: No configured access')).toBeVisible();
  await expect(unrelated.getByText('Booking contact details: Configured access')).toBeVisible();
  const inactive = page.locator('.MuiPaper-root').filter({ has: page.getByText('Inactive Practitioner', { exact: true }) }).last();
  await expect(inactive.getByText('Account access blocked')).toBeVisible();
  await expect(inactive.getByText('Appointment visibility: No configured access')).toBeVisible();
  await expect(page.getByText(/Actual access also requires authorized staff sign-in/)).toBeVisible();
});

test('overview retries failures and rejects a different client response', async ({ page }) => {
  await fixtures(page); let fail = true;
  await page.route('**/api/v1/clients/8/appointments?**', route => {
    if (fail) return route.fulfill({ status: 503, json: { error: { code: 'unavailable' } } });
    return route.fulfill({ json: { data: { client: { ...client, id: 999 }, items: [appointment], page: 1, has_more: false } } });
  });
  await page.goto(`${host}/admin/clients?client_id=8`);
  await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
  fail = false;
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.getByText('The client overview response is invalid.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open appointment' })).toHaveCount(0);
});

test('mobile client history supports French and preserves the selected client', async ({ page }) => {
  await fixtures(page); await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${host}/admin/clients?client_id=8&lang=fr`);
  await expect(page.getByText('Massothérapie', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ouvrir le rendez-vous' })).toBeVisible();
  await expect(page.getByText('Avery Client', { exact: true }).last()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
