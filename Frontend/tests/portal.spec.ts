import { expect, test, type Page } from "@playwright/test";
import {
  pageAt,
  pagePath,
  pagesFor,
  workspacesFor,
} from "../src/portal/access";

const publicHost = "http://localhost:5183";
const portalHost = "http://localhost:5184";
const errors = new WeakMap<Page, string[]>();
test.beforeEach(({ page }) => {
  const list: string[] = [];
  errors.set(page, list);
  page.on("pageerror", (error) => list.push(error.message));
});
test.afterEach(({ page }) => {
  expect(errors.get(page)).toEqual([]);
});
async function fixtures(
  page: Page,
  roles?: string[],
  permissions: string[] = [],
) {
  // Test-only network substitution. No production flag or authentication bypass.
  if (roles)
    await page.route("**/src/auth/AuthProvider.tsx", (route) =>
      route.fulfill({
        contentType: "application/javascript",
        body: `
    const account={homeAccountId:'test-user',name:'Test Staff',username:'staff@example.test'};
    const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-only-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};
    export const StaffAuthProvider=({children})=>children;
    export const selectStaffAccount=()=>account;
    export const useStaffAuth=()=>auth;
  `,
      }),
    );
  await page.route("**/api/v1/**", (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace("/api/v1", "");
    let data: unknown = [];
    if (path === "/site-config")
      data = {
        name: "Test Wellness",
        legal_name: null,
        email: "clinic@example.test",
        phone: "905-555-0100",
      };
    if (path === "/auth/me") data = { roles: roles ?? [], permissions };
    if (path === "/profile/avatar") data = { image_base64: null };
    if (path === "/dashboard") {
      const workspace = url.searchParams.get("workspace") === "practitioner" ? "practitioner" : "admin";
      const definitions = workspace === "admin" ? [
        { id: "appointments_today", renderer: "metric", title: { en: "Today's appointments", fr: "Rendez-vous d’aujourd’hui" }, description: { en: "All active appointments scheduled today.", fr: "Tous les rendez-vous actifs prévus aujourd’hui." }, icon: "calendar-check", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
        { id: "awaiting_confirmation", renderer: "metric", title: { en: "Awaiting confirmation", fr: "En attente de confirmation" }, description: { en: "Requested appointments that still need confirmation.", fr: "Rendez-vous demandés qui doivent encore être confirmés." }, icon: "clock", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
        { id: "onsite_today", renderer: "metric", title: { en: "Today's On-Site visits", fr: "Visites sur place aujourd’hui" }, description: { en: "Appointments taking place at a client location.", fr: "Rendez-vous ayant lieu chez un client." }, icon: "map-pin", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
      ] : [
        { id: "my_appointments_today", renderer: "metric", title: { en: "My appointments today", fr: "Mes rendez-vous aujourd’hui" }, description: { en: "Your active appointments scheduled today.", fr: "Vos rendez-vous actifs prévus aujourd’hui." }, icon: "calendar-check", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
        { id: "my_next_appointment", renderer: "next_appointment", title: { en: "My next appointment", fr: "Mon prochain rendez-vous" }, description: { en: "Your next active appointment.", fr: "Votre prochain rendez-vous actif." }, icon: "clock", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
        { id: "my_onsite_today", renderer: "metric", title: { en: "My On-Site visits today", fr: "Mes visites sur place aujourd’hui" }, description: { en: "Your visits taking place at a client location.", fr: "Vos visites ayant lieu chez un client." }, icon: "map-pin", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"] },
      ];
      data = {
        workspace,
        timezone: "America/Toronto",
        as_of: "2026-09-21T14:00:00Z",
        definitions,
        values: {
          appointments_today: 4,
          awaiting_confirmation: 1,
          onsite_today: 2,
          my_appointments_today: 3,
          my_next_appointment: null,
          my_onsite_today: 1,
        },
      };
    }
    if (path === "/dashboard/preferences") {
      const practitioner = url.searchParams.get("workspace") === "practitioner";
      data = {
        version: 1,
        workspace: practitioner ? "practitioner" : "admin",
        widgets: practitioner ? [
          { id: "my_appointments_today", enabled: true, order: 0, size: "small" },
          { id: "my_next_appointment", enabled: true, order: 1, size: "medium" },
          { id: "my_onsite_today", enabled: true, order: 2, size: "small" },
        ] : [
          { id: "appointments_today", enabled: true, order: 0, size: "small" },
          { id: "awaiting_confirmation", enabled: true, order: 1, size: "small" },
          { id: "onsite_today", enabled: true, order: 2, size: "small" },
        ],
      };
    }
    if (path === "/clients") data = { items: [], has_more: false };
    if (path === "/locations")
      data = [{ id: 1, name: "Holland Landing", timezone: "America/Toronto" }];
    if (path === "/services")
      data = [
        {
          id: 2,
          slug: "massage",
          name: "Massage",
          description: "Therapeutic care",
          price_cents: 10000,
          durations: [{ id: 4, minutes: 60, price_cents: 10000 }],
        },
      ];
    if (path === "/practitioners")
      data = [
        {
          id: 3,
          display_name: "Test Practitioner",
          discipline: "Massage",
          credentials: "RMT",
        },
      ];
    if (path === "/public/practitioners")
      data = [
        {
          slug: "test-practitioner",
          public_name: "Test Practitioner",
          booking_name: "Test",
          booking_practitioner_id: 3,
          services: [],
        },
      ];
    if (path === "/availability")
      data = {
        timezone: "America/Toronto",
        availability: [
          {
            duration_option_id: 4,
            starts_at: "2026-10-01T14:00:00Z",
            ends_at: "2026-10-01T15:00:00Z",
          },
        ],
      };
    return route.fulfill({ json: { data } });
  });
}

test("staff client invitation approval requires review code and verification checkbox", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  let approved = false,
    posts = 0;
  const client = {
    id: 7,
    display_name: "Existing Client",
    given_name: "Existing",
    family_name: "Client",
    email: "existing@example.test",
    phone: "555-0100",
    status: "active",
    preferred_contact: "email",
    revision: "rev",
  };
  await page.route("**/api/v1/clients**", (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = { items: [client], has_more: false };
    if (path.endsWith("/7")) data = client;
    if (path.includes("/invitations")) {
      if (route.request().method() === "POST") {
        const body = route.request().postDataJSON();
        expect(body.action).toBe("approve");
        expect(body.identity_verified).toBe(true);
        expect(body.review_code).toBe("ABCDEF123456");
        approved = true;
        posts++;
      }
      data = {
        linked: approved,
        items: [
          {
            id: 9,
            expires_at: "2026-10-01 12:00:00",
            consumed_at: "2026-09-17 12:00:00",
            revoked_at: null,
            claim_status: approved ? "approved" : "pending",
            claimant_name: "Unverified Claimant",
          },
        ],
      };
    }
    return route.fulfill({ json: { data } });
  });
  await page.goto(`${portalHost}/admin/clients`);
  await page
    .getByRole("button", { name: /Existing Client.*existing@example\.test/ })
    .click();
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  const approve = page.getByRole("button", { name: "Approve client link" });
  await expect(approve).toBeDisabled();
  await page
    .getByRole("textbox", { name: "Review code from the verified client" })
    .fill("ABCDEF123456");
  await expect(approve).toBeDisabled();
  await page
    .getByRole("checkbox", { name: /I independently verified/ })
    .check();
  await expect(approve).toBeEnabled();
  page.on("dialog", (dialog) => dialog.accept());
  await approve.click();
  await expect(
    page.getByText(
      "This client record has an approved customer identity link.",
    ),
  ).toBeVisible();
  expect(posts).toBe(1);
});

test("services use a list-first command bar and selected services can be assigned", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  const services = [
    {
      id: "1",
      name: "Existing massage",
      price_cents: 10000,
      durations: [60],
      duration_options: [{ minutes: 60, price_cents: 10000 }],
      active: 1,
      requires_room: 1,
    },
    {
      id: "2",
      name: "Second massage",
      price_cents: 13000,
      durations: [90],
      duration_options: [{ minutes: 90, price_cents: 13000 }],
      active: 1,
      requires_room: 0,
    },
  ];
  let createdService: Record<string, any> | undefined;
  let savedAssignment: unknown;
  await page.route("**/api/v1/admin/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = [];
    if (path.endsWith("/services")) {
      if (route.request().method() === "POST") {
        createdService = route.request().postDataJSON();
        services.push({ ...services[0], ...createdService, id: "3" });
        data = { id: "3", status: "active" };
      } else {
        data = services;
      }
    } else if (path.endsWith("/service-assignments"))
      data = {
        practitioners: [
          {
            practitioner_id: "3",
            service_id: "2",
            active: "1",
            offers_mobile: "1",
            offers_clinic: "0",
            mobile_radius_km: "25",
            travel_buffer_minutes: "30",
            mobile_fee_cents: "1500",
          },
        ],
        locations: [{ service_id: "2", location_id: "1", active: "1" }],
      };
    else if (path.endsWith("/locations"))
      data = [{ id: "1", name: "Test location" }];
    else if (path.endsWith("/practitioners"))
      data = [{ practitioner_id: "3", display_name: "Test Therapist" }];
    else if (path.endsWith("/assignments"))
      savedAssignment = route.request().postDataJSON();
    else data = {};
    await route.fulfill({ json: { data } });
  });
  await page.goto(`${portalHost}/admin/services`);
  await expect(
    page.getByText("Existing massage", { exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Details" })).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Edit", exact: true }),
  ).toBeDisabled();
  await page.getByRole("button", { name: /Second massage.*130\.00/ }).click();
  await page.getByRole("button", { name: "Details" }).click();
  await expect(
    page.getByText("Service details", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("90 min — $130.00", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close panel" }).click();
  await page.getByRole("button", { name: "Assignments", exact: true }).click();
  await expect(
    page.getByText("Current assignments", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Test location", { exact: true })).toBeVisible();
  await expect(page.getByText("Test Therapist", { exact: true })).toBeVisible();
  await expect(
    page.getByText(
      /25 km radius.*30 min travel each way.*\$15\.00 On-Site fee/,
    ),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close", exact: true }).click();
  await page.getByRole("button", { name: "New service", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Service name", exact: true })
    .fill("New massage");
  await page.getByRole("spinbutton", { name: "Price 1 (CAD)" }).fill("120");
  await page.getByRole("button", { name: "Add duration and price" }).click();
  await page
    .getByRole("spinbutton", { name: "Duration 2 (minutes)" })
    .fill("90");
  await page.getByRole("spinbutton", { name: "Price 2 (CAD)" }).fill("165");
  await page.getByRole("button", { name: "Add service", exact: true }).click();
  await expect(page.getByText("New massage was created.")).toBeVisible();
  expect(createdService?.duration_options).toEqual([
    { minutes: 60, price_cents: 12000 },
    { minutes: 90, price_cents: 16500 },
  ]);
  await page.getByRole("button", { name: /New massage.*120\.00/ }).click();
  await page.getByRole("button", { name: "Assignments", exact: true }).click();
  await expect(
    page.getByText(
      "This service has no current assignments and cannot be booked.",
    ),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Add assignment", exact: true })
    .click();
  await expect(page.getByRole("combobox", { name: /^Service / })).toHaveText(
    "New massage",
  );
  await expect(
    page.getByRole("combobox", { name: /^Service / }),
  ).toBeDisabled();
  await page
    .getByRole("checkbox", { name: "Test Therapist", exact: true })
    .check();
  await page
    .getByRole("checkbox", { name: "Test location", exact: true })
    .check();
  await page.getByRole("button", { name: "Save assignments" }).click();
  await expect(page.getByText("Service assignments saved.")).toBeVisible();
  expect(savedAssignment).toMatchObject({
    location_ids: [1],
    practitioners: [{ practitioner_id: 3, service_id: 3 }],
  });
});

test("locations and rooms use list-first actions with ID-safe create and edit panels", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  const locations = [
    {
      id: "11",
      name: "Holland Landing",
      timezone: "America/Toronto",
      address_line1: "1 Main Street",
      address_line2: null,
      city: "Holland Landing",
      province: "Ontario",
      postal_code: "L9N 1A1",
      phone: "905-555-0100",
      is_bookable: "1",
    },
    {
      id: "12",
      name: "Mobile Service Area",
      timezone: "America/Toronto",
      address_line1: null,
      address_line2: null,
      city: null,
      province: "Ontario",
      postal_code: null,
      phone: null,
      is_bookable: "0",
    },
  ];
  const rooms = [
    {
      id: "21",
      location_id: "11",
      location_name: "Holland Landing",
      name: "Room Birch",
      room_type: "Treatment room",
      equipment_notes: "Massage table",
      turnover_minutes: "15",
      is_bookable: "1",
    },
  ];
  let updatedLocation: Record<string, unknown> | undefined;
  let createdRoom: Record<string, unknown> | undefined;
  await page.route("**/api/v1/admin/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = {};
    if (
      path.endsWith("/locations/12") &&
      route.request().method() === "PATCH"
    ) {
      updatedLocation = route.request().postDataJSON();
      locations[1] = {
        ...locations[1],
        ...updatedLocation,
      } as (typeof locations)[number];
      data = { id: "12" };
    } else if (path.endsWith("/locations")) data = locations;
    else if (path.endsWith("/rooms") && route.request().method() === "POST") {
      createdRoom = route.request().postDataJSON();
      rooms.push({
        id: "22",
        location_name: "Holland Landing",
        room_type: null,
        equipment_notes: null,
        ...createdRoom,
      } as (typeof rooms)[number]);
      data = { id: "22" };
    } else if (path.endsWith("/rooms")) data = rooms;
    else if (path.endsWith("/room-capabilities"))
      data = { capabilities: [], rooms: [], services: [] };
    else if (path.endsWith("/services")) data = [];
    await route.fulfill({ json: { data } });
  });

  await page.goto(`${portalHost}/admin/locations`);
  await expect(page.getByRole("button", { name: "Details" })).toBeDisabled();
  await page
    .getByRole("button", { name: /Mobile Service Area.*Not bookable/ })
    .click();
  await page.getByRole("button", { name: "Details" }).click();
  await expect(
    page.getByText("Location details", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Ontario", { exact: true }).last()).toBeVisible();
  await page.getByRole("button", { name: "Close panel" }).click();
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Phone", exact: true })
    .fill("905-555-0199");
  await page.getByRole("button", { name: "Save changes", exact: true }).click();
  await expect(
    page.getByText("Mobile Service Area was updated."),
  ).toBeVisible();
  expect(updatedLocation?.phone).toBe("905-555-0199");

  await page.goto(`${portalHost}/admin/rooms`);
  await expect(
    page.getByRole("button", { name: "Edit", exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByRole("heading", { name: "Room capabilities" }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Capabilities", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Room capabilities" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close", exact: true }).click();
  await page
    .getByRole("button", { name: /Room Birch.*15 min turnover/ })
    .click();
  await page.getByRole("button", { name: "Details" }).click();
  await expect(page.getByText("Room details", { exact: true })).toBeVisible();
  await expect(page.getByText("Massage table", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Close panel" }).click();
  await page.getByRole("button", { name: "New room", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Room name", exact: true })
    .fill("Room Cedar");
  await page
    .getByRole("spinbutton", { name: "Turnover time (minutes)" })
    .fill("20");
  await page.getByRole("button", { name: "Add room", exact: true }).click();
  await expect(page.getByText("Room Cedar was created.")).toBeVisible();
  expect(createdRoom).toMatchObject({
    location_id: 11,
    name: "Room Cedar",
    turnover_minutes: 20,
  });
});

test("practitioners use list-first details, edit, and identity-linking panels", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  const practitioners = [
    {
      practitioner_id: "8",
      user_id: "18",
      given_name: "Esther",
      family_name: "Vanderpoel",
      display_name: "Esther Vanderpoel",
      preferred_name: "Esther",
      email: "esther@example.test",
      status: "active",
      discipline: "Registered Massage Therapy",
      credentials: "RMT",
      booking_mode: "practitioner_managed",
      active: "1",
      location_id: "1",
      locations: "Holland Landing",
    },
  ];
  let updated: Record<string, unknown> | undefined;
  let created: Record<string, unknown> | undefined;
  await page.route("**/api/v1/admin/practitioners**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = practitioners;
    if (
      path.endsWith("/practitioners/8") &&
      route.request().method() === "PATCH"
    ) {
      updated = route.request().postDataJSON();
      practitioners[0] = {
        ...practitioners[0],
        ...updated,
      } as (typeof practitioners)[number];
      data = { id: "8" };
    } else if (
      path.endsWith("/practitioners/onboard") &&
      route.request().method() === "POST"
    ) {
      created = route.request().postDataJSON();
      practitioners.push({
        practitioner_id: "9",
        user_id: "19",
        status: "active",
        active: "1",
        locations: "Holland Landing",
        ...created,
      } as (typeof practitioners)[number]);
      data = { id: "9", user_id: "19", status: "active" };
    }
    await route.fulfill({ json: { data } });
  });

  await page.goto(`${portalHost}/admin/practitioners`);
  await expect(page.getByRole("button", { name: "Details" })).toBeDisabled();
  await page
    .getByRole("button", { name: /Esther Vanderpoel.*RMT.*Holland Landing/ })
    .click();
  await page.getByRole("button", { name: "Details" }).click();
  await expect(
    page.getByText("Practitioner details", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("esther@example.test", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Preferred public name", { exact: true })).toBeVisible();
  await expect(page.getByText("Esther", { exact: true }).last()).toBeVisible();
  await page.getByRole("button", { name: "Close panel" }).click();
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Credentials", exact: true })
    .fill("RMT, BSc");
  await page.getByRole("button", { name: "Save changes", exact: true }).click();
  await expect(page.getByText("Esther Vanderpoel was updated.")).toBeVisible();
  expect(updated).toMatchObject({
    practitioner_id: 8,
    location_id: 1,
    credentials: "RMT, BSc",
  });

  await page
    .getByRole("button", { name: "New practitioner", exact: true })
    .click();
  await page
    .getByRole("textbox", { name: "First name", exact: true })
    .fill("New");
  await page
    .getByRole("textbox", { name: "Last name", exact: true })
    .fill("Therapist");
  await page
    .getByRole("textbox", { name: "Internal display name", exact: true })
    .fill("New Therapist");
  await page
    .getByRole("textbox", { name: "Microsoft sign-in email" })
    .fill("new@example.test");
  await page
    .getByRole("textbox", { name: "Entra Object ID" })
    .fill("11111111-1111-4111-8111-111111111111");
  await page
    .getByRole("textbox", { name: "Entra Tenant ID" })
    .fill("22222222-2222-4222-8222-222222222222");
  await page
    .getByRole("button", { name: "Add practitioner", exact: true })
    .click();
  await expect(
    page.getByText("New Therapist was added as a practitioner."),
  ).toBeVisible();
  expect(created).toMatchObject({
    location_id: 1,
    given_name: "New",
    family_name: "Therapist",
    display_name: "New Therapist",
    object_id: "11111111-1111-4111-8111-111111111111",
  });
});

test("staff client creation saves a reusable service address with manual fallback", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  let saved: Record<string, any> | undefined;
  await page.route("**/api/v1/clients**", (route) => {
    if (route.request().method() === "POST") {
      saved = route.request().postDataJSON();
      return route.fulfill({ json: { data: { id: 8, ...saved } } });
    }
    return route.fulfill({ json: { data: { items: [], has_more: false } } });
  });
  await page.goto(`${portalHost}/admin/clients`);
  await page.getByRole("button", { name: "New client", exact: true }).click();
  await page.getByRole("textbox", { name: "First name" }).fill("Mobile");
  await page.getByRole("textbox", { name: "Last name" }).fill("Client");
  await page
    .getByRole("textbox", { name: "Email", exact: true })
    .fill("mobile@example.test");
  await page
    .getByRole("textbox", { name: "Street address" })
    .fill("123 Test Street");
  await page
    .getByRole("textbox", { name: "City", exact: true })
    .fill("Test City");
  await page.getByRole("textbox", { name: "Postal code" }).fill("A1A 1A1");
  await page.getByRole("button", { name: "Save client" }).click();
  await expect(
    page.getByText("Client created. The record is ready for booking."),
  ).toBeVisible();
  expect(saved).toMatchObject({
    given_name: "Mobile",
    family_name: "Client",
    address: {
      address_line1: "123 Test Street",
      city: "Test City",
      province: "Ontario",
      postal_code: "A1A 1A1",
      country: "Canada",
    },
  });
});

test("clients use list-first actions with details and edit panels", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  const client = {
    id: 8,
    display_name: "Avery Client",
    given_name: "Avery",
    family_name: "Client",
    email: "avery@example.test",
    phone: "905-555-0188",
    preferred_contact: "email",
    date_of_birth: "1991-04-12",
    emergency_contact_name: "Morgan Client",
    emergency_contact_phone: "905-555-0189",
    administrative_notes: "Prefers afternoon calls",
    status: "active",
    revision: "client-rev",
    address: {
      address_line1: "8 Test Lane",
      address_line2: "",
      city: "Test City",
      province: "Ontario",
      postal_code: "A1A 1A1",
      country: "Canada",
      instructions: "Side door",
    },
  };
  let updated: Record<string, unknown> | undefined;
  await page.route("**/api/v1/clients**", (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/8/invitations"))
      return route.fulfill({ json: { data: { items: [], linked: false } } });
    if (path.endsWith("/8") && route.request().method() === "PATCH") {
      updated = route.request().postDataJSON();
      return route.fulfill({ json: { data: { ...client, ...updated } } });
    }
    if (path.endsWith("/8")) return route.fulfill({ json: { data: client } });
    return route.fulfill({
      json: { data: { items: [client], has_more: false } },
    });
  });
  await page.goto(`${portalHost}/admin/clients`);
  await expect(
    page.getByRole("button", { name: "Details", exact: true }),
  ).toBeDisabled();
  await page
    .getByRole("button", { name: /Avery Client.*avery@example\.test/ })
    .click();
  await page.getByRole("button", { name: "Details", exact: true }).click();
  await expect(page.getByText("Client details", { exact: true })).toBeVisible();
  await expect(
    page.getByText("8 Test Lane, Test City, Ontario, A1A 1A1, Canada", {
      exact: true,
    }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Phone", exact: true })
    .fill("905-555-0190");
  await page.getByRole("button", { name: "Save client", exact: true }).click();
  await expect(page.getByText("Client details saved.")).toBeVisible();
  expect(updated).toMatchObject({
    phone: "905-555-0190",
    revision: "client-rev",
  });
});

test("possible duplicates require acknowledgement and Super Admin can merge with a preview", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  const survivor = {
    id: 2,
    display_name: "Same Client",
    given_name: "Same",
    family_name: "Client",
    email: "first@example.test",
    phone: "555-1000",
    status: "active",
    preferred_contact: "email",
    date_of_birth: "1990-01-01",
    revision: "survivor-rev",
    address: null,
  };
  const duplicate = {
    ...survivor,
    id: 3,
    email: "second@example.test",
    phone: "555-2000",
    revision: "duplicate-rev",
  };
  let createAttempts = 0,
    mergeBody: Record<string, unknown> | undefined,
    merged = false;
  await page.route("**/api/v1/clients**", (route) => {
    const url = new URL(route.request().url()),
      path = url.pathname,
      method = route.request().method();
    if (method === "POST" && path.endsWith("/clients")) {
      createAttempts++;
      const body = route.request().postDataJSON();
      if (!body.confirm_possible_duplicate)
        return route.fulfill({
          status: 409,
          json: {
            error: {
              code: "possible_duplicate",
              message:
                "A similar client record already exists. Review it before creating another client.",
              fields: { candidates: [survivor] },
            },
          },
        });
      return route.fulfill({ json: { data: { id: 4, ...body } } });
    }
    if (method === "GET" && path.endsWith("/merge-preview/3"))
      return route.fulfill({
        json: {
          data: {
            survivor,
            duplicate,
            relationship_counts: { appointments: 2, invoices: 1 },
            customer_links: [],
            blocked: false,
            blocked_reason: null,
          },
        },
      });
    if (method === "POST" && path.endsWith("/merge/3")) {
      mergeBody = route.request().postDataJSON();
      merged = true;
      return route.fulfill({
        json: { data: { client: survivor, merged_client_id: 3 } },
      });
    }
    return route.fulfill({
      json: {
        data: {
          items: merged ? [survivor] : [survivor, duplicate],
          has_more: false,
        },
      },
    });
  });
  await page.goto(`${portalHost}/admin/clients`);
  await page.getByRole("button", { name: "New client", exact: true }).click();
  await page.getByRole("textbox", { name: "First name" }).fill("Same");
  await page.getByRole("textbox", { name: "Last name" }).fill("Client");
  await page
    .getByRole("textbox", { name: "Email", exact: true })
    .fill("third@example.test");
  await page.getByRole("button", { name: "Save client" }).click();
  await expect(page.getByText("Possible duplicate client")).toBeVisible();
  await page.getByRole("button", { name: "Create anyway" }).click();
  await expect(
    page.getByText("Client created. The record is ready for booking."),
  ).toBeVisible();
  expect(createAttempts).toBe(2);
  await page.getByRole("button", { name: /second@example\.test/ }).click();
  await page.getByRole("button", { name: "Merge", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Find the surviving client" })
    .fill("first");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await page.getByRole("button", { name: "Keep this client" }).click();
  await expect(page.getByText("appointments: 2")).toBeVisible();
  await page
    .getByRole("textbox", { name: "Reason for merge" })
    .fill("Duplicate created after a retry");
  await page
    .getByRole("textbox", { name: /Type MERGE 3 INTO 2/ })
    .fill("MERGE 3 INTO 2");
  await page.getByRole("button", { name: "Merge client records" }).click();
  await expect(
    page.getByText(
      "Client records merged. Both email addresses were preserved.",
    ),
  ).toBeVisible();
  await expect(
    page.getByText("second@example.test", { exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: /first@example\.test/ }),
  ).toBeVisible();
  expect(mergeBody).toMatchObject({
    survivor_revision: "survivor-rev",
    duplicate_revision: "duplicate-rev",
    reason: "Duplicate created after a retry",
    confirmation: "MERGE 3 INTO 2",
  });
});

test("mobile-only booking captures destination and price without requesting a room", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  let booking: Record<string, any> | undefined;
  let availabilityMode = "";
  let addressFetches = 0;
  await page.route("**/api/v1/booking-options", (route) =>
    route.fulfill({
      json: {
        data: {
          rooms: [],
          default_location_id: 1,
          combinations: [
            {
              location_id: 1,
              location_name: "Mobile service area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 1,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 30,
              mobile_fee_cents: 2500,
              base_price_cents: 12000,
              practitioner_id: 3,
              practitioner_name: "Therapist",
              duration_option_id: 4,
              duration_minutes: 60,
            },
            {
              location_id: 2,
              location_name: "Alternate area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 30,
              mobile_fee_cents: 0,
              base_price_cents: 10000,
              practitioner_id: 3,
              practitioner_name: "Test Practitioner",
              duration_option_id: 4,
              duration_minutes: 60,
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/booking-clients?**", (route) =>
    route.fulfill({
      json: {
        data: {
          items: [
            {
              id: 5,
              display_name: "Test Client",
              email: "test@example.test",
              phone: "905-555-0100",
            },
          ],
          has_more: false,
        },
      },
    }),
  );
  await page.route("**/api/v1/booking-clients/5/address", (route) => {
    addressFetches += 1;
    return route.fulfill({
      json: {
        data: {
          address: {
            address_line1: "123 Test Street",
            address_line2: "",
            city: "Test City",
            province: "Ontario",
            postal_code: "A1A 1A1",
            country: "Canada",
            instructions: "Side entrance",
          },
        },
      },
    });
  });
  await page.route("**/api/v1/availability?**", (route) => {
    availabilityMode =
      new URL(route.request().url()).searchParams.get("delivery_mode") ?? "";
    return route.fulfill({
      json: {
        data: {
          availability: [
            {
              duration_option_id: 4,
              starts_at: "2030-10-01T10:00:00-04:00",
              ends_at: "2030-10-01T11:00:00-04:00",
              available_room_ids: [],
            },
          ],
        },
      },
    });
  });
  await page.route("**/api/v1/address-coverage/validate", (route) => {
    const body = route.request().postDataJSON();
    expect(body).toMatchObject({
      location_id: 1,
      service_id: 2,
      practitioner_id: 3,
      destination: { address_line1: "123 Test Street" },
    });
    return route.fulfill({
      json: {
        data: {
          destination: {
            ...body.destination,
            address_line1: "123 Test Street",
          },
          distance_km: 8.4,
          radius_km: 25,
          token: "signed-address-proof",
          expires_at: "2030-10-01T13:00:00Z",
        },
      },
    });
  });
  await page.route("**/api/v1/appointments", (route) => {
    booking = route.request().postDataJSON();
    return route.fulfill({ json: { data: { id: 99 } } });
  });
  await page.goto(`${portalHost}/admin/appointments`);
  await page
    .getByRole("button", { name: "Book appointment", exact: true })
    .click();
  const select = async (label: RegExp, option: string) => {
    await page.getByRole("combobox", { name: label }).click();
    await page.getByRole("option", { name: option, exact: true }).click();
  };
  await page
    .getByRole("textbox", { name: "Find an active client" })
    .fill("Test");
  await page.getByRole("button", { name: "Select Test Client" }).click();
  await expect(page.getByText("Selected client")).toBeVisible();
  await expect(
    page.getByRole("textbox", { name: "Street address" }),
  ).toHaveValue("123 Test Street");
  await expect(page.getByText(/saved client address is loaded/i)).toBeVisible();
  expect(addressFetches).toBe(1);
  await expect(
    page.getByRole("combobox", { name: /Base location/ }),
  ).toContainText("Mobile service area");
  await expect(
    page.getByRole("combobox", { name: /Base location/ }),
  ).toBeEnabled();
  await select(/^Base location/, "Mobile service area");
  await select(/^Service/, "Massage");
  await select(/^Practitioner/, "Therapist");
  await select(/^Duration/, "60 minutes — $120.00");
  await expect(
    page.getByRole("button", { name: "Find a time", exact: true }),
  ).toBeDisabled();
  await page
    .getByRole("button", { name: "Validate address and coverage" })
    .click();
  await expect(
    page.getByText("Address confirmed: 8.4 km driving distance (25 km limit)."),
  ).toBeVisible();
  await page.getByRole("button", { name: "Find a time", exact: true }).click();
  await page.getByLabel("Appointment date").fill("2030-10-01");
  await page.getByRole("button", { name: "Find times", exact: true }).click();
  await page.getByRole("button", { name: /Oct 1, 2030/ }).click();
  await expect(
    page.getByRole("combobox", { name: /Available room/ }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Review appointment" }).click();
  await expect(page.getByText(/Subtotal:.*145/)).toBeVisible();
  await page
    .getByRole("button", { name: "Confirm appointment", exact: true })
    .click();
  await expect(page.getByText(/Appointment #99 confirmed/)).toBeVisible();
  expect(availabilityMode).toBe("mobile");
  expect(booking).toMatchObject({
    delivery_mode: "mobile",
    destination: { address_line1: "123 Test Street" },
    address_validation_token: "signed-address-proof",
    quoted_base_price_cents: 12000,
    quoted_mobile_fee_cents: 2500,
  });
  expect(booking).not.toHaveProperty("coverage_confirmed");
  expect(booking).not.toHaveProperty("room_id");
});

test("appointment client finder filters by exact birthdate and debounced contact details", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  await page.route("**/api/v1/booking-options", (route) =>
    route.fulfill({
      json: {
        data: {
          rooms: [],
          default_location_id: 1,
          combinations: [
            {
              location_id: 1,
              location_name: "Test area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 0,
              mobile_fee_cents: 0,
              base_price_cents: 10000,
              practitioner_id: 3,
              practitioner_name: "Therapist",
              duration_option_id: 4,
              duration_minutes: 60,
            },
          ],
        },
      },
    }),
  );
  const searches: { q: string; dateOfBirth: string }[] = [];
  await page.route("**/api/v1/booking-clients?**", (route) => {
    const params = new URL(route.request().url()).searchParams;
    searches.push({
      q: params.get("q") ?? "",
      dateOfBirth: params.get("date_of_birth") ?? "",
    });
    return route.fulfill({
      json: {
        data: {
          items: [
            {
              id: 5,
              display_name: "Test Client",
              email: "test@example.test",
              phone: "905-555-0100",
            },
          ],
          has_more: false,
        },
      },
    });
  });
  await page.goto(`${portalHost}/admin/appointments`);
  await page
    .getByRole("button", { name: "Book appointment", exact: true })
    .click();
  const search = page.getByRole("textbox", { name: "Find an active client" });
  const birthdate = page.getByLabel("Birthdate (optional)");
  await search.fill("T");
  await page.waitForTimeout(400);
  expect(searches).toEqual([]);
  await birthdate.fill("1980-05-06");
  await expect(
    page.getByRole("button", { name: "Select Test Client" }),
  ).toBeVisible();
  expect(searches).toEqual([{ q: "", dateOfBirth: "1980-05-06" }]);
  await search.fill("Te");
  await page.waitForTimeout(100);
  expect(searches).toHaveLength(1);
  await search.fill("Test");
  await expect(
    page.getByRole("button", { name: "Select Test Client" }),
  ).toBeVisible();
  expect(searches).toEqual([
    { q: "", dateOfBirth: "1980-05-06" },
    { q: "Test", dateOfBirth: "1980-05-06" },
  ]);
  await expect(page.getByText("test@example.test")).toBeVisible();
  await expect(page.getByText("905-555-0100")).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Client", exact: true }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Select Test Client" }).click();
  await expect(page.getByText("Selected client")).toBeVisible();
  await page.getByRole("button", { name: "Change client" }).click();
  await expect(search).toHaveValue("");
  await expect(birthdate).toHaveValue("");
});

test("role policies preserve current access without broadening permissions", () => {
  expect(workspacesFor(["client"])).toEqual([]);
  expect(workspacesFor(["reception"])).toEqual(["admin"]);
  expect(pagesFor(["accountant"], "admin")).toEqual(["dashboard", "profile"]);
  expect(pagesFor(["reception"], "admin")).toContain("clients");
  expect(pagesFor(["clinic_admin"], "admin")).not.toContain("staff");
  expect(pagesFor(["practitioner"], "admin")).toEqual([]);
  expect(pagesFor(["super_admin"], "admin")).toContain("business");
  expect(pagesFor(["practitioner"], "practitioner")).toEqual([
    "dashboard",
    "appointments",
    "profile",
  ]);
  expect(workspacesFor(["super_admin", "practitioner"])).toEqual([
    "admin",
    "practitioner",
  ]);
  expect(pageAt("/admin/unknown", "admin")).toBeUndefined();
  expect(pagePath("practitioner", "appointments")).toBe(
    "/practitioner/schedule",
  );
});

test("public home has client-first login and no workforce authentication or fake address", async ({
  page,
}) => {
  const requests: string[] = [];
  page.on("request", (request) => requests.push(request.url()));
  await fixtures(page);
  await page.goto(publicHost);
  await expect(
    page.getByRole("heading", { name: "Feel better, on your schedule." }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Sign In", exact: true }),
  ).toHaveAttribute("href", `${portalHost}/client?lang=en`);
  await expect(
    page.getByRole("button", { name: "Language and region" }),
  ).toBeVisible();
  const globeBox = await page
      .getByRole("button", { name: "Language and region" })
      .boundingBox(),
    signInBox = await page
      .getByRole("link", { name: "Sign In", exact: true })
      .boundingBox();
  expect(
    globeBox && signInBox
      ? signInBox.x - (globeBox.x + globeBox.width)
      : Number.POSITIVE_INFINITY,
  ).toBeLessThanOrEqual(8);
  await expect(
    page.getByRole("link", { name: "Staff Sign In", exact: true }),
  ).toHaveAttribute("href", `${portalHost}/staff/login?lang=en`);
  await expect(page.getByText("240 Queen Street")).toHaveCount(0);
  expect(
    requests.some(
      (url) =>
        url.includes("AuthProvider") ||
        url.includes("login.microsoftonline.com") ||
        url.includes("/auth/me"),
    ),
  ).toBe(false);
});

test("public Markdown pages follow the selected language and publish safe metadata", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto(`${publicHost}/about`);
  await expect(
    page.getByRole("heading", { name: "About our centre", level: 1 }),
  ).toBeVisible();
  await expect(page).toHaveTitle("About us | Test Wellness");
  await expect(page.locator('meta[name="description"]')).toHaveAttribute(
    "content",
    /approach to accessible wellness care/,
  );
  await expect(
    page.getByRole("link", { name: "New clients" }).first(),
  ).toHaveAttribute("href", "/new-clients");
  await page.getByRole("button", { name: "Language and region" }).click();
  await page.getByRole("button", { name: /Français \(Canada\)/ }).click();
  await expect(
    page.getByRole("heading", { name: "À propos de notre centre", level: 1 }),
  ).toBeVisible();
  await expect(page).toHaveTitle("À propos | Test Wellness");
  await page.getByRole("link", { name: "Nouveaux clients" }).first().click();
  await expect(
    page.getByRole("heading", {
      name: "Bienvenue aux nouveaux clients",
      level: 1,
    }),
  ).toBeVisible();
  await page.getByRole("link", { name: "FAQ", exact: true }).first().click();
  await expect(
    page.getByRole("heading", { name: "Foire aux questions", level: 1 }),
  ).toBeVisible();
});

test("contact page lists published practitioners first and carries a team booking choice", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/v1/team", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            slug: "test-practitioner",
            section: "practitioner",
            public_name: "Test Practitioner",
            booking_name: "Test",
            public_title: "Registered Massage Therapist",
            public_title_fr: "Massothérapeute agréée",
            summary: "Mobile therapeutic massage.",
            summary_fr: "Massothérapie thérapeutique mobile.",
            discipline: "Massage therapy",
            credentials: "RMT",
            display_order: 1,
            has_image: 0,
            image_version: null,
            practitioner_id: 3,
          },
          {
            slug: "test-admin",
            section: "administration",
            public_name: "Test Administrator",
            booking_name: null,
            public_title: "Clinic Administrator",
            public_title_fr: "Administration de la clinique",
            summary: "Supports clinic operations.",
            summary_fr: "Soutient les activités de la clinique.",
            discipline: null,
            credentials: null,
            display_order: 1,
            has_image: 0,
            image_version: null,
            practitioner_id: null,
          },
        ],
      },
    }),
  );
  await page.goto(`${publicHost}/contact`);
  const practitionerHeading = page.getByRole("heading", {
    name: "Practitioners",
    level: 3,
  });
  const administrationHeading = page.getByRole("heading", {
    name: "Administration",
    level: 3,
  });
  await expect(practitionerHeading).toBeVisible();
  await expect(administrationHeading).toBeVisible();
  const practitionerBox = await practitionerHeading.boundingBox(),
    administrationBox = await administrationHeading.boundingBox();
  expect(
    practitionerBox && administrationBox
      ? practitionerBox.y
      : Number.POSITIVE_INFINITY,
  ).toBeLessThan(administrationBox?.y ?? 0);
  await expect(page.getByText("Mobile therapeutic massage.")).toBeVisible();
  await expect(page.getByText("RMT · Massage therapy")).toBeVisible();
  await expect(page.getByText("Supports clinic operations.")).toBeVisible();
  await expect(
    page.getByRole("dialog", { name: "Profile for Test Practitioner" }),
  ).toHaveCount(0);
  await page.getByRole("link", { name: "Book with Test Practitioner" }).click();
  await expect(page).toHaveURL(`${publicHost}/book?practitioner_id=3`);
  await expect(
    page.getByRole("combobox", { name: /^Practitioner\b/ }),
  ).toContainText("Test Practitioner");
});

test("published service catalogue filters categories and carries service and practitioner into booking", async ({
  page,
}) => {
  await fixtures(page);
  const massage = {
    slug: "massage-therapy",
    name: "Massage Therapy",
    name_fr: "Massothérapie",
    category: "Massage",
    public_summary: "Treatment tailored to your goals.",
    public_summary_fr: "Un traitement adapté à vos objectifs.",
    description: "A detailed treatment description.",
    description_fr: "Une description détaillée du traitement.",
    preparation_instructions: "Wear comfortable clothing.",
    preparation_instructions_fr: "Portez des vêtements confortables.",
    offers_clinic: false,
    offers_mobile: true,
    durations: [{ minutes: 60, price_cents: 10000 }],
  };
  await page.route("**/api/v1/public/services", (route) =>
    route.fulfill({
      json: {
        data: [
          massage,
          {
            ...massage,
            slug: "nutrition",
            name: "Nutrition",
            category: "Nutrition",
          },
        ],
      },
    }),
  );
  await page.route("**/api/v1/public/services/massage-therapy", (route) =>
    route.fulfill({
      json: {
        data: {
          ...massage,
          practitioners: [
            {
              slug: "test-practitioner",
              public_name: "Test Practitioner",
              booking_name: "Test",
              public_title: "Registered Massage Therapist",
              public_title_fr: "Massothérapeute agréée",
              summary: "Mobile therapeutic massage.",
              summary_fr: "Massothérapie thérapeutique mobile.",
              booking_practitioner_id: 3,
            },
          ],
          locations: [
            {
              name: "Holland Landing",
              city: "Holland Landing",
              province: "Ontario",
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/services**", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            id: 9,
            slug: "nutrition",
            name: "Nutrition",
            description: "Nutrition",
            price_cents: 8000,
            durations: [{ id: 8, minutes: 60, price_cents: 8000 }],
          },
          {
            id: 2,
            slug: "massage-therapy",
            name: "Massage Therapy",
            description: "Therapeutic care",
            price_cents: 10000,
            durations: [{ id: 4, minutes: 60, price_cents: 10000 }],
          },
        ],
      },
    }),
  );
  await page.goto(`${publicHost}/services`);
  await expect(
    page.getByRole("heading", { name: "Find the care that fits you." }),
  ).toBeVisible();
  await page.getByRole("combobox", { name: "Category" }).click();
  await page.getByRole("option", { name: "Massage", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Massage Therapy" }),
  ).toBeVisible();
  await expect(page.getByRole("heading", { name: "Nutrition" })).toHaveCount(0);
  await page.getByRole("link", { name: "View service" }).click();
  await expect(
    page.getByRole("heading", { name: "Massage Therapy", level: 1 }),
  ).toBeVisible();
  await expect(page.getByText("Mobile therapeutic massage.")).toBeVisible();
  await page.getByRole("link", { name: "Book with Test Practitioner" }).click();
  await expect(page).toHaveURL(
    `${publicHost}/book?service=massage-therapy&practitioner_id=3`,
  );
  await expect(
    page.getByRole("combobox").filter({ hasText: "Massage Therapy" }),
  ).toHaveCount(1);
  await expect(
    page.getByRole("combobox", { name: /^Practitioner\b/ }),
  ).toContainText("Test Practitioner");
});

test("staff dashboard shows live metrics and saves a personal layout", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  let saved: unknown = null;
  await page.route("**/api/v1/dashboard/preferences?**", async (route) => {
    if (route.request().method() === "PUT") {
      saved = route.request().postDataJSON();
      return route.fulfill({ json: { data: { version: 1, workspace: "admin", widgets: (saved as { widgets: unknown[] }).widgets } } });
    }
    return route.fulfill({ json: { data: { version: 1, workspace: "admin", widgets: [
      { id: "appointments_today", enabled: true, order: 0, size: "small" },
      { id: "awaiting_confirmation", enabled: true, order: 1, size: "small" },
      { id: "onsite_today", enabled: true, order: 2, size: "small" },
    ] } } });
  });
  await page.goto(`${portalHost}/admin`);
  await expect(page.getByText("Today's appointments")).toBeVisible();
  await expect(page.getByText("4", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Customize dashboard" }).click();
  await page.getByRole("button", { name: "Hide", exact: true }).last().click();
  await page.getByRole("button", { name: "Save layout" }).click();
  await expect.poll(() => saved).not.toBeNull();
  expect((saved as { widgets: Array<{ id: string; enabled: boolean }> }).widgets.find(item => item.id === "onsite_today")?.enabled).toBe(false);
  await expect(page.getByText("Today's On-Site visits")).toHaveCount(0);
});

test("super admin uploads a versioned widget and can restore a prior version", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  const definition = {
    id: "appointments_today", schemaVersion: 1, workspaces: ["admin"], renderer: "metric",
    dataProjection: "appointment_count", parameters: { date: "today" }, requiredCapability: "appointments.view.clinic",
    title: { en: "Today's bookings", fr: "Réservations d’aujourd’hui" },
    description: { en: "Active bookings today.", fr: "Réservations actives aujourd’hui." },
    icon: "calendar-check", destination: { page: "appointments" }, sizes: ["small", "medium", "wide"], defaultSize: "small", defaultEnabled: true, defaultOrder: 10,
  };
  let published = false, restored = 0;
  await page.route("**/api/v1/admin/dashboard-widgets", async route => {
    if (route.request().method() === "POST") {
      const body = route.request().postDataJSON();
      if (!body.confirm_replace) return route.fulfill({ status: 409, json: { error: { code: "widget_exists", message: "A widget with this ID already exists." } } });
      published = true; return route.fulfill({ json: { data: { id: definition.id, version: 2, replaced: true } } });
    }
    return route.fulfill({ json: { data: [{ id: definition.id, source: "built_in", enabled: true, active_version: published ? 2 : 1, has_override: true, definition }] } });
  });
  await page.route("**/api/v1/admin/dashboard-widgets/appointments_today/versions", route => route.fulfill({ json: { data: { built_in: true, versions: [{ version_number: 1, definition_hash: "abc", created_at: "2026-09-21T12:00:00Z", created_by: "Test Admin", active: false, definition }] } } }));
  await page.route("**/api/v1/admin/dashboard-widgets/appointments_today/versions/1/restore", route => { restored = 1; return route.fulfill({ json: { data: { id: definition.id, active_version: 1 } } }); });
  await page.goto(`${portalHost}/admin/dashboard-widgets`);
  await expect(page.getByRole("heading", { name: "Today's bookings" })).toBeVisible();
  await page.locator('input[type="file"]').setInputFiles({ name: "widget.json", mimeType: "application/json", buffer: Buffer.from(JSON.stringify(definition)) });
  await page.getByRole("button", { name: "Validate and publish" }).click();
  await expect(page.getByText(/already exists/)).toBeVisible();
  await page.getByRole("button", { name: "Publish new version" }).click();
  await expect.poll(() => published).toBe(true);
  await page.getByRole("button", { name: "Versions" }).click();
  await page.getByRole("button", { name: "Restore", exact: true }).last().click();
  await expect.poll(() => restored).toBe(1);
});

test("public practitioner directory filters services and links profiles to booking", async ({
  page,
}) => {
  await fixtures(page);
  const esther = {
    slug: "esther-vanderpoel",
    public_name: "Esther Vanderpoel",
    booking_name: "Esther",
    public_title: "Registered Massage Therapist",
    public_title_fr: "Massothérapeute agréée",
    summary: "Mobile therapeutic massage tailored to your goals.",
    summary_fr: "Massothérapie mobile adaptée à vos objectifs.",
    discipline: "Massage Therapy",
    credentials: "RMT",
    has_image: false,
    image_version: null,
    booking_practitioner_id: 3,
    services: [
      { slug: "massage-therapy", name: "Massage Therapy", name_fr: "Massothérapie", category: "Massage" },
    ],
  };
  const nutrition = {
    ...esther,
    slug: "nutrition-practitioner",
    public_name: "Nutrition Practitioner",
    booking_name: "Nutrition Practitioner",
    booking_practitioner_id: 4,
    services: [{ slug: "nutrition", name: "Nutrition", name_fr: "Nutrition", category: "Nutrition" }],
  };
  await page.route("**/api/v1/public/practitioners", route => route.fulfill({ json: { data: [esther, nutrition] } }));
  await page.route("**/api/v1/public/practitioners/esther-vanderpoel", route => route.fulfill({ json: { data: { ...esther, services: [{ ...esther.services[0], public_summary: "Treatment tailored to your goals.", public_summary_fr: "Un traitement adapté à vos objectifs.", description: "Massage treatment.", description_fr: "Traitement de massothérapie.", offers_clinic: false, offers_mobile: true, durations: [{ minutes: 60, price_cents: 12000 }] }] } } }));
  await page.goto(`${publicHost}/practitioners`);
  await expect(page.getByRole("heading", { name: "Meet your care team." })).toBeVisible();
  await page.getByRole("combobox", { name: "Service" }).click();
  await page.getByRole("option", { name: "Massage Therapy" }).click();
  await expect(page.getByRole("heading", { name: "Esther Vanderpoel" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Nutrition Practitioner" })).toHaveCount(0);
  await page.getByRole("link", { name: "View profile" }).click();
  await expect(page).toHaveURL(`${publicHost}/practitioners/esther-vanderpoel`);
  await expect(page.getByRole("heading", { name: "Services offered" })).toBeVisible();
  await expect(page.getByText("60 min — $120.00")).toBeVisible();
  await expect(page.getByRole("link", { name: "Book with Esther" })).toHaveAttribute("href", "/book?practitioner_id=3");
  await expect(page.getByRole("link", { name: "Book", exact: true })).toHaveAttribute("href", "/book?service=massage-therapy&practitioner_id=3");
});

test("public booking can start with a practitioner and filters the service list", async ({ page }) => {
  await fixtures(page);
  const queries: string[] = [];
  await page.route("**/api/v1/services**", route => {
    const url = new URL(route.request().url());
    queries.push(url.search);
    const filtered = url.searchParams.get("practitioner_id") === "3";
    return route.fulfill({ json: { data: filtered ? [{ id: 2, slug: "massage", name: "Massage", description: "Therapeutic care", price_cents: 10000, durations: [{ id: 4, minutes: 60, price_cents: 10000 }] }] : [{ id: 9, slug: "nutrition", name: "Nutrition", description: "Nutrition", price_cents: 8000, durations: [{ id: 8, minutes: 60, price_cents: 8000 }] }] } });
  });
  await page.goto(`${publicHost}/book`);
  await page.getByRole("combobox", { name: "Start with a practitioner (optional)" }).click();
  await page.getByRole("option", { name: "Test" }).click();
  await expect.poll(() => queries.some(query => query.includes("practitioner_id=3"))).toBe(true);
  await expect(page.getByRole("combobox", { name: /^Service\b/ })).toContainText("Massage");
  await expect(page.getByRole("combobox", { name: /^Practitioner\b/ })).toContainText("Test Practitioner");
});

test("super admin deliberately publishes a bilingual public team profile", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  let saved: Record<string, unknown> | null = null;
  await page.route("**/api/v1/admin/team-profiles", (route) =>
    route.fulfill({
      json: {
        data: [
          {
            user_id: 7,
            given_name: "Esther",
            family_name: "Vanderpoel",
            display_name: "Esther Vanderpoel",
            status: "active",
            practitioner_id: 3,
            slug: null,
            section: null,
            public_name: null,
            booking_name: null,
            public_title: null,
            public_title_fr: null,
            summary: null,
            summary_fr: null,
            display_order: null,
            published: null,
            show_booking_action: null,
            has_image: 1,
          },
        ],
      },
    }),
  );
  await page.route("**/api/v1/admin/team-profiles/7", async (route) => {
    saved = route.request().postDataJSON();
    await route.fulfill({ json: { data: { user_id: 7, published: true } } });
  });
  await page.goto(`${portalHost}/admin/team`);
  await expect(
    page.getByRole("heading", { name: "Public team" }),
  ).toBeVisible();
  await page.getByLabel("Title (English)").fill("Registered Massage Therapist");
  await page.getByLabel("Title (French)").fill("Massothérapeute agréée");
  await page.getByLabel("Show Book a session action").check();
  await page.getByLabel("Publish on the Contact page").check();
  await page.getByRole("button", { name: "Save team profile" }).click();
  await expect
    .poll(() => saved)
    .toMatchObject({
      slug: "esther-vanderpoel",
      section: "practitioner",
      public_name: "Esther Vanderpoel",
      booking_name: "Esther",
      published: true,
      show_booking_action: true,
    });
});

test("public booking hands off preferences without reserving or creating an appointment", async ({
  page,
}) => {
  const writes: string[] = [];
  const availabilityQueries: URL[] = [];
  page.on("request", (request) => {
    if (request.method() !== "GET") writes.push(request.url());
  });
  await fixtures(page);
  await page.route("**/api/v1/availability?**", (route) => {
    availabilityQueries.push(new URL(route.request().url()));
    const availability = Array.from({ length: 25 }, (_, index) => {
      const starts = new Date(Date.UTC(2030, 9, 1, 13, index * 15));
      return {
        duration_option_id: 4,
        starts_at: starts.toISOString(),
        ends_at: new Date(starts.getTime() + 60 * 60000).toISOString(),
      };
    });
    return route.fulfill({
      json: { data: { timezone: "America/Toronto", availability } },
    });
  });
  await page.goto(`${publicHost}/book`);
  await page.getByLabel("Appointment date").fill("2030-10-01");
  await expect
    .poll(() => availabilityQueries.at(-1)?.searchParams.get("date_from"))
    .toBe("2030-10-01");
  expect(availabilityQueries.at(-1)?.searchParams.get("date_to")).toBe(
    "2030-10-01",
  );
  await expect(
    page.getByText("60 min — $100.00", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /60 min.*\$100\.00/ }),
  ).toHaveCount(25);
  await page.getByRole("button", { name: /9:00.*60 min/ }).click();
  await page
    .getByRole("link", { name: "View client booking information" })
    .click();
  await expect(page).toHaveURL(
    /localhost:5184\/client\/book\?delivery_mode=mobile&location_id=1/,
  );
  await expect(
    page.getByText("No appointment has been requested or reserved.", {
      exact: false,
    }),
  ).toBeVisible();
  expect(
    await page.evaluate(() =>
      JSON.parse(
        sessionStorage.getItem("wellness.customer.booking-intent.v1") ?? "null",
      ),
    ),
  ).toMatchObject({
    delivery_mode: "mobile",
    location_id: "1",
    service_id: "2",
    practitioner_id: "3",
    duration_option_id: "4",
  });
  expect(writes).toEqual([]);
});

test("public infrastructure errors have a readable retry state", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/v1/services", (route) =>
    route.fulfill({ status: 500, body: "", contentType: "text/html" }),
  );
  await page.goto(`${publicHost}/book`);
  await expect(
    page.getByRole("alert").filter({
      hasText: "The service is temporarily unavailable. Please try again.",
    }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Retry" })).toBeVisible();
  await expect(page.getByText("Unexpected end of JSON")).toHaveCount(0);
});

test("anonymous portal deep link is gated behind staff sign-in", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto(`${portalHost}/admin/clients`);
  await expect(
    page.getByRole("heading", { name: "Staff portal", exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "New client" })).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Feel better, on your schedule." }),
  ).toHaveCount(0);
});

test("warns before unsaved form work is lost through navigation or browser unload", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  await page.route("**/api/v1/admin/catalogue-settings", (route) =>
    route.fulfill({
      json: {
        data: {
          categories: [],
          taxes: [],
          settings: {
            default_lead_time_minutes: 60,
            default_booking_horizon_days: 90,
            default_cancellation_window_minutes: 1440,
          },
        },
      },
    }),
  );
  await page.goto(`${portalHost}/admin/settings`);
  const operatingName = page.getByRole("textbox", { name: "Operating name" });
  await expect(operatingName).toHaveValue("Test Wellness");
  await operatingName.fill("Unsaved Wellness Name");

  expect(
    await page.evaluate(() => {
      const event = new Event("beforeunload", { cancelable: true });
      return !window.dispatchEvent(event);
    }),
  ).toBe(true);

  await page.getByRole("link", { name: /Dashboard Today at a glance/ }).click();
  await expect(
    page.getByRole("heading", { name: "Leave this page?" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Stay" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/settings`);
  await expect(operatingName).toHaveValue("Unsaved Wellness Name");

  await page.getByRole("button", { name: "Save settings" }).click();
  await expect(page.getByText("Business settings saved.")).toBeVisible();
  expect(
    await page.evaluate(() => {
      const event = new Event("beforeunload", { cancelable: true });
      return !window.dispatchEvent(event);
    }),
  ).toBe(false);
  await page.getByRole("link", { name: /Dashboard Today at a glance/ }).click();
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await expect(
    page.getByRole("heading", { name: "Leave this page?" }),
  ).toHaveCount(0);

  await page
    .getByRole("link", {
      name: /Business settings Clinic identity and defaults/,
    })
    .click();
  await page
    .getByRole("textbox", { name: "Business phone" })
    .fill("905-555-0199");
  await page.getByRole("link", { name: /Dashboard Today at a glance/ }).click();
  await page.getByRole("button", { name: "Leave without saving" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin`);
});

test("super admin configures a separate business logo and favicon", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  let logoVersion: string | null = null, faviconVersion: string | null = null;
  const uploads: Record<string, Record<string, unknown>> = {};
  await page.route("**/api/v1/admin/catalogue-settings", route => route.fulfill({ json: { data: { categories: [], taxes: [], settings: { default_lead_time_minutes: 60, default_booking_horizon_days: 90, default_cancellation_window_minutes: 1440 } } } }));
  await page.route("**/api/v1/site-config", route => route.fulfill({ json: { data: { name: "Test Wellness", legal_name: null, email: "clinic@example.test", phone: "905-555-0100", logo_version: logoVersion, favicon_version: faviconVersion } } }));
  await page.route("**/api/v1/admin/clinic/branding/*", route => {
    const type = new URL(route.request().url()).pathname.split("/").at(-1)!;
    if (route.request().method() === "PUT") {
      uploads[type] = route.request().postDataJSON();
      if (type === "logo") logoVersion = "logo-hash"; else faviconVersion = "favicon-hash";
      return route.fulfill({ json: { data: { asset_type: type, updated: true } } });
    }
    if (type === "logo") logoVersion = null; else faviconVersion = null;
    return route.fulfill({ json: { data: { asset_type: type, deleted: true } } });
  });
  const pixel = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", "base64");
  await page.goto(`${portalHost}/admin/settings`);
  const choose = page.getByRole("button", { name: "Choose image" });
  await choose.nth(0).locator("input").setInputFiles({ name: "logo.png", mimeType: "image/png", buffer: pixel });
  await expect(page.getByText("Business logo updated.")).toBeVisible();
  await page.getByRole("button", { name: "Choose image" }).locator("input").setInputFiles({ name: "favicon.png", mimeType: "image/png", buffer: pixel });
  await expect(page.getByText("Favicon updated.")).toBeVisible();
  expect(uploads.logo).toMatchObject({ mime_type: "image/webp" });
  expect(uploads.favicon).toMatchObject({ mime_type: "image/png" });
  expect(String(uploads.logo.image_base64).length).toBeGreaterThan(20);
  expect(String(uploads.favicon.image_base64).length).toBeGreaterThan(20);
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute("href", /brand\/favicon\?v=favicon-hash/);
  await expect(page.getByRole("img", { name: "Current business logo" })).toBeVisible();
});

test("reception routes support refresh, back, profile menu and restricted deep links", async ({
  page,
}) => {
  await fixtures(page, ["reception"]);
  await page.goto(`${portalHost}/admin/clients`);
  await expect(
    page.getByRole("heading", { name: "Clients", exact: true, level: 1 }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "New client" })).toBeVisible();
  await page.getByRole("link", { name: /Appointments Bookings/ }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/appointments`);
  await expect(
    page.getByRole("button", { name: "Book appointment", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Appointments", exact: true, level: 1 }),
  ).toBeVisible();
  await page.goBack();
  await expect(page).toHaveURL(`${portalHost}/admin/clients`);
  await page
    .getByRole("button", { name: "Open account menu for Test Staff" })
    .click();
  await page.getByRole("menuitem", { name: "My profile" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/profile`);
  await expect(page.getByText("Account profile")).toBeVisible();
  await page.goto(`${portalHost}/admin/users`);
  await expect(
    page.getByText("You do not have permission to access this page."),
  ).toBeVisible();
});

test("practitioner mobile navigation can book and change only the scoped schedule", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixtures(page, ["practitioner"]);
  const scopedRequests: string[] = [];
  const changes: Record<string, unknown>[] = [];
  const appointment = {
    id: 10,
    client_id: 5,
    practitioner_id: 3,
    service_id: 2,
    duration_option_id: 4,
    room_id: null,
    delivery_mode: "mobile",
    destination_snapshot: null,
    travel_buffer_minutes: 30,
    base_price_cents: 10000,
    mobile_fee_cents: 0,
    client_name: "Existing Client",
    service_name: "Massage",
    practitioner_name: "Test Practitioner",
    location_name: "Holland Landing",
    timezone: "America/Toronto",
    room_name: null,
    starts_at: "2030-10-01 14:00:00",
    ends_at: "2030-10-01 15:00:00",
    status: "confirmed",
    version: 2,
  };
  await page.route("**/api/v1/appointments?**", (route) => {
    scopedRequests.push(route.request().url());
    return route.fulfill({ json: { data: [appointment] } });
  });
  await page.route("**/api/v1/booking-options?**", (route) => {
    scopedRequests.push(route.request().url());
    return route.fulfill({
      json: {
        data: {
          rooms: [],
          default_location_id: 1,
          combinations: [
            {
              location_id: 1,
              location_name: "Mobile area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 30,
              mobile_fee_cents: 0,
              base_price_cents: 10000,
              practitioner_id: 3,
              practitioner_name: "Test Practitioner",
              duration_option_id: 4,
              duration_minutes: 60,
            },
          ],
        },
      },
    });
  });
  await page.route("**/api/v1/booking-clients?**", (route) => {
    scopedRequests.push(route.request().url());
    return route.fulfill({
      json: {
        data: {
          items: [
            {
              id: 6,
              display_name: "New Clinic Client",
              email: "new-client@example.test",
              phone: "905-555-0110",
            },
          ],
          has_more: false,
        },
      },
    });
  });
  await page.route("**/api/v1/appointments/10/availability?**", (route) =>
    route.fulfill({
      json: {
        data: {
          availability: [
            {
              duration_option_id: 4,
              starts_at: "2030-10-01T10:00:00-04:00",
              ends_at: "2030-10-01T11:00:00-04:00",
              available_room_ids: [],
            },
            {
              duration_option_id: 4,
              starts_at: "2030-10-01T11:00:00-04:00",
              ends_at: "2030-10-01T12:00:00-04:00",
              available_room_ids: [],
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/appointments/10", (route) => {
    changes.push(route.request().postDataJSON());
    return route.fulfill({ json: { data: { ...appointment, version: 3 } } });
  });
  await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.getByRole("button", { name: "Open portal menu" }).click();
  await page.getByRole("link", { name: /Appointments Bookings/ }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner/schedule`);
  await expect(
    page.getByRole("button", { name: "Book appointment", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Book appointment", exact: true })
    .click();
  await expect
    .poll(() =>
      scopedRequests.some((url) =>
        url.includes("/booking-options?scope=practitioner"),
      ),
    )
    .toBe(true);
  await expect(page.getByLabel("Practitioner")).toHaveValue(
    "Test Practitioner",
  );
  await expect(page.getByLabel("Practitioner")).not.toBeEditable();
  await expect(
    page.getByRole("combobox", { name: "Base location / service area" }),
  ).toContainText("Mobile area");
  await expect(
    page.getByRole("combobox", { name: "Base location / service area" }),
  ).toBeEnabled();
  await page
    .getByRole("textbox", { name: "Find an active client" })
    .fill("New Clinic");
  await expect(
    page.getByRole("button", { name: "Select New Clinic Client" }),
  ).toBeVisible();
  expect(
    scopedRequests.some(
      (url) =>
        url.includes("/booking-clients?") && url.includes("scope=practitioner"),
    ),
  ).toBe(true);
  await page.getByRole("button", { name: "Select New Clinic Client" }).click();
  await expect(page.getByText("Selected client")).toBeVisible();
  await expect(page.getByText("New Clinic Client")).toBeVisible();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await page.getByRole("button", { name: "Change appointment" }).click();
  await page.getByRole("button", { name: "Reschedule", exact: true }).click();
  await page.getByLabel("Appointment date").fill("2030-10-01");
  await page.getByRole("button", { name: "Find times", exact: true }).click();
  await expect(
    page.getByRole("button", { name: /Oct 1, 2030, 10:00/ }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: /Oct 1, 2030, 11:00/ }).click();
  await page.getByRole("button", { name: "Confirm reschedule" }).click();
  await expect(
    page.getByText("Appointment #10 was rescheduled."),
  ).toBeVisible();
  expect(changes[0]).toMatchObject({
    action: "reschedule",
    version: 2,
    starts_at: "2030-10-01T11:00:00-04:00",
  });
  await page.getByRole("button", { name: "Change appointment" }).click();
  await page.getByRole("button", { name: "Cancel appointment" }).click();
  await page.getByRole("button", { name: "Confirm cancellation" }).click();
  await expect(page.getByText("Appointment #10 was canceled.")).toBeVisible();
  expect(changes[1]).toMatchObject({ action: "cancel", version: 2 });
  expect(
    scopedRequests.some(
      (url) =>
        url.includes("/appointments?") && url.includes("scope=practitioner"),
    ),
  ).toBe(true);
  await page.goto(`${portalHost}/admin/clients`);
  await expect(
    page.getByText("You do not have permission to access this page."),
  ).toBeVisible();
});

test("delegated scheduling permission allows a practitioner to choose another practitioner", async ({
  page,
}) => {
  await fixtures(page, ["practitioner"], ["schedule_for_other_practitioners"]);
  await page.route("**/api/v1/appointments?**", (route) =>
    route.fulfill({ json: { data: [] } }),
  );
  await page.route("**/api/v1/booking-options?**", (route) =>
    route.fulfill({
      json: {
        data: {
          rooms: [],
          combinations: [
            {
              location_id: 1,
              location_name: "Mobile area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 30,
              mobile_fee_cents: 0,
              base_price_cents: 10000,
              practitioner_id: 3,
              practitioner_name: "Test Practitioner",
              duration_option_id: 4,
              duration_minutes: 60,
            },
            {
              location_id: 1,
              location_name: "Mobile area",
              timezone: "America/Toronto",
              service_id: 2,
              service_name: "Massage",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 30,
              mobile_fee_cents: 0,
              base_price_cents: 10000,
              practitioner_id: 9,
              practitioner_name: "Covering Practitioner",
              duration_option_id: 4,
              duration_minutes: 60,
            },
          ],
        },
      },
    }),
  );
  await page.goto(`${portalHost}/practitioner/schedule`);
  await page
    .getByRole("button", { name: "Book appointment", exact: true })
    .click();
  await page
    .getByRole("combobox", { name: "Base location / service area" })
    .click();
  await page.getByRole("option", { name: "Mobile area" }).click();
  await page.getByRole("combobox", { name: "Service", exact: true }).click();
  await page.getByRole("option", { name: "Massage" }).click();
  await expect(
    page.getByRole("combobox", { name: "Practitioner", exact: true }),
  ).toBeEditable();
  await page
    .getByRole("combobox", { name: "Practitioner", exact: true })
    .click();
  await expect(
    page.getByRole("option", { name: "Covering Practitioner" }),
  ).toBeVisible();
});

test("dual roles can switch eligible workspaces and retain that preference", async ({
  page,
}) => {
  await fixtures(page, ["super_admin", "practitioner"]);
  await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await page.getByRole("link", { name: "Practitioner", exact: true }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
});

test("legacy public staff bookmarks migrate to guarded portal routes", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  await page.goto(`${publicHost}/?portal=clients#portal`);
  await expect(page).toHaveURL(`${portalHost}/admin/clients`);
  await expect(
    page.getByRole("heading", { name: "Clients", exact: true, level: 1 }),
  ).toBeVisible();
});

test("failed authorization never renders protected screens and allows retry", async ({
  page,
}) => {
  await fixtures(page, ["super_admin"]);
  await page.route("**/api/v1/auth/me", (route) =>
    route.fulfill({
      status: 401,
      json: {
        error: { message: "Token expired", correlation_id: "test-reference" },
      },
    }),
  );
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole("alert")).toContainText(
    "Your sign-in is no longer valid. Please sign in again.",
  );
  await expect(page.getByRole("button", { name: "New client" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Retry" })).toBeVisible();
});

test("accountant has only currently released authorized screens", async ({
  page,
}) => {
  await fixtures(page, ["accountant"]);
  await page.goto(portalHost);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await expect(page.getByRole("link", { name: /Clients Contact/ })).toHaveCount(
    0,
  );
  await expect(
    page.getByRole("link", { name: /Appointments Bookings/ }),
  ).toHaveCount(0);
  await page.goto(`${portalHost}/admin/appointments`);
  await expect(
    page.getByText("You do not have permission to access this page."),
  ).toBeVisible();
});

test("unknown portal paths show a safe not-found screen", async ({ page }) => {
  await fixtures(page, ["reception"]);
  await page.goto(`${portalHost}/admin/not-a-page`);
  await expect(page.getByText("This portal page was not found.")).toBeVisible();
  await page.getByRole("link", { name: "Return to your workspace" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin`);
});

test("signed-in staff login returns to the authorized workspace", async ({
  page,
}) => {
  await fixtures(page, ["practitioner"]);
  await page.goto(`${portalHost}/staff/login`);
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await expect(
    page.getByText("Your day at a glance", { exact: true }),
  ).toBeVisible();
});

test("screenshots: public and mobile portal layouts", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  await page.goto(publicHost);
  await expect(
    page.getByRole("heading", { name: "Feel better, on your schedule." }),
  ).toBeVisible();
  await page.screenshot({ path: "../.tmp/public-home.png", fullPage: true });
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByRole("button", { name: "New client" })).toBeVisible();
  await page.screenshot({ path: "../.tmp/portal-desktop.png", fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole("button", { name: "Open portal menu" }).click();
  await expect(
    page.getByRole("button", { name: "Close portal menu" }),
  ).toBeVisible();
  await expect
    .poll(async () =>
      Math.round((await page.locator(".MuiDrawer-paper").boundingBox())!.x),
    )
    .toBe(0);
  await page.screenshot({ path: "../.tmp/portal-mobile.png", fullPage: true });
});
