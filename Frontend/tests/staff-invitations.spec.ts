import { expect, test, type Page } from '@playwright/test';

const host = 'http://localhost:5184';
const token = 'a'.repeat(64), verification = 'ABCDEF123456';
async function setup(page: Page, signedIn = true) {
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account=${signedIn ? "{homeAccountId:'test-staff',name:'Invited Staff',username:'staff@example.test'}" : 'null'};
    const auth={account,configured:true,isAuthenticated:Boolean(account),signIn:async()=>{window.testStaffLogin=true},signOut:async()=>{},getAccessToken:async()=>'test-signed-staff-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>account?[account]:[],setActiveAccount:()=>{}};
    export const StaffAuthProvider=({children})=>children; export const selectStaffAccount=()=>account; export const useStaffAuth=()=>auth;
  ` }));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: 'https://public.example.test/' } }));
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname;
    const data = path.endsWith('/site-config') ? { name: 'Test Clinic' }
      : path.endsWith('/auth/me') ? { roles: ['super_admin'], permissions: [] }
        : path.endsWith('/profile/avatar') ? { image_base64: null }
          : path.endsWith('/admin/locations') ? [{ id: '1', name: 'Main clinic', is_bookable: '1' }]
            : path.endsWith('/admin/staff-invitations') ? { items: [] } : [];
    return route.fulfill({ json: { data } });
  });
}

test('invitation strips the secret fragment and a claim grants no immediate workspace', async ({ page }) => {
  await setup(page);
  let submitted: unknown;
  await page.route('**/api/v1/staff-invitations/claim', route => {
    submitted = route.request().postDataJSON();
    return route.fulfill({ json: { data: { status: 'pending', verification_code: verification } } });
  });
  await page.goto(`${host}/staff/invitation#token=${token}`);
  await expect(page).toHaveURL(`${host}/staff/invitation`);
  await expect(page.getByRole('heading', { name: 'Practitioner invitation' })).toBeVisible();
  await page.getByRole('textbox', { name: 'Your name' }).fill('New Practitioner');
  await page.getByRole('button', { name: 'Submit invitation claim' }).click();
  await expect(page.getByRole('textbox', { name: 'Verification code' })).toHaveValue(verification);
  expect(submitted).toEqual({ token, claimant_name: 'New Practitioner' });
  await expect(page.getByText('Your claim is waiting for clinic approval. Contact the clinic to verify your identity.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toHaveCount(0);
});

test('missing invitation and failed claim provide recovery', async ({ page }) => {
  await setup(page);
  await page.goto(`${host}/staff/invitation`);
  await expect(page.getByRole('button', { name: 'Submit invitation claim' })).toBeDisabled();
  await expect(page.getByText('Open the invitation link supplied by the clinic.')).toBeVisible();
  await page.route('**/api/v1/staff-invitations/claim', route => route.fulfill({ status: 409, json: { error: { code: 'invitation_unavailable', message: 'Unavailable' } } }));
  await page.goto(`${host}/staff/invitation#token=${token}`);
  await page.getByRole('textbox', { name: 'Your name' }).fill('New Practitioner');
  await page.getByRole('button', { name: 'Submit invitation claim' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Verification code' })).toHaveCount(0);
});

test('admin creates a one-time manual link and verifies claim before approval', async ({ page }) => {
  await setup(page);
  let created: unknown, approved: unknown;
  const item = { id: '5', recipient_email: 'new@example.test', given_name: 'New', family_name: 'Practitioner', expires_at: '2099-01-01 00:00:00', revoked_at: null, accepted_at: null as string | null, claimant_name: 'New Practitioner', claim_status: 'pending', verification_code: verification };
  await page.route('**/api/v1/admin/staff-invitations', route => {
    if (route.request().method() === 'POST') { created = route.request().postDataJSON(); return route.fulfill({ json: { data: { token } } }); }
    return route.fulfill({ json: { data: { items: [item] } } });
  });
  await page.route('**/api/v1/admin/staff-invitations/5/approve', route => {
    approved = route.request().postDataJSON(); item.accepted_at = '2026-10-05 20:00:00';
    return route.fulfill({ json: { data: { status: 'approved' } } });
  });
  await page.goto(`${host}/admin/users`);
  await page.getByRole('textbox', { name: 'Email', exact: true }).fill('new@example.test');
  await page.getByRole('textbox', { name: 'Given name' }).fill('New');
  await page.getByRole('textbox', { name: 'Family name' }).fill('Practitioner');
  await page.getByRole('textbox', { name: 'Discipline' }).fill('Massage');
  await page.getByRole('combobox', { name: 'Location', exact: true }).click();
  await page.getByRole('option', { name: 'Main clinic' }).click();
  await page.getByRole('button', { name: 'Create practitioner invitation' }).click();
  await expect(page.getByRole('textbox', { name: 'Invitation link' })).toHaveValue(`${host}/staff/invitation#token=${token}`);
  expect(created).toMatchObject({ role: 'practitioner', location_id: 1, existing_user_id: null });
  await page.getByRole('button', { name: 'Review claim' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('button', { name: 'Approve practitioner' })).toBeDisabled();
  await dialog.getByRole('checkbox').check();
  await dialog.getByRole('textbox', { name: 'Verification code' }).fill('WRONG');
  await expect(dialog.getByRole('button', { name: 'Approve practitioner' })).toBeDisabled();
  await dialog.getByRole('textbox', { name: 'Verification code' }).fill(verification);
  await dialog.getByRole('button', { name: 'Approve practitioner' }).click();
  expect(approved).toEqual({ recipient_verified: true, verification_code: verification });
  await expect(page.getByText('New Practitioner · Approved')).toBeVisible();
});

test('mobile invited sign-in remains separate from workforce and client sign-in', async ({ page }) => {
  await setup(page, false); await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${host}/staff/external`);
  await expect(page.getByRole('button', { name: 'Invited practitioner sign-in', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Existing workforce staff sign-in' })).toHaveAttribute('href', '/staff/login');
  await expect(page.getByRole('link', { name: 'Client sign in / booking' })).toHaveAttribute('href', '/client');
  await page.goto(`${host}/staff/login`);
  await expect(page.getByRole('button', { name: 'Sign in with Microsoft' })).toBeVisible();
  expect(await page.evaluate(() => sessionStorage.getItem('wellness.staff.provider'))).toBeNull();
});
