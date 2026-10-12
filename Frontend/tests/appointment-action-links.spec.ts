import { expect, test, type Page } from '@playwright/test';
const host='http://localhost:5184', token='a'.repeat(64);
const appointment={id:41,starts_at:'2099-09-20 14:00:00',ends_at:'2099-09-20 15:00:00',status:'confirmed',version:3,room_id:null,room_name:null,duration_option_id:8,service:'Therapeutic Massage',practitioner:'Esther Practitioner',location:'Main clinic',timezone:'America/Toronto',delivery_mode:'mobile'};
async function fixture(page:Page,signedIn=true,linked=true){
  await page.route('**/src/customer/auth.ts',route=>route.fulfill({contentType:'application/javascript',body:`
    const account=${signedIn?"{homeAccountId:'customer',name:'Test Client'}":'null'};
    export const customerConfigured=true,customerHome='${host}/client';
    export const customerInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>account?[account]:[],setActiveAccount:()=>{}};
    export const selectCustomerAccount=()=>account;export const customerToken=async()=>'test-only-token';
    export const customerSignIn=async()=>{window.signInStarted=true;};export const customerSignOut=async()=>{};
  `}));
  await page.route('**/api/runtime-config.php',route=>route.fulfill({json:{publicWebsiteUrl:'https://public.example.test/'}}));
  await page.route('**/api/v1/site-config',route=>route.fulfill({json:{data:{name:'Test Clinic'}}}));
  await page.route('**/api/v1/customer/auth/me',route=>route.fulfill({json:{data:{authenticated:true,authentication_context:'customer',onboarding_status:linked?'linked':'not_linked',capabilities:linked?['own_appointments','book_own_appointments']:[],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}}}}));
  await page.route('**/api/v1/customer/appointments?**',route=>route.fulfill({json:{data:{items:[]}}}));
  await page.route('**/api/v1/customer/appointment-links/resolve',route=>route.fulfill({json:{data:{appointment}}}));
}
test('appointment link survives sign-in, hides the token and changes nothing until confirmation',async({page})=>{
  await fixture(page,false);let resolves=0,changes:unknown[]=[];
  await page.route('**/api/v1/customer/appointment-links/resolve',route=>{resolves++;expect(route.request().method()).toBe('POST');expect(route.request().postDataJSON()).toEqual({token});return route.fulfill({json:{data:{appointment}}});});
  await page.route('**/api/v1/customer/appointments/41/cancellation-preview',route=>route.fulfill({json:{data:{fee_cents:2500,currency:'CAD'}}}));
  await page.route('**/api/v1/customer/appointments/41',route=>{changes.push(route.request().postDataJSON());return route.fulfill({json:{data:{...appointment,status:'canceled_by_client'}}});});
  await page.goto(`${host}/client/appointment#token=${token}`);await expect(page).toHaveURL(`${host}/client`);
  await expect(page.getByText(/Sign in with the client account linked/)).toBeVisible();expect(resolves).toBe(0);expect(changes).toHaveLength(0);
  await page.getByRole('button',{name:'Sign in or create client account'}).click();expect(await page.evaluate(()=>(window as Window & {signInStarted?:boolean}).signInStarted)).toBe(true);
  await fixture(page,true);await page.reload();await expect(page.getByRole('heading',{name:'Appointment #41'})).toBeVisible();
  expect(await page.evaluate(()=>sessionStorage.getItem('wellness.customer.appointment-link.v1'))).toBeNull();expect(changes).toHaveLength(0);
  await page.getByRole('button',{name:'Cancel appointment',exact:true}).click();await expect(page.getByText(/will apply a \$25.00 cancellation fee/)).toBeVisible();expect(changes).toHaveLength(0);
  await page.getByRole('button',{name:'Confirm cancellation'}).click();await expect(page.getByText('Appointment #41 was canceled.')).toBeVisible();
  expect(changes).toEqual([{action:'cancel',version:3,reason:'',expected_cancellation_fee_cents:2500}]);
});
test('expired or wrong-account links expose no booking and can return to My appointments',async({page})=>{
  await fixture(page);await page.route('**/api/v1/customer/appointment-links/resolve',route=>route.fulfill({status:404,json:{error:{code:'appointment_link_unavailable',message:'This appointment link is unavailable. Sign in with the account linked to this booking, or open My appointments.'}}}));
  await page.goto(`${host}/client/appointment#token=${token}`);await expect(page.getByText(/This appointment link is unavailable/)).toBeVisible();
  await expect(page.getByRole('heading',{name:'Appointment #41'})).toHaveCount(0);await expect(page.getByRole('button',{name:'Confirm cancellation'})).toHaveCount(0);
  await page.getByRole('button',{name:'Back to My appointments'}).click();await expect(page.getByText('No appointments in this view.')).toBeVisible();
  expect(await page.evaluate(()=>sessionStorage.getItem('wellness.customer.appointment-link.v1'))).toBeNull();
});
test('link rescheduling still requires availability selection and handles slot conflicts',async({page})=>{
  await fixture(page);let changes=0;
  await page.route('**/api/v1/customer/appointments/41/availability?**',route=>route.fulfill({json:{data:{availability:[{duration_option_id:8,starts_at:'2099-09-21T11:00:00-04:00',ends_at:'2099-09-21T12:00:00-04:00',available_room_ids:[]}]}}}));
  await page.route('**/api/v1/customer/appointments/41',route=>{changes++;expect(route.request().postDataJSON()).toMatchObject({action:'reschedule',version:3,starts_at:'2099-09-21T11:00:00-04:00'});return route.fulfill({status:409,json:{error:{code:'schedule_conflict',message:'The requested time is no longer bookable. Refresh availability and choose another time.'}}});});
  await page.goto(`${host}/client/appointment#token=${token}`);await page.getByRole('button',{name:'Reschedule',exact:true}).click();await expect(page.getByRole('button',{name:'Confirm reschedule'})).toBeDisabled();
  await page.getByLabel('Appointment date').fill('2099-09-21');await page.getByRole('button',{name:'Find times'}).click();await page.getByRole('button',{name:/Sep.*21.*2099/i}).click();expect(changes).toBe(0);
  await page.getByRole('button',{name:'Confirm reschedule'}).click();await expect(page.getByText(/requested time is no longer bookable/)).toBeVisible();expect(changes).toBe(1);await expect(page.getByRole('button',{name:'Confirm reschedule'})).toBeDisabled();
});
test('a changed cancellation fee requires another explicit confirmation',async({page})=>{
  await fixture(page);let previews=0;const changes:Record<string,unknown>[]=[];
  await page.route('**/api/v1/customer/appointments/41/cancellation-preview',route=>route.fulfill({json:{data:{fee_cents:previews++===0?0:2500,currency:'CAD'}}}));
  await page.route('**/api/v1/customer/appointments/41',route=>{changes.push(route.request().postDataJSON());return changes.length===1?route.fulfill({status:409,json:{error:{code:'cancellation_fee_changed',message:'The cancellation fee changed. Review the updated fee before confirming.'}}}):route.fulfill({json:{data:{...appointment,status:'canceled_by_client'}}});});
  await page.goto(`${host}/client/appointment#token=${token}`);await page.getByRole('button',{name:'Cancel appointment',exact:true}).click();await expect(page.getByText('No cancellation fee applies if you cancel now.')).toBeVisible();
  await page.getByRole('button',{name:'Confirm cancellation'}).click();await expect(page.getByText(/will apply a \$25.00 cancellation fee/)).toBeVisible();expect(changes).toHaveLength(1);
  await page.getByRole('button',{name:'Confirm cancellation'}).click();await expect(page.getByText('Appointment #41 was canceled.')).toBeVisible();expect(changes.map(body=>body.expected_cancellation_fee_cents)).toEqual([0,2500]);
});
test('malformed links are cleared from the address and show a French mobile recovery state',async({page})=>{
  await fixture(page);await page.setViewportSize({width:390,height:844});await page.addInitScript(()=>localStorage.setItem('wellness.language','fr'));let resolves=0;
  await page.route('**/api/v1/customer/appointment-links/resolve',route=>{resolves++;return route.fulfill({status:500});});
  await page.goto(`${host}/client/appointment?appointment_id=999#token=not-a-token`);await expect(page).toHaveURL(`${host}/client`);
  await expect(page.getByText(/Ce lien de rendez-vous est invalide/)).toBeVisible();expect(resolves).toBe(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
});
test('an unlinked identity cannot resolve an appointment link',async({page})=>{
  await fixture(page,true,false);let resolves=0;
  await page.route('**/api/v1/customer/appointment-links/resolve',route=>{resolves++;return route.fulfill({status:500});});
  await page.goto(`${host}/client/appointment#token=${token}`);await expect(page.getByText(/Sign in with the client account linked/)).toBeVisible();await expect(page.getByRole('heading',{name:'Appointment #41'})).toHaveCount(0);expect(resolves).toBe(0);
});
test('an incomplete appointment link response shows recovery instead of mounting broken details',async({page})=>{
  await fixture(page);await page.route('**/api/v1/customer/appointment-links/resolve',route=>route.fulfill({json:{data:{appointment:{id:41,version:3,status:'confirmed'}}}}));
  await page.goto(`${host}/client/appointment#token=${token}`);await expect(page.getByText('The appointment link response is invalid.')).toBeVisible();await expect(page.getByRole('heading',{name:'Appointment #41'})).toHaveCount(0);
});
