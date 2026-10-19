import { expect, test, type Page } from "@playwright/test";
import { selectAccount } from "../src/auth/accountSelection";

test('patient privacy instructions preserve independent restrictions and current versions', async ({ page }) => {
  await fixture(page, true);
  await page.route('**/api/v1/customer/auth/me', route => route.fulfill({ json: { data: { authenticated: true, authentication_context: 'customer', onboarding_status: 'linked', capabilities: ['own_appointments'], session: { idle_expires_at: Date.now()/1000+1800, absolute_expires_at: Date.now()/1000+28800 } } } }));
  await page.route('**/api/v1/customer/appointments?**', route => route.fulfill({ json: { data: { items: [] } } }));
  const items: {scope: string; target_staff_id: number; restricted: boolean; version: number}[] = [];
  const changes: unknown[] = [];
  await page.route('**/api/v1/customer/consent', async route => {
    if (route.request().method() === 'PUT') {
      const body = route.request().postDataJSON(); changes.push(body);
      const old = items.find(item => item.scope === body.scope && item.target_staff_id === body.target_staff_id);
      expect(body.version).toBe(old?.version ?? 0);
      if (old) Object.assign(old, {restricted: body.restricted, version: old.version+1});
      else items.push({...body, version: 1});
    }
    await route.fulfill({json: {data: {items, staff: [{id: 2, display_name: 'Practitioner A'}]}}});
  });
  await page.goto('http://localhost:5184/client');
  await page.getByRole('button', {name: 'Privacy instructions', exact: true}).click();
  await page.getByRole('button', {name: 'Withdraw staff access permission', exact: true}).click();
  await expect(page.getByText('Access restricted', {exact: true})).toBeVisible();
  await page.getByLabel('Staff access restriction', {exact: true}).click();
  await page.getByRole('option', {name: 'Practitioner A', exact: true}).click();
  await expect(page.getByText('The restriction for all clinic staff also applies. Restoring one staff member does not clear it.', {exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Withdraw staff access permission', exact: true}).click();
  await page.getByRole('button', {name: 'Restore staff access permission', exact: true}).click();
  expect(changes).toEqual([
    {scope:'staff_access', target_staff_id:0, restricted:true, version:0},
    {scope:'staff_access', target_staff_id:2, restricted:true, version:0},
    {scope:'staff_access', target_staff_id:2, restricted:false, version:1},
  ]);
  expect(items.find(item => item.target_staff_id === 0)?.restricted).toBe(true);
  await page.screenshot({path:test.info().outputPath('privacy-instructions.png'), fullPage:true});
});

test('mobile privacy conflict requires reload before another change', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await fixture(page, true);
  await page.route('**/api/v1/customer/auth/me', route => route.fulfill({json:{data:{authenticated:true,authentication_context:'customer',onboarding_status:'linked',capabilities:['own_appointments'],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}}}}));
  await page.route('**/api/v1/customer/appointments?**', route => route.fulfill({json:{data:{items:[]}}}));
  let attempts=0;
  await page.route('**/api/v1/customer/consent', route => {
    if(route.request().method()==='PUT') {attempts++;return route.fulfill({status:409,json:{error:{code:'consent_changed',message:'Consent instructions changed. Reload before saving.'}}});}
    return route.fulfill({json:{data:{items:[],staff:[]}}});
  });
  await page.goto('http://localhost:5184/client');
  await page.getByRole('button',{name:'Privacy instructions',exact:true}).click();
  await page.getByRole('button',{name:'Stop appointment messages',exact:true}).click();
  await expect(page.getByText('Consent instructions changed. Reload before saving.',{exact:false})).toBeVisible();
  await expect(page.getByRole('button',{name:'Stop appointment messages',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Reload',exact:true}).click();
  await expect(page.getByRole('button',{name:'Stop appointment messages',exact:true})).toBeEnabled();
  expect(attempts).toBe(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
});
import {
  freshCustomerLoginParameters,
  shouldClearCustomerAccountHint,
} from "../src/customer/providerRouting";
import type { AccountInfo } from "@azure/msal-browser";

test('client discovers from a saved address and sends a duplicate-safe travel enquiry before reading a reply',async({page})=>{
  await fixture(page,true);
  await page.route('**/api/v1/customer/auth/me',route=>route.fulfill({json:{data:{authenticated:true,authentication_context:'customer',onboarding_status:'linked',capabilities:['own_appointments','book_own_appointments'],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}}}}));
  await page.route('**/api/v1/customer/appointments?**',route=>route.fulfill({json:{data:{items:[]}}}));
  await page.route('**/api/v1/customer/profile',route=>route.fulfill({json:{data:{address:{address_line1:'1 Synthetic Street',city:'Toronto',province:'Ontario',postal_code:'M5V 1A1',country:'Canada'}}}}));
  await page.route('**/api/v1/public/discovery/options',route=>route.fulfill({json:{data:{map_browser_key:''}}}));
  await page.route('**/api/v1/public/practitioners',route=>route.fulfill({json:{data:[{slug:'esther',public_name:'Esther'}]}}));
  await page.route('**/api/v1/public/services',route=>route.fulfill({json:{data:[{slug:'massage',name:'Massage'}]}}));
  await page.route('**/api/v1/customer/discovery/search',route=>{expect(route.request().postDataJSON()).toMatchObject({area:'1 Synthetic Street, Toronto, Ontario, M5V 1A1, Canada'});return route.fulfill({json:{data:{label:'Synthetic area',point:{latitude:43.6,longitude:-79.4},settings:{extra_km:25,cap_km:100},matches:[{practitioner_id:8,service_id:13,location_id:8,practitioner_slug:'esther',service_slug:'massage',status:'enquiry_only',token:'synthetic-proof',starting_price_cents:7500}]}}});});
  let received:any=null,attempts=0;
  await page.route('**/api/v1/customer/travel-enquiries',route=>{
    if(route.request().method()==='POST'){
      const body=route.request().postDataJSON();attempts++;if(!received)received=body;else expect(body).toEqual(received);
      return attempts===1?route.abort('failed'):route.fulfill({json:{data:{id:81}}});
    }
    return route.fulfill({json:{data:{items:received?[{id:81,status:'open',service_name:'Massage',practitioner_name:'Esther',client_name:'Client',unread:1}]:[],unread_count:received?1:0,next_before:null}}});
  });
  await page.route('**/api/v1/customer/travel-enquiries/81',route=>route.fulfill({json:{data:{id:81,status:'open',search_label:'Synthetic area',service_name:'Massage',practitioner_name:'Esther',messages:[{id:101,body:'Please ask staff to arrange a visit.',created_at:'2026-10-18 15:00:00',sender_name:'Clinic team'}],next_after:null}}}));
  await page.route('**/api/v1/customer/travel-enquiries/81/read',route=>route.fulfill({json:{data:{saved:true}}}));
  await page.goto('http://localhost:5184/client#discover=1');
  await expect(page.getByText('Travel approval required',{exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Ask about travel',exact:true}).click();
  await page.getByLabel('Your message',{exact:true}).fill('Would you consider extra travel?');
  await page.getByRole('button',{name:'Send travel enquiry',exact:true}).click();
  await expect(page.getByRole('button',{name:'Retry same enquiry',exact:true})).toBeEnabled();
  await expect(page.getByRole('textbox',{name:'Your message',exact:true})).toBeDisabled();
  await page.getByRole('button',{name:'Retry same enquiry',exact:true}).click();
  await page.getByRole('button',{name:/Unread · Massage/}).click();
  await expect(page.getByText('Please ask staff to arrange a visit.',{exact:true})).toBeVisible();
  expect(attempts).toBe(2);expect(received).toMatchObject({token:'synthetic-proof',search_label:'Synthetic area'});
});

test('signed-in booking defaults to saved-address filtering and keeps extra-distance matches out of booking choices',async({page})=>{
  await fixture(page,true);
  await page.route('**/api/v1/customer/auth/me',route=>route.fulfill({json:{data:{authenticated:true,authentication_context:'customer',onboarding_status:'linked',capabilities:['book_own_appointments'],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}}}}));
  await page.route('**/api/v1/customer/appointments?**',route=>route.fulfill({json:{data:{items:[]}}}));
  await page.route('**/api/v1/customer/booking-options',route=>route.fulfill({json:{data:{default_location_id:8,rooms:[],combinations:[{location_id:8,location_name:'Virtual clinic',timezone:'America/Toronto',service_id:13,service_name:'Massage',practitioner_id:8,practitioner_name:'Esther',duration_option_id:4,duration_minutes:60,offers_mobile:1,offers_clinic:0,base_price_cents:7500,mobile_fee_cents:0}]}}}));
  await page.route('**/api/v1/customer/profile',route=>route.fulfill({json:{data:{address:{address_line1:'1 Synthetic Street',city:'Toronto',province:'Ontario',postal_code:'M5V 1A1',country:'Canada'}}}}));
  await page.route('**/api/v1/public/discovery/options',route=>route.fulfill({json:{data:{map_browser_key:''}}}));
  await page.route('**/api/v1/public/discovery/search',route=>{expect(route.request().postDataJSON()).toMatchObject({area:'1 Synthetic Street, Toronto, Ontario, M5V 1A1, Canada'});return route.fulfill({json:{data:{label:'Synthetic area',point:{latitude:43.6,longitude:-79.4},settings:{extra_km:25,cap_km:100},matches:[{practitioner_id:8,service_id:13,location_id:8,practitioner_slug:'esther',service_slug:'massage',status:'enquiry_only',token:'synthetic-proof',starting_price_cents:7500}]}}});});
  await page.goto('http://localhost:5184/client');await page.getByRole('button',{name:'Book appointment',exact:true}).click();
  await expect(page.getByText('No practitioners within normal coverage for this selection.',{exact:true})).toBeVisible();
  await expect(page.getByRole('button',{name:'Find nearby care',exact:true}).last()).toBeVisible();
});

test('clients retrieve receipt PDFs and see voided receipts on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page, true);
  await page.route('**/api/v1/customer/auth/me', route => route.fulfill({ json: { data: { authenticated: true, authentication_context: 'customer', onboarding_status: 'linked', capabilities: ['own_appointments'], session: { idle_expires_at: Date.now() / 1000 + 1800, absolute_expires_at: Date.now() / 1000 + 28800 } } } }));
  await page.route('**/api/v1/customer/appointments?**', route => route.fulfill({ json: { data: { items: [] } } }));
  await page.route('**/api/v1/customer/receipts?**', route => route.fulfill({ json: { data: { enabled: true, has_more: false, items: [{ id: 5, number: 'R-TEST', appointment_id: 41, status: 'issued', service_name: 'Massage', practitioner_name: 'Practitioner', total_cents: 11300 }, { id: 4, number: 'R-VOID', appointment_id: 40, status: 'void', service_name: 'Massage', practitioner_name: 'Practitioner', total_cents: 11300 }] } } }));
  await page.route('**/api/v1/customer/receipts/5/pdf', route => {
    expect(route.request().headers().authorization).toBe('Bearer customer-test-token');
    return route.fulfill({ json: { data: { filename: 'R-TEST.pdf', pdf_base64: Buffer.from('%PDF-1.4\nSynthetic receipt download').toString('base64') } } });
  });
  await page.goto('http://localhost:5184/client');
  await page.getByRole('button', { name: 'My receipts', exact: true }).click();
  await expect(page.getByText('Voided receipt', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Download receipt PDF' })).toHaveCount(1);
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download receipt PDF' }).click();
  expect((await download).suggestedFilename()).toBe('R-TEST.pdf');
  await expect(page.getByRole('button', { name: 'Void receipt', exact: true })).toHaveCount(0);
  await page.screenshot({ path: test.info().outputPath('client-receipts-mobile.png'), fullPage: true });
});

async function fixture(page: Page, signedIn = false, callbackFails = false) {
  // Only Playwright intercepts this module; no production bypass or fake tokens.
  await page.route("**/src/customer/auth.ts", (route) =>
    route.fulfill({
      contentType: "application/javascript",
      body: `
    const account=${signedIn ? "{homeAccountId:'customer',name:'Test Client'}" : "null"};
    export const customerConfigured=true;
    export const customerHome='http://localhost:5184/client';
    export const customerInstance={initialize:async()=>{},handleRedirectPromise:async()=>{${callbackFails ? "throw new Error('test callback failure');" : "return null;"}},getActiveAccount:()=>account?{...account}:null,getAllAccounts:()=>account?[{...account}]:[],setActiveAccount:()=>{}};
    export const selectCustomerAccount=()=>account?{...account,idTokenClaims:{exp:Math.floor(Date.now()/1000)+3600}}:null;
    export const customerToken=async()=>'customer-test-token';
    export const customerSignIn=async()=>{window.customerSignInCalled=true;};
    export const customerSignOut=async()=>{window.customerSignOutCalled=true;};
  `,
    }),
  );
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({ json: { data: { name: "Test Wellness" } } }),
  );
}
test("customer route offers sign-in without running staff authentication", async ({
  page,
}) => {
  const requests: string[] = [];
  page.on("request", (r) => requests.push(r.url()));
  await fixture(page);
  await page.goto("http://localhost:5184/client");
  await expect(
    page.getByRole("heading", { name: "Client portal" }),
  ).toBeVisible();
  await page.screenshot({
    path: test.info().outputPath("client-sign-in.png"),
    fullPage: true,
  });
  await page
    .getByRole("button", { name: "Sign in or create client account" })
    .click();
  expect(await page.evaluate(() => (window as any).customerSignInCalled)).toBe(
    true,
  );
  expect(
    requests.some((url) => /\/api\/v1\/(auth\/me|profile\/avatar)/.test(url)),
  ).toBe(false);
  await expect(
    page.getByRole("button", { name: "Create appointment", exact: true }),
  ).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Report a problem" })).toHaveAttribute("href", /localhost:5183\/contact/);
});
test("portal Book handoff preserves a guest selection and starts client sign-in", async ({ page }) => {
  await fixture(page);
  await page.goto("http://localhost:5184/book?delivery_mode=clinic&location_id=2&service_id=4&practitioner_id=7&duration_option_id=8&starts_at=2099-10-01T14%3A00%3A00-04%3A00");
  await expect(page).toHaveURL("http://localhost:5184/client");
  await expect.poll(() => page.evaluate(() => Boolean((window as Window & { customerSignInCalled?: boolean }).customerSignInCalled))).toBe(true);
  expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem('wellness.customer.booking-intent.v1') ?? 'null'))).toMatchObject({ service_id: '4', practitioner_id: '7', duration_option_id: '8' });
});
test("portal Book without a valid selection returns to guest browsing", async ({ page }) => {
  await fixture(page);
  await page.goto("http://localhost:5184/book?service_id=4");
  await expect(page).toHaveURL("http://localhost:5184/");
  await expect(page.getByRole('heading', { name: 'Welcome to Test Wellness' })).toBeVisible();
});

test("client login from the catalogue returns linked clients to browsing",async({page})=>{
  await fixture(page,true);
  await page.route('**/api/v1/customer/auth/me',route=>route.fulfill({json:{data:{authenticated:true,authentication_context:'customer',onboarding_status:'linked',capabilities:['own_appointments','book_own_appointments'],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}}}}));
  await page.goto('http://localhost:5184/client?return=browse');
  await expect(page).toHaveURL('http://localhost:5184/');
  await expect(page.getByRole('heading',{name:/Welcome to/})).toBeVisible();
});
test("verified identity remains unlinked with no client records or staff roles", async ({
  page,
}) => {
  await fixture(page, true);
  let checks = 0;
  await page.route("**/api/v1/customer/auth/me", (route) => {
    checks++;
    expect(route.request().headers().authorization).toBe(
      "Bearer customer-test-token",
    );
    return route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: "not_linked",
          capabilities: [],
        },
      },
    });
  });
  await page.goto(
    "http://localhost:5184/client/auth/callback?code=synthetic#state=synthetic",
  );
  await expect(page).toHaveURL("http://localhost:5184/client");
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText(/has not been linked to a clinic record/),
  ).toBeVisible();
  await page.screenshot({
    path: test.info().outputPath("client-verified.png"),
    fullPage: true,
  });
  await page.getByRole("button", { name: "Open client account menu" }).click();
  expect(checks).toBe(1);
  await page.getByRole("menuitem", { name: "Sign out", exact: true }).click();
  expect(await page.evaluate(() => (window as any).customerSignOutCalled)).toBe(
    true,
  );
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toHaveCount(0);
});

test("account restoration separates staff and customers even with a wrong active account", () => {
  const staff = {
    homeAccountId: "staff",
    tenantId: "staff-tenant",
    environment: "login.windows.net",
  } as AccountInfo;
  const client = {
    homeAccountId: "client",
    tenantId: "client-tenant",
    environment: "clients.ciamlogin.com",
  } as AccountInfo;
  expect(
    selectAccount([client, staff], client, "staff-tenant", [
      "login.windows.net",
    ]),
  ).toBe(staff);
  expect(
    selectAccount([staff, client], staff, "client-tenant", [
      "clients.ciamlogin.com",
    ]),
  ).toBe(client);
  expect(
    selectAccount([client], client, "staff-tenant", ["login.windows.net"]),
  ).toBeNull();
  expect(
    selectAccount(
      [{ ...client, environment: "untrusted.test" }],
      null,
      "client-tenant",
      ["clients.ciamlogin.com"],
    ),
  ).toBeNull();
});

test("fresh customer login clears cached account hints and lets the user flow select the provider", () => {
  expect(
    shouldClearCustomerAccountHint({
      idTokenClaims: {
        idp: "google.com",
        login_hint: "opaque-login-hint",
      },
    }),
  ).toBe(true);
  expect(
    shouldClearCustomerAccountHint({
      idTokenClaims: { idp: "untrusted.example" },
    }),
  ).toBe(true);
  expect(shouldClearCustomerAccountHint(null)).toBe(false);

  const parameters = freshCustomerLoginParameters("nonce");
  expect(parameters).toMatchObject({
    extraQueryParameters: { max_age: "0" },
  });
  expect(parameters).not.toHaveProperty("domainHint");
  expect(parameters).not.toHaveProperty("loginHint");
});

test("client verification stays stable across rerenders, refresh and public navigation", async ({
  page,
}) => {
  await fixture(page, true);
  let checks = 0;
  await page.route("**/api/v1/customer/auth/me", (route) => {
    checks++;
    return route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: "not_linked",
          capabilities: [],
        },
      },
    });
  });
  await page.goto("http://localhost:5184/client");
  const globeBox = await page
      .getByRole("button", { name: "Language and region" })
      .boundingBox(),
    accountBox = await page
      .getByRole("button", { name: "Open client account menu" })
      .boundingBox();
  expect(
    globeBox && accountBox
      ? accountBox.x - (globeBox.x + globeBox.width)
      : Number.POSITIVE_INFINITY,
  ).toBeLessThanOrEqual(8);
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Open client account menu" }).click();
  await page.keyboard.press("Escape");
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toBeVisible();
  expect(checks).toBe(1);
  await page.reload();
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toBeVisible();
  expect(checks).toBe(2);
  await page.getByRole("link", { name: "Public website" }).click();
  await expect(
    page.getByRole("link", { name: "Open client account", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("link", { name: "Open client account", exact: true }),
  ).toBeVisible();
  expect(checks).toBe(2); // Display bridge never requests tokens or API verification.
  await page
    .getByRole("link", { name: "Open client account", exact: true })
    .click();
  await expect(page).toHaveURL("http://localhost:5184/client");
  await expect(
    page.getByText("Customer sign-in verified.", { exact: true }),
  ).toBeVisible();
  expect(checks).toBe(3);
});

test("generic login is client-first and staff login remains separate", async ({
  page,
}) => {
  await fixture(page);
  await page.goto("http://localhost:5184/login");
  await expect(page).toHaveURL("http://localhost:5184/client");
  await expect(
    page.getByRole("heading", { name: "Client portal" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Staff Sign In", exact: true }).click();
  await expect(page).toHaveURL("http://localhost:5184/staff/login");
  await expect(
    page.getByRole("heading", { name: "Staff portal", exact: true }),
  ).toBeVisible();
});

test("public login ignores spoofed account messages and works without the bridge", async ({
  page,
}) => {
  await fixture(page);
  await page.route("**/client/session", (route) => route.abort());
  await page.goto("http://localhost:5183/");
  await page.evaluate(() =>
    window.postMessage(
      { type: "wellness:account-display", initials: "XX", nonce: "" },
      "*",
    ),
  );
  await expect(
    page.getByRole("link", { name: "Sign In", exact: true }),
  ).toHaveAttribute("href", "http://localhost:5184/client?lang=en");
  await expect(
    page.getByRole("link", { name: "Open client account" }),
  ).toHaveCount(0);
});
for (const status of [401, 403, 500]) {
  test(`customer API ${status} is readable and never loops or grants access`, async ({
    page,
  }) => {
    await fixture(page, true);
    let requests = 0;
    await page.route("**/api/v1/customer/auth/me", (route) => {
      requests++;
      return route.fulfill({ status, body: "", contentType: "text/html" });
    });
    await page.goto("http://localhost:5184/client");
    await expect(
      page.getByRole("button", { name: "Retry verification" }),
    ).toBeVisible();
    await expect(
      page.getByText("Customer sign-in verified.", { exact: true }),
    ).toHaveCount(0);
    expect(requests).toBe(1);
    await page.getByRole("button", { name: "Retry verification" }).click();
    await expect.poll(() => requests).toBe(2);
    await expect(page.getByText(/Unexpected end of JSON/)).toHaveCount(0);
  });
}
test("callback failure strips response and offers deliberate recovery", async ({
  page,
}) => {
  await fixture(page, false, true);
  await page.goto("http://localhost:5184/client/auth/callback?code=synthetic");
  await expect(page).toHaveURL("http://localhost:5184/client");
  await expect(
    page.getByText(/Client sign-in could not be completed/),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Sign in or create client account" }),
  ).toBeEnabled();
});

test("real customer MSAL starts code flow with PKCE and customer-only scope", async ({
  page,
}) => {
  await page.route("**/api/v1/customer/auth/options", (route) =>
    route.fulfill({ json: { data: { onboarding_enabled: true } } }),
  );
  await page.route("**/api/v1/customer/auth/challenge", (route) =>
    route.fulfill({ json: { data: { nonce: "a".repeat(64) } } }),
  );
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({ json: { data: { name: "Test Wellness" } } }),
  );
  const authority =
    "https://testcustomers.ciamlogin.com/44444444-4444-4444-4444-444444444444";
  await page.route("https://testcustomers.ciamlogin.com/**", (route) => {
    if (route.request().url().includes(".well-known"))
      return route.fulfill({
        json: {
          issuer: `${authority}/v2.0`,
          authorization_endpoint: `${authority}/oauth2/v2.0/authorize`,
          token_endpoint: `${authority}/oauth2/v2.0/token`,
          end_session_endpoint: `${authority}/oauth2/v2.0/logout`,
          jwks_uri: `${authority}/discovery/v2.0/keys`,
          response_types_supported: ["code"],
          subject_types_supported: ["pairwise"],
          id_token_signing_alg_values_supported: ["RS256"],
        },
      });
    return route.fulfill({
      contentType: "text/html",
      body: "Test identity provider",
    });
  });
  await page.goto("http://localhost:5184/client");
  const request = page.waitForRequest((r) =>
    r.url().includes("/oauth2/v2.0/authorize"),
  );
  await page
    .getByRole("button", { name: "Sign in or create client account" })
    .click();
  const url = new URL((await request).url());
  expect(url.searchParams.get("client_id")).toBe(
    "55555555-5555-5555-5555-555555555555",
  );
  expect(url.searchParams.get("response_type")).toBe("code");
  expect(url.searchParams.get("code_challenge_method")).toBe("S256");
  expect(url.searchParams.get("code_challenge")).toBeTruthy();
  expect(url.searchParams.get("state")).toBeTruthy();
  expect(url.searchParams.get("redirect_uri")).toBe(
    "http://localhost:5184/client/auth/callback",
  );
  expect(url.searchParams.get("scope")).toContain(
    "api://66666666-6666-6666-6666-666666666666/access_as_client",
  );
  expect(url.searchParams.has("client_secret")).toBe(false);
  expect(url.searchParams.get("nonce")).toBe("a".repeat(64));
  // Start at the External ID provider chooser, not Microsoft's remembered-
  // account picker (where selecting a Gmail address may fail).
  expect(url.searchParams.get("prompt")).toBe("login");
  expect(url.searchParams.get("max_age")).toBe("0");
  expect(url.searchParams.has("domain_hint")).toBe(false);
  expect(url.searchParams.has("login_hint")).toBe(false);
  expect(
    JSON.parse(url.searchParams.get("claims") ?? "{}").id_token.auth_time
      .essential,
  ).toBe(true);
});

test("new customer registers once and pending invitation never exposes a profile", async ({
  page,
}) => {
  await fixture(page, true);
  let status = "not_linked",
    registrations = 0,
    checks = 0;
  await page.route("**/api/v1/customer/auth/me", (route) => {
    checks++;
    return route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: status,
          review_code: "ABCDEF123456",
          session: {
            idle_expires_at: Date.now() / 1000 + 1800,
            absolute_expires_at: Date.now() / 1000 + 28800,
          },
        },
      },
    });
  });
  await page.route("**/api/v1/customer/register", (route) => {
    registrations++;
    const body = route.request().postDataJSON();
    expect(body.given_name).toBe("Test");
    expect(body.address.city).toBe("Town");
    expect(body.client_id).toBeUndefined();
    expect(body.roles).toBeUndefined();
    status = "pending_review"; // Exercise the privacy boundary on the subsequent status response.
    return route.fulfill({ json: { data: { onboarding_status: status } } });
  });
  await page.goto("http://localhost:5184/client");
  await page.getByRole("button", { name: "I am a new client" }).click();
  const preferredContact = page.getByRole("combobox", { name: "Preferred contact" });
  await expect(preferredContact).toHaveText("Email");
  await preferredContact.click();
  await expect(page.getByRole("option", { name: "Email" })).toBeVisible();
  await page.getByRole("option", { name: "Email" }).click();
  for (const [label, value] of Object.entries({
    "First name": "Test",
    "Last name": "Client",
    "Contact email": "test@example.test",
    Phone: "555-0100",
    "Street address": "1 Test Street",
    City: "Town",
    "Province / region": "ON",
    "Postal code": "A1A 1A1",
  }))
    await page.getByRole("textbox", { name: label, exact: true }).fill(value);
  await page.getByRole("button", { name: "Create my client record" }).click();
  await expect(page.getByText(/Staff must verify your identity/)).toBeVisible();
  await expect(page.getByText("ABCDEF123456")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "My profile", exact: true }),
  ).toHaveCount(0);
  expect(registrations).toBe(1);
  expect(checks).toBeLessThanOrEqual(3);
});

test("linked client sees only their appointments and idle expiry removes private UI without polling", async ({
  page,
}) => {
  await fixture(page, true);
  await page.clock.install();
  const now = Date.now() / 1000;
  let checks = 0,
    appointmentReads = 0;
  await page.route("**/api/v1/customer/auth/me", (route) => {
    checks++;
    return route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: "linked",
          session: {
            idle_expires_at: now + 1800,
            absolute_expires_at: now + 28800,
          },
        },
      },
    });
  });
  await page.route("**/api/v1/customer/profile", (route) =>
    route.fulfill({
      json: {
        data: {
          given_name: "Private",
          family_name: "Client",
          email: "private@example.test",
          phone: "555-0100",
          address: null,
          revision: "revision",
        },
      },
    }),
  );
  await page.route("**/api/v1/customer/appointments*", (route) => {
    appointmentReads++;
    expect(route.request().url()).not.toContain("client_id");
    return route.fulfill({
      json: {
        data: {
          items: [
            {
              id: 41,
              starts_at: "2099-09-20 14:00:00",
              ends_at: "2099-09-20 15:30:00",
              status: "confirmed",
              service: "Therapeutic Massage",
              practitioner: "Esther Vanderpoel",
              location: "Holland Landing Clinic",
              timezone: "America/Toronto",
              delivery_mode: "mobile",
            },
            {
              id: 12,
              starts_at: "2020-01-10 16:00:00",
              ends_at: "2020-01-10 17:00:00",
              status: "completed",
              service: "Massage",
              practitioner: "Esther Vanderpoel",
              location: "Holland Landing Clinic",
              timezone: "America/Toronto",
              delivery_mode: "clinic",
            },
            {
              id: 13,
              starts_at: "2099-09-21 16:00:00",
              ends_at: "2099-09-21 17:00:00",
              status: "canceled_by_client",
              service: "Canceled Massage",
              practitioner: "Esther Vanderpoel",
              location: "Holland Landing Clinic",
              timezone: "America/Toronto",
              delivery_mode: "clinic",
            },
          ],
        },
      },
    });
  });
  let calendarReads = 0;
  await page.route("**/api/v1/customer/appointments/41/calendar", (route) => {
    calendarReads++;
    return route.fulfill({ json: { data: { filename: "appointment-41.ics", content: "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n" } } });
  });
  await page.goto("http://localhost:5184/client");
  await expect(
    page.getByText("Therapeutic Massage", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText(/On-Site \(client location\).*Appointment #41/),
  ).toBeVisible();
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Add to calendar" }).click();
  expect((await download).suggestedFilename()).toBe("appointment-41.ics");
  expect(calendarReads).toBe(1);
  await expect(page.getByRole("checkbox", { name: "Show canceled appointments" })).not.toBeChecked();
  await expect(page.getByText("Canceled Massage", { exact: true })).toHaveCount(0);
  await page.getByRole("checkbox", { name: "Show canceled appointments" }).check();
  await expect(page.getByText("Canceled Massage", { exact: true })).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem('wellness.client.showCanceledAppointments'))).toBe('true');
  await page.getByRole("checkbox", { name: "Show canceled appointments" }).uncheck();
  await expect(page.getByText("Canceled Massage", { exact: true })).toHaveCount(0);
  await expect(page.getByText("Massage", { exact: true })).toHaveCount(0);
  await page.getByRole("combobox").click();
  await page.getByRole("option", { name: "Past", exact: true }).click();
  await expect(page.getByText("Massage", { exact: true })).toBeVisible();
  await expect(
    page.getByText(/In clinic · Holland Landing Clinic/),
  ).toBeVisible();
  await page.getByRole("button", { name: "My profile", exact: true }).click();
  await expect(
    page.getByRole("textbox", { name: "First name", exact: true }),
  ).toHaveValue("Private");
  await page.clock.fastForward(1800001);
  await expect(
    page.getByText("Your client session has ended. Please sign in again."),
  ).toBeVisible();
  await expect(
    page.getByRole("textbox", { name: "First name", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Sign in again", exact: true }),
  ).toBeVisible();
  expect(checks).toBeLessThanOrEqual(2);
  expect(appointmentReads).toBe(3);
});

test("invitation fragment is removed and acceptance remains pending until manual refresh", async ({
  page,
}) => {
  await fixture(page, true);
  let pending = false,
    reads = 0;
  await page.route("**/api/v1/customer/auth/me", (route) => {
    reads++;
    return route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: pending ? "pending_review" : "not_linked",
          review_code: "CODE12345678",
          session: {
            idle_expires_at: Date.now() / 1000 + 1800,
            absolute_expires_at: Date.now() / 1000 + 28800,
          },
        },
      },
    });
  });
  await page.route("**/api/v1/customer/invitations/accept", (route) => {
    expect(route.request().postDataJSON().token).toBe("b".repeat(64));
    pending = true;
    return route.fulfill({
      json: { data: { onboarding_status: "pending_review" } },
    });
  });
  await page.goto(
    "http://localhost:5184/client/invite#token=" + "b".repeat(64),
  );
  await expect(page).toHaveURL("http://localhost:5184/client");
  await page.getByRole("button", { name: "I have an invitation" }).click();
  await page.getByLabel("Your full name").fill("Test Client");
  await page
    .getByRole("button", { name: "Accept invitation", exact: true })
    .click();
  await expect(page.getByText("CODE12345678")).toBeVisible();
  const before = reads;
  await page.getByRole("button", { name: "Check approval status" }).click();
  await expect.poll(() => reads).toBeGreaterThan(before);
});

for (const quotedFee of [0, 1500]) test(`linked client reviews distance fee ${quotedFee} before confirming`, async ({
  page,
}) => {
  await fixture(page, true);
  await page.route("**/api/v1/customer/auth/me", (route) =>
    route.fulfill({
      json: {
        data: {
          authenticated: true,
          authentication_context: "customer",
          onboarding_status: "linked",
          capabilities: [
            "own_profile",
            "own_appointments",
            "book_own_appointments",
          ],
          session: {
            idle_expires_at: Date.now() / 1000 + 1800,
            absolute_expires_at: Date.now() / 1000 + 28800,
          },
        },
      },
    }),
  );
  let appointmentReads = 0,
    confirmations = 0;
  await page.route("**/api/v1/customer/appointments*", (route) => {
    if (route.request().method() === "POST") {
      confirmations++;
      const body = route.request().postDataJSON();
      expect(body.client_id).toBeUndefined();
      expect(body.quoted_mobile_fee_cents).toBe(quotedFee);
      expect(body.service_id).toBe(4);
      expect(body.practitioner_id).toBe(7);
      expect(body.delivery_mode).toBe("mobile");
      expect(body.destination.address_line1).toBe("10 Client Street");
      expect(body.address_validation_token).toBe("coverage-proof");
      expect(body.idempotency_key).toBeTruthy();
      return route.fulfill({ json: { data: { id: 91 } } });
    }
    appointmentReads++;
    return route.fulfill({ json: { data: { items: [], limit: 100 } } });
  });
  await page.route("**/api/v1/customer/booking-options", (route) =>
    route.fulfill({
      json: {
        data: {
          default_location_id: 2,
          rooms: [],
          combinations: [
            {
              location_id: 2,
              location_name: "Holland Landing",
              timezone: "America/Toronto",
              service_id: 4,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 20,
              mobile_fee_cents: 1500,
              mobile_free_radius_km: 20,
              base_price_cents: 12000,
              practitioner_id: 7,
              practitioner_name: "Esther Vanderpoel",
              duration_option_id: 8,
              duration_minutes: 60,
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/customer/profile", (route) =>
    route.fulfill({
      json: {
        data: {
          given_name: "Test",
          family_name: "Client",
          email: "client@example.test",
          phone: "555-0100",
          preferred_contact: "email",
          revision: "r1",
          address: {
            address_line1: "10 Client Street",
            address_line2: "",
            city: "Newmarket",
            province: "ON",
            postal_code: "L3Y 1A1",
            country: "Canada",
            instructions: "",
          },
        },
      },
    }),
  );
  await page.route("**/api/v1/customer/address-coverage/validate", (route) =>
    route.fulfill({
      json: {
        data: {
          destination: route.request().postDataJSON().destination,
          covered: true,
          radius_km: 25,
          token: "coverage-proof",
          mobile_fee_cents: quotedFee,
        },
      },
    }),
  );
  await page.route("**/api/v1/customer/availability?**", (route) =>
    route.fulfill({
      json: {
        data: {
          availability: [
            {
              duration_option_id: 8,
              starts_at: "2099-10-01T14:00:00-04:00",
              ends_at: "2099-10-01T15:00:00-04:00",
              available_room_ids: [],
            },
          ],
        },
      },
    }),
  );

  await page.goto("http://localhost:5184/client");
  await page.getByRole("button", { name: "Book appointment" }).click();
  await page.getByRole("combobox", { name: "Service", exact: true }).click();
  await page.getByRole("option", { name: "Massage", exact: true }).click();
  await page
    .getByRole("combobox", { name: "Practitioner", exact: true })
    .click();
  await page
    .getByRole("option", { name: "Esther Vanderpoel", exact: true })
    .click();
  await page.getByRole("combobox", { name: "Duration", exact: true }).click();
  await page.getByRole("option", { name: /60 minutes/ }).click();
  await page
    .getByRole("button", { name: "Validate address and coverage" })
    .click();
  await expect(page.getByText("Your address is within the practitioner’s On-Site service area.")).toBeVisible();
  await expect(page.getByText(`Travel fee for this address: $${(quotedFee/100).toFixed(2)} CAD`)).toBeVisible();
  await page.getByRole("button", { name: "Find a time" }).click();
  await page.getByLabel("Appointment date").fill("2099-10-01");
  await page.getByRole("button", { name: "Find times" }).click();
  await page.getByRole("button", { name: /Oct.*1.*2099/i }).click();
  await page.getByRole("button", { name: "Review appointment" }).click();
  await page.getByRole("button", { name: "Confirm appointment" }).click();
  await expect(
    page.getByText(
      "Appointment #91 confirmed. A confirmation email is being sent.",
    ),
  ).toBeVisible();
  expect(confirmations).toBe(1);
  expect(appointmentReads).toBeGreaterThanOrEqual(2);
});

test("selected public On-Site time survives sign-in and address validation", async ({ page }) => {
  await fixture(page);
  await page.route("**/api/v1/customer/auth/me", route => route.fulfill({ json: { data: {
    authenticated: true, authentication_context: "customer", onboarding_status: "linked",
    capabilities: ["book_own_appointments"],
    session: { idle_expires_at: Date.now()/1000+1800, absolute_expires_at: Date.now()/1000+28800 },
  } } }));
  await page.route("**/api/v1/customer/booking-options", route => route.fulfill({ json: { data: {
    default_location_id: 2, rooms: [], combinations: [{
      location_id: 2, location_name: "Holland Landing", timezone: "America/Toronto",
      service_id: 4, service_name: "Massage", requires_room: 0, offers_mobile: 1, offers_clinic: 0,
      travel_buffer_minutes: 20, mobile_fee_cents: 1500, base_price_cents: 12000,
      practitioner_id: 7, practitioner_name: "Esther", duration_option_id: 8, duration_minutes: 60,
    }],
  } } }));
  await page.route("**/api/v1/customer/profile", route => route.fulfill({ json: { data: { address: {
    address_line1: "10 Client Street", address_line2: "", city: "Newmarket", province: "ON",
    postal_code: "L3Y 1A1", country: "Canada", instructions: "",
  } } } }));
  await page.route("**/api/v1/customer/address-coverage/approval", route => route.fulfill({ json: { data: { approved: false } } }));
  await page.route("**/api/v1/customer/address-coverage/validate", route => route.fulfill({ json: { data: {
    destination: route.request().postDataJSON().destination, covered: true, radius_km: 25, token: "coverage-proof",
  } } }));
  let availabilityChecks = 0;
  await page.route("**/api/v1/customer/availability?**", route => {
    availabilityChecks++;
    return route.fulfill({ json: { data: { availability: [{
      duration_option_id: 8, starts_at: "2099-10-01T14:00:00-04:00", ends_at: "2099-10-01T15:00:00-04:00", available_room_ids: [],
    }] } } });
  });
  await page.goto("http://localhost:5184/client/book?delivery_mode=mobile&location_id=2&service_id=4&practitioner_id=7&duration_option_id=8&starts_at=2099-10-01T14%3A00%3A00-04%3A00");
  await expect(page.getByRole("button", { name: "Sign in or create client account" })).toBeVisible();
  await expect(page.getByText("We saved your selected service and time for this sign-in. We will check availability again before you confirm.")).toBeVisible();
  expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem("wellness.customer.booking-intent.v1") ?? "null"))).toMatchObject({
    delivery_mode: "mobile", location_id: "2", service_id: "4", practitioner_id: "7", duration_option_id: "8",
  });
  await page.unroute("**/src/customer/auth.ts");
  await fixture(page, true);
  await page.goto("http://localhost:5184/client");
  await expect(page.getByText(/Your selected Massage appointment.*still available/)).toBeVisible();
  await expect(page.getByText("Confirm the visit address to continue with this time.", { exact: false })).toBeVisible();
  expect(availabilityChecks).toBe(1);
  await page.getByRole("button", { name: "Validate address and coverage" }).click();
  await expect(page.getByRole("button", { name: "Review appointment" })).toBeEnabled();
  await expect(page.getByRole("button", { name: /Oct.*1.*2099/i })).toHaveAttribute("class", /MuiButton-contained/);
  await expect(page.getByLabel("Appointment date")).toHaveValue("2099-10-01");
  expect(availabilityChecks).toBe(1);
});

test("stale public time returns to the selected date without silently choosing another slot", async ({ page }) => {
  await fixture(page, true);
  await page.route("**/api/v1/customer/auth/me", route => route.fulfill({ json: { data: {
    authenticated: true, authentication_context: "customer", onboarding_status: "linked",
    capabilities: ["book_own_appointments"],
    session: { idle_expires_at: Date.now()/1000+1800, absolute_expires_at: Date.now()/1000+28800 },
  } } }));
  await page.route("**/api/v1/customer/booking-options", route => route.fulfill({ json: { data: {
    default_location_id: 2, rooms: [], combinations: [{
      location_id: 2, location_name: "Holland Landing", timezone: "America/Toronto",
      service_id: 4, service_name: "Massage", requires_room: 0, offers_mobile: 0, offers_clinic: 1,
      travel_buffer_minutes: 0, mobile_fee_cents: 0, base_price_cents: 12000,
      practitioner_id: 7, practitioner_name: "Esther", duration_option_id: 8, duration_minutes: 60,
    }],
  } } }));
  await page.route("**/api/v1/customer/profile", route => route.fulfill({ json: { data: { address: null } } }));
  await page.route("**/api/v1/customer/appointments?**", route => route.fulfill({ json: { data: { items: [] } } }));
  await page.route("**/api/v1/customer/availability?**", route => route.fulfill({ json: { data: { availability: [{
    duration_option_id: 8, starts_at: "2099-10-01T15:00:00-04:00", ends_at: "2099-10-01T16:00:00-04:00", available_room_ids: [],
  }] } } }));
  await page.goto("http://localhost:5184/client/book?delivery_mode=clinic&location_id=2&service_id=4&practitioner_id=7&duration_option_id=8&starts_at=2099-10-01T14%3A00%3A00-04%3A00");
  await expect(page.getByText("The time you selected before sign-in is no longer available. Please choose another time.")).toBeVisible();
  await expect(page.getByLabel("Appointment date")).toHaveValue("2099-10-01");
  await expect(page.getByRole("button", { name: "Review appointment" })).toBeDisabled();
  await expect(page.getByRole("button", { name: /Oct.*1.*2099/i })).toBeVisible();
  await page.getByRole("button", { name: "Back" }).click();
  await expect(page.getByRole("combobox", { name: /^Service\b/ })).toBeVisible();
  await page.getByRole("button", { name: "Cancel" }).click();
  expect(await page.evaluate(() => sessionStorage.getItem("wellness.customer.booking-intent.v1"))).toBeNull();
  await expect(page).toHaveURL("http://localhost:5184/client/book");
  await page.reload();
  await expect(page.getByRole("heading", { name: "New appointment" })).toHaveCount(0);
});

test("linked client reschedules and cancels only their own upcoming appointment", async ({ page }) => {
  await fixture(page, true);
  await page.route("**/api/v1/customer/auth/me", route => route.fulfill({ json: { data: {
    authenticated: true,
    authentication_context: "customer",
    onboarding_status: "linked",
    capabilities: ["own_appointments", "book_own_appointments"],
    session: { idle_expires_at: Date.now() / 1000 + 1800, absolute_expires_at: Date.now() / 1000 + 28800 },
  } } }));
  const appointment = {
    id: 41, starts_at: "2099-09-20 14:00:00", ends_at: "2099-09-20 15:00:00",
    status: "confirmed", version: 3, room_id: null, room_name: null, duration_option_id: 8,
    service: "Therapeutic Massage", practitioner: "Esther Vanderpoel", location: "Holland Landing Clinic",
    timezone: "America/Toronto", delivery_mode: "mobile",
  };
  const changes: Record<string, unknown>[] = [];
  await page.route("**/api/v1/customer/appointments?**", route => route.fulfill({ json: { data: { items: [appointment], limit: 100 } } }));
  await page.route("**/api/v1/customer/appointments/41/availability?**", route => route.fulfill({ json: { data: { availability: [
    { duration_option_id: 8, starts_at: "2099-09-20T10:00:00-04:00", ends_at: "2099-09-20T11:00:00-04:00", available_room_ids: [] },
    { duration_option_id: 8, starts_at: "2099-09-21T11:00:00-04:00", ends_at: "2099-09-21T12:00:00-04:00", available_room_ids: [] },
  ] } } }));
  await page.route("**/api/v1/customer/appointments/41/cancellation-preview", route => route.fulfill({ json: { data: {
    window_minutes: 1440, deadline: "2099-09-19T14:00:00+00:00", inside_fee_window: true,
    fee_type: "percentage", appointment_total_cents: 12000, fee_cents: 6000, currency: "CAD",
  } } }));
  await page.route("**/api/v1/customer/appointments/41", route => {
    changes.push(route.request().postDataJSON());
    return route.fulfill({ json: { data: { ...appointment, version: 4 } } });
  });

  await page.goto("http://localhost:5184/client");
  await page.getByRole("button", { name: "View or change" }).click();
  await page.getByRole("button", { name: "Reschedule" }).click();
  await page.getByLabel("Appointment date").fill("2099-09-21");
  await page.getByRole("button", { name: "Find times" }).click();
  await expect(page.getByRole("button", { name: /Sep.*21.*2099/i })).toHaveCount(1);
  await page.getByRole("button", { name: /Sep.*21.*2099/i }).click();
  await page.getByRole("button", { name: "Confirm reschedule" }).click();
  await expect(page.getByText("Appointment #41 was rescheduled.")).toBeVisible();
  expect(changes[0]).toMatchObject({ action: "reschedule", version: 3, starts_at: "2099-09-21T11:00:00-04:00" });
  expect(changes[0]).not.toHaveProperty("client_id");

  await page.getByRole("button", { name: "View or change" }).click();
  await page.getByRole("button", { name: "Cancel appointment" }).click();
  await expect(page.getByText(/will apply a \$60.00 cancellation fee/)).toBeVisible();
  await page.getByRole("button", { name: "Confirm cancellation" }).click();
  await expect(page.getByText("Appointment #41 was canceled.")).toBeVisible();
  expect(changes[1]).toMatchObject({ action: "cancel", version: 3, expected_cancellation_fee_cents: 6000 });
  expect(changes[1]).not.toHaveProperty("client_id");
});
