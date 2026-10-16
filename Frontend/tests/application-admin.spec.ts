import { test, expect, type Page } from '@playwright/test';
test('platform form library creates a template and approves its immutable version', async ({ page }) => {
  await authentication(page);
  await page.route('**/api/v1/application/me', route => route.fulfill({ json: { data: { id: 1 } } }));
  await page.route('**/api/v1/application/clinics', route => route.fulfill({ json: { data: { items: [] } } }));
  const item = { id: 51, entry_id: 7, name: 'Global starter', description: 'Shared reusable form', scope: 'global', version: 1, revision: 1, form_type: 'intake', status: 'pending', review_note: '', can_review: true, can_revise: true, can_retire: false, can_use: false, definition: { instructions: '', instructions_fr: '', questions: [{ id: 'q1', label: 'Example question', label_fr: '', type: 'text', required: true }] } };
  let submitted = false, approved = false;
  await page.route('**/api/v1/application/form-library?**', route => route.fulfill({ json: { data: { items: submitted ? [{ ...item, status: approved ? 'approved' : 'pending', can_review: !approved }] : [], can_review: true, has_more: false, max_questions: 100 } } }));
  await page.route('**/api/v1/application/form-library', route => { expect(route.request().postDataJSON()).toMatchObject({ scope: 'global', name: item.name, description: item.description }); submitted = true; return route.fulfill({ json: { data: item } }); });
  await page.route('**/api/v1/application/form-library/51', route => route.fulfill({ json: { data: item } }));
  await page.route('**/api/v1/application/form-library/51/review', route => { expect(route.request().postDataJSON()).toEqual({ action: 'approve', revision: 1, review_note: '' }); approved = true; return route.fulfill({ json: { data: { ...item, status: 'approved' } } }); });
  await page.goto('http://localhost:5185/admin/'); await page.getByRole('tab', { name: 'Form library', exact: true }).click();
  await page.getByRole('button', { name: 'Create library template', exact: true }).click();
  const editor = page.getByRole('dialog');
  await editor.getByRole('textbox', { name: 'Form name', exact: true }).fill(item.name); await editor.getByRole('textbox', { name: 'Template description', exact: true }).fill(item.description); await editor.getByRole('textbox', { name: 'Question (English)', exact: true }).fill('Example question');
  await editor.getByRole('button', { name: 'Submit for approval', exact: true }).click(); await expect(page.getByText('Template submitted for approval.')).toBeVisible();
  await page.getByRole('combobox', { name: /^Library view/ }).click(); await page.getByRole('option', { name: 'Awaiting approval', exact: true }).click();
  await page.getByRole('button', { name: 'Preview template', exact: true }).click(); await page.getByRole('button', { name: 'Approve template', exact: true }).click();
  await expect(page.getByText('Library review saved.')).toBeVisible(); expect(approved).toBe(true);
  await page.screenshot({ path: test.info().outputPath('global-form-library.png'), fullPage: true });
});

async function authentication(page: Page, signedIn = true) {
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account=${signedIn ? "{name:'Operator'}" : 'null'};
    const auth={account,configured:true,isAuthenticated:!!account,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,setActiveAccount:()=>{}};
    export const selectStaffAccount=()=>account;
    export const StaffAuthProvider=({children})=>children;
    export const useStaffAuth=()=>auth;
  ` }));
}

test('central directory imports an existing practitioner and assigns a second clinic without credentials', async ({ page }) => {
  await authentication(page);
  await page.route('**/api/v1/application/me', route => route.fulfill({ json: { data: { id: 1 } } }));
  const people: { id: number; display_name: string; email: string; clinics: { clinic_id: number; clinic_name: string; status: string; version: number }[] }[] = [];
  await page.route('**/api/v1/application/clinics', route => route.fulfill({ json: { data: { items: [{ id: 1, name: 'Livin Lively', status: 'active', portal_host: 'livin.test', location_count: 1 }, { id: 2, name: 'Wellnexa', status: 'active', portal_host: 'wellnexa.test', location_count: 1 }] } } }));
  await page.route('**/api/v1/application/practitioners', route => route.fulfill({ json: { data: { items: people } } }));
  await page.route('**/api/v1/application/practitioners/import-candidates', route => route.fulfill({ json: { data: { items: people.length ? [] : [{ practitioner_id: 7, display_name: 'Dual Practitioner', email: 'person@example.test', clinic_name: 'Livin Lively' }] } } }));
  await page.route('**/api/v1/application/practitioners/import', route => {
    expect(route.request().postDataJSON()).toEqual({ practitioner_id: 7 });
    people.push({ id: 9, display_name: 'Dual Practitioner', email: 'person@example.test', clinics: [{ clinic_id: 1, clinic_name: 'Livin Lively', status: 'active', version: 1 }] });
    return route.fulfill({ json: { data: { id: 9 } } });
  });
  await page.route('**/api/v1/application/clinics/2/locations', route => route.fulfill({ json: { data: { items: [{ id: 21, name: 'Virtual Clinic' }] } } }));
  await page.route('**/api/v1/application/practitioners/9/clinics', route => {
    expect(route.request().postDataJSON()).toEqual({ clinic_id: 2, location_id: 21 });
    people[0].clinics.push({ clinic_id: 2, clinic_name: 'Wellnexa', status: 'active', version: 1 });
    return route.fulfill({ json: { data: { practitioner_id: 17 } } });
  });
  await page.goto('http://localhost:5185/admin/');
  await page.getByRole('tab', { name: 'Global practitioners' }).click();
  await page.getByRole('combobox', { name: 'Existing clinic practitioner' }).click();
  await page.getByRole('option', { name: /Dual Practitioner/ }).click();
  await page.getByRole('button', { name: 'Import practitioner', exact: true }).click();
  await expect(page.getByText('Practitioner imported. Existing clinic records are preserved.')).toBeVisible();
  await page.getByRole('button', { name: 'Assign a clinic' }).click();
  await page.getByRole('combobox', { name: 'Clinic', exact: true }).click();
  await expect(page.getByRole('option', { name: 'Livin Lively' })).toHaveCount(0);
  await page.getByRole('option', { name: 'Wellnexa' }).click();
  await page.getByRole('combobox', { name: 'Location', exact: true }).click();
  await page.getByRole('option', { name: 'Virtual Clinic' }).click();
  await page.getByRole('button', { name: 'Assign practitioner', exact: true }).click();
  await expect(page.getByText('Clinic assigned. Credentials remain private until the practitioner shares them.')).toBeVisible();
  await expect(page.getByText('Wellnexa · Assigned')).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('anonymous administration exposes sign-in but no clinic records', async ({ page }) => {
  await authentication(page, false);
  let requests = 0; await page.route('**/api/v1/**', route => { requests++; return route.fulfill({ status: 401 }); });
  await page.goto('http://localhost:5185/admin/');
  await expect(page.getByRole('button', { name: 'Sign in with Microsoft' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create clinic' })).toHaveCount(0);
  expect(requests).toBe(0);
});

test('signed-in clinic administrator without a global grant sees access denial', async ({ page }) => {
  await authentication(page); let clinicRequests = 0;
  await page.route('**/api/v1/application/me', route => route.fulfill({ status: 403, json: { error: { code: 'application_admin_required' } } }));
  await page.route('**/api/v1/application/clinics', route => { clinicRequests++; return route.fulfill({ json: { data: { items: [] } } }); });
  await page.goto('http://localhost:5185/admin/');
  await expect(page.getByText('Application administration access could not be confirmed. Contact the application owner or try again.')).toBeVisible();
  expect(clinicRequests).toBe(0);
});

test('application administrator creates clinics with field validation and edits any clinic registration', async ({ page }) => {
  await authentication(page);
  await page.route('**/api/v1/application/me', route => route.fulfill({ json: { data: { id: 1, roles: ['application_admin'] } } }));
  const items = [{ id: 1, name: "Livin Lively", portal_host: 'livinlively.copihue.ca', location_count: 1 }];
  let invalid = true; let created: Record<string, unknown> | null = null;
  await page.route('**/api/v1/application/clinics', route => {
    if (route.request().method() === 'POST') {
      created = route.request().postDataJSON();
      if (invalid) return route.fulfill({ status: 422, json: { error: { code: 'validation_error', fields: { portal_host: 'Enter a hostname without a path.' } } } });
      items.push({ id: 2, name: String(created!.name), portal_host: String(created!.portal_host), location_count: 1 });
      return route.fulfill({ json: { data: { id: 2 } } });
    }
    return route.fulfill({ json: { data: { items } } });
  });
  await page.route('**/api/v1/application/clinics/1', route => {
    expect(route.request().method()).toBe('PATCH');
    expect(route.request().postDataJSON().portal_host).toBe('new-livin.copihue.ca');
    return route.fulfill({ json: { data: { id: 1 } } });
  });
  await page.goto('http://localhost:5185/admin/');
  await page.getByRole('button', { name: 'Create clinic', exact: true }).click();
  await page.getByRole('textbox', { name: 'Clinic name', exact: true }).fill('Willow Wellness Virtual Clinic');
  await page.getByRole('textbox', { name: 'Portal hostname', exact: true }).fill('https://willowwellness.copihue.ca');
  await page.getByLabel('First location name').fill('Virtual');
  await page.getByRole('button', { name: 'Create clinic', exact: true }).click();
  await expect(page.getByRole('textbox', { name: 'Portal hostname', exact: true })).toBeFocused();
  await expect(page.getByRole('textbox', { name: 'Portal hostname', exact: true })).toHaveAccessibleDescription(/Enter a hostname without a path/);
  invalid = false;
  await page.getByRole('textbox', { name: 'Portal hostname', exact: true }).fill('willowwellness.copihue.ca');
  await page.getByRole('button', { name: 'Create clinic', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Open clinic portal' }).last()).toHaveAttribute('href', 'https://willowwellness.copihue.ca/staff/login');
  await page.getByRole('button', { name: 'Edit clinic' }).first().click();
  await expect(page.getByLabel('First location name')).toHaveCount(0);
  await page.getByRole('textbox', { name: 'Portal hostname', exact: true }).fill('new-livin.copihue.ca');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Clinic settings saved.')).toBeVisible();
});


test('central sign-in uses the workforce scope and returns to the admin path despite an external-provider hint', async ({ page }) => {
  await page.addInitScript(() => sessionStorage.setItem('wellness.staff.provider', 'external'));
  await page.route('https://login.microsoftonline.com/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/authorize')) return route.fulfill({ body: 'Synthetic authorization request captured', contentType: 'text/plain' });
    return route.continue();
  });
  // Use the same discovery response as the clinic workforce integration fixture.
  await page.route('**/v2.0/.well-known/openid-configuration', route => route.fulfill({ json: {
    authorization_endpoint: 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/authorize',
    token_endpoint: 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/token',
    end_session_endpoint: 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/logout',
    issuer: 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0',
    jwks_uri: 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
  } }));
  await page.route('**/discovery/instance?**', route => route.fulfill({ json: { tenant_discovery_endpoint: 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0/.well-known/openid-configuration', metadata: [{ preferred_network: 'login.microsoftonline.com', preferred_cache: 'login.windows.net', aliases: ['login.microsoftonline.com', 'login.windows.net'] }] } }));
  await page.goto('http://localhost:5185/admin/');
  await page.getByRole('button', { name: 'Sign in with Microsoft' }).click();
  await page.waitForURL('https://login.microsoftonline.com/**');
  const target = new URL(page.url());
  expect(target.searchParams.get('redirect_uri')).toBe('http://localhost:5185/admin/');
  expect(target.searchParams.get('scope')).toContain('api://33333333-3333-3333-3333-333333333333/access_as_user');
  expect(target.searchParams.get('code_challenge_method')).toBe('S256');
});
