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
test('administrator revokes existing appointment links only after confirmation',async({page})=>{
  await fixtures(page,['super_admin']);
  const appointment={id:41,client_name:'Test Client',service_name:'Massage',practitioner_name:'Practitioner',location_name:'Main',timezone:'America/Toronto',room_id:null,room_name:null,duration_option_id:1,starts_at:'2099-10-01 14:00:00',ends_at:'2099-10-01 15:00:00',status:'confirmed',version:1,delivery_mode:'clinic',destination_snapshot:null,travel_buffer_minutes:0,base_price_cents:10000,mobile_fee_cents:0,action_links_enabled:true};
  let revocations=0;
  await page.route('**/api/v1/appointments?**',route=>route.fulfill({json:{data:[appointment]}}));
  await page.route('**/api/v1/appointments/41',route=>route.fulfill({json:{data:appointment}}));
  await page.route('**/api/v1/appointments/41/logistics-notes',route=>route.fulfill({json:{data:{notes:[],truncated:false}}}));
  await page.route('**/api/v1/appointments/41/action-links/revoke',route=>{revocations++;expect(route.request().method()).toBe('POST');return route.fulfill({json:{data:{revoked:true}}});});
  await page.goto(`${portalHost}/admin/appointments?appointment_id=41`);
  await page.getByRole('button',{name:'Revoke existing email links'}).click();expect(revocations).toBe(0);
  await page.getByRole('button',{name:'Confirm link revocation'}).click();await expect(page.getByText('Existing appointment email links were revoked.')).toBeVisible();expect(revocations).toBe(1);
});
test.beforeEach(({ page }) => {
  const list: string[] = [];
  errors.set(page, list);
  page.on("pageerror", (error) => list.push(error.message));
});
test.afterEach(({ page }) => {
  expect(errors.get(page)).toEqual([]);
});

test("practitioner qualification submission stays pending and shows renewal warning", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const rows = [{ id: 11, qualification_type_id: 2, qualification_name: "First aid", issuer: "Training group", issued_on: "2025-01-01", expires_on: "2026-10-15", status: "verified", days_until_expiry: 15, renewal_warning: true, review_note: null }];
  await page.route("**/api/v1/qualifications/types", route => route.fulfill({ json: { data: [{ id: 2, name: "First aid", requires_expiry: true, active: true }] } }));
  await page.route("**/api/v1/profile/qualifications", route => {
    if (route.request().method() === "POST") {
      rows.push({ id: 12, qualification_type_id: 2, qualification_name: "First aid", issuer: "New group", issued_on: "2026-09-01", expires_on: "2027-09-01", status: "pending", days_until_expiry: 336, renewal_warning: false, review_note: null });
      return route.fulfill({ json: { data: { id: 12, status: "pending" } } });
    }
    return route.fulfill({ json: { data: rows } });
  });
  await page.goto(`${portalHost}/practitioner/profile`);
  await expect(page.getByText("At least one verified qualification needs renewal soon or has expired.")).toBeVisible();
  await page.getByRole("combobox", { name: "Qualification type" }).click();
  await page.getByRole("option", { name: "First aid" }).click();
  await page.getByRole("textbox", { name: "Issuing organization" }).fill("New group");
  await page.getByLabel("Issue date").fill("2026-09-01");
  await page.getByLabel("Expiry date").fill("2027-09-01");
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expect(page.getByText("Qualification submitted for review.")).toBeVisible();
  await expect(page.getByText("Pending review")).toBeVisible();
});

test("staff session recovery recognizes silent timeouts and prevents redirect loops", async ({ page }) => {
  await fixtures(page);
  await page.goto(`${portalHost}/staff/login`);
  const result = await page.evaluate(async () => {
    const modulePath = '/src/auth/staffSessionRecovery.ts';
    const recovery = await import(/* @vite-ignore */ modulePath);
    recovery.clearStaffSessionRecovery();
    const timedOut = recovery.needsInteractiveStaffAuth({ errorCode: 'timed_out' });
    const unrelated = recovery.needsInteractiveStaffAuth({ errorCode: 'invalid_client' });
    const first = recovery.claimStaffSessionRecovery(100_000);
    const duplicate = recovery.claimStaffSessionRecovery(100_001);
    const later = recovery.claimStaffSessionRecovery(220_001);
    recovery.clearStaffSessionRecovery();
    return { timedOut, unrelated, first, duplicate, later };
  });
  expect(result).toEqual({ timedOut: true, unrelated: false, first: true, duplicate: false, later: true });
});

test("expired staff session offers a fresh Microsoft sign-in instead of a raw error", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.route("**/src/auth/AuthProvider.tsx", route => route.fulfill({
    contentType: "application/javascript",
    body: `
      const account={homeAccountId:'test-user',name:'Test Staff',username:'staff@example.test'};
      const auth={account,configured:true,isAuthenticated:true,sessionExpired:true,signIn:async()=>{window.staffRenewalStarted=true},signOut:async()=>{},getAccessToken:async()=>{throw new Error('timed_out')}};
      export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};
      export const StaffAuthProvider=({children})=>children;
      export const selectStaffAccount=()=>account;
      export const useStaffAuth=()=>auth;
    `,
  }));
  await page.goto(`${portalHost}/practitioner`);
  await expect(page.getByText("Your staff session has expired. Sign in with Microsoft to continue.")).toBeVisible();
  await expect(page.getByText("timed_out")).toHaveCount(0);
  await page.getByRole("button", { name: "Sign in again" }).click();
  await expect.poll(() => page.evaluate(() => Boolean((window as Window & { staffRenewalStarted?: boolean }).staffRenewalStarted))).toBe(true);
});

test("staff portal does not start authentication inside a silent refresh iframe", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.goto(`${portalHost}/practitioner`);
  await page.evaluate(() => {
    const frame = document.createElement('iframe');
    frame.id = 'silent-auth-probe';
    frame.src = `${window.location.origin}/practitioner`;
    document.body.append(frame);
  });
  await expect(page.frameLocator('#silent-auth-probe').locator('#root')).toBeEmpty();
});

async function fixtures(
  page: Page,
  roles?: string[],
  permissions: string[] = [],
  timeOffFollowUps?: { count: number; appointments: { id: number; starts_at: string; status: string }[] },
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
    if (path === "/profile/public-card") data = { public_name: "Test Practitioner", booking_name: "Test", summary: "", summary_fr: "", public_website_url: "", public_contact_email: "", public_contact_phone: "", public_contact_sms: false, slug: "test-practitioner", published: true };
    if (path === "/profile/notifications") data = { work_email: "staff@example.test", email_enabled: false, email_destination: "work", personal_email: null, personal_email_verified: false, mobile_phone: null, sms_requested: false, sms_delivery_active: false };
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
        ...(workspace === "practitioner" ? { time_off_follow_ups: timeOffFollowUps ?? { count: 0, appointments: [] } } : {}),
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
    if (path === "/practitioner/today") data = { date: "2026-09-29", timezone: "America/Toronto", appointments: [] };
    if (/^\/appointments\/\d+\/logistics-notes$/.test(path)) data = { notes: [], truncated: false };
    if (path === "/practitioner/visit-summary") data = { start_date: "2026-09-23", end_date: "2026-09-29", timezone: "America/Toronto", counts: { scheduled_visits: 0, completed: 0, no_show: 0, awaiting_outcome: 0, visits_with_steps: 0, onsite_arrivals: 0, onsite_departures: 0 } };
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
        location_id: "12",
        location_name: "Holland Landing",
        name: "",
        room_type: "",
        equipment_notes: "",
        turnover_minutes: "",
        is_bookable: "1",
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
            {
              location_id: 1,
              location_name: "Mobile service area",
              timezone: "America/Toronto",
              service_id: 5,
              service_name: "Acupuncture",
              requires_room: 0,
              offers_mobile: 1,
              offers_clinic: 0,
              travel_buffer_minutes: 20,
              mobile_fee_cents: 2500,
              base_price_cents: 9000,
              practitioner_id: 6,
              practitioner_name: "Other Therapist",
              duration_option_id: 7,
              duration_minutes: 45,
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
  let savedApproval = false;
  await page.route("**/api/v1/address-coverage/approval", route => route.fulfill({ json: { data: { approved: savedApproval } } }));
  await page.route("**/api/v1/address-coverage/approve", (route) => {
    expect(route.request().postDataJSON()).toMatchObject({ client_id: 5, location_id: 1, service_id: 2, practitioner_id: 3, address_validation_token: "signed-address-proof" });
    savedApproval = true;
    return route.fulfill({ json: { data: { approved: true } } });
  });
  page.on("dialog", (dialog) => dialog.accept());
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
  await select(/^Practitioner/, "Other Therapist");
  await select(/^Service/, "Acupuncture");
  await select(/^Service/, "Clear service");
  await select(/^Practitioner/, "Clear practitioner");
  await select(/^Practitioner/, "Therapist");
  await page.getByRole("combobox", { name: /^Service/ }).click();
  await expect(page.getByRole("option", { name: "Acupuncture" })).toHaveCount(0);
  await page.getByRole("option", { name: "Massage" }).click();
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
  await page.getByRole("button", { name: "Approve this address for future On-Site bookings" }).click();
  await expect(page.getByText(/No new distance check is needed/)).toBeVisible();
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
    quoted_base_price_cents: 12000,
    quoted_mobile_fee_cents: 2500,
  });
  expect(booking).not.toHaveProperty("coverage_confirmed");
  expect(booking).not.toHaveProperty("address_validation_token");
  expect(booking).not.toHaveProperty("room_id");
  await page.getByRole("button", { name: "Book appointment", exact: true }).click();
  await page.getByRole("textbox", { name: "Find an active client" }).fill("Test");
  await page.getByRole("button", { name: "Select Test Client" }).click();
  await select(/^Practitioner/, "Therapist");
  await select(/^Service/, "Massage");
  await select(/^Duration/, "60 minutes — $120.00");
  await expect(page.getByText(/No new distance check is needed/)).toBeVisible();
  await expect(page.getByRole("button", { name: "Validate address and coverage" })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Find a time", exact: true })).toBeEnabled();
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
  expect(pagesFor(["clinic_admin"], "admin")).toContain("notifications");
  expect(pagesFor(["reception"], "admin")).not.toContain("notifications");
  expect(pagesFor(["practitioner"], "admin")).toEqual([]);
  expect(pagesFor(["super_admin"], "admin")).toContain("business");
  expect(pageAt("/admin/notifications", "admin")).toBe("notifications");
  expect(pagesFor(["practitioner"], "practitioner")).toEqual([
    "dashboard",
    "schedule_calendar",
    "appointments",
    "clients",
    "my_notifications",
    "calendar",
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
  expect(pageAt("/practitioner/calendar", "practitioner")).toBe("schedule_calendar");
  expect(pageAt("/practitioner/notifications", "practitioner")).toBe("my_notifications");
  expect(pageAt("/practitioner/clients", "practitioner")).toBe("clients");
});

test("staff beta feedback link omits URL secrets and uses the clinic email", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.goto(`${portalHost}/practitioner/schedule?token=private-value#invite-secret`);
  const href = await page.getByRole("link", { name: "Report a problem" }).getAttribute("href");
  expect(href).toContain("mailto:clinic@example.test?");
  const body = new URL(href!).searchParams.get("body") ?? "";
  expect(body).toContain("Page: /practitioner/schedule");
  expect(body).toContain("Time (UTC):");
  expect(body).toContain("Release: test-release");
  await expect(page.getByText("Release: test-release")).toBeVisible();
  expect(body).toContain("What happened?");
  expect(body).not.toContain("private-value");
  expect(body).not.toContain("invite-secret");
});

test("practitioner My clients is scoped and starts a booking with the selected client", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const requests: string[] = [];
  await page.route("**/api/v1/practitioner/clients?**", route => {
    requests.push(route.request().url());
    return route.fulfill({ json: { data: { page: 1, has_more: false, items: [
      { id: 42, display_name: "Lou Dee", email: "lou@example.test", phone: "4165550100", preferred_contact: "email", status: "active", appointment_count: 2, recent_appointment_id: 10 },
      { id: 43, display_name: "New Client", email: "new@example.test", phone: null, preferred_contact: "email", status: "active", appointment_count: 0, recent_appointment_id: null },
    ] } } });
  });
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/booking-options?**", route => route.fulfill({ json: { data: { rooms: [], default_location_id: 1, combinations: [{ location_id: 1, location_name: "Mobile area", timezone: "America/Toronto", service_id: 2, service_name: "Massage", requires_room: 0, offers_mobile: 1, offers_clinic: 0, travel_buffer_minutes: 30, mobile_fee_cents: 0, base_price_cents: 10000, practitioner_id: 3, practitioner_name: "Test Practitioner", duration_option_id: 4, duration_minutes: 60 }] } } }));
  await page.route("**/api/v1/booking-clients?**", route => route.fulfill({ json: { data: { items: [{ id: 43, display_name: "New Client", email: "new@example.test", phone: null }], has_more: false } } }));
  await page.goto(`${portalHost}/practitioner/clients`);
  await expect(page.getByRole("heading", { name: "My clients" })).toBeVisible();
  await expect(page.getByRole("list", { name: "My clients" }).getByText("Lou Dee")).toBeVisible();
  await expect(page.getByRole("list", { name: "My clients" }).getByText("New Client")).toBeVisible();
  await page.getByRole("list", { name: "My clients" }).getByText("Lou Dee").click();
  await expect(page.getByRole("link", { name: "Call client" })).toHaveAttribute("href", "tel:4165550100");
  await page.getByRole("link", { name: "Book appointment" }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner/schedule`);
  await expect(page.getByText("Selected client")).toBeVisible();
  await expect(page.getByText("lou@example.test")).toBeVisible();
  await page.getByRole("button", { name: "Change client" }).click();
  await expect(page.getByRole("textbox", { name: "Find an active client" })).toBeVisible();
  await page.getByRole("textbox", { name: "Find an active client" }).fill("New");
  await page.getByRole("button", { name: "Select New Client" }).click();
  await expect(page.getByText("new@example.test")).toBeVisible();
  await expect(page.getByText("lou@example.test")).toHaveCount(0);
  expect(requests.some(url => url.includes("/practitioner/clients?"))).toBe(true);
  await page.goto(`${portalHost}/admin/clients`);
  await expect(page.getByText("You do not have permission to access this page.")).toBeVisible();
});

test("Operations client-list booking action preselects its client and permits a change", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  await page.route("**/api/v1/clients?**", route => route.fulfill({ json: { data: { items: [{ id: 8, display_name: "Avery Client", email: "avery@example.test", phone: "9055550188", status: "active" }], has_more: false } } }));
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/booking-options", route => route.fulfill({ json: { data: { rooms: [], default_location_id: 1, combinations: [{ location_id: 1, location_name: "Test clinic", timezone: "America/Toronto", service_id: 2, service_name: "Massage", requires_room: 0, offers_mobile: 1, offers_clinic: 1, travel_buffer_minutes: 0, mobile_fee_cents: 0, base_price_cents: 12000, practitioner_id: 3, practitioner_name: "Test Practitioner", duration_option_id: 4, duration_minutes: 60 }] } } }));
  await page.route("**/api/v1/booking-clients?**", route => route.fulfill({ json: { data: { items: [{ id: 9, display_name: "Other Client", email: "other@example.test", phone: null }], has_more: false } } }));
  await page.goto(`${portalHost}/admin/clients`);
  await page.getByRole("button", { name: /Avery Client.*avery@example.test/ }).click();
  await page.getByRole("link", { name: "Book appointment" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/appointments`);
  await expect(page.getByText("Selected client")).toBeVisible();
  await expect(page.getByText("avery@example.test")).toBeVisible();
  await page.getByRole("button", { name: "Change client" }).click();
  await page.getByRole("textbox", { name: "Find an active client" }).fill("Other");
  await page.getByRole("button", { name: "Select Other Client" }).click();
  await expect(page.getByText("other@example.test")).toBeVisible();
  await expect(page.getByText("avery@example.test")).toHaveCount(0);
});

test("service booking action opens the appointment form with that service selected", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  await page.route("**/api/v1/admin/services", route => route.fulfill({ json: { data: [{ id: 2, name: "Massage", slug: "massage", category_id: null, category_name: null, active: 1, published: 1, price_cents: 12000, durations: [60], duration_options: [{ minutes: 60, price_cents: 12000 }], lead_time_minutes: 0, booking_horizon_days: 365, cancellation_window_minutes: 1440, cancellation_fee_type: "none", cancellation_fee_value: 0, buffer_before_minutes: 0, buffer_after_minutes: 0, display_order: 1, requires_room: 0, recurrence_allowed: 0 }] } }));
  await page.route("**/api/v1/admin/catalogue-settings", route => route.fulfill({ json: { data: { categories: [] } } }));
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/booking-options", route => route.fulfill({ json: { data: { rooms: [], default_location_id: 1, combinations: [{ location_id: 1, location_name: "Test clinic", timezone: "America/Toronto", service_id: 2, service_name: "Massage", requires_room: 0, offers_mobile: 1, offers_clinic: 1, travel_buffer_minutes: 0, mobile_fee_cents: 0, base_price_cents: 12000, practitioner_id: 3, practitioner_name: "Test Practitioner", duration_option_id: 4, duration_minutes: 60 }] } } }));
  await page.goto(`${portalHost}/admin/services`);
  await page.getByRole("list", { name: "Services" }).getByText("Massage").click();
  await page.getByRole("link", { name: "Book appointment" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/appointments`);
  await expect(page.getByRole("heading", { name: "New appointment" })).toBeVisible();
  await expect(page.getByRole("combobox", { name: /^Service Massage$/ })).toHaveText("Massage");
});

test("practitioner can inspect only their read-only booking notice history", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  let requested = '';
  await page.route("**/api/v1/practitioner/notifications?**", route => {
    requested = route.request().url();
    expect(route.request().headers().authorization).toBe("Bearer test-only-token");
    return route.fulfill({ json: { data: { items: [{ id: 31, appointment_id: 12, recipient_address: "practitioner@example.test", event_code: "staff_booking_confirmation", channel: "email", status: "needs_review", scheduled_at: "2026-09-28 13:00:00", sent_at: null }], total: 1, page: 1, page_size: 25 } } });
  });
  await page.goto(`${portalHost}/practitioner/notifications`);
  await expect(page.getByRole("heading", { name: "My notifications" })).toBeVisible();
  await expect(page.getByText("#31 · Staff booking notice")).toBeVisible();
  await page.getByText("#31 · Staff booking notice").click();
  await expect(page.getByText("A clinic administrator will review this notice. It will not be resent automatically.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Retry recent SMS — confirmed not sent" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "View appointment" })).toHaveAttribute("href", "/practitioner/schedule?appointment_id=12");
  await page.getByRole("button", { name: "Close" }).click();
  await page.getByRole("combobox", { name: "Status" }).click();
  await page.getByRole("option", { name: "Needs review" }).click();
  await expect.poll(() => requested).toContain("status=needs_review");
  await page.getByRole("combobox", { name: "Channel" }).click();
  await page.getByRole("option", { name: "SMS" }).click();
  await expect.poll(() => requested).toContain("channel=sms");
});

test("practitioner calendar shows the assigned schedule and masks names in privacy mode", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const start = new Date(); start.setHours(14, 0, 0, 0);
  const end = new Date(start.getTime() + 60 * 60 * 1000);
  const sqlTime = (date: Date) => date.toISOString().slice(0, 19).replace("T", " ");
  let requests = 0;
  await page.route("**/api/v1/practitioner/calendar?*", route => {
    requests++;
    expect(route.request().headers().authorization).toBe("Bearer test-only-token");
    const url = new URL(route.request().url());
    expect(url.searchParams.get("start")).toMatch(/Z$/);
    expect(url.searchParams.get("end")).toMatch(/Z$/);
    return route.fulfill({ json: { data: [{ id: 12, starts_at: sqlTime(start), ends_at: sqlTime(end), status: "confirmed", delivery_mode: "mobile", service_name: "Massage", client_name: "Test Client", location_name: "Holland Landing", timezone: "America/Toronto" }] } });
  });
  await page.goto(`${portalHost}/practitioner/calendar`);
  await expect(page.getByRole("heading", { name: "My calendar" })).toBeVisible();
  await expect(page.getByText("Test Client · Massage")).toBeVisible();
  await page.getByLabel("Privacy mode — hide client names").check();
  await expect(page.getByText("Test Client · Massage")).toHaveCount(0);
  await expect(page.getByText("Private appointment")).toBeVisible();
  await page.getByRole("button", { name: "Month", exact: true }).click();
  await expect.poll(() => requests).toBeGreaterThan(1);
  await expect(page.getByText("Private appointment")).toBeVisible();
  await page.getByText("Private appointment").click();
  await expect(page.getByText("Test Client · Massage")).toHaveCount(0);
  await expect(page.getByText("Open appointments")).toHaveCount(0);
});

test("practitioner calendar starts in a day view on a small screen", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixtures(page, ["practitioner"]);
  await page.goto(`${portalHost}/practitioner/calendar`);
  await expect(page.getByRole("heading", { name: "My calendar" })).toBeVisible();
  await expect(page.getByRole("grid")).toBeVisible();
  await expect(page.getByRole("button", { name: "Day", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
});

test("practitioner calendar remembers the account's view but opens on today", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.goto(`${portalHost}/practitioner/calendar`);
  await expect(page.getByRole("button", { name: "Week", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
  await page.getByRole("button", { name: "Month", exact: true }).click();
  await expect(page.getByRole("button", { name: "Month", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
  const currentMonth = await page.getByRole("grid").getAttribute("aria-label");
  await page.getByRole("button", { name: "Next period" }).click();
  await expect(page.getByRole("grid")).not.toHaveAttribute("aria-label", currentMonth!);
  await page.reload();
  await expect(page.getByRole("button", { name: "Month", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
  await expect(page.getByRole("grid")).toHaveAttribute("aria-label", currentMonth!);

  const reopened = await page.context().newPage();
  await fixtures(reopened, ["practitioner"]);
  await reopened.goto(`${portalHost}/practitioner/calendar`);
  await expect(reopened.getByRole("button", { name: "Month", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
  await reopened.getByRole("button", { name: "Day", exact: true }).click();
  await reopened.close();
  await page.reload();
  await expect(page.getByRole("button", { name: "Day", exact: true })).toHaveAttribute("class", /MuiButton-contained/);
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

test("public Markdown sections follow the selected language and legacy links retain their destinations", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto(`${publicHost}/about`);
  await expect(page).toHaveURL(`${publicHost}/#about`);
  await expect(
    page.getByRole("heading", { name: "About our centre", level: 2 }),
  ).toBeVisible();
  await expect(page).toHaveTitle("Test Wellness");
  await expect(page.locator('meta[name="description"]')).toHaveAttribute(
    "content",
    /Explore our services/,
  );
  await expect(
    page.getByRole("link", { name: "New clients" }).first(),
  ).toHaveAttribute("href", "/#new-clients");
  await page.getByRole("button", { name: "Language and region" }).click();
  await page.getByRole("button", { name: /Français \(Canada\)/ }).click();
  await expect(
    page.getByRole("heading", { name: "À propos de notre centre", level: 2 }),
  ).toBeVisible();
  await expect(page).toHaveTitle("Test Wellness");
  await page.getByRole("link", { name: "Nouveaux clients" }).first().click();
  await expect(page).toHaveURL(`${publicHost}/#new-clients`);
  await expect(
    page.getByRole("heading", {
      name: "Bienvenue aux nouveaux clients",
      level: 2,
    }),
  ).toBeVisible();
  await page.getByRole("link", { name: "FAQ", exact: true }).first().click();
  await expect(
    page.getByRole("heading", { name: "Foire aux questions", level: 2 }),
  ).toBeVisible();
});

test("practitioner calendar shows a week of time off even without appointments", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const start = new Date(); start.setDate(start.getDate() - 2); start.setHours(0, 0, 0, 0);
  const end = new Date(start.getTime() + 9 * 24 * 60 * 60 * 1000);
  const sqlTime = (date: Date) => date.toISOString().slice(0, 19).replace("T", " ");
  await page.route("**/api/v1/practitioner/calendar?*", route => route.fulfill({ json: { data: [{
    kind: "time_off", id: 12, starts_at: sqlTime(start), ends_at: sqlTime(end),
    reason_type: "vacation", location_name: "Holland Landing",
  }] } }));
  await page.goto(`${portalHost}/practitioner/calendar`);
  await expect(page.getByRole("grid").getByRole("button", { name: /Time off/ }).first()).toBeVisible();
  await expect(page.getByText("No appointments or time off in this period.")).toHaveCount(0);
  await page.getByRole("grid").getByRole("button", { name: /Time off/ }).first().click();
  await expect(page.getByText("Vacation")).toBeVisible();
  await page.getByRole("button", { name: "Close" }).click();
  await page.getByRole("button", { name: "Month", exact: true }).click();
  await expect(page.getByRole("grid").getByRole("button", { name: /Time off/ }).first()).toBeVisible();
  await page.getByRole("button", { name: "Day", exact: true }).click();
  await expect(page.getByRole("grid").getByRole("button", { name: /Time off/ }).first()).toBeVisible();
});

test("public navigation tracks scrolling and mobile links open the requested section", async ({ page }) => {
  await fixtures(page);
  await page.goto(publicHost);
  const navigation = page.getByRole('navigation', { name: 'Public navigation' }).first();
  await navigation.getByRole('link', { name: 'Services' }).click();
  await expect(page).toHaveURL(`${publicHost}/#services`);
  await expect(navigation.getByRole('link', { name: 'Services' })).toHaveAttribute('aria-current', 'location');
  await page.locator('#faq').scrollIntoViewIfNeeded();
  await expect(navigation.getByRole('link', { name: 'FAQs' })).toHaveAttribute('aria-current', 'location');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: 'Open menu' }).click();
  await page.getByRole('navigation', { name: 'Public navigation' }).last().getByRole('link', { name: 'Contact' }).click();
  await expect(page).toHaveURL(`${publicHost}/#contact`);
  await expect(page.getByRole('heading', { name: 'Contact Test Wellness' })).toBeInViewport();
});

test('legacy section links stay aligned after earlier content loads', async ({ page }) => {
  await fixtures(page);
  await page.route('**/api/v1/public/services', async route => {
    await new Promise(resolve => setTimeout(resolve, 400));
    await route.fulfill({ json: { data: [] } });
  });
  await page.goto(`${publicHost}/contact`);
  await expect(page).toHaveURL(`${publicHost}/#contact`);
  await expect(page.getByRole('heading', { name: 'Contact Test Wellness' })).toBeVisible();
  const contactOffset = async () => {
    const header = await page.locator('#public-header').boundingBox();
    const target = await page.locator('#contact').boundingBox();
    return header && target ? target.y - header.height : Infinity;
  };
  await expect.poll(contactOffset).toBeLessThan(120);
  expect(await contactOffset()).toBeGreaterThanOrEqual(0);
  await expect(page.locator('#contact')).toBeInViewport();
});

test('editorial sections use the layout manifest and FAQs expand accessibly', async ({ page }) => {
  await fixtures(page);
  await page.goto(`${publicHost}/faq`);
  await expect(page.locator('#new-clients .editorial-detail')).toHaveCount(1);
  await expect(page.locator('#about .editorial-detail')).toHaveCount(1);
  const answer = page.getByText('No. The system checks availability again when you confirm the appointment.');
  await expect(answer).toBeHidden();
  await page.getByRole('button', { name: 'Is a time reserved when I select it?' }).click();
  await expect(answer).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Is a time reserved when I select it?', level: 3 })).toBeVisible();
});

test("one-page team places practitioners before administration without duplicate cards", async ({
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
  await page.route('**/api/v1/public/practitioners', route => route.fulfill({ json: { data: [{ slug: 'test-practitioner', public_name: 'Test Practitioner', booking_name: 'Test', public_title: 'Registered Massage Therapist', public_title_fr: 'Massothérapeute agréée', summary: 'Mobile therapeutic massage.', summary_fr: 'Massothérapie thérapeutique mobile.', discipline: 'Massage therapy', credentials: 'RMT', has_image: false, image_version: null, booking_practitioner_id: 3, services: [] }] } }));
  await page.goto(`${publicHost}/contact`);
  const practitionerHeading = page.getByRole("heading", {
    name: "Meet your care team.",
    level: 2,
  });
  const administrationHeading = page.getByRole("heading", {
    name: "Administration",
    level: 3,
  });
  await expect(practitionerHeading).toBeVisible();
  await expect(administrationHeading).toBeVisible();
  const practitionerBeforeAdministration = await practitionerHeading.evaluate((heading, administration) =>
    Boolean(heading.compareDocumentPosition(administration as Node) & Node.DOCUMENT_POSITION_FOLLOWING),
    await administrationHeading.elementHandle(),
  );
  expect(practitionerBeforeAdministration).toBe(true);
  await expect(page.getByText("Mobile therapeutic massage.")).toBeVisible();
  await expect(page.getByText("RMT · Massage therapy")).toBeVisible();
  await expect(page.getByText("Supports clinic operations.")).toBeVisible();
  await expect(page.locator('#team').getByText('Test Practitioner')).toHaveCount(0);
  await expect(
    page.getByRole("dialog", { name: "Profile for Test Practitioner" }),
  ).toHaveCount(0);
  await page.locator('#practitioners').getByRole("link", { name: "Book with Test Practitioner" }).click();
  await expect(page).toHaveURL(/localhost:5184\/\?practitioner_id=3/);
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
    /localhost:5184\/services\/massage-therapy\/book\?practitioner_id=3/,
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

test("practitioner dashboard highlights future bookings overlapping time off", async ({ page }) => {
  await fixtures(page, ["practitioner"], [], { count: 2, appointments: [
    { id: 42, starts_at: "2030-10-01 14:00:00", status: "confirmed" },
    { id: 43, starts_at: "2030-10-02 14:00:00", status: "rescheduled" },
  ] });
  await page.goto(`${portalHost}/practitioner`);
  await expect(page.getByText("2 upcoming appointments overlap your time off and need follow-up.")).toBeVisible();
  await expect(page.getByRole("link", { name: "Review appointment #42" })).toHaveAttribute("href", "/practitioner/schedule?appointment_id=42");
  await expect(page.getByRole("link", { name: "Review appointment #43" })).toHaveAttribute("href", "/practitioner/schedule?appointment_id=43");
});

test("practitioner requests a current-traffic route only after choosing an origin", async ({ page, context }) => {
  await fixtures(page, ["practitioner"]);
  const startsAt = new Date(Date.now() + 3 * 60 * 60 * 1000).toISOString().slice(0, 19).replace("T", " ");
  let requests = 0;
  await page.route("**/api/v1/practitioner/next-onsite", route => route.fulfill({ json: { data: {
    id: 84, starts_at: startsAt, service_name: "Massage", client_name: "Test Client",
    timezone: "America/Toronto", clinic_origin_available: true,
  } } }));
  await page.route("**/api/v1/practitioner/next-onsite/84/travel-estimate", route => {
    requests += 1;
    const body = route.request().postDataJSON() as { source: string; latitude?: number; longitude?: number };
    if (requests === 1) expect(body).toEqual({ source: "clinic" });
    else expect(body).toEqual({ source: "current", latitude: 43.7, longitude: -79.4 });
    return route.fulfill({ json: { data: {
      appointment_id: 84, starts_at: startsAt, source: body.source, duration_minutes: 28,
      distance_km: 17.2, checked_at_utc: new Date().toISOString(),
      destination_address: "123 Test Street, Toronto, ON, M1M 1M1", traffic_aware: true,
    } } });
  });
  await page.goto(`${portalHost}/practitioner`);
  await expect(page.getByText("Travel to your next On-Site visit")).toBeVisible();
  expect(requests).toBe(0);
  await page.getByRole("button", { name: "Check from clinic" }).click();
  await expect(page.getByText("With current traffic: about 28 min · 17.2 km")).toBeVisible();
  await expect(page.getByRole("link", { name: "Open Google Maps directions" })).toHaveAttribute("href", /destination=123%20Test%20Street/);
  await context.grantPermissions(["geolocation"], { origin: portalHost });
  await context.setGeolocation({ latitude: 43.7, longitude: -79.4 });
  await page.getByRole("button", { name: "Check from my location" }).click();
  await expect.poll(() => requests).toBe(2);
});

test("practitioner day-of card calls clients, records optional steps, and closes a visit", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const start = new Date(Date.now() - 30 * 60000).toISOString().slice(0, 19).replace("T", " ");
  const end = new Date(Date.now() + 30 * 60000).toISOString().slice(0, 19).replace("T", " ");
  const events: Record<string, { code: string; occurred_at: string }> = {};
  let status = "confirmed";
  let version = 1;
  let milestoneWrites = 0;
  await page.route("**/api/v1/practitioner/today", route => route.fulfill({ json: { data: {
    date: "2026-09-29", timezone: "America/Toronto", appointments: [{
      id: 91, client_id: 5, service_id: 2, starts_at: start, ends_at: end,
      status, version, delivery_mode: "mobile", service_name: "Massage",
      client_name: "Test Client", client_email: "client@example.test", client_phone: "4166166855",
      location_name: "Clinic", timezone: "America/Toronto", events,
    }],
  } } }));
  await page.route("**/api/v1/practitioner/today/91/milestone", route => {
    const body = route.request().postDataJSON() as { code: string; action: string };
    milestoneWrites += 1;
    if (body.action === "undo") delete events[body.code];
    else events[body.code] = { code: body.code, occurred_at: new Date().toISOString().slice(0, 19).replace("T", " ") };
    return route.fulfill({ json: { data: { events } } });
  });
  await page.route("**/api/v1/practitioner/today/91/outcome", route => {
    const body = route.request().postDataJSON() as { outcome: string; version: number };
    if (body.outcome === "completed") { expect(body.version).toBe(1); status = "completed"; version = 2; }
    else { expect(body).toEqual({ outcome: "reopen", version: 2 }); status = "confirmed"; version = 3; }
    return route.fulfill({ json: { data: { status, version } } });
  });
  await page.route("**/api/v1/booking-options?scope=practitioner", route => route.fulfill({ json: { data: { combinations: [{
    location_id: 1, location_name: "Clinic", timezone: "America/Toronto",
    service_id: 2, service_name: "Massage", practitioner_id: 3, practitioner_name: "Test Practitioner",
    duration_option_id: 4, duration_minutes: 60, base_price_cents: 12000,
    offers_mobile: 1, offers_clinic: 1, requires_room: 0, travel_buffer_minutes: 30,
    mobile_fee_cents: 0, mobile_radius_km: 20,
  }], rooms: [], default_location_id: 1 } } }));
  await page.goto(`${portalHost}/practitioner`);
  await expect(page.getByText("Today’s visits")).toBeVisible();
  await expect(page.getByRole("link", { name: "Call client" })).toHaveAttribute("href", "tel:4166166855");
  await expect(page.getByRole("link", { name: "Book next visit" })).toHaveAttribute("href", "/practitioner/schedule");
  await page.getByRole("button", { name: "En route" }).click();
  await expect(page.getByText(/Last step: En route/)).toBeVisible();
  await page.getByRole("button", { name: "Arrived" }).click();
  await page.getByRole("button", { name: "Undo last step" }).click();
  await expect(page.getByRole("button", { name: "Arrived" })).toBeEnabled();
  expect(milestoneWrites).toBe(3);
  page.on("dialog", dialog => void dialog.accept());
  await page.getByRole("button", { name: "Mark completed" }).click();
  await expect(page.getByText("completed", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Left residence" })).toBeEnabled();
  await page.getByRole("button", { name: "Undo outcome" }).click();
  await expect(page.getByText("confirmed", { exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Book next visit" }).click();
  await expect(page.getByText("Selected client")).toBeVisible();
  await expect(page.getByText("Test Client", { exact: true })).toBeVisible();
});

test("practitioner can review recorded and undone visit steps from appointment details", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const appointment = {
    id: 91, client_name: "Test Client", client_email: "client@example.test", client_phone: "4166166855",
    service_name: "Massage", practitioner_name: "Test Practitioner", location_name: "Clinic", timezone: "America/Toronto",
    room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "mobile", destination_snapshot: null,
    travel_buffer_minutes: 30, base_price_cents: 12000, mobile_fee_cents: 0, status: "completed", version: 2,
    starts_at: "2026-09-29 12:00:00", ends_at: "2026-09-29 13:00:00",
  };
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [appointment] } }));
  await page.route("**/api/v1/appointments/91?scope=practitioner", route => route.fulfill({ json: { data: appointment } }));
  let historyRequests = 0;
  await page.route("**/api/v1/practitioner/appointments/91/visit-history", route => {
    historyRequests++;
    return route.fulfill({ json: { data: { appointment_id: 91, truncated: false, events: [
      { event_code: "arrived", event_action: "undo", occurred_at: "2026-09-29 12:04:00" },
      { event_code: "arrived", event_action: "record", occurred_at: "2026-09-29 12:03:00" },
    ] } } });
  });
  await page.goto(`${portalHost}/practitioner/schedule`);
  await page.getByRole("button", { name: /Test Client.*Massage/ }).click();
  expect(historyRequests).toBe(0);
  await page.getByRole("button", { name: "More details" }).click();
  const history = page.getByRole("list", { name: "Visit step history" });
  await expect(history.getByText("Undid Arrived")).toBeVisible();
  await expect(history.getByText("Recorded Arrived")).toBeVisible();
  await expect(history.locator("li").first()).toContainText("Undid Arrived");
  expect(historyRequests).toBe(1);
});

test("practitioner can close and correct a past visit from appointment details", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const sqlTime = (offsetHours: number) => new Date(Date.now() + offsetHours * 3600000).toISOString().slice(0, 19).replace("T", " ");
  const appointment = {
    id: 92, client_name: "Past Client", client_email: "past@example.test", client_phone: "4166166855",
    service_name: "Massage", practitioner_name: "Test Practitioner", location_name: "Clinic", timezone: "America/Toronto",
    room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "mobile", destination_snapshot: null,
    travel_buffer_minutes: 30, base_price_cents: 12000, mobile_fee_cents: 0, status: "confirmed", version: 1,
    starts_at: sqlTime(-24), ends_at: sqlTime(-23),
  };
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [appointment] } }));
  await page.route("**/api/v1/appointments/92?scope=practitioner", route => route.fulfill({ json: { data: appointment } }));
  await page.route("**/api/v1/practitioner/appointments/92/visit-history", route => route.fulfill({ json: { data: { appointment_id: 92, events: [], truncated: false } } }));
  const outcomes: { outcome: string; version: number }[] = [];
  await page.route("**/api/v1/practitioner/today/92/outcome", route => {
    const body = route.request().postDataJSON() as { outcome: string; version: number };
    outcomes.push(body);
    appointment.status = body.outcome === "reopen" ? "confirmed" : body.outcome;
    appointment.version++;
    return route.fulfill({ json: { data: { status: appointment.status, version: appointment.version } } });
  });
  page.on("dialog", dialog => void dialog.accept());
  await page.goto(`${portalHost}/practitioner/schedule`);
  await page.getByRole("combobox", { name: "Show" }).click();
  await page.getByRole("option", { name: "Past" }).click();
  const openDetails = async () => {
    await page.getByRole("button", { name: /Past Client.*Massage/ }).click();
    await page.getByRole("button", { name: "More details" }).click();
  };
  await openDetails();
  await page.getByRole("button", { name: "Mark completed" }).click();
  await expect(page.getByText("Appointment #92 marked completed.")).toBeVisible();
  await openDetails();
  await page.getByRole("button", { name: "Undo outcome" }).click();
  await expect(page.getByText("Appointment #92 outcome restored.")).toBeVisible();
  await openDetails();
  await page.getByRole("button", { name: "Mark no-show" }).click();
  await expect(page.getByText("Appointment #92 marked no-show.")).toBeVisible();
  expect(outcomes).toEqual([
    { outcome: "completed", version: 1 },
    { outcome: "reopen", version: 2 },
    { outcome: "no_show", version: 3 },
  ]);
});

test("practitioner visit activity distinguishes current outcomes from optional steps", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.route("**/api/v1/practitioner/visit-summary?**", route => route.fulfill({ json: { data: {
    start_date: "2026-09-23", end_date: "2026-09-29", timezone: "America/Toronto",
    counts: { scheduled_visits: 8, completed: 5, no_show: 1, awaiting_outcome: 2, visits_with_steps: 4, onsite_arrivals: 3, onsite_departures: 2 },
  } } }));
  await page.goto(`${portalHost}/practitioner`);
  await expect(page.getByText("Visit activity · last 7 days")).toBeVisible();
  await expect(page.getByText("Past visits awaiting outcome").locator("..")).toContainText("2");
  await expect(page.getByText(/Optional steps currently recorded: 4/)).toBeVisible();
  await expect(page.getByText(/On-Site arrivals recorded: 3/)).toBeVisible();
  await page.getByRole("link", { name: /Review visits awaiting outcome/ }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner/schedule?view=needs_outcome`);
  await expect(page.getByRole("combobox", { name: "Show" })).toHaveText("Needs visit outcome");
});

test("an outcome removes its visit from the practitioner follow-up filter", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const appointment = {
    id: 93, client_name: "Follow-up Client", client_email: "followup@example.test", client_phone: "4166166855",
    service_name: "Massage", practitioner_name: "Test Practitioner", location_name: "Clinic", timezone: "America/Toronto",
    room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "mobile", destination_snapshot: null,
    travel_buffer_minutes: 30, base_price_cents: 12000, mobile_fee_cents: 0, status: "confirmed", version: 1,
    starts_at: "2026-09-28 12:00:00", ends_at: "2026-09-28 13:00:00",
  };
  const views: string[] = [];
  await page.route("**/api/v1/appointments?**", route => {
    const view = new URL(route.request().url()).searchParams.get("view") ?? "";
    views.push(view);
    return route.fulfill({ json: { data: view === "needs_outcome" && appointment.status === "confirmed" ? [appointment] : [] } });
  });
  await page.route("**/api/v1/appointments/93?scope=practitioner", route => route.fulfill({ json: { data: appointment } }));
  await page.route("**/api/v1/practitioner/appointments/93/visit-history", route => route.fulfill({ json: { data: { appointment_id: 93, events: [], truncated: false } } }));
  await page.route("**/api/v1/practitioner/today/93/outcome", route => {
    appointment.status = "completed"; appointment.version++;
    return route.fulfill({ json: { data: { status: appointment.status, version: appointment.version } } });
  });
  page.on("dialog", dialog => void dialog.accept());
  await page.goto(`${portalHost}/practitioner/schedule?view=needs_outcome`);
  await expect(page.getByRole("combobox", { name: "Show" })).toHaveText("Needs visit outcome");
  await page.getByRole("button", { name: /Follow-up Client.*Massage/ }).click();
  await page.getByRole("button", { name: "More details" }).click();
  await page.getByRole("button", { name: "Mark completed" }).click();
  await expect(page.getByRole("button", { name: /Follow-up Client.*Massage/ })).toHaveCount(0);
  await expect(page.getByText("No appointments in this view.")).toBeVisible();
  expect(views.every(view => view === "needs_outcome")).toBe(true);
});

test("admin notification widget separates email and SMS by period and links to status", async ({ page }) => {
  await fixtures(page, ["clinic_admin"]);
  await page.route("**/api/v1/dashboard?workspace=admin", route => route.fulfill({ json: { data: {
    workspace: "admin", timezone: "America/Toronto", as_of: "2026-09-28T14:00:00Z",
    definitions: [{ id: "notification_delivery_summary", renderer: "notification_summary", title: { en: "Email and SMS activity", fr: "Activité des courriels et SMS" }, description: { en: "Provider acceptance today and over the last 7 days; other statuses use scheduled time.", fr: "Acceptation du fournisseur aujourd’hui et au cours des 7 derniers jours; les autres états utilisent l’heure prévue." }, icon: "mail-check", destination: { page: "notifications" }, sizes: ["wide"] }],
    values: { notification_delivery_summary: { needs_review_total: 4, health: { state: "stale", last_status: "succeeded", last_started_at: "2026-09-28 12:00:00", last_completed_at: "2026-09-28 12:00:01", last_success_at: "2026-09-28 12:00:01", last_failure_at: null, overdue_count: 2, stale_after_minutes: 45, overdue_after_minutes: 30 },
      today: { email: { sent: 2, queued: 1, failed: 0, needs_review: 0 }, sms: { sent: 3, queued: 0, failed: 1, needs_review: 1 } },
      last7: { email: { sent: 7, queued: 2, failed: 1, needs_review: 0 }, sms: { sent: 5, queued: 1, failed: 2, needs_review: 1 } },
    } },
  } } }));
  await page.route("**/api/v1/dashboard/preferences?workspace=admin", route => route.fulfill({ json: { data: { version: 1, workspace: "admin", widgets: [{ id: "notification_delivery_summary", enabled: true, order: 0, size: "wide" }] } } }));
  let requestedNotifications = "";
  await page.route("**/api/v1/admin/notifications?**", route => {
    requestedNotifications = route.request().url();
    return route.fulfill({ json: { data: { items: [], counts: { queued: 0, sending: 0, sent: 0, delivered: 0, failed: 0, canceled: 0, needs_review: 4, resolved: 0 }, total: 4, page: 1, page_size: 25 } } });
  });
  await page.goto(`${portalHost}/admin`);
  const card = page.getByText("Email and SMS activity").locator("xpath=ancestor::*[contains(@class,'MuiCard-root')][1]");
  await expect(card.getByRole("link", { name: /View notification status/ })).toHaveAttribute("href", "/admin/notifications");
  await expect(card.getByText("4 notifications need review across all dates.")).toBeVisible();
  await expect(card.getByText("Notification scheduler: Scheduler has not succeeded recently")).toBeVisible();
  await expect(card.getByText(/Overdue notices \(more than 30 minutes\): 2/)).toBeVisible();
  await expect(card.getByRole("link", { name: "Review items" })).toHaveAttribute("href", "/admin/notifications?status=needs_review");
  await expect(card).toContainText("Today · Email");
  await expect(card).toContainText("Accepted by provider: 2");
  await expect(card).toContainText("Today · SMS");
  await expect(card).toContainText("Needs review: 1");
  await expect(card).toContainText("Last 7 days · SMS");
  await card.getByRole("link", { name: "Review items" }).click();
  await expect(page).toHaveURL(`${portalHost}/admin/notifications?status=needs_review`);
  await expect.poll(() => requestedNotifications).toContain("status=needs_review");
  await page.getByRole("combobox", { name: "Channel" }).click();
  await page.getByRole("option", { name: "SMS" }).click();
  await expect.poll(() => requestedNotifications).toContain("channel=sms");
  await page.getByRole("combobox", { name: "Scheduled date" }).click();
  await page.getByRole("option", { name: "Last 7 days" }).click();
  await expect.poll(() => requestedNotifications).toContain("period=last7");
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
  await expect(page.getByRole("link", { name: "Esther Vanderpoel", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Nutrition Practitioner", exact: true })).toHaveCount(0);
  await page.getByRole("link", { name: "View profile" }).click();
  await expect(page).toHaveURL(`${publicHost}/practitioners/esther-vanderpoel`);
  await expect(page.getByRole("heading", { name: "Services offered" })).toBeVisible();
  await expect(page.getByText("60 min — $120.00")).toBeVisible();
  await expect(page.getByRole("link", { name: "Find a time" }).first()).toHaveAttribute("href", /localhost:5184\/availability\?practitioner_id=3/);
  await expect(page.getByRole("link", { name: "Find a time" }).last()).toHaveAttribute("href", /services\/massage-therapy\/book\?practitioner_id=3/);
});

test("public legacy booking URL redirects to the guest portal with selection hints", async ({ page }) => {
  await fixtures(page);
  await page.goto(`${publicHost}/book?service=massage&practitioner_id=3`);
  await expect(page).toHaveURL(/localhost:5184\/services\/massage\/book\?practitioner_id=3/);
  await expect(page.getByRole('heading', { name: 'Find a time that fits your life.' })).toBeVisible();
});
test("portal home allows browsing without starting staff or client authentication", async ({ page }) => {
  const requests: string[] = [];
  page.on('request', request => requests.push(request.url()));
  await fixtures(page);
  await page.goto(`${portalHost}/`);
  await expect(page.getByRole('heading', { name: 'Welcome to Test Wellness' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Explore treatments' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Meet practitioners' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose care' })).toHaveCount(0);
  expect(requests.some(url => /\/api\/v1\/availability/.test(url))).toBe(false);
  expect(requests.some(url => /\/api\/v1\/(auth\/me|customer\/auth\/me)/.test(url))).toBe(false);
});
test("service and duration booking links remain public and work in a fresh browser", async ({ page, browser }) => {
  const publicServices = [{ slug: 'massage', name: 'Massage', name_fr: 'Massothérapie', public_summary: 'Therapeutic care', public_summary_fr: 'Soin thérapeutique', description: null, description_fr: null, category_id: 3, category: 'Massage', category_fr: 'Massage', category_description: null, category_description_fr: null, durations: [{ minutes: 60, price_cents: 10000 }, { minutes: 90, price_cents: 15000 }], offers_clinic: true, offers_mobile: true }];
  const bookingServices = [{ id: 2, slug: 'massage', name: 'Massage', description: 'Therapeutic care', price_cents: 10000, durations: [{ id: 4, minutes: 60, price_cents: 10000 }, { id: 5, minutes: 90, price_cents: 15000 }] }];
  await fixtures(page);
  await page.route('**/api/v1/public/services', route => route.fulfill({ json: { data: publicServices } }));
  await page.route('**/api/v1/services', route => route.fulfill({ json: { data: bookingServices } }));
  await page.goto(portalHost);
  await expect(page.getByRole('link', { name: 'Book 60 minutes of Massage' })).toHaveAttribute('href', '/services/massage/book?duration=60');
  await expect(page.getByRole('link', { name: 'Book 90 minutes of Massage' })).toHaveAttribute('href', '/services/massage/book?duration=90');
  await page.getByRole('link', { name: 'Book 90 minutes of Massage' }).click();
  await expect(page).toHaveURL(`${portalHost}/services/massage/book?duration=90`);
  await expect(page.getByRole('combobox', { name: 'Appointment length' })).toContainText('90 min — $150.00');
  await expect(page.getByRole('button', { name: 'Share booking link' })).toBeVisible();
  await page.getByRole('button', { name: 'Copy link' }).click();
  await expect(page.getByRole('status').filter({ hasText: /Booking link copied|Select and copy this booking link/ })).toBeVisible();
  await page.getByRole('combobox', { name: 'Appointment length' }).click();
  await page.getByRole('option', { name: '60 min — $100.00' }).click();
  await expect(page).toHaveURL(`${portalHost}/services/massage/book?duration=60`);

  const fresh = await browser.newContext();
  try {
    const visitor = await fresh.newPage();
    const authRequests: string[] = [];
    visitor.on('request', request => { if (/\/api\/v1\/(auth\/me|customer\/auth\/me)/.test(request.url())) authRequests.push(request.url()); });
    await fixtures(visitor);
    await visitor.route('**/api/v1/services', route => route.fulfill({ json: { data: bookingServices } }));
    await visitor.goto(`${portalHost}/services/massage/book?duration=90`);
    await expect(visitor.getByRole('combobox', { name: 'Appointment length' })).toContainText('90 min — $150.00');
    expect(authRequests).toEqual([]);
  } finally { await fresh.close(); }
});
test("an unavailable service link never silently switches to another treatment", async ({ page }) => {
  await fixtures(page);
  await page.goto(`${portalHost}/services/unpublished-treatment/book`);
  await expect(page.getByText('This treatment is not available for online booking.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Book this time' })).toHaveCount(0);
});
test("staff authorization response at the portal root still enters the staff bootstrap", async ({ page }) => {
  await fixtures(page, ['super_admin']);
  await page.goto(`${portalHost}/#code=test-only&state=test-only`);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
});

test("guest portal can start with a practitioner and filters the service list", async ({ page }) => {
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
  await page.getByLabel("Website or social page URL", { exact: true }).fill("https://example.test/esther");
  await page.getByLabel("Show Book a session action").check();
  await page.getByLabel("Publish on the Contact page").check();
  await page.getByRole("button", { name: "Save team profile" }).click();
  await expect
    .poll(() => saved)
    .toMatchObject({
      public_website_url: "https://example.test/esther",
      slug: "esther-vanderpoel",
      section: "practitioner",
      public_name: "Esther Vanderpoel",
      booking_name: "Esther",
      published: true,
      show_booking_action: true,
    });
});

test("guest portal prepares a booking handoff without reserving or creating an appointment", async ({
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
  await page.goto(`${portalHost}/services/massage/book`);
  await page.getByLabel("Appointment date").fill("2030-10-01");
  await expect
    .poll(() => availabilityQueries.at(-1)?.searchParams.get("date_from"))
    .toBe("2030-10-01");
  expect(availabilityQueries.at(-1)?.searchParams.get("date_to")).toBe(
    "2030-10-01",
  );
  await page.getByRole('gridcell', { name: 'October 2, 2030' }).click();
  await expect(page.getByLabel("Appointment date")).toHaveValue("2030-10-02");
  await expect.poll(() => availabilityQueries.at(-1)?.searchParams.get("date_from")).toBe("2030-10-02");
  await expect(page.getByText("No available times were found on this date.")).toBeVisible();
  await page.getByRole('gridcell', { name: 'October 1, 2030' }).click();
  await expect(page.getByLabel("Appointment date")).toHaveValue("2030-10-01");
  await expect(page.getByRole('gridcell', { name: 'October 1, 2030' })).toHaveClass(/booking-calendar-selected/);
  await expect(page.getByRole('gridcell', { name: 'October 1, 2030' }).locator('.booking-calendar-date-number')).toHaveCSS('background-color', 'rgb(23, 107, 98)');
  await expect(
    page.getByText("60 min — $100.00", { exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("combobox", { name: "Appointment length" })).toContainText("60 min — $100.00");
  await expect(page.locator('button[aria-pressed]')).toHaveCount(25);
  await page.getByRole("button", { name: "9:00 a.m." }).click();
  await expect(page.getByRole("link", { name: "Book this time" })).toHaveAttribute("href", /localhost:5184\/book\?lang=en&delivery_mode=mobile&location_id=1&service_id=2&practitioner_id=3&duration_option_id=4/);
  expect(writes).toEqual([]);
});

test("guest portal requires an explicit length when a service has multiple options", async ({ page }) => {
  await fixtures(page);
  let availabilityRequests = 0;
  await page.route("**/api/v1/services", route => route.fulfill({ json: { data: [{
    id: 2, slug: "massage", name: "Massage", description: "Therapeutic care", price_cents: 10000,
    durations: [{ id: 5, minutes: 90, price_cents: 15000 }, { id: 4, minutes: 60, price_cents: 10000 }],
  }] } }));
  await page.route("**/api/v1/availability?**", route => { availabilityRequests += 1; return route.fulfill({ json: { data: { availability: [
    { duration_option_id: 4, starts_at: "2030-10-01T13:00:00Z", ends_at: "2030-10-01T14:00:00Z" },
    { duration_option_id: 5, starts_at: "2030-10-01T13:00:00Z", ends_at: "2030-10-01T14:30:00Z" },
    { duration_option_id: 4, starts_at: "2030-10-01T13:15:00Z", ends_at: "2030-10-01T14:15:00Z" },
  ] } } }); });
  await page.goto(`${portalHost}/services/massage/book`);
  await expect(page.getByText("Choose an appointment length before selecting a time.")).toBeVisible();
  await expect(page.getByLabel("Appointment date")).toBeDisabled();
  await expect(page.locator('button[aria-pressed]')).toHaveCount(0);
  expect(availabilityRequests).toBe(0);
  await page.getByRole("combobox", { name: "Appointment length" }).click();
  await expect(page.getByRole("option", { name: "60 min — $100.00" })).toBeVisible();
  await page.getByRole("option", { name: "90 min — $150.00" }).click();
  await page.getByLabel("Appointment date").fill("2030-10-01");
  await expect(page.locator('button[aria-pressed]')).toHaveCount(1);
  await page.getByRole("button", { name: "9:00 a.m." }).click();
  await expect(page.getByRole("link", { name: "Book this time" })).toHaveAttribute("href", /duration_option_id=5/);
  const requestsBeforeLengthChange = availabilityRequests;
  await page.getByRole("combobox", { name: "Appointment length" }).click();
  await page.getByRole("option", { name: "60 min — $100.00" }).click();
  await expect(page.getByRole("button", { name: "Book this time" })).toBeDisabled();
  await expect(page.locator('button[aria-pressed]')).toHaveCount(2);
  expect(availabilityRequests).toBe(requestsBeforeLengthChange);
  await page.getByRole("button", { name: "9:15 a.m." }).click();
  await expect(page.getByRole("link", { name: "Book this time" })).toHaveAttribute("href", /duration_option_id=4/);
});

test("guest portal infrastructure errors have a readable retry state", async ({
  page,
}) => {
  await fixtures(page);
  await page.route("**/api/v1/services", (route) =>
    route.fulfill({ status: 500, body: "", contentType: "text/html" }),
  );
  await page.goto(`${portalHost}/services/massage/book`);
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

test("staff access uses a selected-list ribbon and shared details, edit, and new panel", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  const people = [
    { id: 1, display_name: "Test Staff", email: "staff@example.test", status: "active", roles: ["super_admin"], permissions: [] },
    { id: 2, display_name: "Esther Test", email: "esther@example.test", status: "active", roles: ["practitioner"], permissions: [] },
  ];
  let created: Record<string, unknown> | null = null;
  let updated: Record<string, unknown> | null = null;
  await page.route("**/api/v1/admin/staff**", route => {
    const method = route.request().method();
    if (method === "POST") {
      created = route.request().postDataJSON();
      people.push({ id: 3, display_name: String(created?.display_name), email: String(created?.email), status: "active", roles: [String(created?.role)], permissions: [] });
      return route.fulfill({ json: { data: { id: 3 } } });
    }
    if (method === "PATCH") {
      updated = route.request().postDataJSON();
      Object.assign(people[1], updated);
      return route.fulfill({ json: { data: { id: 2 } } });
    }
    return route.fulfill({ json: { data: people } });
  });
  await page.goto(`${portalHost}/admin/users`);
  await expect(page.getByRole("button", { name: "Details", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Edit", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: /Esther Test/ }).click();
  await page.getByRole("button", { name: "Details", exact: true }).click();
  await expect(page.getByText("Staff details")).toBeVisible();
  await expect(page.getByText("esther@example.test")).toHaveCount(2);
  await page.getByRole("button", { name: "Edit", exact: true }).last().click();
  await page.getByRole("checkbox", { name: "Add clients and send invitations" }).check();
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Staff access updated.")).toBeVisible();
  expect(updated?.permissions).toEqual(["add_clients"]);
  await page.getByRole("button", { name: "New staff member" }).click();
  await page.getByRole("textbox", { name: "Display name" }).fill("New Staff");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("new@example.test");
  await page.getByRole("textbox", { name: "Microsoft Entra tenant ID" }).fill("11111111-1111-1111-1111-111111111111");
  await page.getByRole("textbox", { name: "Microsoft Entra user object ID" }).fill("22222222-2222-2222-2222-222222222222");
  await page.getByRole("button", { name: "Add staff member" }).click();
  await expect(page.getByText("Staff account added.")).toBeVisible();
  expect(created).toMatchObject({ email: "new@example.test", role: "reception" });
  await page.getByRole("textbox", { name: "Filter staff" }).fill("Esther");
  await expect(page.getByRole("button", { name: /Esther Test/ })).toBeVisible();
  await expect(page.getByRole("button", { name: /New Staff/ })).toHaveCount(0);
});
test("super admin saves clinic-scoped portal colours and font", async ({ page }) => {
  await fixtures(page, ['super_admin']);
  await page.route('**/api/v1/admin/catalogue-settings', route => route.fulfill({ json: { data: { categories: [], taxes: [], settings: { default_lead_time_minutes: 60, default_booking_horizon_days: 90, default_cancellation_window_minutes: 1440 } } } }));
  let saved: Record<string, string> | null = null;
  await page.route('**/api/v1/admin/clinic/portal-theme', route => { saved = route.request().postDataJSON(); return route.fulfill({ json: { data: saved } }); });
  await page.goto(`${portalHost}/admin/settings`);
  await expect(page.getByRole('heading', { name: 'Portal appearance' })).toBeVisible();
  await page.locator('input[type="color"]').first().fill('#123456');
  await page.locator('input[type="color"]').last().fill('#abcdef');
  await page.getByRole('combobox', { name: 'Portal font' }).click();
  await page.getByRole('option', { name: 'Georgia' }).click();
  await page.getByRole('button', { name: 'Save portal appearance' }).click();
  await expect.poll(() => saved).toEqual({ primary_color: '#123456', secondary_color: '#abcdef', font_family: 'Georgia' });
  await expect(page.getByText('Portal theme saved.')).toBeVisible();
});

test("clinic welcome and category translations can be edited without rebuilding", async ({page}) => {
  await fixtures(page,['super_admin']);
  await page.route('**/api/v1/admin/catalogue-settings',route=>route.fulfill({json:{data:{categories:[{id:3,name:'Massage',name_fr:null,description:null,description_fr:null}],taxes:[],settings:null}}}));
  let welcome:Record<string,string>|null=null,category:Record<string,string>|null=null;
  await page.route('**/api/v1/admin/clinic/portal-welcome',route=>{welcome=route.request().postDataJSON();return route.fulfill({json:{data:welcome}});});
  await page.route('**/api/v1/admin/service-categories/3',route=>{category=route.request().postDataJSON();return route.fulfill({json:{data:{id:3}}});});
  await page.goto(`${portalHost}/admin/settings`);
  await page.getByRole('textbox',{name:'Welcome title (English)'}).fill('Welcome to Willow');
  await page.getByRole('textbox',{name:'Welcome message (English)'}).fill('Find care that fits.');
  await page.getByRole('textbox',{name:'Welcome title (French)'}).fill('Bienvenue chez Willow');
  await page.getByRole('textbox',{name:'Welcome message (French)'}).fill('Trouvez les soins qui vous conviennent.');
  await page.getByRole('button',{name:'Save welcome message'}).click();
  await expect.poll(()=>welcome?.welcome_title_fr).toBe('Bienvenue chez Willow');
  await page.getByRole('button',{name:'Edit'}).last().click();
  await page.getByRole('textbox',{name:'Category name (French)'}).fill('Massothérapie');
  await page.getByRole('textbox',{name:'Category description (French)'}).fill('Des soins personnalisés.');
  await page.getByRole('button',{name:'Save category'}).click();
  await expect.poll(()=>category?.name_fr).toBe('Massothérapie');
});

test("guest catalogue groups multilingual treatments and links public practitioner profiles",async({page})=>{
  await fixtures(page);
  await page.route('**/api/v1/site-config',route=>route.fulfill({json:{data:{name:'Willow',welcome_title_en:'Welcome to Willow',welcome_title_fr:'Bienvenue chez Willow',welcome_body_en:'Care at your pace.',welcome_body_fr:'Des soins à votre rythme.'}}}));
  await page.route('**/api/v1/public/services',route=>route.fulfill({json:{data:[{slug:'massage',name:'Massage',name_fr:'Massage thérapeutique',public_summary:'A restorative treatment.',public_summary_fr:'Un soin revitalisant.',description:'Detailed care.',description_fr:'Soin détaillé.',category_id:3,category:'Massage Therapy',category_fr:'Massothérapie',category_description:'Hands-on treatments.',category_description_fr:'Soins manuels.',durations:[{minutes:90,price_cents:16000},{minutes:60,price_cents:12000}],offers_clinic:true,offers_mobile:false}]}}));
  await page.route('**/api/v1/public/practitioners',route=>route.fulfill({json:{data:[{slug:'esther',public_name:'Esther',booking_name:'Esther',public_title:'Massage therapist',public_title_fr:'Massothérapeute',summary:'Experienced practitioner.',summary_fr:'Praticienne expérimentée.',discipline:null,credentials:null,has_image:false,image_version:null,booking_practitioner_id:7,services:[{slug:'massage'}]}]}}));
  await page.route('**/api/v1/public/practitioners/esther',route=>route.fulfill({json:{data:{slug:'esther',public_name:'Esther',booking_name:'Esther',public_title:'Massage therapist',public_title_fr:'Massothérapeute',summary:'Experienced practitioner.',summary_fr:'Praticienne expérimentée.',discipline:null,credentials:null,has_image:false,image_version:null,booking_practitioner_id:7,services:[{slug:'massage',name:'Massage',name_fr:'Massage thérapeutique',public_summary:'A restorative treatment.',public_summary_fr:'Un soin revitalisant.',description:'Detailed care.',description_fr:'Soin détaillé.',category_id:3,category:'Massage Therapy',category_fr:'Massothérapie',category_description:null,category_description_fr:null,durations:[{minutes:60,price_cents:12000}],offers_clinic:true,offers_mobile:false}]}}}));
  await page.goto(portalHost);
  await expect(page.getByRole('heading',{name:'Welcome to Willow'})).toBeVisible();
  await page.screenshot({path:'test-results/portal-catalogue-desktop.png'});
  await page.getByRole('navigation',{name:'Treatment categories'}).getByRole('link',{name:'Massage Therapy'}).click();
  await expect(page).toHaveURL(/#category-3$/);
  await page.screenshot({path:'test-results/portal-catalogue-category.png'});
  await expect(page.getByText('60 min — $120.00')).toBeVisible();
  await expect(page.getByText('90 min — $160.00')).toBeVisible();
  await page.getByRole('link',{name:'Esther'}).hover();
  await expect(page.getByText('Experienced practitioner.')).toBeVisible();
  await page.getByRole('link',{name:'Esther'}).click();
  await expect(page).toHaveURL(`${portalHost}/practitioners/esther`);
  await expect(page.getByRole('heading',{name:'Esther'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Share profile'})).toBeVisible();
  await page.getByRole('button',{name:'Copy link'}).click();
  await expect(page.getByRole('status').filter({hasText:/Profile link copied|Select and copy this profile link/})).toBeVisible();
  await page.getByRole('link',{name:'Back to treatments'}).first().click();
  await expect(page).toHaveURL(/#category-3$/);
  await page.getByRole('button',{name:'Language and region'}).click();
  await page.getByText('Français (Canada)').click();
  await expect(page.getByRole('heading',{name:'Bienvenue chez Willow'})).toBeVisible();
  await expect(page.getByRole('navigation',{name:'Catégories de soins'}).getByRole('link',{name:'Massothérapie'})).toBeVisible();
  await page.setViewportSize({width:390,height:844});
  await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
});

test("practitioner stores notification preferences and verifies a separate personal email", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  let preferences = { work_email: "staff@example.test", email_enabled: false, email_destination: "work", personal_email: null as string | null, personal_email_verified: false, mobile_phone: null as string | null, sms_requested: false, sms_delivery_active: false };
  await page.route("**/api/v1/profile/notifications**", async route => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = preferences;
    if (path.endsWith("/send-code")) data = { sent: true };
    else if (path.endsWith("/verify")) { preferences = { ...preferences, personal_email_verified: true }; data = preferences; }
    else if (route.request().method() === "PUT") {
      const body = route.request().postDataJSON();
      preferences = { ...preferences, ...body, personal_email_verified: body.personal_email === preferences.personal_email && preferences.personal_email_verified };
      data = preferences;
    }
    await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data }) });
  });
  await page.goto(`${portalHost}/practitioner/profile`);
  await expect(page.getByRole("heading", { name: "Appointment notifications" })).toBeVisible();
  await expect(page.getByLabel("Microsoft sign-in email")).toHaveValue("staff@example.test");
  await page.getByLabel("Personal notification email").fill("personal@example.test");
  await page.getByRole("button", { name: "Save notification preferences" }).click();
  await page.getByRole("button", { name: "Send verification code" }).click();
  await page.getByLabel("Eight-digit code").fill("12345678");
  await page.getByRole("button", { name: "Verify email" }).click();
  await page.getByRole("checkbox", { name: "Send appointment notices by email" }).check();
  await page.getByLabel("Send email to").click();
  await page.getByRole("option", { name: "Verified personal email" }).click();
  await page.getByLabel("Mobile number for SMS").fill("4165550123");
  await page.getByRole("checkbox", { name: "Request SMS appointment notices" }).check();
  await page.getByRole("button", { name: "Save notification preferences" }).click();
  await expect(page.getByText("Notification preferences saved.")).toBeVisible();
  await expect(page.getByText(/SMS delivery is not active yet/)).toBeVisible();
  expect(preferences).toMatchObject({ email_enabled: true, email_destination: "personal", sms_requested: true });
});

test("appointment ribbon filters, refreshes, and enables actions only for a selected future booking", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  let listRequests = 0;
  const base = { id: 10, client_name: "Future Client", service_name: "Massage", practitioner_name: "Esther Test", location_name: "Holland Landing", timezone: "America/Toronto", room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "clinic", destination_snapshot: null, travel_buffer_minutes: 0, base_price_cents: 12000, mobile_fee_cents: 0, status: "confirmed", version: 1 };
  const future = { ...base, starts_at: "2030-10-01 14:00:00", ends_at: "2030-10-01 15:00:00" };
  const past = { ...base, id: 11, client_name: "Past Client", starts_at: "2020-10-01 14:00:00", ends_at: "2020-10-01 15:00:00" };
  const canceled = { ...future, id: 12, client_name: "Canceled Client", status: "canceled_by_client" };
  await page.route("**/api/v1/appointments?**", route => {
    listRequests++;
    const params = new URL(route.request().url()).searchParams;
    return route.fulfill({ json: { data: params.get("view") === "past" ? [past] : params.get("show_canceled") === "1" ? [future, canceled] : [future] } });
  });
  let detailRequests = 0;
  await page.route("**/api/v1/appointments/10", route => {
    detailRequests++;
    return route.fulfill({ json: { data: { ...future, client_email: "future@example.test", client_phone: "4165550100", client_preferred_contact: "phone" } } });
  });
  await page.goto(`${portalHost}/admin/appointments`);
  const actions = page.getByRole("navigation", { name: "Appointment actions" });
  for (const width of [740, 390]) {
    await page.setViewportSize({ width, height: 844 });
    const controls = [
      actions.getByRole("button", { name: "Book appointment" }),
      actions.getByRole("button", { name: "More details" }),
      actions.getByRole("button", { name: "Reschedule" }),
      actions.getByRole("button", { name: "Cancel appointment" }),
      actions.getByRole("combobox", { name: "Show" }),
      actions.locator("label.MuiFormControlLabel-root"),
      actions.getByRole("button", { name: "Refresh" }),
    ];
    const boxes = await Promise.all(controls.map(control => control.boundingBox()));
    for (let first = 0; first < boxes.length; first++) {
      for (let second = first + 1; second < boxes.length; second++) {
        const a = boxes[first]!;
        const b = boxes[second]!;
        expect(a.x + a.width <= b.x + 1 || b.x + b.width <= a.x + 1 || a.y + a.height <= b.y + 1 || b.y + b.height <= a.y + 1, `Toolbar controls ${first} and ${second} overlap at ${width}px`).toBe(true);
      }
    }
  }
  await page.setViewportSize({ width: 1280, height: 720 });
  await expect(page.getByRole("checkbox", { name: "Show canceled appointments" })).not.toBeChecked();
  await expect(page.getByRole("button", { name: /Canceled Client.*Massage/ })).toHaveCount(0);
  await page.getByRole("checkbox", { name: "Show canceled appointments" }).check();
  await expect(page.getByRole("button", { name: /Canceled Client.*Massage/ })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("checkbox", { name: "Show canceled appointments" })).toBeChecked();
  await expect(page.getByRole("button", { name: /Canceled Client.*Massage/ })).toBeVisible();
  await page.getByRole("checkbox", { name: "Show canceled appointments" }).uncheck();
  await expect(page.getByRole("button", { name: /Canceled Client.*Massage/ })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "More details" })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Reschedule", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: /Future Client.*Massage/ }).click();
  await expect(page.getByRole("button", { name: "Reschedule", exact: true })).toBeEnabled();
  expect(detailRequests).toBe(0);
  await page.getByRole("button", { name: "More details" }).click();
  await expect(page.getByText("Appointment details")).toBeVisible();
  await expect(page.getByText("future@example.test")).toBeVisible();
  await expect(page.getByText("4165550100")).toBeVisible();
  await expect(page.getByRole("link", { name: "future@example.test" })).toHaveAttribute("href", "mailto:future%40example.test");
  await expect(page.getByRole("link", { name: "Call client" })).toHaveAttribute("href", "tel:4165550100");
  await expect(page.getByRole("link", { name: "Text client" })).toHaveAttribute("href", "sms:4165550100");
  await expect(page.getByText("Preferred contact", { exact: true })).toBeVisible();
  expect(detailRequests).toBe(1);
  await expect(page.getByText("Holland Landing").last()).toBeVisible();
  await page.getByRole("button", { name: "Close panel" }).click();
  await page.getByRole("combobox", { name: "Show" }).click();
  await page.getByRole("option", { name: "Past" }).click();
  await expect(page.getByRole("button", { name: /Past Client.*Massage/ })).toBeVisible();
  await page.getByRole("button", { name: /Past Client.*Massage/ }).click();
  await expect(page.getByRole("button", { name: "Reschedule", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Cancel appointment" })).toBeDisabled();
  const before = listRequests;
  await page.getByRole("button", { name: "Refresh" }).click();
  await expect.poll(() => listRequests).toBeGreaterThan(before);
});

test("clinic admin can reassign a future in-clinic appointment with a reason", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  let appointment = { id: 10, client_name: "Future Client", service_name: "Massage", practitioner_name: "Original Practitioner", location_name: "Holland Landing", timezone: "America/Toronto", room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "clinic", destination_snapshot: null, travel_buffer_minutes: 0, base_price_cents: 12000, mobile_fee_cents: 0, status: "confirmed", version: 1, starts_at: "2030-10-01 14:00:00", ends_at: "2030-10-01 15:00:00" };
  let patched: Record<string, unknown> | null = null;
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [appointment] } }));
  await page.route("**/api/v1/appointments/10/reassignment-options", route => route.fulfill({ json: { data: [{ practitioner_id: 9, practitioner_name: "New Practitioner" }] } }));
  await page.route("**/api/v1/appointments/10", route => {
    if (route.request().method() === "PATCH") {
      patched = route.request().postDataJSON();
      appointment = { ...appointment, practitioner_name: "New Practitioner", version: 2 };
    }
    return route.fulfill({ json: { data: appointment } });
  });
  await page.goto(`${portalHost}/admin/appointments`);
  const actions = page.getByRole("navigation", { name: "Appointment actions" });
  await expect(actions.getByRole("button", { name: "Change practitioner" })).toBeDisabled();
  await page.getByRole("button", { name: /Future Client.*Massage/ }).click();
  await actions.getByRole("button", { name: "Change practitioner" }).click();
  await expect(page.getByText(/same time, room, and price/)).toBeVisible();
  await page.getByRole("combobox", { name: "New practitioner" }).click();
  await page.getByRole("option", { name: "New Practitioner" }).click();
  await page.getByRole("textbox", { name: "Reason for reassignment" }).fill("Practitioner unavailable");
  await page.getByRole("button", { name: "Confirm practitioner change" }).click();
  await expect(page.getByText("Appointment #10 was assigned to a new practitioner.")).toBeVisible();
  expect(patched).toMatchObject({ action: "reassign", version: 1, practitioner_id: 9, reason: "Practitioner unavailable" });
});

test("staff can add an appointment-only logistics note with visible author and time", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  const appointment = { id: 10, client_name: "Future Client", service_name: "Massage", practitioner_name: "Test Practitioner", location_name: "Holland Landing", timezone: "America/Toronto", room_id: null, room_name: null, duration_option_id: 4, delivery_mode: "clinic", destination_snapshot: null, travel_buffer_minutes: 0, base_price_cents: 12000, mobile_fee_cents: 0, status: "confirmed", version: 1, starts_at: "2030-10-01 14:00:00", ends_at: "2030-10-01 15:00:00", client_email: "future@example.test", client_phone: "4165550100", client_preferred_contact: "email" };
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [appointment] } }));
  await page.route("**/api/v1/appointments/10?scope=practitioner", route => route.fulfill({ json: { data: appointment } }));
  const notes: { id: number; note_text: string; author_name: string; created_at: string }[] = [];
  await page.route("**/api/v1/appointments/10/logistics-notes", route => {
    if (route.request().method() === "POST") {
      const body = route.request().postDataJSON();
      notes.unshift({ id: 1, note_text: body.note, author_name: "Test Practitioner", created_at: "2030-09-30 16:00:00" });
      return route.fulfill({ status: 201, json: { data: { note: notes[0] } } });
    }
    return route.fulfill({ json: { data: { notes, truncated: false } } });
  });
  await page.goto(`${portalHost}/practitioner/schedule`);
  await page.getByRole("button", { name: /Future Client.*Massage/ }).click();
  await page.getByRole("button", { name: "More details" }).click();
  await expect(page.getByRole("heading", { name: "Appointment logistics" })).toBeVisible();
  await page.getByRole("textbox", { name: "Add logistics note" }).fill("Use side entrance; call on arrival.");
  await page.getByRole("button", { name: "Save note" }).click();
  await expect(page.getByText("Use side entrance; call on arrival.")).toBeVisible();
  await expect(page.getByText(/Test Practitioner · Sep 30/)).toBeVisible();
  expect(notes).toHaveLength(1);
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
  await page.route("**/api/v1/appointments/10/cancellation-preview", route => route.fulfill({ json: { data: {
    window_minutes: 1440, deadline: "2030-09-30T14:00:00+00:00", inside_fee_window: false,
    fee_cents: 0, appointment_total_cents: 10000, currency: "CAD",
  } } }));
  await page.route("**/api/v1/appointments/10", (route) => {
    changes.push(route.request().postDataJSON());
    return route.fulfill({ json: { data: { ...appointment, version: 3 } } });
  });
  await page.goto(`${portalHost}/staff/login`);
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
  await expect(page.getByRole("button", { name: "Reschedule", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page.getByRole("button", { name: "Reschedule", exact: true })).toBeDisabled();
  await page.getByRole("button", { name: /Existing Client.*Massage/ }).click();
  await expect(page.getByRole("button", { name: "More details" })).toBeEnabled();
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

test("permitted practitioner adds a booking client and emails a portal invitation without identity approval", async ({ page }) => {
  await fixtures(page, ["practitioner"], ["add_clients"]);
  const created: Record<string, unknown>[] = [];
  const invitations: Record<string, unknown>[] = [];
  await page.route("**/api/v1/appointments?**", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/booking-options?**", route => route.fulfill({ json: { data: { rooms: [], default_location_id: 1, combinations: [{
    location_id: 1, location_name: "Mobile area", timezone: "America/Toronto", service_id: 2, service_name: "Massage", requires_room: 0,
    offers_mobile: 1, offers_clinic: 0, travel_buffer_minutes: 30, mobile_fee_cents: 0, base_price_cents: 10000,
    practitioner_id: 3, practitioner_name: "Test Practitioner", duration_option_id: 4, duration_minutes: 60,
  }] } } }));
  await page.route("**/api/v1/clients", route => {
    created.push(route.request().postDataJSON());
    return route.fulfill({ json: { data: { id: 42, display_name: "New Client", email: "new-client@example.test", phone: "9055550100" } } });
  });
  await page.route("**/api/v1/clients/42/invitations", route => {
    invitations.push(route.request().postDataJSON());
    return route.fulfill({ json: { data: { id: 7, token: "a".repeat(64), delivery: "email_accepted" } } });
  });
  page.on("dialog", dialog => void dialog.accept());
  await page.goto(`${portalHost}/practitioner/schedule`);
  await page.getByRole("button", { name: "Book appointment", exact: true }).click();
  await expect(page.getByRole("button", { name: "Add new client" })).toBeVisible();
  await page.getByRole("button", { name: "Add new client" }).click();
  await page.getByRole("textbox", { name: "First name" }).fill("New");
  await page.getByRole("textbox", { name: "Last name" }).fill("Client");
  await page.getByRole("textbox", { name: "Email", exact: true }).fill("new-client@example.test");
  await page.getByRole("button", { name: "Save new client" }).click();
  await expect(page.getByText("Selected client")).toBeVisible();
  await page.getByRole("button", { name: "Send portal invitation email" }).click();
  await expect(page.getByText(/mail provider accepted the invitation/i)).toBeVisible();
  expect(created).toMatchObject([{ given_name: "New", family_name: "Client", email: "new-client@example.test", preferred_contact: "email" }]);
  expect(invitations).toEqual([{ delivery: "email" }]);
  await expect(page.getByRole("link", { name: /My clients/ })).toBeVisible();
  await expect(page.getByRole("button", { name: "Approve client link" })).toHaveCount(0);
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
  await page.goto(`${portalHost}/staff/login`);
  await expect(page).toHaveURL(`${portalHost}/admin`);
  await page.getByRole("link", { name: "Practitioner", exact: true }).click();
  await expect(page).toHaveURL(`${portalHost}/practitioner`);
  await page.goto(`${portalHost}/staff/login`);
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
  await page.goto(`${portalHost}/staff/login`);
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
  await expect(page.getByRole('heading', { name: 'About our centre', level: 2 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Welcome, new clients', level: 2 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Frequently asked questions', level: 2 })).toBeVisible();
  await page.screenshot({ path: "../.tmp/public-home.png", fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: "../.tmp/public-home-mobile.png", fullPage: true });
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
test("availability is practitioner-first with selectable hours, changes, and time off", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  const rules = [{ id: "31", practitioner_id: "8", location_id: "1", practitioner_name: "Esther Vanderpoel", location_name: "Holland Landing", weekday: "1", start_time: "09:00:00", end_time: "17:00:00", valid_from: "2026-09-01", valid_until: null, recurrence_interval_weeks: "1", active: "1" }];
  const exceptions = [
    { id: "41", practitioner_id: "8", location_id: "1", practitioner_name: "Esther Vanderpoel", location_name: "Holland Landing", starts_at: "2026-09-28 13:00:00", ends_at: "2026-09-28 14:00:00", type: "blocked", reason: "Appointment", kind: "override" },
    { id: "42", practitioner_id: "8", location_id: "1", practitioner_name: "Esther Vanderpoel", location_name: "Holland Landing", starts_at: "2026-10-05 13:00:00", ends_at: "2026-10-05 21:00:00", type: "vacation", reason: "Away", kind: "time_off" },
  ];
  let update: Record<string, unknown> | undefined;
  await page.route("**/api/v1/admin/practitioners", route => route.fulfill({ json: { data: [{ practitioner_id: "8", display_name: "Esther Vanderpoel", preferred_name: "Esther", discipline: "Registered Massage Therapy", location_id: "1", active: "1" }] } }));
  await page.route("**/api/v1/admin/locations", route => route.fulfill({ json: { data: [{ id: "1", name: "Holland Landing", timezone: "America/Toronto" }] } }));
  await page.route("**/api/v1/admin/availability-rules**", async route => {
    if (route.request().method() === "PATCH") update = route.request().postDataJSON();
    await route.fulfill({ json: { data: route.request().method() === "GET" ? rules : { id: "31" } } });
  });
  await page.route("**/api/v1/admin/schedule-exceptions", route => route.fulfill({ json: { data: exceptions } }));

  await page.goto(`${portalHost}/admin/availability`);
  await expect(page.getByRole("button", { name: "Add hours", exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: /Esther.*1 hour rules.*1 changes.*1 time off/ }).click();
  await expect(page.getByRole("heading", { name: "Regular hours", exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Changes", exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Time off", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Details" })).toBeDisabled();
  await page.getByRole("button", { name: /Monday.*09:00–17:00/ }).click();
  await page.getByRole("button", { name: "Details" }).click();
  await expect(page.getByText("Schedule details", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Edit", exact: true }).last().click();
  await page.getByLabel("Ends").fill("18:00");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect.poll(() => update).toMatchObject({ practitioner_id: 8, location_id: 1, weekday: 1, end_time: "18:00" });
});

test("time off previews booked appointments before save and retains a follow-up list", async ({ page }) => {
  await fixtures(page, ["super_admin"]);
  const affected = { id: "71", starts_at: "2026-10-06 14:00:00", ends_at: "2026-10-06 15:00:00", status: "confirmed", client_name: "Test Client", service_name: "Massage", location_name: "Holland Landing", timezone: "America/Toronto" };
  const existing = { id: "42", practitioner_id: "8", location_id: "1", practitioner_name: "Esther Vanderpoel", location_name: "Holland Landing", starts_at: "2026-10-05 13:00:00", ends_at: "2026-10-09 21:00:00", type: "vacation", reason: null, kind: "time_off", affected_appointment_count: "1" };
  let created: Record<string, unknown> | undefined;
  await page.route("**/api/v1/admin/practitioners", route => route.fulfill({ json: { data: [{ practitioner_id: "8", display_name: "Esther Vanderpoel", location_id: "1", active: "1" }] } }));
  await page.route("**/api/v1/admin/locations", route => route.fulfill({ json: { data: [{ id: "1", name: "Holland Landing", timezone: "America/Toronto" }] } }));
  await page.route("**/api/v1/admin/availability-rules", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/admin/schedule-exceptions", route => route.fulfill({ json: { data: [existing] } }));
  await page.route("**/api/v1/admin/time-off/42/impact", route => route.fulfill({ json: { data: { appointments: [affected] } } }));
  await page.route("**/api/v1/admin/time-off/impact", route => route.fulfill({ json: { data: { appointments: [affected] } } }));
  await page.route("**/api/v1/appointments/71", route => route.fulfill({ json: { data: {
    ...affected, id: "71", delivery_mode: "clinic", destination_snapshot: null, travel_buffer_minutes: 0,
    base_price_cents: 12000, mobile_fee_cents: 0, cancellation_fee_cents: 0, currency: "CAD",
    client_id: "5", practitioner_id: "8", service_id: "2", duration_option_id: "3", room_id: null,
    practitioner_name: "Esther Vanderpoel", room_name: null, version: "1",
  } } }));
  await page.route("**/api/v1/admin/time-off", route => {
    if (route.request().method() === "POST") created = route.request().postDataJSON();
    return route.fulfill({ json: { data: { id: "43", affected_appointment_count: 1 } } });
  });
  await page.goto(`${portalHost}/admin/availability`);
  await page.getByRole("button", { name: /Esther.*1 time off/ }).click();
  await expect(page.getByRole("button", { name: /Vacation.*1 need follow-up/ })).toBeVisible();
  await page.getByRole("button", { name: /Vacation.*1 need follow-up/ }).click();
  await page.getByRole("button", { name: "Details", exact: true }).click();
  await expect(page.getByText("Test Client · Massage")).toBeVisible();
  await page.getByRole("link", { name: "Review appointment #71" }).click();
  await expect(page).toHaveURL(/\/admin\/appointments\?appointment_id=71/);
  await expect(page.getByText("Appointment details", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Reschedule" })).toBeEnabled();
  await page.goto(`${portalHost}/admin/availability`);
  await page.getByRole("button", { name: /Esther.*1 time off/ }).click();
  await page.getByRole("button", { name: "Add time off", exact: true }).first().click();
  await page.getByLabel("Starts").fill("2026-10-06T09:00");
  await page.getByLabel("Ends").fill("2026-10-06T17:00");
  await page.getByRole("button", { name: "Review affected appointments" }).click();
  await expect(page.getByText(/1 booked appointments overlap this time off/)).toBeVisible();
  expect(created).toBeUndefined();
  await page.getByRole("button", { name: "Add time off", exact: true }).last().click();
  await expect.poll(() => created).toMatchObject({ practitioner_id: 8, expected_affected_appointment_ids: [71] });
  await expect(page.getByText(/1 appointments need follow-up/)).toBeVisible();
});

test("practitioner appointment deep link respects the practitioner scope", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  let scoped = false;
  await page.route("**/api/v1/appointments/71?scope=practitioner", route => {
    scoped = true;
    return route.fulfill({ status: 404, json: { error: { code: "appointment_not_found", message: "Appointment not found." } } });
  });
  await page.goto(`${portalHost}/practitioner/schedule?appointment_id=71`);
  await expect.poll(() => scoped).toBe(true);
  await expect(page.getByRole("alert")).toContainText("HTTP 404");
  await expect(page.getByText("Appointment details", { exact: true })).toHaveCount(0);
});

test("practitioner manages only their own availability at assigned locations", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  let created: Record<string, unknown> | undefined;
  await page.route("**/api/v1/practitioner/availability-context", route => route.fulfill({ json: { data: {
    practitioners: [{ practitioner_id: "8", display_name: "Esther Vanderpoel", preferred_name: "Esther", discipline: "Registered Massage Therapist", location_id: "1", active: "1" }],
    locations: [{ id: "1", name: "Holland Landing", timezone: "America/Toronto" }],
    can_manage: true,
  } } }));
  await page.route("**/api/v1/admin/availability-rules**", async route => {
    if (route.request().method() === "POST") created = route.request().postDataJSON();
    await route.fulfill({ json: { data: route.request().method() === "GET" ? [] : { id: "51" } } });
  });
  await page.route("**/api/v1/admin/schedule-exceptions", route => route.fulfill({ json: { data: [] } }));

  await page.goto(`${portalHost}/practitioner/availability`);
  await expect(page.getByRole("heading", { name: "My availability", exact: true })).toBeVisible();
  await expect(page.getByText("Esther", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Add hours", exact: true }).click();
  await page.getByRole("button", { name: "Add hours", exact: true }).last().click();
  await expect.poll(() => created).toMatchObject({ practitioner_id: 8, location_id: 1 });
});

test("clinic-managed practitioner availability is read-only", async ({ page }) => {
  await fixtures(page, ["practitioner"]);
  await page.route("**/api/v1/practitioner/availability-context", route => route.fulfill({ json: { data: {
    practitioners: [{ practitioner_id: "8", display_name: "Esther Vanderpoel", preferred_name: "Esther", discipline: "Registered Massage Therapist", location_id: "1", active: "1" }],
    locations: [{ id: "1", name: "Holland Landing", timezone: "America/Toronto" }],
    can_manage: false,
  } } }));
  await page.route("**/api/v1/admin/availability-rules**", route => route.fulfill({ json: { data: [] } }));
  await page.route("**/api/v1/admin/schedule-exceptions", route => route.fulfill({ json: { data: [] } }));

  await page.goto(`${portalHost}/practitioner/availability`);
  await expect(page.getByRole("alert")).toContainText("Your clinic manages this schedule");
  await expect(page.getByRole("button", { name: "Add hours", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Add change", exact: true })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Add time off", exact: true })).toBeDisabled();
});

test('practitioner saves and clears their public website URL', async ({ page }) => {
  await fixtures(page, ['practitioner']);
  let card = { public_name: 'Grace Preferred', booking_name: 'Grace', summary: '', summary_fr: '', public_contact_email: '', public_contact_phone: '', public_contact_sms: false, public_website_url: '', slug: 'grace', published: true };
  await page.route('**/api/v1/profile/public-card', route => {
    if (route.request().method() === 'PUT') card = { ...card, ...route.request().postDataJSON() };
    return route.fulfill({ json: { data: card } });
  });
  await page.goto(`${portalHost}/practitioner/profile`);
  const field = page.getByRole('textbox', { name: 'Website or social page URL', exact: true });
  await field.fill('https://www.facebook.com/grace?ref=profile');
  await page.getByRole('button', { name: 'Save public card', exact: true }).click();
  await expect(page.getByText('Public card saved.', { exact: true })).toBeVisible();
  expect(card.public_website_url).toBe('https://www.facebook.com/grace?ref=profile');
  await page.reload();
  await expect(field).toHaveValue(card.public_website_url);
  await field.fill('');
  await page.getByRole('button', { name: 'Save public card', exact: true }).click();
  await expect(page.getByText('Public card saved.', { exact: true })).toBeVisible();
  expect(card.public_website_url).toBe('');
});
