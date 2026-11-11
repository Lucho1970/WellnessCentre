import { expect, test, type Page } from '@playwright/test';

const host = 'http://localhost:5184';
async function fixture(page: Page, roles: string[], response: (path: string, method: string, body: Record<string, unknown> | null) => unknown, permissions: string[] = []) {
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account={homeAccountId:'synthetic',name:'Synthetic Staff',username:'staff@example.test'};
    const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'synthetic-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};
    export const StaffAuthProvider=({children})=>children;export const selectStaffAccount=()=>account;export const useStaffAuth=()=>auth;
  ` }));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: 'https://public.example.test/' } }));
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname.replace('/api/v1', '');
    const data = path === '/site-config' ? { name: 'Synthetic Clinic' } : path === '/auth/me' ? { roles, permissions }
      : path === '/profile/avatar' ? { image_base64: null }
        : response(path, route.request().method(), route.request().postDataJSON() as Record<string, unknown> | null);
    return route.fulfill({ json: { data } });
  });
}

test('clinic admin can immediately hold a practitioner and cannot restore the hold', async ({ page }) => {
  let held = false;
  await fixture(page, ['clinic_admin'], (path, method, body) => {
    if (path === '/admin/practitioner-emergency-access') return [{ practitioner_id: 7, user_id: 9, display_name: 'Synthetic Practitioner', status: held ? 'locked' : 'active', access_held: held ? '1' : '0', reason_code: held ? 'security_concern' : null }];
    if (path === '/admin/practitioners/7/emergency-access' && method === 'POST') {
      expect(body).toEqual({ hold: true, reason_code: 'security_concern' }); held = true; return { practitioner_id: 7, access_held: true, status: 'locked' };
    }
    return [];
  });
  await page.goto(`${host}/admin/practitioner-safety`);
  const hold = page.getByRole('switch', { name: 'Block practitioner access: Synthetic Practitioner' });
  await expect(hold).not.toBeChecked();
  await hold.click();
  await expect(page.getByRole('dialog', { name: 'Stop practitioner access' })).toBeVisible();
  await page.getByRole('button', { name: 'Stop access now' }).click();
  await expect(hold).toBeChecked();
  await expect(hold).toBeDisabled();
});

for (const resolution of ['active', 'inactive'] as const) {
  test(`Super Admin resolves a practitioner hold to ${resolution} without an intermediate save`, async ({ page }) => {
    let status = 'locked';
    let held = true;
    await fixture(page, ['super_admin'], (path, method, body) => {
      if (path === '/admin/practitioner-emergency-access') return [{ practitioner_id: 7, user_id: 9, display_name: 'Synthetic Practitioner', status, access_held: held ? '1' : '0', reason_code: held ? 'departure_review' : null }];
      if (path === '/admin/practitioners/7/emergency-access' && method === 'POST') {
        expect(body).toEqual({ hold: false, resolution });
        held = false; status = resolution; return { practitioner_id: 7, access_held: false, status };
      }
      return [];
    });
    await page.goto(`${host}/admin/practitioner-safety`);
    const hold = page.getByRole('switch', { name: 'Block practitioner access: Synthetic Practitioner' });
    await expect(hold).toBeChecked();
    await hold.click();
    const dialog = page.getByRole('dialog', { name: 'Resolve practitioner access hold' });
    await expect(dialog).toBeVisible();
    const save = dialog.getByRole('button', { name: 'Resolve hold' });
    await expect(save).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Resolve hold to' }).click();
    await page.getByRole('option', { name: resolution === 'active' ? 'Restore active access' : 'Keep account inactive' }).click();
    await save.click();
    await expect(hold).not.toBeChecked();
    if (resolution === 'inactive') await expect(hold).toBeDisabled();
    else await expect(hold).toBeEnabled();
    await expect(page.getByText(resolution === 'inactive' ? 'Hold resolved; account remains inactive.' : 'Practitioner access restored.')).toBeVisible();
  });
}

test('French mobile hold resolution keeps the practitioner inactive', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  let inactive = false;
  await fixture(page, ['super_admin'], (path, method, body) => {
    if (path === '/admin/practitioner-emergency-access') return [{ practitioner_id: 7, user_id: 9, display_name: 'Praticien fictif', status: inactive ? 'inactive' : 'locked', access_held: inactive ? '0' : '1', reason_code: inactive ? null : 'departure_review' }];
    if (path === '/admin/practitioners/7/emergency-access' && method === 'POST') {
      expect(body).toEqual({ hold: false, resolution: 'inactive' }); inactive = true;
      return { practitioner_id: 7, access_held: false, status: 'inactive' };
    }
    return [];
  });
  await page.goto(`${host}/admin/practitioner-safety?lang=fr`);
  await page.getByRole('switch', { name: 'Bloquer l’accès du praticien: Praticien fictif' }).click();
  const dialog = page.getByRole('dialog', { name: 'Résoudre le blocage de l’accès du praticien' });
  await dialog.getByRole('combobox', { name: 'Résoudre le blocage en' }).click();
  await page.getByRole('option', { name: 'Garder le compte inactif' }).click();
  await dialog.getByRole('button', { name: 'Résoudre le blocage' }).click();
  await expect(page.getByText('Blocage levé; le compte demeure inactif.')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('office user with explicit hold permission can open only the hold workflow', async ({ page }) => {
  await fixture(page, ['reception'], path => path === '/admin/practitioner-emergency-access'
    ? [{ practitioner_id: 7, user_id: 9, display_name: 'Synthetic Practitioner', status: 'active', access_held: '0', reason_code: null }]
    : [], ['hold_practitioner_access']);
  await page.goto(`${host}/admin/practitioner-safety`);
  await expect(page.getByRole('switch', { name: 'Block practitioner access: Synthetic Practitioner' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Staff access/ })).toHaveCount(0);
});

test('practitioner acknowledges the exact bilingual document version', async ({ page }) => {
  let accepted = false;
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page, ['practitioner'], (path, method, body) => {
    if (path === '/practitioner/documents') return { items: [{ id: 12, kind: 'records_agreement', version_code: 'synthetic-v1', title_en: 'Synthetic rules', title_fr: 'Règles fictives', body_en: 'Synthetic English content', body_fr: 'Contenu français fictif', content_hash: 'a'.repeat(64), accepted: accepted ? { accepted_at: '2026-11-06 12:00:00', displayed_language: 'fr' } : null }] };
    if (path === '/practitioner/documents/12/accept' && method === 'POST') {
      expect(body).toEqual({ accepted: true, language: 'fr', content_hash: 'a'.repeat(64) }); accepted = true; return { id: 1, document_id: 12, accepted: true };
    }
    return [];
  });
  await page.goto(`${host}/practitioner/documents?lang=fr`);
  await expect(page.getByText('Contenu français fictif')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const button = page.getByRole('button', { name: 'Enregistrer ma reconnaissance' });
  await expect(button).toBeDisabled();
  await page.getByRole('checkbox', { name: /J’ai lu/ }).check();
  await button.click();
  await expect(page.getByText(/2026-11-06 12:00:00/)).toBeVisible();
});

test('clinic admin explicitly assigns care when SQL reports an unassigned text zero', async ({ page }) => {
  let assigned = false;
  const client = { id: 8, display_name: 'Synthetic Client', given_name: 'Synthetic', family_name: 'Client', email: 'client@example.test', phone: null, preferred_contact: 'email', date_of_birth: null, emergency_contact_name: null, emergency_contact_phone: null, administrative_notes: null, status: 'active', revision: 'synthetic-revision', address: null };
  await fixture(page, ['clinic_admin'], (path, method, body) => {
    if (path === '/clients') return { items: [client], has_more: false };
    if (path === '/clients/8') return client;
    if (path === '/clients/8/invitations') return { items: [], linked: false };
    if (path === '/clients/8/care-team' && method === 'GET') return { items: [{ practitioner_id: 7, display_name: 'Synthetic Practitioner', assigned: assigned ? '1' : '0' }] };
    if (path === '/clients/8/care-team/7' && method === 'PUT') {
      expect(body).toEqual(assigned
        ? { assigned: false, reason_code: 'care_completed', review_confirmed: true }
        : { assigned: true, reason_code: 'starting_care', review_confirmed: true });
      assigned = !assigned; return { assigned };
    }
    return [];
  });
  await page.goto(`${host}/admin/clients`);
  await page.getByRole('button', { name: /Synthetic Client.*client@example.test/ }).click();
  await page.getByRole('button', { name: 'Details', exact: true }).click();
  await expect(page.getByText('Synthetic Practitioner · Not assigned')).toBeVisible();
  await page.getByRole('button', { name: 'Assign care' }).click();
  const confirm = page.getByRole('button', { name: 'Confirm care change' });
  await expect(confirm).toBeDisabled();
  await page.getByRole('combobox', { name: 'Reason for care change' }).click();
  await page.getByRole('option', { name: 'Starting care' }).click();
  await page.getByRole('checkbox', { name: 'I confirmed this change reflects the current care relationship.' }).check();
  await confirm.click();
  await expect(page.getByText('Synthetic Practitioner · Assigned')).toBeVisible();
  await page.getByRole('button', { name: 'End care assignment' }).click();
  await expect(page.getByText('Ending care removes practitioner access to this client’s contact, appointments, shared notes and financial details. Records remain with the clinic.')).toBeVisible();
  await expect(confirm).toBeDisabled();
  await page.getByRole('combobox', { name: 'Reason for care change' }).click();
  await page.getByRole('option', { name: 'Care completed' }).click();
  await page.getByRole('checkbox', { name: 'I confirmed this change reflects the current care relationship.' }).check();
  await confirm.click();
  await expect(page.getByText('Synthetic Practitioner · Not assigned')).toBeVisible();
});
