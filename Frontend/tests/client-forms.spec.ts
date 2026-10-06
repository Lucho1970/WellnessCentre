import { expect, test, type Page } from '@playwright/test';
const host = 'http://localhost:5184';
const definition = { instructions: 'Synthetic questions only', instructions_fr: 'Questions fictives uniquement', questions: [{ id: 'q1', label: 'Synthetic answer', label_fr: 'Réponse fictive', type: 'text', required: true }, { id: 'yes', label: 'Synthetic yes/no', label_fr: 'Oui ou non fictif', type: 'yes_no', required: true }, { id: 'consent', label: 'Synthetic consent', label_fr: 'Consentement fictif', type: 'consent', required: true }] };
const task = { id: 19, name: 'Synthetic intake', status: 'pending', version: 1, template_version: 1, practitioner_name: 'Esther', practitioner_id: 7, required: 1, appointment_id: null, can_read_answers: true };
const template = { id: 12, name: 'Synthetic intake', form_type: 'consent', version: 1, owner_practitioner_id: 7, practitioner_name: 'Esther', definition, service_ids: [4] };
async function fixture(page: Page, mode: 'client' | 'admin' | 'practitioner' = 'client') {
  await page.route('**/src/customer/auth.ts', route => route.fulfill({ contentType: 'application/javascript', body: `const account={homeAccountId:'customer',name:'Test Client'};export const customerConfigured=true,customerHome='${host}/client';export const customerInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};export const selectCustomerAccount=()=>account;export const customerToken=async()=>'test-token';export const customerSignIn=async()=>{};export const customerSignOut=async()=>{};` }));
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `const account={homeAccountId:'staff',name:'Test Staff',username:'staff@example.test'};export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};export const StaffAuthProvider=({children})=>children;export const selectStaffAccount=()=>account;const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-token'};export const useStaffAuth=()=>auth;` }));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: 'https://public.example.test/' } }));
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
    const client = { id: 8, display_name: 'Test Client', email: 'client@example.test', status: 'active', appointment_count: 1, phone: null, preferred_contact: 'email' };
    let data: unknown = {};
    if (path === '/site-config') data = { name: 'Test Clinic' };
    if (path === '/auth/me') data = { roles: mode === 'practitioner' ? ['practitioner'] : ['super_admin'], permissions: [] };
    if (path === '/profile/avatar') data = { image_base64: null };
    if (path === '/customer/auth/me') data = { authenticated: true, authentication_context: 'customer', onboarding_status: 'linked', capabilities: ['own_appointments', 'book_own_appointments'], session: { idle_expires_at: Date.now() / 1000 + 1800, absolute_expires_at: Date.now() / 1000 + 28800 } };
    if (path === '/customer/appointments') data = { items: [] };
    if (path === '/practitioner/clients' || path === '/clients') data = { items: [client], has_more: false };
    if (path === '/clients/8') data = client;
    if (path === '/clients/8/invitations') data = { items: [], linked: false };
    if (path === '/customer/forms' || path === '/clients/8/forms') data = { client, items: [{ ...task, can_read_answers: mode !== 'admin' }], has_more: false };
    if (path === '/customer/forms/19' || path === '/forms/tasks/19') data = { ...task, definition, answers: null };
    if (path === '/forms/templates') data = { items: [template], practitioners: [{ id: 7, display_name: 'Esther' }], services: [{ id: 4, name: 'Massage' }], can_author: true, has_more: false };
    if (path === '/forms/templates/12/history') data = { items: [template] };
    return route.fulfill({ json: { data } });
  });
}
async function complete(page: Page) {
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'My forms', exact: true }).click(); await page.getByRole('button', { name: 'Complete form', exact: true }).click();
  await page.getByLabel('Synthetic answer').fill('Test response');
  await page.getByRole('combobox', { name: 'Synthetic yes/no' }).click(); await page.getByRole('option', { name: 'No', exact: true }).click();
  await page.getByRole('checkbox', { name: 'Synthetic consent', exact: true }).check();
  await page.getByRole('checkbox', { name: 'I reviewed these answers and confirm submission.' }).check();
}
test('client explicitly submits the assigned version and a required false yes/no answer', async ({ page }) => {
  await fixture(page); let body: any;
  await page.route('**/api/v1/customer/forms/19/submit', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 19, status: 'submitted' } } }); });
  await complete(page); await expect(page.getByRole('button', { name: 'My appointments', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible();
  expect(body).toEqual({ version: 1, confirmed: true, answers: { q1: 'Test response', yes: false, consent: true } }); await expect(page.getByRole('button', { name: 'My appointments', exact: true })).toBeEnabled();
});
test('uncertain submission locks answers and retries the identical request', async ({ page }) => {
  await fixture(page); const bodies: unknown[] = [];
  await page.route('**/api/v1/customer/forms/19/submit', route => { bodies.push(route.request().postDataJSON()); return route.fulfill(bodies.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry' } } } : { json: { data: { id: 19, status: 'submitted' } } }); });
  await complete(page); await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByRole('button', { name: 'Retry confirmation', exact: true })).toBeVisible(); await expect(page.getByLabel('Synthetic answer')).toBeDisabled(); await expect(page.getByRole('button', { name: 'My profile', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Retry confirmation', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(bodies[1]).toEqual(bodies[0]);
});
test('administrator client forms show status without an answer-reading action', async ({ page }) => {
  await fixture(page, 'admin'); await page.goto(`${host}/admin/clients`); await page.getByRole('button', { name: /Test Client.*client@example.test/ }).click(); await page.getByRole('button', { name: 'Details', exact: true }).click(); await page.getByRole('button', { name: 'Client forms', exact: true }).click();
  await expect(page.getByText('Answers are visible only to the client and assigned practitioner.')).toBeVisible(); await expect(page.getByRole('button', { name: 'View form', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Assign form', exact: true }).click(); await expect(page.getByRole('combobox', { name: 'Form template' })).toBeVisible();
});
test('assigned practitioner reviews submitted answers in their client drawer', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: unknown;
  await page.route('**/api/v1/forms/tasks/19', route => { if (route.request().method() === 'PATCH') { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 19 } } }); } return route.fulfill({ json: { data: { ...task, status: 'submitted', version: 2, definition, answers: { q1: 'Test response', yes: false, consent: true } } } }); });
  await page.goto(`${host}/practitioner/clients`); await page.getByRole('button', { name: /Test Client/ }).click(); await page.getByRole('button', { name: 'Client forms', exact: true }).click(); await page.getByRole('button', { name: 'View form', exact: true }).click(); await expect(page.getByLabel('Synthetic answer')).toBeDisabled();
  await page.getByRole('button', { name: 'Mark form reviewed' }).click(); await expect(page.getByRole('button', { name: 'Mark form reviewed' })).toHaveCount(0); expect(body).toEqual({ action: 'review', version: 2 });
});
test('uncertain assignment keeps its key and locks client details until retry', async ({ page }) => {
  await fixture(page, 'admin'); const bodies: unknown[] = [];
  await page.route('**/api/v1/clients/8/forms', route => { if (route.request().method() !== 'POST') return route.fallback(); bodies.push(route.request().postDataJSON()); return route.fulfill(bodies.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry' } } } : { json: { data: { id: 20 } } }); });
  await page.goto(`${host}/admin/clients`); await page.getByRole('button', { name: /Test Client.*client@example.test/ }).click(); await page.getByRole('button', { name: 'Details', exact: true }).click(); await page.getByRole('button', { name: 'Client forms', exact: true }).click(); await page.getByRole('button', { name: 'Assign form', exact: true }).click();
  await page.getByRole('combobox', { name: 'Form template' }).click(); await page.getByRole('option', { name: /Synthetic intake/ }).click(); await page.getByRole('button', { name: 'Confirm assignment' }).click();
  await expect(page.getByRole('tab', { name: 'Appointment history' })).toBeDisabled(); await expect(page.getByRole('button', { name: 'Close panel' })).toBeDisabled(); await expect(page.getByRole('combobox', { name: 'Form template' })).toBeDisabled();
  await page.getByRole('button', { name: 'Retry confirmation', exact: true }).click(); await expect(page.getByText('Form assigned.', { exact: true })).toBeVisible(); expect(bodies[1]).toEqual(bodies[0]); await expect(page.getByRole('tab', { name: 'Appointment history' })).toBeEnabled();
});
test('template changes publish a new version and preserve the previous definition', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: any;
  await page.route('**/api/v1/forms/templates/12/versions', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Version history', exact: true }).click(); await expect(page.getByText('Synthetic answer / Réponse fictive')).toBeVisible(); await page.getByRole('button', { name: 'Close', exact: true }).click(); await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  await expect(page.getByRole('combobox', { name: 'Assigned practitioner' })).toBeDisabled(); await page.getByRole('textbox', { name: 'Form name', exact: true }).fill('Revised intake'); await page.getByRole('button', { name: 'Publish new version' }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible();
  expect(body.expected_version).toBe(1); expect(body.name).toBe('Revised intake'); expect(body.definition.questions[0].label).toBe('Synthetic answer'); expect(body.idempotency_key).toMatch(/^[a-f0-9-]{36}$/);
});
test('client form questions and controls remain usable in French on mobile', async ({ page }) => {
  await fixture(page); await page.setViewportSize({ width: 390, height: 844 }); await page.goto(`${host}/client`); await page.getByRole('button', { name: 'Language and region', exact: true }).click(); await page.getByRole('button', { name: /Français \(Canada\)/ }).click(); await page.getByRole('button', { name: 'Mes formulaires', exact: true }).click(); await page.getByRole('button', { name: 'Remplir le formulaire', exact: true }).click(); await expect(page.getByLabel('Réponse fictive')).toBeVisible();
  await expect(page.getByText('Questions fictives uniquement')).toBeVisible(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true); await page.screenshot({ path: 'test-results/client-form-mobile-fr.png', fullPage: true });
});

const typedDefinition = { instructions: '', instructions_fr: '', questions: [
  { id: 'birth', label: 'Birth date', label_fr: 'Date de naissance', type: 'date', required: true, no_future: true },
  { id: 'phone', label: 'Contact telephone', label_fr: 'Téléphone', type: 'phone', required: true },
  { id: 'email', label: 'Contact email', label_fr: 'Courriel', type: 'email', required: true }
] };
async function openTyped(page: Page) {
  await fixture(page);
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, definition: typedDefinition, answers: null } } }));
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'My forms', exact: true }).click(); await page.getByRole('button', { name: 'Complete form', exact: true }).click();
}
test('date, international phone and email fields submit canonical answers', async ({ page }) => {
  await openTyped(page); let body: any;
  await page.route('**/api/v1/customer/forms/19/submit', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 19, status: 'submitted' } } }); });
  await page.getByLabel('Birth date').fill('2000-02-29');
  await expect(page.getByLabel('Birth date')).toHaveAttribute('type', 'date');
  await expect(page.getByLabel('Birth date')).toHaveAttribute('max', /\d{4}-\d{2}-\d{2}/);
  await page.getByRole('combobox', { name: 'Country for Contact telephone' }).click(); await page.getByRole('option', { name: 'United Kingdom (+44)', exact: true }).click();
  await page.getByRole('textbox', { name: 'Contact telephone', exact: true }).fill('020 7946 0018'); await page.getByLabel('Contact email').fill('test@example.test');
  await page.getByRole('checkbox', { name: 'I reviewed these answers and confirm submission.' }).check(); await page.getByRole('button', { name: 'Submit form', exact: true }).click();
  await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(body.answers).toEqual({ birth: '2000-02-29', phone: '+442079460018', email: 'test@example.test' });
});
test('invalid typed answers remain editable and do not send a submission', async ({ page }) => {
  await openTyped(page); let sent = 0;
  await page.route('**/api/v1/customer/forms/19/submit', route => { sent++; return route.fulfill({ json: { data: { id: 19, status: 'submitted' } } }); });
  await page.getByLabel('Birth date').fill('9999-01-01'); await page.getByRole('textbox', { name: 'Contact telephone', exact: true }).fill('123'); await page.getByLabel('Contact email').fill('invalid'); await page.getByRole('checkbox', { name: 'I reviewed these answers and confirm submission.' }).check();
  await page.getByRole('button', { name: 'Submit form', exact: true }).click(); expect(sent).toBe(0);
  await page.getByLabel('Birth date').fill('2000-01-01'); await page.getByLabel('Contact email').fill('test@example.test'); await page.getByRole('button', { name: 'Submit form', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Enter a valid phone number for the selected country.' })).toBeVisible(); expect(sent).toBe(0); await expect(page.getByRole('textbox', { name: 'Contact telephone', exact: true })).toBeEnabled();
  await page.getByRole('textbox', { name: 'Contact telephone', exact: true }).fill('(416) 555-1234'); await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(sent).toBe(1);
});
test('a new version changes text to date while the existing version remains text', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: any;
  await page.route('**/api/v1/forms/templates/12/versions', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  await page.getByRole('combobox', { name: /Answer type/ }).first().click(); await page.getByRole('option', { name: 'Date', exact: true }).click(); await page.getByRole('checkbox', { name: 'No future dates (for example, birth date)' }).check();
  await page.getByRole('button', { name: 'Publish new version' }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible(); expect(body.definition.questions[0].type).toBe('date'); expect(body.definition.questions[0].no_future).toBe(true); expect(template.definition.questions[0].type).toBe('text');
});
test('saved typed answers are read-only on a French mobile screen', async ({ page }) => {
  await fixture(page); await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, status: 'submitted', definition: typedDefinition, answers: { birth: '2000-02-29', phone: '+14165551234', email: 'test@example.test' } } } }));
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'Language and region', exact: true }).click(); await page.getByRole('button', { name: /Français \(Canada\)/ }).click(); await page.getByRole('button', { name: 'Mes formulaires', exact: true }).click(); await page.getByRole('button', { name: 'Remplir le formulaire', exact: true }).click();
  await expect(page.getByLabel('Date de naissance')).toBeDisabled(); await expect(page.getByLabel('Téléphone')).toHaveValue('+14165551234'); await expect(page.getByLabel('Courriel')).toBeDisabled(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/typed-form-mobile-fr.png', fullPage: true });
});
