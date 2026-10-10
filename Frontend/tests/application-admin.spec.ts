import { test, expect, type Page } from '@playwright/test';

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
