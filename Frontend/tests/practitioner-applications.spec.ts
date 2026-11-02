import { expect, test, type Page } from '@playwright/test';

const host = 'http://localhost:5184';
const terms = 'Synthetic reviewed joining terms. Six calendar months free for eligible new members; no automatic paid conversion. Do not submit patient information.';
const profile = { given_name: 'Alice', family_name: 'Applicant', email: 'alice@example.test', phone: '', discipline: 'Massage therapy', credentials: 'Declared synthetic registration', service_area: 'Toronto, Ontario', services: 'Synthetic services for testing.', enquiry_reference: '' };
async function fixture(page: Page, signedIn = true) {
  const state = {
    offer: { available: true, enabled: true, activation_available: false, terms: { id: 1, version: 1, content: terms }, plan: { id: 1, name: 'Beta membership', base_monthly_cents: null } },
    application: null as (Record<string, unknown> & { status: string }) | null, writes: [] as { path: string; body: Record<string, unknown> }[], uncertain: false, denial: 0, privateRequests: [] as string[],
  };
  await page.route('**/src/auth/AuthProvider.tsx', route => route.fulfill({ contentType: 'application/javascript', body: `
    const account=${signedIn ? "{homeAccountId:'alice',name:'Alice',username:'alice@example.test'}" : 'null'};
    const auth={account,configured:true,isAuthenticated:!!account,signIn:async(provider)=>{window.testLogin=provider??'picker'},signOut:async()=>{},getAccessToken:async()=>'synthetic-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>account?[account]:[],setActiveAccount:()=>{}};
    export const selectStaffAccount=()=>account;export const StaffAuthProvider=({children})=>children;export const useStaffAuth=()=>auth;
  ` }));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: {} }));
  await page.route('**/api/v1/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/public/practitioner-applications')) return route.fulfill({ json: { data: state.offer } });
    if (path.includes('/practitioner-applications/')) {
      if (state.denial) return route.fulfill({ status: state.denial, json: { error: { code: 'application_version_changed', message: 'Reload the current application.' } } });
      if (route.request().method() === 'POST') {
        const body = route.request().postDataJSON(); state.writes.push({ path, body });
        if (state.uncertain) { state.uncertain = false; return route.fulfill({ status: 503, json: { error: { code: 'unavailable', message: 'Temporary synthetic failure.' } } }); }
        if (path.endsWith('/activate')) state.application = { ...state.application, status: 'activated', account_available: true, subscription: { status: 'trialing', trial_ends_at: '2027-04-27 16:00:00' }, checklist: { services: false, availability: false, credentials: false, published: false } };
        else if (path.endsWith('/withdraw')) state.application.status = 'withdrawn';
        else state.application = { ...profile, ...body, id: 7, version: 1, status: 'submitted', verification_code: 'ABCDEF123456', review_message: '', account_available: false, subscription: null, checklist: {} };
        return route.fulfill({ json: { data: { id: 7, status: state.application.status } } });
      }
      return route.fulfill({ json: { data: { offer: state.offer, application: state.application } } });
    }
    if (path.endsWith('/site-config')) return route.fulfill({ json: { data: { name: 'Test clinic' } } });
    state.privateRequests.push(path); return route.fulfill({ status: 403, json: { error: { code: 'membership_required', message: 'Not approved.' } } });
  });
  return state;
}
async function fill(page: Page) {
  for (const [label, value] of Object.entries({ 'First name': profile.given_name, 'Last name': profile.family_name, 'Contact email': profile.email, 'Discipline / type of practice': profile.discipline, 'Credentials and professional registration': profile.credentials, 'City, province and service area': profile.service_area, 'Services you would like to offer': profile.services })) await page.getByRole('textbox', { name: label, exact: true }).fill(value);
  await page.getByRole('checkbox', { name: /I confirm that these details/ }).check();
  await page.getByRole('checkbox', { name: 'I accept the joining terms displayed above.' }).check();
}
function approved() { return { ...profile, id: 7, version: 2, status: 'approved', verification_code: 'ABCDEF123456', review_message: 'Approved after independent review.', account_available: false, subscription: null, checklist: {} }; }

test('anonymous mobile application entry chooses practitioner identity and retains language', async ({ page }) => {
  const state = await fixture(page, false); await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${host}/staff/apply`);
  await expect(page.getByRole('heading', { name: 'Apply to join the clinic', exact: true })).toBeVisible({ timeout: 15000 });
  await expect(page.getByRole('button', { name: 'Continue with Google', exact: true })).toBeVisible();
  expect(await page.evaluate(() => sessionStorage.getItem('wellness.staff.provider'))).toBe('external');
  await page.getByRole('button', { name: 'Continue with Google', exact: true }).click();
  expect(await page.evaluate(() => (window as Window & { testLogin?: string }).testLogin)).toBe('google');
  await page.goto(`${host}/staff/apply?lang=fr`);
  await expect(page.getByRole('heading', { name: 'Présenter une candidature à la clinique' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/practitioner-applications/apply-mobile-fr.png', fullPage: true, animations: 'disabled' });
  expect(state.privateRequests).toEqual([]);
});

test('application requires explicit consent and grants no staff workspace', async ({ page }) => {
  const state = await fixture(page); await page.goto(`${host}/staff/apply`);
  const submit = page.getByRole('button', { name: 'Submit practitioner application' });
  await expect(submit).toBeDisabled(); await fill(page); await submit.click();
  await expect(page.getByText('Application submitted', { exact: true })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Verification code' })).toHaveValue('ABCDEF123456');
  expect(state.writes[0].body).toMatchObject({ ...profile, version: 0, terms_id: 1, accept_terms: true, information_confirmed: true });
  expect(state.writes[0].body.request_key).toMatch(/^[a-f0-9-]{36}$/);
  expect(state.privateRequests).toEqual([]);
  await expect(page.getByRole('button', { name: 'Activate my approved account' })).toHaveCount(0);
});

test('uncertain submission freezes the exact original request for retry', async ({ page }) => {
  const state = await fixture(page); state.uncertain = true; await page.goto(`${host}/staff/apply`); await fill(page);
  await page.getByRole('button', { name: 'Submit practitioner application' }).click();
  await expect(page.getByRole('textbox', { name: 'First name', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Retry application change' }).click();
  await expect(page.getByText('Application submitted', { exact: true })).toBeVisible();
  expect(state.writes[1]).toEqual(state.writes[0]);
});

test('approved activation is held until rollout and then requires current terms', async ({ page }) => {
  const state = await fixture(page); state.application = approved(); await page.goto(`${host}/staff/apply`);
  await expect(page.getByText('Application approved', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Activate my approved account' })).toHaveCount(0);
  state.offer.activation_available = true; state.offer.terms = { id: 2, version: 2, content: terms + ' Updated terms.' };
  await page.getByRole('button', { name: 'Refresh application', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Activate my approved account' })).toBeDisabled();
  await page.getByRole('checkbox', { name: 'I accept the joining terms displayed above.' }).check();
  await page.getByRole('button', { name: 'Activate my approved account' }).click();
  await expect(page.getByRole('link', { name: 'Open practitioner portal' })).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ version: 2, terms_id: 2, accept_terms: true });
  await expect(page.getByText(/Your recorded trial ends:/)).toBeVisible();
  expect(state.privateRequests).toEqual([]);
});

test('changes requested preload revision while stale denial clears private content', async ({ page }) => {
  const state = await fixture(page); state.application = { ...approved(), status: 'changes_requested', review_message: 'Please clarify your services.' }; await page.goto(`${host}/staff/apply`);
  await expect(page.getByRole('textbox', { name: 'First name', exact: true })).toHaveValue('Alice');
  await expect(page.getByText('Please clarify your services.')).toBeVisible();
  await fill(page); state.denial = 409;
  await page.getByRole('button', { name: 'Submit revised application' }).click();
  await expect(page.getByText('The information changed or conflicts with another update. Refresh and try again.')).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'First name', exact: true })).toHaveCount(0);
  await expect(page.getByText('Please clarify your services.')).toHaveCount(0);
});

test('return callback restores application while workforce login clears application intent', async ({ page }) => {
  await fixture(page); await page.goto(`${host}/staff/apply`);
  await page.goto(`${host}/staff/external`);
  await expect(page).toHaveURL(`${host}/staff/apply`);
  await expect(page.getByRole('heading', { name: 'Apply to join the clinic' })).toBeVisible();
  await page.goto(`${host}/staff/login`);
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('wellness.staff.application-return'))).toBeNull();
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('wellness.staff.provider'))).toBeNull();
});

test('changing language preserves an unfinished application', async ({ page }) => {
  await fixture(page); await page.goto(`${host}/staff/apply`);
  await page.getByRole('textbox', { name: 'First name', exact: true }).fill('Unfinished');
  await page.getByRole('button', { name: 'Language and region' }).click();
  await page.getByRole('button', { name: /Français \(Canada\)/ }).click();
  await expect(page.getByRole('textbox', { name: 'Prénom', exact: true })).toHaveValue('Unfinished');
  await expect(page.getByRole('button', { name: 'Soumettre la candidature de praticien' })).toBeDisabled();
});

test('withdrawal requires confirmation and does not expose activation', async ({ page }) => {
  const state = await fixture(page); state.application = { ...approved(), status: 'submitted' };
  await page.goto(`${host}/staff/apply`);
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Withdraw application' }).click(); expect(state.writes).toEqual([]);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Withdraw application' }).click();
  await expect(page.getByText('Application withdrawn', { exact: true })).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ version: 2 });
  await expect(page.getByRole('button', { name: 'Activate my approved account' })).toHaveCount(0);
});

test('application-only entry uses the real dedicated practitioner MSAL configuration', async ({ page }) => {
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: {} }));
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: route.request().url().endsWith('/site-config') ? { name: 'Test clinic' } : { available: true, enabled: false, activation_available: false, terms: null, plan: null } } }));
  await page.goto(`${host}/staff/apply`);
  await expect(page.getByRole('button', { name: 'Continue with personal Microsoft account', exact: true })).toBeVisible();
  const configuration = await page.evaluate(async () => {
    const module = await import(/* @vite-ignore */ '/src/auth/AuthProvider.tsx');
    const auth = module.msalInstance.getConfiguration().auth;
    return { external: module.externalStaff, client: auth.clientId, authority: auth.authority, redirect: auth.redirectUri };
  });
  expect(configuration).toEqual({ external: true, client: '77777777-7777-7777-7777-777777777777', authority: 'https://teststaff.ciamlogin.com/44444444-4444-4444-4444-444444444444', redirect: `${host}/staff/external` });
});

test('the public portal separates practitioner and clinic workforce entry points', async ({ page }) => {
  await fixture(page, false); await page.goto(host);
  await expect(page.getByRole('link', { name: 'Clinic staff sign-in', exact: true })).toHaveAttribute('href', '/staff/login');
  await page.getByRole('link', { name: 'Practitioner sign-in', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Practitioner sign-in', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Continue with Google', exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Existing workforce staff sign-in', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign in with Microsoft', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Continue with Google', exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => sessionStorage.getItem('wellness.staff.provider'))).toBeNull();
});

for (const label of ['Continue with Google', 'Continue with personal Microsoft account']) {
  test(`${label} uses the external staff provider picker without rejected issuer hints`, async ({ page }) => {
    await page.route('**/api/runtime-config.php', route => route.fulfill({ json: {} }));
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: route.request().url().endsWith('/site-config') ? { name: 'Synthetic clinic' } : { available: true, enabled: true, activation_available: false, terms: null, plan: null } } }));
    await page.goto(`${host}/staff/apply?domain_hint=evil.example&login_hint=someone@example.test&redirect_uri=https://evil.example`);
    await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible();
    await expect(page.getByText('On the next screen, choose Google or Microsoft account to continue.', { exact: true })).toBeVisible();
    await page.evaluate(async () => {
      const module = await import(/* @vite-ignore */ '/src/auth/AuthProvider.tsx');
      // Keep the real bridge/request construction. Replace only the navigation effect.
      module.msalInstance.loginRedirect = async (request: unknown) => { (window as Window & { capturedLogin?: unknown }).capturedLogin = request; };
    });
    await page.getByRole('button', { name: label, exact: true }).click();
    expect(await page.evaluate(() => (window as Window & { capturedLogin?: unknown }).capturedLogin)).toEqual({ scopes: ['api://88888888-8888-8888-8888-888888888888/access_as_staff'], prompt: 'login' });
  });
}

test('personal Microsoft selection remains usable in French at mobile width and 200 percent zoom', async ({ page }) => {
  await fixture(page, false); await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${host}/staff/external?lang=fr`);
  await expect(page.getByText('À l’écran suivant, choisissez Google ou Compte Microsoft pour continuer.', { exact: true })).toBeVisible();
  await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
  const microsoft = page.getByRole('button', { name: 'Continuer avec un compte Microsoft personnel', exact: true });
  await microsoft.focus(); await expect(microsoft).toBeFocused(); await page.keyboard.press('Enter');
  expect(await page.evaluate(() => (window as Window & { testLogin?: string }).testLogin)).toBe('microsoft');
  const overflow = await page.evaluate(() => [...document.querySelectorAll('h1,button,a,p,header')].filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => ({ tag: el.tagName, text: el.textContent, right: el.getBoundingClientRect().right })));
  expect(overflow).toEqual([]);
  await page.screenshot({ path: 'test-results/practitioner-applications/practitioner-signin-fr-zoom.png', fullPage: true });
});

test('an unapproved practitioner can reach their application after portal access denial', async ({ page }) => {
  const state = await fixture(page); await page.goto(`${host}/staff/external`);
  await expect(page.getByRole('link', { name: 'Apply or check your practitioner application' })).toBeVisible();
  await page.getByRole('link', { name: 'Apply or check your practitioner application' }).click();
  await expect(page.getByRole('heading', { name: 'Apply to join the clinic' })).toBeVisible();
  expect(state.privateRequests).toContain('/api/v1/auth/me');
  expect(state.privateRequests).not.toContain('/api/v1/clients');
});

for (const transport of ['hash', 'query']) for (const failed of [false, true]) {
  test(`staff callback cleans ${transport} only after MSAL settles (${failed ? 'failure' : 'success'})`, async ({ page }) => {
    await fixture(page, false);
    await page.route('**/src/auth/AuthProvider.tsx', async route => {
      const response = await route.fetch();
      await route.fulfill({ response, body: await response.text() + `\nmsalInstance.handleRedirectPromise=()=>new Promise((resolve,reject)=>{window.finishRedirect=()=>${failed ? 'reject(new Error("Synthetic private callback diagnostic"))' : 'resolve(null)'};});` });
    });
    const response = failed ? 'error=access_denied&error_description=synthetic-private-error&state=synthetic-state' : 'code=synthetic-code&state=synthetic-state&client_info=synthetic-info';
    const url = transport === 'hash' ? `${host}/staff/external?return=application#${response}&keep=anchor` : `${host}/staff/external?return=application&${response}#keep=anchor`;
    await page.goto(url);
    await expect.poll(() => page.evaluate(() => typeof (window as Window & { finishRedirect?: () => void }).finishRedirect)).toBe('function');
    expect(page.url()).toContain(failed ? 'error_description=' : 'code=synthetic-code');
    await page.evaluate(() => (window as Window & { finishRedirect?: () => void }).finishRedirect?.());
    await expect(page.getByRole('heading', { name: 'Practitioner sign-in', exact: true })).toBeVisible();
    await expect(page).toHaveURL(`${host}/staff/external?return=application#keep=anchor`);
    if (failed) await expect(page.getByText('Sign-in could not be completed. Try again or choose another sign-in method.')).toBeVisible();
    await expect(page.getByText('Synthetic private callback diagnostic')).toHaveCount(0);
    expect(await page.evaluate(() => sessionStorage.getItem('wellness.staff.provider'))).toBe('external');
  });
}

test('callback cleanup preserves unrelated navigation and never overwrites navigation during processing', async ({ page }) => {
  await fixture(page, false); await page.goto(`${host}/staff/login?portal=admin#section`);
  await expect(page.getByRole('heading', { name: 'Staff portal', exact: true })).toBeVisible();
  const result = await page.evaluate(async () => {
    const { clearStaffRedirectResponse } = await import('/src/auth/staffRedirectCleanup.ts');
    const original = location.href; clearStaffRedirectResponse(original);
    const unchanged = location.href === original;
    const callback = `${location.origin}/staff/login?code=synthetic&state=synthetic`;
    history.replaceState({ synthetic: true }, '', callback);
    history.replaceState({ synthetic: true }, '', '/staff/login#new-navigation');
    clearStaffRedirectResponse(callback);
    return { unchanged, path: location.pathname + location.hash, retainedHistory: history.state.synthetic };
  });
  expect(result).toEqual({ unchanged: true, path: '/staff/login#new-navigation', retainedHistory: true });
});

test('a rejected identity callback renders recovery without exposing provider diagnostics', async ({ page }) => {
  const pageErrors: string[] = []; page.on('pageerror', error => pageErrors.push(error.message));
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: {} }));
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: { name: 'Synthetic clinic' } } }));
  await page.route('**/src/auth/AuthProvider.tsx', async route => {
    const response = await route.fetch();
    await route.fulfill({ response, body: await response.text() + '\nmsalInstance.handleRedirectPromise = async () => { throw new Error("Synthetic private provider diagnostic"); };' });
  });
  await page.goto(`${host}/staff/external`);
  await expect(page.getByText('Sign-in could not be completed. Try again or choose another sign-in method.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Continue with Google', exact: true })).toBeEnabled();
  await expect(page.getByText('Synthetic private provider diagnostic')).toHaveCount(0);
  expect(pageErrors).toEqual([]);
});

for (const language of ['en', 'fr']) {
  test(`pilot support reference stays readable and clears after retry in ${language}`, async ({ page }) => {
    const state = await fixture(page);
    const reference = 'WPI1 ' + Array.from({ length: 22 }, () => 'synthetic-reference-group-12345678').join(' ');
    let approved = false;
    await page.route('**/api/v1/auth/me', route => approved
      ? route.fulfill({ json: { data: { roles: [], permissions: [] } } })
      : route.fulfill({ status: 403, json: { error: { code: 'pilot_access_denied', fields: { ID: reference } } } }));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${host}/staff/external?lang=${language}`);
    const support = page.getByText(`ID: ${reference}`, { exact: true });
    await expect(support).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(page.getByRole('button', { name: 'Continue with Google', exact: true })).toHaveCount(0);
    approved = true;
    await page.getByRole('button', { name: language === 'fr' ? 'Réessayer' : 'Retry', exact: true }).click();
    await expect(support).toHaveCount(0);
    expect(state.privateRequests).not.toContain('/api/v1/clients');
    expect(state.writes).toEqual([]);
  });
}
