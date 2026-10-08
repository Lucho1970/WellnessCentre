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
    if (path === '/forms/templates') data = { items: [template], practitioners: [{ id: 7, display_name: 'Esther' }], services: [{ id: 4, name: 'Massage' }], can_author: true, has_more: false, drafts_enabled: true };
    if (path === '/forms/drafts') data = { items: [], has_more: false };
    if (path === '/forms/source/validate') data = route.request().postDataJSON().document;
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
  const phone = page.getByRole('textbox', { name: 'Contact telephone', exact: true });
  await expect(phone).toHaveAccessibleDescription('Enter a valid phone number for the selected country.');
  await expect(phone).toBeFocused(); await expect(phone).toBeInViewport(); expect(sent).toBe(0); await expect(phone).toBeEnabled();
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

const sectionedDefinition = { ...definition, sections: [
  { id: 'client_info', title: 'Client Information', title_fr: 'Renseignements du client', description: 'Please check your information.', description_fr: 'Vérifiez vos renseignements.' },
  { id: 'health', title: 'Health History', title_fr: 'Antécédents médicaux', description: 'Use synthetic answers only.', description_fr: 'Utilisez uniquement des réponses fictives.' }
], questions: definition.questions.map((question, index) => ({ ...question, section_id: index === 0 ? 'client_info' : 'health' })) };
test('sections group client questions and descriptions without changing answer IDs', async ({ page }) => {
  await fixture(page); let body: any;
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, definition: sectionedDefinition, answers: null } } }));
  await page.route('**/api/v1/customer/forms/19/submit', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 19, status: 'submitted' } } }); });
  await complete(page);
  await expect(page.getByRole('group', { name: 'Client Information', exact: true }).getByLabel('Synthetic answer')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Health History', exact: true }).getByRole('checkbox', { name: 'Synthetic consent', exact: true })).toBeVisible();
  await expect(page.getByText('Please check your information.')).toBeVisible(); await expect(page.getByText('Use synthetic answers only.')).toBeVisible();
  await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(body.answers).toEqual({ q1: 'Test response', yes: false, consent: true });
});
test('author creates sections, adds questions into them and reorders sections', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: any;
  await page.route('**/api/v1/forms/templates/12/versions', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  await page.getByRole('button', { name: 'Add section', exact: true }).click(); await page.getByLabel('Section 1 title (English)').fill('Client Information'); await page.getByLabel('Section 1 description (English)').fill('Check your details.');
  await page.getByRole('button', { name: 'Add section', exact: true }).click(); await page.getByLabel('Section 2 title (English)').fill('Health History');
  await page.getByRole('combobox', { name: 'Section for question 1' }).first().click(); await page.getByRole('option', { name: 'Client Information', exact: true }).click();
  await page.getByRole('button', { name: 'Add question to Health History', exact: true }).click(); await page.getByRole('textbox', { name: 'Question (English)' }).last().fill('Synthetic history question');
  await page.getByRole('button', { name: 'Move section down', exact: true }).first().click(); await expect(page.getByLabel('Section 1 title (English)')).toHaveValue('Health History');
  await page.getByRole('button', { name: 'Publish new version' }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible();
  expect(body.definition.sections.map((section: any) => section.title)).toEqual(['Health History', 'Client Information']); expect(body.definition.sections[1].description).toBe('Check your details.');
  expect(body.definition.questions[0].section_id).toBe(body.definition.sections[1].id); expect(body.definition.questions[3].section_id).toBe(body.definition.sections[0].id); expect(template.definition.questions).toHaveLength(3);
});
test('removing a section preserves its questions and uncertain publication locks sections', async ({ page }) => {
  await fixture(page, 'practitioner'); const bodies: any[] = [];
  await page.route('**/api/v1/forms/templates*', route => route.fulfill({ json: { data: { items: [{ ...template, definition: sectionedDefinition }], practitioners: [{ id: 7, display_name: 'Esther' }], services: [], can_author: true, has_more: false } } }));
  await page.route('**/api/v1/forms/templates/12/versions', route => { bodies.push(route.request().postDataJSON()); return route.fulfill(bodies.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry' } } } : { json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: 'Remove section', exact: true }).first().click(); await expect(page.getByText('Questions without a section', { exact: true }).first()).toBeVisible(); await expect(page.getByRole('textbox', { name: 'Question (English)' })).toHaveCount(3);
  await page.getByRole('button', { name: 'Publish new version' }).click(); await expect(page.getByRole('button', { name: 'Retry confirmation', exact: true })).toBeVisible(); await expect(page.getByLabel('Section 1 title (English)')).toBeDisabled(); await expect(page.getByRole('button', { name: 'Add section', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Retry confirmation', exact: true }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible(); expect(bodies[1]).toEqual(bodies[0]); expect(bodies[0].definition.questions[0].section_id).toBeUndefined(); expect(bodies[0].definition.questions).toHaveLength(3);
});
test('French mobile forms show localized section titles and descriptions', async ({ page }) => {
  await fixture(page); await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, definition: sectionedDefinition, answers: null } } }));
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'Language and region', exact: true }).click(); await page.getByRole('button', { name: /Français \(Canada\)/ }).click(); await page.getByRole('button', { name: 'Mes formulaires', exact: true }).click(); await page.getByRole('button', { name: 'Remplir le formulaire', exact: true }).click();
  await expect(page.getByRole('group', { name: 'Renseignements du client', exact: true })).toBeVisible(); await expect(page.getByText('Vérifiez vos renseignements.')).toBeVisible(); await expect(page.getByRole('group', { name: 'Antécédents médicaux', exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true); await page.screenshot({ path: 'test-results/form-sections-mobile-fr.png', fullPage: true });
});

const choiceOptions = [{ id: 'low', label: 'Low', label_fr: 'Faible' }, { id: 'moderate', label: 'Moderate', label_fr: 'Modéré' }, { id: 'high', label: 'High', label_fr: 'Élevé' }];
const choiceDefinition = { instructions: '', instructions_fr: '', questions: [
  { id: 'stress', label: 'Average stress level', label_fr: 'Niveau de stress moyen', type: 'single_choice', required: true, options: choiceOptions },
  { id: 'habits', label: 'Lifestyle choices', label_fr: 'Habitudes de vie', type: 'multiple_choice', required: true, options: choiceOptions }
] };
async function openChoices(page: Page) {
  await fixture(page);
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, definition: choiceDefinition, answers: null } } }));
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'My forms', exact: true }).click(); await page.getByRole('button', { name: 'Complete form', exact: true }).click();
}
test('required radio and checkbox questions reject empty answers and submit selected IDs', async ({ page }) => {
  await openChoices(page); let body: any;
  await page.route('**/api/v1/customer/forms/19/submit', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 19, status: 'submitted' } } }); });
  await page.getByRole('checkbox', { name: 'I reviewed these answers and confirm submission.' }).check(); await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Check the form questions' })).toBeVisible(); expect(body).toBeUndefined();
  await page.getByRole('radio', { name: 'Low', exact: true }).check(); await page.getByRole('radio', { name: 'Moderate', exact: true }).check(); await expect(page.getByRole('radio', { name: 'Low', exact: true })).not.toBeChecked();
  await page.getByRole('button', { name: 'Submit form', exact: true }).click(); expect(body).toBeUndefined();
  await page.getByRole('checkbox', { name: 'Low', exact: true }).check(); await page.getByRole('checkbox', { name: 'High', exact: true }).check(); await page.getByRole('button', { name: 'Submit form', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(body.answers).toEqual({ stress: 'moderate', habits: ['high', 'low'] });
});
test('uncertain choice submission locks selections and retries the same answers', async ({ page }) => {
  await openChoices(page); const bodies: any[] = [];
  await page.route('**/api/v1/customer/forms/19/submit', route => { bodies.push(route.request().postDataJSON()); return route.fulfill(bodies.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry' } } } : { json: { data: { id: 19, status: 'submitted' } } }); });
  await page.getByRole('radio', { name: 'High', exact: true }).check(); await page.getByRole('checkbox', { name: 'Moderate', exact: true }).check(); await page.getByRole('checkbox', { name: 'I reviewed these answers and confirm submission.' }).check(); await page.getByRole('button', { name: 'Submit form', exact: true }).click();
  await expect(page.getByRole('radio', { name: 'Low', exact: true })).toBeDisabled(); await expect(page.getByRole('checkbox', { name: 'High', exact: true })).toBeDisabled(); await page.getByRole('button', { name: 'Retry confirmation', exact: true }).click(); await expect(page.getByText('Form submitted. Your answers have been saved.')).toBeVisible(); expect(bodies[1]).toEqual(bodies[0]);
});
test('author publishes editable choice options in a new version', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: any;
  await page.route('**/api/v1/forms/templates/12/versions', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  await page.getByRole('combobox', { name: /Answer type/ }).first().click(); await page.getByRole('option', { name: 'Single choice (radio buttons)', exact: true }).click();
  await page.getByLabel('Option 1 (English)').fill('Low'); await page.getByLabel('Option 1 (French)').fill('Faible'); await page.getByLabel('Option 2 (English)').fill('Moderate'); await page.getByRole('button', { name: 'Add option', exact: true }).click(); await page.getByLabel('Option 3 (English)').fill('High');
  await page.getByRole('button', { name: 'Publish new version' }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible(); expect(body.definition.questions[0].options.map((option: any) => option.label)).toEqual(['Low', 'Moderate', 'High']); expect(body.definition.questions[0].type).toBe('single_choice'); expect(template.definition.questions[0].type).toBe('text');
});
test('saved choice answers display read-only French labels on mobile', async ({ page }) => {
  await fixture(page); await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/api/v1/customer/forms/19', route => route.fulfill({ json: { data: { ...task, status: 'submitted', definition: choiceDefinition, answers: { stress: 'moderate', habits: ['high', 'low'] } } } }));
  await page.goto(`${host}/client`); await page.getByRole('button', { name: 'Language and region', exact: true }).click(); await page.getByRole('button', { name: /Français \(Canada\)/ }).click(); await page.getByRole('button', { name: 'Mes formulaires', exact: true }).click(); await page.getByRole('button', { name: 'Remplir le formulaire', exact: true }).click();
  await expect(page.getByRole('radio', { name: 'Modéré', exact: true })).toBeChecked(); await expect(page.getByRole('radio', { name: 'Modéré', exact: true })).toBeDisabled(); await expect(page.getByRole('checkbox', { name: 'Élevé', exact: true })).toBeChecked(); await expect(page.getByRole('checkbox', { name: 'Élevé', exact: true })).toBeDisabled(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true); await page.screenshot({ path: 'test-results/form-choices-mobile-fr.png', fullPage: true });
});

const portable = { format: 'wellness-form', format_version: 1, name: template.name, form_type: template.form_type, definition };
test('editable source validates JSON and publishes changes as a new version', async ({ page }) => {
  await fixture(page, 'practitioner'); let body: any;
  await page.route('**/api/v1/forms/templates/12/versions', route => { body = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'View source', exact: true }).click();
  const panel = page.getByRole('dialog'), raw = panel.getByRole('textbox', { name: 'Form JSON source' });
  expect(JSON.parse(await raw.inputValue())).toEqual(portable);
  await raw.fill('{broken'); await panel.getByRole('button', { name: 'Apply source', exact: true }).click();
  await expect(panel.getByText('Enter valid JSON before applying the source.')).toBeVisible();
  await expect(page.getByLabel('Form name', { exact: false })).toHaveValue(template.name);
  const changed = structuredClone(portable); changed.name = 'Edited source'; changed.definition.questions[0].label = 'Source question'; await raw.fill(JSON.stringify(changed));
  const download = page.waitForEvent('download'); await panel.getByRole('button', { name: 'Download JSON', exact: true }).click(); expect((await download).suggestedFilename()).toBe('form-source.json');
  await panel.getByRole('button', { name: 'Apply source', exact: true }).click(); await expect(panel).toHaveCount(0);
  await page.getByRole('button', { name: 'Publish new version', exact: true }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible();
  expect(body.expected_version).toBe(1); expect(body.definition.questions[0].label).toBe('Source question'); expect(body.service_ids).toEqual([4]);
});
test('rejected source schema leaves the original editor intact', async ({ page }) => {
  await fixture(page, 'practitioner'); await page.route('**/api/v1/forms/source/validate', route => route.fulfill({ status: 422, json: { error: { code: 'invalid_form', message: 'Invalid schema' } } }));
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'View source', exact: true }).click(); const panel = page.getByRole('dialog');
  await panel.getByRole('textbox', { name: 'Form JSON source' }).fill(JSON.stringify({ ...portable, name: 'Should not apply', answers: ['private'] }));
  await panel.getByRole('button', { name: 'Apply source', exact: true }).click(); await expect(panel.getByRole('alert')).toBeVisible(); await expect(page.getByLabel('Form name', { exact: false })).toHaveValue(template.name);
});
test('unfinished draft can be saved with blank labels and resumed after reload', async ({ page }) => {
  await fixture(page, 'practitioner'); let stored: any;
  await page.route('**/api/v1/forms/drafts*', route => { if (route.request().method() === 'POST') { stored = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 31, version: 1, status: 'draft' } } }); } return route.fulfill({ json: { data: { items: stored ? [{ id: 31, version: 1, name: stored.name, updated_at: '2026-10-06 12:00:00' }] : [], has_more: false } } }); });
  await page.route('**/api/v1/forms/drafts/31', route => { const { idempotency_key, draft_version, ...payload } = stored; return route.fulfill({ json: { data: { id: 31, version: 1, status: 'draft', payload } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create form', exact: true }).click(); await page.getByRole('textbox', { name: 'Form name', exact: true }).fill('Unfinished intake');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click(); await expect(page.getByText('Draft saved. You can return after signing in again.')).toBeVisible(); expect(stored.definition.questions[0].label).toBe('');
  await page.reload(); await page.getByRole('button', { name: 'Resume draft', exact: true }).click(); await expect(page.getByRole('textbox', { name: 'Form name', exact: true })).toHaveValue('Unfinished intake'); await expect(page.getByRole('textbox', { name: 'Question (English)', exact: true })).toHaveValue(''); await expect(page.getByText('Draft saved', { exact: true })).toBeVisible();
});
test('uncertain draft save locks editing and retries the identical request', async ({ page }) => {
  await fixture(page, 'practitioner'); const requests: any[] = []; let publication: any;
  await page.route('**/api/v1/forms/drafts', route => { requests.push(route.request().postDataJSON()); return route.fulfill(requests.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry draft' } } } : { json: { data: { id: 31, version: 1, status: 'draft' } } }); });
  await page.route('**/api/v1/forms/templates/12/versions', route => { publication = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 13, version: 2 } } }); });
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Create new version', exact: true }).click(); await page.getByRole('button', { name: 'Save draft', exact: true }).click(); await expect(page.getByRole('textbox', { name: 'Form name', exact: true })).toBeDisabled(); await expect(page.getByRole('button', { name: 'View source', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Retry draft save', exact: true }).click(); await expect(page.getByText('Draft saved. You can return after signing in again.')).toBeVisible(); expect(requests[1]).toEqual(requests[0]);
  await page.getByRole('button', { name: 'Publish new version', exact: true }).click(); await expect(page.getByRole('button', { name: 'Create form', exact: true })).toBeVisible(); expect(publication.draft_id).toBe(31); expect(publication.draft_version).toBe(1);
});
test('JSON file imports as a private draft with no inherited service bindings', async ({ page }) => {
  await fixture(page, 'practitioner'); let imported: any;
  await page.route('**/api/v1/forms/drafts/import', route => { imported = route.request().postDataJSON(); return route.fulfill({ json: { data: { id: 32, version: 1, status: 'draft' } } }); });
  await page.route('**/api/v1/forms/drafts/32', route => route.fulfill({ json: { data: { id: 32, version: 1, status: 'draft', payload: { name: portable.name, form_type: portable.form_type, definition, owner_practitioner_id: 7, service_ids: [], previous_template_id: null, expected_version: 0 } } } }));
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Import form JSON', exact: true }).click(); const panel = page.getByRole('dialog'); await panel.locator('input[type=file]').setInputFiles({ name: 'test.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(portable)) });
  await expect(panel.getByRole('textbox', { name: 'Form JSON source' })).toHaveValue(JSON.stringify(portable)); await panel.getByRole('button', { name: 'Import as draft', exact: true }).click(); await expect(panel).toHaveCount(0); await expect(page.getByRole('button', { name: 'Publish form', exact: true })).toBeVisible(); await expect(page.getByRole('checkbox', { name: 'Massage', exact: true })).not.toBeChecked(); expect(imported.document).toEqual(portable); expect(imported.owner_practitioner_id).toBe(7); expect(imported.service_ids).toBeUndefined();
});
test('French mobile source panel fits the viewport', async ({ page }) => {
  await fixture(page, 'practitioner'); await page.setViewportSize({ width: 390, height: 844 }); await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Language and region', exact: true }).click(); await page.getByRole('button', { name: /Fran.+Canada/ }).click(); await page.getByRole('button', { name: 'Voir la source', exact: true }).click(); await expect(page.getByRole('dialog').getByRole('textbox', { name: 'Source JSON du formulaire' })).toBeVisible(); expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true); await page.screenshot({ path: 'test-results/form-source-mobile-fr.png', fullPage: true });
});

test('invalid import remains editable and uncertain import reuses its original key', async ({ page }) => {
  await fixture(page, 'practitioner'); const imports: any[] = [];
  await page.route('**/api/v1/forms/drafts/import', route => { imports.push(route.request().postDataJSON()); return route.fulfill(imports.length === 1 ? { status: 503, json: { error: { code: 'unavailable', message: 'Retry import' } } } : { json: { data: { id: 32, version: 1, status: 'draft' } } }); });
  await page.route('**/api/v1/forms/drafts/32', route => route.fulfill({ json: { data: { id: 32, version: 1, status: 'draft', payload: { name: portable.name, form_type: portable.form_type, definition, owner_practitioner_id: 7, service_ids: [], previous_template_id: null, expected_version: 0 } } } }));
  await page.goto(`${host}/practitioner/forms`); await page.getByRole('button', { name: 'Import form JSON', exact: true }).click(); const panel = page.getByRole('dialog'), raw = panel.getByRole('textbox', { name: 'Form JSON source' });
  await raw.fill('{broken'); await panel.getByRole('button', { name: 'Import as draft', exact: true }).click(); await expect(raw).toBeEnabled(); expect(imports).toHaveLength(0);
  await raw.fill(JSON.stringify(portable)); await panel.getByRole('button', { name: 'Import as draft', exact: true }).click(); await expect(raw).toBeDisabled(); await expect(panel.getByRole('button', { name: 'Close', exact: true })).toBeDisabled();
  await panel.getByRole('button', { name: 'Retry confirmation', exact: true }).click(); await expect(panel).toHaveCount(0); expect(imports[1]).toEqual(imports[0]);
});

test('draft loading errors do not block existing published forms or source viewing', async ({ page }) => {
  await fixture(page, 'practitioner'); await page.route('**/api/v1/forms/drafts*', route => route.fulfill({ status: 503, json: { error: { code: 'unavailable', message: 'Draft list unavailable' } } }));
  await page.goto(`${host}/practitioner/forms`); await expect(page.getByRole('alert').filter({ hasText: 'The service is temporarily unavailable.' })).toBeVisible(); await expect(page.getByText(template.name, { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'View source', exact: true }).click(); await expect(page.getByRole('dialog')).toBeVisible();
});
test('source viewing remains available with draft storage disabled', async ({ page }) => {
  await fixture(page, 'practitioner'); let draftRequests = 0;
  await page.route('**/api/v1/forms/templates*', route => route.fulfill({ json: { data: { items: [template], practitioners: [{ id: 7, display_name: 'Esther' }], services: [], can_author: true, drafts_enabled: false } } }));
  await page.route('**/api/v1/forms/drafts*', route => { draftRequests++; return route.fulfill({ status: 503, json: {} }); });
  await page.goto(`${host}/practitioner/forms`); await expect(page.getByRole('button', { name: 'Import form JSON', exact: true })).toHaveCount(0); await page.getByRole('button', { name: 'View source', exact: true }).click(); const panel = page.getByRole('dialog'); await expect(panel.getByRole('textbox', { name: 'Form JSON source' })).toBeVisible();
  await panel.getByRole('button', { name: 'Close', exact: true }).click(); await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: 'Publish new version', exact: true })).toBeEnabled(); expect(draftRequests).toBe(0);
});
test('configured question limit permits more than 30 questions and stops at the API limit', async ({ page }) => {
  await fixture(page, 'practitioner');
  const expanded = { ...template, form_type: 'intake', definition: { ...definition, sections: [{ id: 'info', title: 'Information' }], questions: Array.from({ length: 30 }, (_, index) => ({ ...definition.questions[0], id: `q${index}`, section_id: 'info' })) } };
  await page.route('**/api/v1/forms/templates?*', route => route.fulfill({ json: { data: { items: [expanded], practitioners: [{ id: 7, display_name: 'Esther' }], services: [], can_author: true, drafts_enabled: true, max_questions: 32, has_more: false } } }));
  let saved: any;
  await page.route('**/api/v1/forms/drafts', route => route.request().method() === 'POST' ? (saved = route.request().postDataJSON(), route.fulfill({ json: { data: { id: 91, version: 1, status: 'draft' } } })) : route.fulfill({ json: { data: { items: [], has_more: false } } }));
  await page.goto(`${host}/practitioner/forms`);
  await page.getByRole('button', { name: 'Create new version', exact: true }).click();
  const sectionAdd = page.getByRole('button', { name: 'Add question to Information', exact: true });
  const add = page.getByRole('button', { name: 'Add question', exact: true });
  await expect(add).toBeEnabled(); await expect(sectionAdd).toBeEnabled();
  await sectionAdd.click(); await add.click();
  await expect(add).toBeDisabled(); await expect(sectionAdd).toBeDisabled();
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(page.getByText('Draft saved. You can return after signing in again.')).toBeVisible();
  expect(saved.definition.questions).toHaveLength(32);
});
test('answer types use friendly translated English labels', async ({ page }) => {
  await fixture(page, 'practitioner');
  await page.goto(`${host}/practitioner/forms`);
  await page.getByRole('button', { name: 'Create form', exact: true }).click();
  await page.getByRole('combobox', { name: /Answer type/ }).click();
  for (const label of ['Text', 'Yes/No', 'Consent', 'Date', 'Phone number', 'Email address', 'Single choice (radio buttons)', 'Multiple choice (checkboxes)']) {
    await expect(page.getByRole('option', { name: label, exact: true })).toBeVisible();
  }
  await expect(page.getByRole('option', { name: 'Answer type: text', exact: true })).toHaveCount(0);
});
