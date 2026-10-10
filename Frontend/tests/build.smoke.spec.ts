import { expect, test } from "@playwright/test";
import { readdirSync, readFileSync } from "node:fs";
import en from "../src/i18n/en";
import fr from "../src/i18n/fr";

// Exercise release bundles under their configured origins without contacting the live site.
const productionHosts = process.env.BUILD_PRODUCTION_HOSTS === "1";
const publicOrigin = productionHosts
  ? "https://livinlively.com"
  : "http://localhost:5193";
const portalOrigin = productionHosts
  ? "https://willowwellness.copihue.ca"
  : "http://localhost:5194";
test.beforeEach(async ({ page }) => {
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: 'https://livinlively.com/' } }));
  if (!productionHosts) return;
  for (const [origin, local] of [
    [publicOrigin, "http://localhost:5193"],
    [portalOrigin, "http://localhost:5194"],
  ]) {
    await page.route(`${origin}/**`, async (route) => {
      if (new URL(route.request().url()).pathname.endsWith('/api/runtime-config.php')) {
        await route.fulfill({ json: { publicWebsiteUrl: 'https://livinlively.com/' } });
        return;
      }
      const response = await route.fetch({
        url: route.request().url().replace(origin, local),
      });
      await route.fulfill({ response });
    });
  }
});

test("English and French resource catalogs contain the same keys", () => {
  expect(Object.keys(fr).sort()).toEqual(Object.keys(en).sort());
});

test('public website destination changes after refresh without rebuilding', async ({ page }, info) => {
  let destination = 'https://livinlively.com/';
  await page.route('**/api/runtime-config.php', route => route.fulfill({ json: { publicWebsiteUrl: destination } }));
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.goto(`${portalOrigin}${info.config.metadata.portalBase}`);
  await expect(page.getByRole('link', { name: 'Public website', exact: true })).toHaveAttribute('href', 'https://livinlively.com/?lang=en');
  destination = 'https://example.test/clinic/';
  await page.reload();
  await expect(page.getByRole('link', { name: 'Public website', exact: true })).toHaveAttribute('href', 'https://example.test/clinic/?lang=en');
});

test('unavailable runtime settings stop startup instead of using a stale domain', async ({ page }, info) => {
  await page.route('**/api/runtime-config.php', route => route.fulfill({ status: 503, json: { error: 'Unavailable' } }));
  await page.goto(`${portalOrigin}${info.config.metadata.portalBase}`);
  await expect(page.locator('#root')).toContainText('Unable to start the application.');
  await expect(page.getByRole('link', { name: 'Public website', exact: true })).toHaveCount(0);
});

test("neutral portal landing separates clinic browsing from application administration", async ({ page }) => {
  const files: Record<string, { contentType: string; path: string }> = {
    "/": { contentType: "text/html", path: "../hosting/netfirms/portal-landing/index.html" },
    "/styles.css": { contentType: "text/css", path: "../hosting/netfirms/portal-landing/styles.css" },
    "/landing.js": { contentType: "application/javascript", path: "../hosting/netfirms/portal-landing/landing.js" },
  };
  await page.route("https://portal.copihue.ca/**", route => {
    const file = files[new URL(route.request().url()).pathname];
    return file ? route.fulfill({ status: 200, contentType: file.contentType, body: readFileSync(file.path) }) : route.fulfill({ status: 404 });
  });
  await page.goto("https://portal.copihue.ca/");
  await expect(page.getByRole("heading", { name: "Your care starts with the right portal." })).toBeVisible();
  await expect(page.getByRole("link", { name: /Open Willow Wellness Virtual Clinic/ })).toHaveAttribute("href", "https://willowwellness.copihue.ca/");
  await expect(page.getByRole("link", { name: "Administration sign-in" })).toHaveAttribute("href", "/admin/");
  await page.getByRole("button", { name: "FR" }).click();
  await expect(page.getByRole("heading", { name: "Vos soins commencent par le bon portail." })).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
});

test('built central administration starts independently without clinic configuration or records', async ({ page }) => {
  let clinicRequests = 0;
  await page.route('https://portal.copihue.ca/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/api/')) { clinicRequests++; return route.fulfill({ status: 404 }); }
    if (path === '/admin/') return route.fulfill({ contentType: 'text/html', body: readFileSync('dist/application-admin/central.html') });
    if (path.startsWith('/admin/assets/')) return route.fulfill({ contentType: path.endsWith('.js') ? 'application/javascript' : 'text/css', body: readFileSync(`dist/application-admin/assets/${path.split('/').pop()}`) });
    return route.fulfill({ status: 404 });
  });
  await page.goto('https://portal.copihue.ca/admin/');
  await expect(page.getByRole('heading', { name: 'Application administration' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign in with Microsoft' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Create clinic' })).toHaveCount(0);
  expect(clinicRequests).toBe(0);
});

test("practitioner hover card shows only explicitly published contact actions", async ({ page }, info) => {
  await page.route('**/api/v1/site-config', route => route.fulfill({ json: { data: { name: 'Build Smoke Clinic' } } }));
  await page.route('**/api/v1/public/services', route => route.fulfill({ json: { data: [{ id: 3, slug: 'massage', name: 'Massage', name_fr: null, category_id: 1, category: 'Massage', category_fr: null, category_description: null, category_description_fr: null, public_summary: 'A treatment', public_summary_fr: null, description: null, description_fr: null, durations: [{ minutes: 60, price_cents: 12000 }], offers_clinic: true, offers_mobile: false }] } }));
  await page.route('**/api/v1/public/practitioners', route => route.fulfill({ json: { data: [{ slug: 'grace', public_name: 'Grace Preferred', booking_name: 'Grace', public_title: 'Massage therapist', public_title_fr: null, summary: 'A short biography.', summary_fr: null, discipline: 'Massage', credentials: 'RMT', has_image: false, image_version: null, public_website_url: 'https://www.facebook.com/grace?ref=profile', public_contact_email: 'grace@example.test', public_contact_phone: '+12892975234', public_contact_sms: true, booking_practitioner_id: 1, services: [{ slug: 'massage' }] }] } }));
  await page.goto(`${portalOrigin}${info.config.metadata.portalBase}`);
  await page.getByRole('link', { name: 'Grace Preferred' }).hover();
  await expect(page.getByRole('link', { name: 'Website / social page', exact: true })).toHaveAttribute('href', 'https://www.facebook.com/grace?ref=profile');
  await expect(page.getByRole('link', { name: 'Website / social page', exact: true })).toHaveAttribute('target', '_blank');
  await expect(page.getByRole('link', { name: 'Call', exact: true })).toHaveAttribute('href', 'tel:+12892975234');
  await expect(page.getByRole('link', { name: 'Text', exact: true })).toHaveAttribute('href', 'sms:+12892975234');
  await expect(page.getByRole('link', { name: 'Email', exact: true })).toHaveAttribute('href', 'mailto:grace@example.test');
  await expect(page.getByText('A short biography.')).toBeVisible();
});

test("public artifacts contain no staff authentication or private feature modules", () => {
  const files = readdirSync("dist/public/assets").filter((file) =>
    file.endsWith(".js"),
  );
  const code = files
    .map((file) => readFileSync(`dist/public/assets/${file}`, "utf8"))
    .join("\n");
  expect(code).not.toContain("login.microsoftonline.com");
  expect(code).not.toContain("StaffAuthProvider");
  expect(code).not.toContain("/auth/me");
  expect(code).not.toContain("ciamlogin.com");
  expect(readFileSync("dist/public/.htaccess", "utf8")).toContain(
    "^api(?:/|$)",
  );
  expect(readFileSync("dist/portal/.htaccess", "utf8")).toContain("index.html");
});

test("built public and portal deep links load independently, including base paths", async ({
  page,
}, info) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({
      json: {
        data: {
          name: "Build Smoke Clinic",
          email: "clinic@example.test",
          phone: null,
          legal_name: null,
        },
      },
    }),
  );
  await page.route("**/api/v1/team", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}contact`);
  await expect(page).toHaveURL(new RegExp('/#contact$'));
  await expect(
    page.getByRole("heading", { name: "Contact Build Smoke Clinic" }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Contact Build Smoke Clinic" }),
  ).toBeVisible();
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}about`);
  await expect(
    page.getByRole("heading", { name: "About our centre", level: 2 }),
  ).toBeVisible();
  await page.reload();
  await expect(page).toHaveTitle("Build Smoke Clinic");
  const portalRequests: string[] = [];
  page.on("request", (request) => {
    if (request.url().includes("/api/v1/")) portalRequests.push(request.url());
  });
  await page.goto(
    `${portalOrigin}${info.config.metadata.portalBase}admin/clients`,
  );
  await expect(
    page.getByRole("heading", { name: "Staff portal", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("button", { name: "Sign in with Microsoft", exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Add client" })).toHaveCount(0);
  expect(portalRequests.length).toBeGreaterThan(0);
  expect(
    portalRequests.every((url) => url.startsWith(`${portalOrigin}/api/v1/`)),
  ).toBe(true);
  expect(errors).toEqual([]);
});

test("built client callback loads independently of staff login", async ({
  page,
}, info) => {
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({ json: { data: { name: "Build Smoke Clinic" } } }),
  );
  await page.goto(
    `${portalOrigin}${info.config.metadata.portalBase}client/auth/callback`,
  );
  await expect(
    page.getByRole("heading", { name: "Client portal", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Staff portal", exact: true }),
  ).toHaveCount(0);
});

test("built service booking URL opens the public time picker without authentication", async ({ page }, info) => {
  const authRequests: string[] = [];
  page.on('request', request => { if (/\/api\/v1\/(auth\/me|customer\/auth\/me)/.test(request.url())) authRequests.push(request.url()); });
  await page.route('**/api/v1/site-config', route => route.fulfill({ json: { data: { name: 'Build Smoke Clinic' } } }));
  await page.route('**/api/v1/locations', route => route.fulfill({ json: { data: [{ id: 1, name: 'Clinic', timezone: 'America/Toronto' }] } }));
  await page.route('**/api/v1/services', route => route.fulfill({ json: { data: [{ id: 2, slug: 'massage', name: 'Massage', description: null, price_cents: 10000, durations: [{ id: 4, minutes: 60, price_cents: 10000 }] }] } }));
  await page.route('**/api/v1/public/practitioners', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/practitioners?**', route => route.fulfill({ json: { data: [] } }));
  await page.goto(`${portalOrigin}${info.config.metadata.portalBase}services/massage/book?duration=60`);
  await expect(page.getByRole('heading', { name: 'Find a time that fits your life.' })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Appointment length' })).toContainText('60 min');
  expect(authRequests).toEqual([]);
});

test("language selection switches to French and persists across reloads", async ({
  page,
}, info) => {
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({
      json: {
        data: {
          name: "Build Smoke Clinic",
          email: "clinic@example.test",
          phone: null,
          legal_name: null,
        },
      },
    }),
  );
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}contact`);
  await page.getByRole("button", { name: "Language and region" }).click();
  await expect(
    page.getByRole("dialog", { name: "Language and region" }),
  ).toBeVisible();
  await page.getByRole("button", { name: /Français \(Canada\)/ }).click();
  await expect(
    page.getByRole("heading", { name: "Communiquer avec Build Smoke Clinic" }),
  ).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
  await expect(
    page.getByRole("link", { name: "Connexion", exact: true }),
  ).toHaveAttribute("href", /\/client\?lang=fr$/);
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Communiquer avec Build Smoke Clinic" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Langue et région" }),
  ).toBeVisible();
  await page.goto(
    `${portalOrigin}${info.config.metadata.portalBase}client?lang=fr`,
  );
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("lang", "fr");
});

test("API failures are presented in the selected language", async ({
  page,
}, info) => {
  await page.route("**/api/v1/site-config", (route) =>
    route.fulfill({
      json: {
        data: {
          name: "Build Smoke Clinic",
          email: null,
          phone: null,
          legal_name: null,
        },
      },
    }),
  );
  await page.route("**/api/v1/locations", (route) =>
    route.fulfill({
      status: 422,
      json: {
        error: {
          code: "validation_error",
          message: "Untranslated server detail.",
          correlation_id: "build-smoke-reference",
        },
      },
    }),
  );
  await page.route("**/api/v1/services", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.route("**/api/v1/public/practitioners", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}contact`);
  await page.getByRole("button", { name: "Language and region" }).click();
  await page.getByRole("button", { name: /Français \(Canada\)/ }).click();
  await page.goto(`${portalOrigin}${info.config.metadata.portalBase}availability?lang=fr`);
  await expect(
    page.getByText(
      "Vérifiez les renseignements saisis et corrigez les champs non valides. Référence : build-smoke-reference",
    ),
  ).toBeVisible();
});

for (const surface of ['public', 'portal'] as const) {
  test(`${surface} practitioner profile displays and hides the external link`, async ({ page }, info) => {
    let website: string | null = 'https://www.facebook.com/example?ref=profile';
    await page.route('**/api/v1/**', route => {
      const path = new URL(route.request().url()).pathname;
      const data = path.endsWith('/public/practitioners/grace') ? {
        slug: 'grace', public_name: 'Grace Preferred', public_title: 'Therapist',
        public_title_fr: null, summary: null, summary_fr: null, has_image: false,
        services: [], public_website_url: website,
      } : path.endsWith('/site-config') ? { name: 'Test clinic' } : [];
      return route.fulfill({ json: { data } });
    });
    const origin = surface === 'public' ? publicOrigin : portalOrigin;
    await page.goto(`${origin}${info.config.metadata[`${surface}Base`]}practitioners/grace`);
    const link = page.getByRole('link', { name: 'Website / social page', exact: true });
    await expect(link).toHaveAttribute('href', website!);
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    website = null;
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Grace Preferred' })).toBeVisible();
    await expect(link).toHaveCount(0);
    website = 'javascript:alert(1)';
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Grace Preferred' })).toBeVisible();
    await expect(link).toHaveCount(0);
  });
}
