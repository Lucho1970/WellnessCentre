import { expect, test } from "@playwright/test";
import { readdirSync, readFileSync } from "node:fs";
import en from "../src/i18n/en";
import fr from "../src/i18n/fr";

// Exercise release bundles under their configured origins without contacting the live site.
const productionHosts = process.env.BUILD_PRODUCTION_HOSTS === "1";
const publicOrigin = productionHosts
  ? "https://wellness.copihue.ca"
  : "http://localhost:5193";
const portalOrigin = productionHosts
  ? "https://portal.copihue.ca"
  : "http://localhost:5194";
test.beforeEach(async ({ page }) => {
  if (!productionHosts) return;
  for (const [origin, local] of [
    [publicOrigin, "http://localhost:5193"],
    [portalOrigin, "http://localhost:5194"],
  ]) {
    await page.route(`${origin}/**`, async (route) => {
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
  await expect(
    page.getByRole("heading", { name: "Contact Build Smoke Clinic" }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Contact Build Smoke Clinic" }),
  ).toBeVisible();
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}about`);
  await expect(
    page.getByRole("heading", { name: "About our centre", level: 1 }),
  ).toBeVisible();
  await page.reload();
  await expect(page).toHaveTitle("About us | Build Smoke Clinic");
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
  await page.getByRole("button", { name: "LanguageAndRegion" }).click();
  await expect(
    page.getByRole("dialog", { name: "LanguageAndRegion" }),
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
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}contact`);
  await page.getByRole("button", { name: "LanguageAndRegion" }).click();
  await page.getByRole("button", { name: /Français \(Canada\)/ }).click();
  await page.goto(`${publicOrigin}${info.config.metadata.publicBase}book`);
  await expect(
    page.getByText(
      "Vérifiez les renseignements saisis et corrigez les champs non valides. Référence : build-smoke-reference",
    ),
  ).toBeVisible();
});
