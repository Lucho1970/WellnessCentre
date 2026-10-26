import { test, expect, type Page } from "@playwright/test";

test("global discovery settings save revisioned distances and expose conflicts", async ({
  page,
}) => {
  await authentication(page);
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({ json: { data: { id: 1 } } }),
  );
  await page.route("**/api/v1/application/clinics", (route) =>
    route.fulfill({ json: { data: { items: [] } } }),
  );
  let settings = { extra_km: 25, cap_km: 100, revision: 1 };
  await page.route("**/api/v1/application/discovery-settings", (route) => {
    if (route.request().method() === "PUT") {
      expect(route.request().postDataJSON()).toEqual({
        extra_km: 30,
        cap_km: 100,
        revision: 1,
      });
      settings = { extra_km: 30, cap_km: 100, revision: 2 };
    }
    return route.fulfill({ json: { data: settings } });
  });
  await page.goto("http://localhost:5185/admin/");
  await page
    .getByRole("tab", { name: "Location discovery settings", exact: true })
    .click();
  await expect(page.getByLabel("Extra enquiry allowance (km)")).toHaveValue(
    "25",
  );
  await page.getByLabel("Extra enquiry allowance (km)").fill("30");
  await page
    .getByRole("button", { name: "Save discovery settings", exact: true })
    .click();
  await expect(
    page.getByText("Discovery settings saved.", { exact: true }),
  ).toBeVisible();
  await page.route("**/api/v1/application/discovery-settings", (route) =>
    route.fulfill({
      status: 409,
      json: {
        error: {
          code: "settings_changed",
          message: "Discovery settings changed. Reload and try again.",
        },
      },
    }),
  );
  await page
    .getByRole("button", { name: "Save discovery settings", exact: true })
    .click();
  await expect(page.getByRole("alert")).toBeVisible();
});
test("application admin changes the practitioner enquiry recipient and reviews a saved enquiry", async ({
  page,
}) => {
  await authentication(page);
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({ json: { data: { id: 1 } } }),
  );
  await page.route("**/api/v1/application/clinics", (route) =>
    route.fulfill({ json: { data: { items: [{ id: 4, name: "Wellnexa" }] } } }),
  );
  let settings = {
    recipient_email: "info@copihue.ca",
    enabled: true,
    revision: 1,
    mail_configured: true,
  };
  let item = {
    id: 91,
    reference: "10000000-0000-4000-8000-000000000001",
    full_name: "Synthetic applicant",
    email: "applicant@example.test",
    phone: "",
    discipline: "Massage therapy",
    credentials: "Synthetic",
    service_area: "Toronto",
    message: "Interested in joining",
    status: "new",
    revision: 1,
    notification_status: "sent",
    created_at: "2026-10-18 12:00:00",
  };
  await page.route(
    "**/api/v1/application/clinics/4/practitioner-enquiries**",
    (route) => {
      if (route.request().method() === "PUT") {
        expect(route.request().postDataJSON()).toEqual({
          recipient_email: "joining@example.test",
          enabled: true,
          revision: 1,
        });
        settings = {
          ...settings,
          recipient_email: "joining@example.test",
          revision: 2,
        };
        return route.fulfill({ json: { data: settings } });
      }
      if (route.request().method() === "PATCH") {
        expect(route.request().postDataJSON()).toEqual({
          status: "contacted",
          revision: 1,
        });
        item = { ...item, status: "contacted", revision: 2 };
        return route.fulfill({ json: { data: item } });
      }
      return route.fulfill({
        json: { data: { items: [item], has_more: false, settings } },
      });
    },
  );
  await page.goto("http://localhost:5185/admin/");
  await page
    .getByRole("tab", { name: "Practitioner enquiries", exact: true })
    .click();
  const recipient = page.getByRole("textbox", {
    name: "Enquiry notification email",
    exact: true,
  });
  await expect(recipient).toHaveValue("info@copihue.ca");
  await recipient.fill("joining@example.test");
  await page
    .getByRole("button", { name: "Save enquiry settings", exact: true })
    .click();
  await expect(
    page.getByText("Enquiry changes saved.", { exact: true }),
  ).toBeVisible();
  await expect(recipient).toHaveValue("joining@example.test");
  await page.getByRole("combobox", { name: "Enquiry status" }).click();
  await page.getByRole("option", { name: "contacted", exact: true }).click();
  await expect(
    page.getByRole("combobox", { name: "Enquiry status" }),
  ).toContainText("contacted");
});
test("platform form library creates a template and approves its immutable version", async ({
  page,
}) => {
  await authentication(page);
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({ json: { data: { id: 1 } } }),
  );
  await page.route("**/api/v1/application/clinics", (route) =>
    route.fulfill({ json: { data: { items: [] } } }),
  );
  const item = {
    id: 51,
    entry_id: 7,
    name: "Global starter",
    description: "Shared reusable form",
    scope: "global",
    version: 1,
    revision: 1,
    form_type: "intake",
    status: "pending",
    review_note: "",
    can_review: true,
    can_revise: true,
    can_retire: false,
    can_use: false,
    definition: {
      instructions: "",
      instructions_fr: "",
      questions: [
        {
          id: "q1",
          label: "Example question",
          label_fr: "",
          type: "text",
          required: true,
        },
      ],
    },
  };
  let submitted = false,
    approved = false;
  await page.route("**/api/v1/application/form-library?**", (route) =>
    route.fulfill({
      json: {
        data: {
          items: submitted
            ? [
                {
                  ...item,
                  status: approved ? "approved" : "pending",
                  can_review: !approved,
                },
              ]
            : [],
          can_review: true,
          has_more: false,
          max_questions: 100,
        },
      },
    }),
  );
  await page.route("**/api/v1/application/form-library", (route) => {
    expect(route.request().postDataJSON()).toMatchObject({
      scope: "global",
      name: item.name,
      description: item.description,
    });
    submitted = true;
    return route.fulfill({ json: { data: item } });
  });
  await page.route("**/api/v1/application/form-library/51", (route) =>
    route.fulfill({ json: { data: item } }),
  );
  await page.route("**/api/v1/application/form-library/51/review", (route) => {
    expect(route.request().postDataJSON()).toEqual({
      action: "approve",
      revision: 1,
      review_note: "",
    });
    approved = true;
    return route.fulfill({ json: { data: { ...item, status: "approved" } } });
  });
  await page.goto("http://localhost:5185/admin/");
  await page.getByRole("tab", { name: "Form library", exact: true }).click();
  await page
    .getByRole("button", { name: "Create library template", exact: true })
    .click();
  const editor = page.getByRole("dialog");
  await editor
    .getByRole("textbox", { name: "Form name", exact: true })
    .fill(item.name);
  await editor
    .getByRole("textbox", { name: "Template description", exact: true })
    .fill(item.description);
  await editor
    .getByRole("textbox", { name: "Question (English)", exact: true })
    .fill("Example question");
  await editor
    .getByRole("button", { name: "Submit for approval", exact: true })
    .click();
  await expect(
    page.getByText("Template submitted for approval."),
  ).toBeVisible();
  await page.getByRole("combobox", { name: /^Library view/ }).click();
  await page
    .getByRole("option", { name: "Awaiting approval", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Preview template", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Approve template", exact: true })
    .click();
  await expect(page.getByText("Library review saved.")).toBeVisible();
  expect(approved).toBe(true);
  await page.screenshot({
    path: test.info().outputPath("global-form-library.png"),
    fullPage: true,
  });
});

async function authentication(page: Page, signedIn = true) {
  await page.route("**/src/auth/AuthProvider.tsx", (route) =>
    route.fulfill({
      contentType: "application/javascript",
      body: `
    const account=${signedIn ? "{name:'Operator'}" : "null"};
    const auth={account,configured:true,isAuthenticated:!!account,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-token'};
    export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,setActiveAccount:()=>{}};
    export const selectStaffAccount=()=>account;
    export const StaffAuthProvider=({children})=>children;
    export const useStaffAuth=()=>auth;
  `,
    }),
  );
}

async function billingFixture(page: Page) {
  await authentication(page);
  await page.route('**/api/v1/application/me', route => route.fulfill({ json: { data: { id: 1 } } }));
  await page.route('**/api/v1/application/clinics', route => route.fulfill({ json: { data: { items: [{ id: 4, name: 'Wellnexa' }, { id: 5, name: 'Other clinic' }] } } }));
  const state = {
    writes: [] as { path: string; body: Record<string, any> }[],
    denied: false, uncertain: false,
    plans: [{ id: 1, code: 'beta', display_name: 'Beta membership', scope: 'practitioner', version: 1, base_monthly_cents: null as number | null, features: { booking: true, sms_reminders: false } }, { id: 2, code: 'clinic', display_name: 'Clinic plan', scope: 'tenant', version: 1, base_monthly_cents: null as number | null, features: { booking: true } }],
    data: {
      month: '2026-10', pool: { id: 1, revision: 3, timezone: 'America/Toronto', monthly_limit_cents: 10000, member_limit_cents: 1000, max_members: 10, status: 'inactive' },
      summary: { limit_cents: 10000, spent_cents: 3000, reserved_cents: 5000, remaining_cents: 2000, over_limit_cents: 0, threshold: 80, overhead_cents: 1500, overhead_reserved_cents: 2000, unresolved_count: 1 },
      members: [{ membership_id: 7, display_name: 'Practitioner One', status: 'active', membership_status: 'active', limit_cents: 1000, spent_cents: 800, reserved_cents: 0, threshold: 80 }],
      candidates: [{ id: 7, display_name: 'Practitioner One' }, { id: 8, display_name: 'Practitioner Two' }],
      entries: [{ id: 3, reference: 'rental-estimate', membership_id: null, estimated_cents: 2000, actual_cents: null as number | null, status: 'reserved' }],
      subscriptions: [{ id: 10, version: 2, membership_id: 7 as number | null, member_name: 'Practitioner One' as string | null, plan_name: 'Beta membership', plan_version: 1, status: 'trialing', trial_ends_at: '2027-04-26 12:00:00' as string | null, trial_timezone: 'America/Toronto', resume_status: null as string | null, allows_new_activity: true }],
      events: [{ id: 1, action: 'sms.pool.configure', reason: 'Initial beta allocation', administrator: 'Operator', created_at: '2026-10-26 12:00:00' }],
    },
  };
  await page.route('**/api/v1/application/billing**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (state.denied) return route.fulfill({ status: 403, json: { error: { code: 'application_admin_required', message: 'Application administrator access is required.' } } });
    if (route.request().method() === 'POST') {
      const body = route.request().postDataJSON(); state.writes.push({ path, body });
      if (state.uncertain && state.writes.length === 1) return route.fulfill({ status: 503, json: { error: { code: 'server', message: 'Temporary service failure' } } });
      if (path.endsWith('/plans') && !state.uncertain) state.plans.unshift({ id: 3, code: body.code, display_name: body.display_name, scope: body.scope, version: 2, base_monthly_cents: body.base_monthly_cents, features: body.features });
      return route.fulfill({ json: { data: { id: 3, version: 3 } } });
    }
    return route.fulfill({ json: { data: path.endsWith('/billing') ? { plans: state.plans, features: ['booking', 'calendar', 'client_management', 'reporting', 'sms_reminders', 'dedicated_number', 'custom_email'] } : state.data } });
  });
  await page.goto('http://localhost:5185/admin/');
  await page.getByRole('tab', { name: 'Billing', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Monthly SMS budget' })).toBeVisible();
  return state;
}

test('billing dashboard separates actual costs, commitments and shared overhead with threshold alerts', async ({ page }) => {
  await billingFixture(page);
  await expect(page.getByText('Clinic SMS budget has reached 80% of its monthly limit.')).toBeVisible();
  await expect(page.getByText('Shared overhead: $15.00 confirmed; $20.00 reserved.')).toBeVisible();
  await expect(page.getByText('One submitted message awaits cost reconciliation.')).toBeVisible();
  await expect(page.getByRole('table', { name: 'Funded practitioner budgets' })).toContainText('Practitioner One');
  await expect(page.getByRole('table', { name: 'Funded practitioner budgets' })).toContainText('$8.00');
  await expect(page.getByText(/Payment collection and automatic SMS budget enforcement are not active yet/)).toBeVisible();
  await page.screenshot({ path: 'test-results/billing-desktop.png', fullPage: true, animations: 'disabled' });
});

test('billing creates a new priced plan version with explicit features and a recorded reason', async ({ page }) => {
  const state = await billingFixture(page);
  await page.getByRole('button', { name: 'Create plan version', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Plan code').fill('beta');
  await dialog.getByLabel('Plan name').fill('Beta membership');
  await dialog.getByLabel('Monthly price (CAD)', { exact: true }).fill('30.50');
  await dialog.getByLabel('SMS reminders', { exact: true }).check();
  await dialog.getByLabel('Reason for change').fill('Approved beta pricing proposal');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes).toHaveLength(1);
  expect(state.writes[0].body).toMatchObject({ code: 'beta', scope: 'practitioner', base_monthly_cents: 3050, features: { booking: false, sms_reminders: true }, reason: 'Approved beta pricing proposal' });
  expect(state.writes[0].body.request_key).toMatch(/^[a-f0-9-]{36}$/);
  await expect(page.getByText(/Beta membership · beta v1/)).toBeVisible();
  await expect(page.getByText(/Beta membership · beta v2/)).toBeVisible();
});

test('billing creates a clinic subscription independently from practitioner memberships', async ({ page }) => {
  const state = await billingFixture(page);
  await page.getByRole('button', { name: 'Create subscription', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByLabel('Subscription owner')).toContainText('Clinic subscription');
  await dialog.getByLabel('Plan version').click();
  await page.getByRole('option', { name: 'Clinic plan v1', exact: true }).click();
  await dialog.getByLabel('Reason for change').fill('Configure the Wellnexa tenant');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ membership_id: null, plan_version_id: 2, status: 'pending', timezone: 'America/Toronto' });
});

test('billing extends a trial with its current version and preserves timezone wording', async ({ page }) => {
  const state = await billingFixture(page);
  await page.getByRole('button', { name: 'Extend trial', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Last day of trial').fill('2027-05-31');
  await dialog.getByLabel('Reason for change').fill('Approved additional beta testing');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[0]).toMatchObject({ path: '/api/v1/application/billing/clinics/4/subscriptions/10', body: { version: 2, action: 'extend', trial_last_day: '2027-05-31' } });
});

test('billing budget edits retain the selected month and save next-period defaults explicitly', async ({ page }) => {
  const state = await billingFixture(page);
  await page.getByRole('button', { name: 'Configure SMS budget', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Clinic monthly limit (CAD)').fill('150.00');
  await dialog.getByLabel('Reason for change').fill('Approved budget for a new month');
  await expect(dialog.getByText(/Existing monthly limits stay unchanged/)).toBeVisible();
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ revision: 3, monthly_limit_cents: 15000, member_limit_cents: 1000, max_members: 10, status: 'inactive' });
  await expect(page.getByText('$100.00', { exact: true })).toBeVisible();
});

test('billing funds an eligible practitioner and confirms shared overhead without messaging', async ({ page }) => {
  const state = await billingFixture(page);
  await page.getByRole('button', { name: 'Fund a practitioner', exact: true }).click();
  let dialog = page.getByRole('dialog');
  await dialog.getByLabel('Practitioner').click();
  await page.getByRole('option', { name: 'Practitioner Two', exact: true }).click();
  await dialog.getByLabel('Reason for change').fill('Second funded beta participant');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ membership_id: 8, action: 'enroll', revision: 3 });
  await page.getByRole('button', { name: 'Confirm overhead cost', exact: true }).click();
  dialog = page.getByRole('dialog');
  await dialog.getByLabel('Amount (CAD)').fill('21.25');
  await dialog.getByLabel('Reason for change').fill('Actual provider statement amount');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[1].body).toMatchObject({ action: 'settle', reference: 'rental-estimate', amount_cents: 2125, month: '2026-10' });
});

test('billing uncertain saves retry the identical frozen request and disable edits', async ({ page }) => {
  const state = await billingFixture(page); state.uncertain = true;
  await page.getByRole('button', { name: 'Suspend subscription', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Reason for change').fill('Administrative trial suspension');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(dialog.getByText(/The save outcome is uncertain/)).toBeVisible();
  await expect(dialog.getByLabel('Reason for change')).toBeDisabled();
  await dialog.getByRole('button', { name: 'Retry same change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes).toHaveLength(2); expect(state.writes[1]).toEqual(state.writes[0]);
});

test('billing confirmed overhead corrections carry the previously reviewed amount', async ({ page }) => {
  const state = await billingFixture(page);
  state.data.entries.push({ id: 4, reference: 'rental-confirmed', membership_id: null, estimated_cents: 2000, actual_cents: 2500, status: 'settled' });
  await page.getByRole('button', { name: 'Reload billing data', exact: true }).click();
  await page.getByRole('button', { name: 'Correct overhead cost', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Amount (CAD)').fill('24.50');
  await dialog.getByLabel('Reason for change').fill('Corrected provider statement amount');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByText('Billing changes saved.')).toBeVisible();
  expect(state.writes[0].body).toMatchObject({ action: 'correct', amount_cents: 2450, expected_actual_cents: 2500, reference: 'rental-confirmed' });
});

test('billing stale budget versions clear the editor and require refreshed authority', async ({ page }) => {
  await billingFixture(page);
  await page.route('**/api/v1/application/billing/clinics/4/pool', route => route.fulfill({ status: 409, json: { error: { code: 'billing_version_changed', message: 'Billing settings changed or this action is unavailable. Reload before trying again.' } } }));
  await page.getByRole('button', { name: 'Configure SMS budget', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Reason for change').fill('Attempt using a stale configuration');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.getByText('Billing settings changed or this action is unavailable. Reload before trying again.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Monthly SMS budget' })).toHaveCount(0);
});

test('billing validation focuses its field and preserves the unsaved plan', async ({ page }) => {
  await billingFixture(page);
  await page.route('**/api/v1/application/billing/plans', route => route.fulfill({ status: 422, json: { error: { code: 'validation_error', message: 'Check the billing fields.', fields: { display_name: 'Enter a valid value.' } } } }));
  await page.getByRole('button', { name: 'Create plan version', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Plan code').fill('beta');
  await dialog.getByLabel('Plan name').fill('Retain this draft');
  await dialog.getByLabel('Reason for change').fill('Reason retained after validation');
  await dialog.getByRole('button', { name: 'Save billing change' }).click();
  await expect(dialog.getByLabel('Plan name')).toBeFocused();
  await expect(dialog.getByLabel('Plan name')).toHaveValue('Retain this draft');
  await expect(dialog.getByLabel('Reason for change')).toHaveValue('Reason retained after validation');
});

test('billing clinic switching hides old financial details while the next clinic loads', async ({ page }) => {
  const state = await billingFixture(page);
  let release!: () => void;
  let requested!: () => void;
  const started = new Promise<void>(resolve => { requested = resolve; });
  const held = new Promise<void>(resolve => { release = resolve; });
  await page.route('**/api/v1/application/billing/clinics/5', async route => {
    requested(); await held;
    await route.fulfill({ json: { data: { ...state.data, members: [], candidates: [], subscriptions: [], entries: [], events: [] } } });
  });
  await page.getByLabel('Clinic').click();
  await page.getByRole('option', { name: 'Other clinic', exact: true }).click();
  await started;
  await expect(page.getByText('Practitioner One', { exact: true })).toHaveCount(0);
  release();
  await expect(page.getByRole('heading', { name: 'Monthly SMS budget' })).toBeVisible();
  await expect(page.getByText('Practitioner One', { exact: true })).toHaveCount(0);
});

test('billing revocation clears previously visible financial details and blocks further edits', async ({ page }) => {
  const state = await billingFixture(page); state.denied = true;
  await page.getByRole('button', { name: 'Reload billing data', exact: true }).click();
  await expect(page.getByRole('alert', { }).filter({ hasText: /administrator|access|permission/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Monthly SMS budget' })).toHaveCount(0);
  await expect(page.getByText('Practitioner One', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Create subscription', exact: true })).toHaveCount(0);
});

test('billing French mobile dashboard and editor fit the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await billingFixture(page);
  await page.getByRole('button', { name: 'FR', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Budget SMS mensuel' })).toBeVisible();
  await expect(page.getByText('Le budget SMS de la clinique a atteint 80 % de son plafond mensuel.')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  await page.screenshot({ path: 'test-results/billing-french-dashboard.png', fullPage: true });
  await page.getByRole('button', { name: 'Configurer le budget SMS', exact: true }).click();
  await expect(page.getByRole('dialog')).toContainText('Plafond mensuel de la clinique (CAD)');
  await page.screenshot({ path: 'test-results/billing-french-mobile.png', animations: 'disabled' });
  await page.getByRole('dialog').getByRole('button', { name: 'Fermer', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', { name: 'Créer une version de forfait', exact: true }).click();
  await expect(page.getByRole('dialog').getByLabel('Calendrier')).toBeVisible();
  await expect(page.getByRole('dialog').getByLabel('Gestion des clients')).toBeVisible();
  await expect(page.getByRole('dialog').getByLabel('Rapports')).toBeVisible();
});

test("central directory imports an existing practitioner and assigns a second clinic without credentials", async ({
  page,
}) => {
  await authentication(page);
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({ json: { data: { id: 1 } } }),
  );
  const people: {
    id: number;
    display_name: string;
    email: string;
    clinics: {
      clinic_id: number;
      clinic_name: string;
      status: string;
      version: number;
    }[];
  }[] = [];
  await page.route("**/api/v1/application/clinics", (route) =>
    route.fulfill({
      json: {
        data: {
          items: [
            {
              id: 1,
              name: "Livin Lively",
              status: "active",
              portal_host: "livin.test",
              location_count: 1,
            },
            {
              id: 2,
              name: "Wellnexa",
              status: "active",
              portal_host: "wellnexa.test",
              location_count: 1,
            },
          ],
        },
      },
    }),
  );
  await page.route("**/api/v1/application/practitioners", (route) =>
    route.fulfill({ json: { data: { items: people } } }),
  );
  await page.route(
    "**/api/v1/application/practitioners/import-candidates",
    (route) =>
      route.fulfill({
        json: {
          data: {
            items: people.length
              ? []
              : [
                  {
                    practitioner_id: 7,
                    display_name: "Dual Practitioner",
                    email: "person@example.test",
                    clinic_name: "Livin Lively",
                  },
                ],
          },
        },
      }),
  );
  await page.route("**/api/v1/application/practitioners/import", (route) => {
    expect(route.request().postDataJSON()).toEqual({ practitioner_id: 7 });
    people.push({
      id: 9,
      display_name: "Dual Practitioner",
      email: "person@example.test",
      clinics: [
        {
          clinic_id: 1,
          clinic_name: "Livin Lively",
          status: "active",
          version: 1,
        },
      ],
    });
    return route.fulfill({ json: { data: { id: 9 } } });
  });
  await page.route("**/api/v1/application/clinics/2/locations", (route) =>
    route.fulfill({
      json: { data: { items: [{ id: 21, name: "Virtual Clinic" }] } },
    }),
  );
  await page.route("**/api/v1/application/practitioners/9/clinics", (route) => {
    expect(route.request().postDataJSON()).toEqual({
      clinic_id: 2,
      location_id: 21,
    });
    people[0].clinics.push({
      clinic_id: 2,
      clinic_name: "Wellnexa",
      status: "active",
      version: 1,
    });
    return route.fulfill({ json: { data: { practitioner_id: 17 } } });
  });
  await page.goto("http://localhost:5185/admin/");
  await page.getByRole("tab", { name: "Global practitioners" }).click();
  await page
    .getByRole("combobox", { name: "Existing clinic practitioner" })
    .click();
  await page.getByRole("option", { name: /Dual Practitioner/ }).click();
  await page
    .getByRole("button", { name: "Import practitioner", exact: true })
    .click();
  await expect(
    page.getByText(
      "Practitioner imported. Existing clinic records are preserved.",
    ),
  ).toBeVisible();
  await page.getByRole("button", { name: "Assign a clinic" }).click();
  await page.getByRole("combobox", { name: "Clinic", exact: true }).click();
  await expect(page.getByRole("option", { name: "Livin Lively" })).toHaveCount(
    0,
  );
  await page.getByRole("option", { name: "Wellnexa" }).click();
  await page.getByRole("combobox", { name: "Location", exact: true }).click();
  await page.getByRole("option", { name: "Virtual Clinic" }).click();
  await page
    .getByRole("button", { name: "Assign practitioner", exact: true })
    .click();
  await expect(
    page.getByText(
      "Clinic assigned. Credentials remain private until the practitioner shares them.",
    ),
  ).toBeVisible();
  await expect(page.getByText("Wellnexa · Assigned")).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
});

test("anonymous administration exposes sign-in but no clinic records", async ({
  page,
}) => {
  await authentication(page, false);
  let requests = 0;
  await page.route("**/api/v1/**", (route) => {
    requests++;
    return route.fulfill({ status: 401 });
  });
  await page.goto("http://localhost:5185/admin/");
  await expect(
    page.getByRole("button", { name: "Sign in with Microsoft" }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Create clinic" })).toHaveCount(
    0,
  );
  expect(requests).toBe(0);
});

test("signed-in clinic administrator without a global grant sees access denial", async ({
  page,
}) => {
  await authentication(page);
  let clinicRequests = 0;
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({
      status: 403,
      json: { error: { code: "application_admin_required" } },
    }),
  );
  await page.route("**/api/v1/application/clinics", (route) => {
    clinicRequests++;
    return route.fulfill({ json: { data: { items: [] } } });
  });
  await page.goto("http://localhost:5185/admin/");
  await expect(
    page.getByText(
      "Application administration access could not be confirmed. Contact the application owner or try again.",
    ),
  ).toBeVisible();
  expect(clinicRequests).toBe(0);
});

test("application administrator creates clinics with field validation and edits any clinic registration", async ({
  page,
}) => {
  await authentication(page);
  await page.route("**/api/v1/application/me", (route) =>
    route.fulfill({ json: { data: { id: 1, roles: ["application_admin"] } } }),
  );
  const items = [
    {
      id: 1,
      name: "Livin Lively",
      portal_host: "livinlively.copihue.ca",
      location_count: 1,
    },
  ];
  let invalid = true;
  let created: Record<string, unknown> | null = null;
  await page.route("**/api/v1/application/clinics", (route) => {
    if (route.request().method() === "POST") {
      created = route.request().postDataJSON();
      if (invalid)
        return route.fulfill({
          status: 422,
          json: {
            error: {
              code: "validation_error",
              fields: { portal_host: "Enter a hostname without a path." },
            },
          },
        });
      items.push({
        id: 2,
        name: String(created!.name),
        portal_host: String(created!.portal_host),
        location_count: 1,
      });
      return route.fulfill({ json: { data: { id: 2 } } });
    }
    return route.fulfill({ json: { data: { items } } });
  });
  await page.route("**/api/v1/application/clinics/1", (route) => {
    expect(route.request().method()).toBe("PATCH");
    expect(route.request().postDataJSON().portal_host).toBe(
      "new-livin.copihue.ca",
    );
    return route.fulfill({ json: { data: { id: 1 } } });
  });
  await page.goto("http://localhost:5185/admin/");
  await page
    .getByRole("button", { name: "Create clinic", exact: true })
    .click();
  await page
    .getByRole("textbox", { name: "Clinic name", exact: true })
    .fill("Willow Wellness Virtual Clinic");
  await page
    .getByRole("textbox", { name: "Portal hostname", exact: true })
    .fill("https://livinlively.copihue.ca");
  await page.getByLabel("First location name").fill("Virtual");
  await page
    .getByRole("button", { name: "Create clinic", exact: true })
    .click();
  await expect(
    page.getByRole("textbox", { name: "Portal hostname", exact: true }),
  ).toBeFocused();
  await expect(
    page.getByRole("textbox", { name: "Portal hostname", exact: true }),
  ).toHaveAccessibleDescription(/Enter a hostname without a path/);
  invalid = false;
  await page
    .getByRole("textbox", { name: "Portal hostname", exact: true })
    .fill("livinlively.copihue.ca");
  await page
    .getByRole("button", { name: "Create clinic", exact: true })
    .click();
  await expect(
    page.getByRole("link", { name: "Open clinic portal" }).last(),
  ).toHaveAttribute("href", "https://livinlively.copihue.ca/staff/login");
  await page.getByRole("button", { name: "Edit clinic" }).first().click();
  await expect(page.getByLabel("First location name")).toHaveCount(0);
  await page
    .getByRole("textbox", { name: "Portal hostname", exact: true })
    .fill("new-livin.copihue.ca");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Clinic settings saved.")).toBeVisible();
});

test("central sign-in uses the workforce scope and returns to the admin path despite an external-provider hint", async ({
  page,
}) => {
  await page.addInitScript(() =>
    sessionStorage.setItem("wellness.staff.provider", "external"),
  );
  await page.route("https://login.microsoftonline.com/**", async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/authorize"))
      return route.fulfill({
        body: "Synthetic authorization request captured",
        contentType: "text/plain",
      });
    return route.continue();
  });
  // Use the same discovery response as the clinic workforce integration fixture.
  await page.route("**/v2.0/.well-known/openid-configuration", (route) =>
    route.fulfill({
      json: {
        authorization_endpoint:
          "https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/authorize",
        token_endpoint:
          "https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/token",
        end_session_endpoint:
          "https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/logout",
        issuer:
          "https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0",
        jwks_uri:
          "https://login.microsoftonline.com/common/discovery/v2.0/keys",
      },
    }),
  );
  await page.route("**/discovery/instance?**", (route) =>
    route.fulfill({
      json: {
        tenant_discovery_endpoint:
          "https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0/.well-known/openid-configuration",
        metadata: [
          {
            preferred_network: "login.microsoftonline.com",
            preferred_cache: "login.windows.net",
            aliases: ["login.microsoftonline.com", "login.windows.net"],
          },
        ],
      },
    }),
  );
  await page.goto("http://localhost:5185/admin/");
  await page.getByRole("button", { name: "Sign in with Microsoft" }).click();
  await page.waitForURL("https://login.microsoftonline.com/**");
  const target = new URL(page.url());
  expect(target.searchParams.get("redirect_uri")).toBe(
    "http://localhost:5185/admin/",
  );
  expect(target.searchParams.get("scope")).toContain(
    "api://33333333-3333-3333-3333-333333333333/access_as_user",
  );
  expect(target.searchParams.get("code_challenge_method")).toBe("S256");
});
