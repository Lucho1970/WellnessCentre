import { expect, test, type Page } from "@playwright/test";
import { selectAccount } from "../src/auth/accountSelection";
import {
  freshCustomerLoginParameters,
  shouldClearCustomerAccountHint,
} from "../src/customer/providerRouting";
import type { AccountInfo } from "@azure/msal-browser";

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
  const globeBox=await page.getByRole("button", { name: "Language and region" }).boundingBox(),accountBox=await page.getByRole("button", { name: "Open client account menu" }).boundingBox();
  expect(globeBox&&accountBox?accountBox.x-(globeBox.x+globeBox.width):Number.POSITIVE_INFINITY).toBeLessThanOrEqual(8);
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
  expect(url.searchParams.get("prompt")).toBe("select_account");
  expect(url.searchParams.get("max_age")).toBe("0");
  expect(url.searchParams.has("domain_hint")).toBe(false);
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
  let checks = 0, appointmentReads = 0;
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
  await page.route("**/api/v1/customer/appointments", (route) => {
    appointmentReads++;
    expect(route.request().url()).not.toContain("client_id");
    return route.fulfill({
      json: {
        data: {
          items: [
            { id: 41, starts_at: "2099-09-20 14:00:00", ends_at: "2099-09-20 15:30:00", status: "confirmed", service: "Therapeutic Massage", practitioner: "Esther Vanderpoel", location: "Holland Landing Clinic", timezone: "America/Toronto", delivery_mode: "mobile" },
            { id: 12, starts_at: "2020-01-10 16:00:00", ends_at: "2020-01-10 17:00:00", status: "completed", service: "Massage", practitioner: "Esther Vanderpoel", location: "Holland Landing Clinic", timezone: "America/Toronto", delivery_mode: "clinic" },
          ],
        },
      },
    });
  });
  await page.goto("http://localhost:5184/client");
  await expect(page.getByText("Therapeutic Massage", { exact: true })).toBeVisible();
  await expect(page.getByText(/On-Site \(client location\).*Appointment #41/)).toBeVisible();
  await expect(page.getByText("Massage", { exact: true })).toHaveCount(0);
  await page.getByLabel("Show").click();
  await page.getByRole("option", { name: "Past", exact: true }).click();
  await expect(page.getByText("Massage", { exact: true })).toBeVisible();
  await expect(page.getByText(/In clinic · Holland Landing Clinic/)).toBeVisible();
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
  expect(appointmentReads).toBe(1);
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

test("linked client books only for the signed-in client through the customer API", async ({ page }) => {
  await fixture(page, true);
  await page.route("**/api/v1/customer/auth/me", route => route.fulfill({ json: { data: {
    authenticated: true, authentication_context: "customer", onboarding_status: "linked",
    capabilities: ["own_profile", "own_appointments", "book_own_appointments"],
    session: { idle_expires_at: Date.now()/1000+1800, absolute_expires_at: Date.now()/1000+28800 },
  } } }));
  let appointmentReads = 0, confirmations = 0;
  await page.route("**/api/v1/customer/appointments", route => {
    if (route.request().method() === "POST") {
      confirmations++;
      const body = route.request().postDataJSON();
      expect(body.client_id).toBeUndefined();
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
  await page.route("**/api/v1/customer/booking-options", route => route.fulfill({ json: { data: {
    default_location_id: 2, rooms: [], combinations: [{
      location_id: 2, location_name: "Holland Landing", timezone: "America/Toronto",
      service_id: 4, service_name: "Massage", requires_room: 0, offers_mobile: 1, offers_clinic: 0,
      travel_buffer_minutes: 20, mobile_fee_cents: 1500, base_price_cents: 12000,
      practitioner_id: 7, practitioner_name: "Esther Vanderpoel", duration_option_id: 8, duration_minutes: 60,
    }],
  } } }));
  await page.route("**/api/v1/customer/profile", route => route.fulfill({ json: { data: {
    given_name: "Test", family_name: "Client", email: "client@example.test", phone: "555-0100", preferred_contact: "email", revision: "r1",
    address: { address_line1: "10 Client Street", address_line2: "", city: "Newmarket", province: "ON", postal_code: "L3Y 1A1", country: "Canada", instructions: "" },
  } } }));
  await page.route("**/api/v1/customer/address-coverage/validate", route => route.fulfill({ json: { data: {
    destination: route.request().postDataJSON().destination, distance_km: 7.2, radius_km: 25, token: "coverage-proof",
  } } }));
  await page.route("**/api/v1/customer/availability?**", route => route.fulfill({ json: { data: { availability: [{
    duration_option_id: 8, starts_at: "2099-10-01T14:00:00-04:00", ends_at: "2099-10-01T15:00:00-04:00", available_room_ids: [],
  }] } } }));

  await page.goto("http://localhost:5184/client");
  await page.getByRole("button", { name: "Book appointment" }).click();
  await page.getByRole("combobox", { name: "Service", exact: true }).click();
  await page.getByRole("option", { name: "Massage", exact: true }).click();
  await page.getByRole("combobox", { name: "Practitioner", exact: true }).click();
  await page.getByRole("option", { name: "Esther Vanderpoel", exact: true }).click();
  await page.getByRole("combobox", { name: "Duration", exact: true }).click();
  await page.getByRole("option", { name: /60 minutes/ }).click();
  await page.getByRole("button", { name: "Validate address and coverage" }).click();
  await expect(page.getByText(/Address confirmed: 7.2 km/)).toBeVisible();
  await page.getByRole("button", { name: "Find a time" }).click();
  await page.getByLabel("Appointment date").fill("2099-10-01");
  await page.getByRole("button", { name: "Find times" }).click();
  await page.getByRole("button", { name: /Oct.*1.*2099/i }).click();
  await page.getByRole("button", { name: "Review appointment" }).click();
  await page.getByRole("button", { name: "Confirm appointment" }).click();
  await expect(page.getByText("Appointment #91 confirmed. Confirmation email is queued; delivery is not yet enabled.")).toBeVisible();
  expect(confirmations).toBe(1);
  expect(appointmentReads).toBeGreaterThanOrEqual(2);
});
