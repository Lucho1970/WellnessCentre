import { expect, test, type Page } from '@playwright/test';
const host='http://localhost:5184';
const combination={recurrence_allowed:1,location_id:2,location_name:'Main clinic',timezone:'America/Toronto',service_id:4,service_name:'Massage',requires_room:0,offers_mobile:0,offers_clinic:1,travel_buffer_minutes:0,mobile_fee_cents:0,base_price_cents:10000,practitioner_id:7,practitioner_name:'Esther',duration_option_id:8,duration_minutes:60};
const appointment={recurring_series_id:17,id:41,starts_at:'2099-10-01 14:00:00',ends_at:'2099-10-01 15:00:00',status:'confirmed',version:1,room_id:null,room_name:null,duration_option_id:8,service:'Massage',practitioner:'Esther',location:'Main clinic',timezone:'America/Toronto',delivery_mode:'clinic'};
const items=[{appointment_id:41,duration_option_id:8,version:1,starts_at:'2099-10-01 14:00:00',room_id:null,cancellation_fee_cents:2500},{appointment_id:42,duration_option_id:8,version:1,starts_at:'2099-10-08 14:00:00',room_id:null,cancellation_fee_cents:0}];
const preview={series_id:null,timezone:'America/Toronto',ready:true,applied:false,preview_token:'review-proof',items:[{starts_at:'2099-10-01T10:00:00-04:00',ok:true,subtotal_cents:10000},{starts_at:'2099-10-08T10:00:00-04:00',ok:true,subtotal_cents:10000}]};
async function fixture(page:Page,staff=false){
  await page.route('**/src/customer/auth.ts',route=>route.fulfill({contentType:'application/javascript',body:`const account={homeAccountId:'customer',name:'Test Client'}; export const customerConfigured=true,customerHome='${host}/client';export const customerInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};export const selectCustomerAccount=()=>account;export const customerToken=async()=>'test-token';export const customerSignIn=async()=>{};export const customerSignOut=async()=>{};`}));
  if(staff)await page.route('**/src/auth/AuthProvider.tsx',route=>route.fulfill({contentType:'application/javascript',body:`const account={homeAccountId:'staff',name:'Test Staff',username:'staff@example.test'};export const msalInstance={initialize:async()=>{},handleRedirectPromise:async()=>null,getActiveAccount:()=>account,getAllAccounts:()=>[account],setActiveAccount:()=>{}};export const StaffAuthProvider=({children})=>children;export const selectStaffAccount=()=>account;const auth={account,configured:true,isAuthenticated:true,signIn:async()=>{},signOut:async()=>{},getAccessToken:async()=>'test-token'};export const useStaffAuth=()=>auth;`}));
  await page.route('**/api/runtime-config.php',route=>route.fulfill({json:{publicWebsiteUrl:'https://public.example.test/'}}));
  await page.route('**/api/v1/**',route=>{
    const path=new URL(route.request().url()).pathname.replace('/api/v1','');let data:unknown=[];
    if(path==='/site-config')data={name:'Test clinic'};
    if(path==='/auth/me')data={roles:['super_admin'],permissions:[]};
    if(path==='/profile/avatar')data={image_base64:null};
    if(path==='/customer/auth/me')data={authenticated:true,authentication_context:'customer',onboarding_status:'linked',capabilities:['own_appointments','book_own_appointments'],session:{idle_expires_at:Date.now()/1000+1800,absolute_expires_at:Date.now()/1000+28800}};
    if(path==='/customer/profile')data={address:null};
    if(path.endsWith('/booking-options'))data={default_location_id:2,combinations:[combination],rooms:[]};
    if(path==='/booking-clients')data={items:[{id:8,display_name:'Test Client',email:'client@example.test',phone:''}],has_more:false};
    if(path.endsWith('/availability'))data={availability:[{duration_option_id:8,starts_at:'2099-10-01T10:00:00-04:00',ends_at:'2099-10-01T11:00:00-04:00',available_room_ids:[]}]};
    if(path==='/customer/appointments')data={items:[]};
    return route.fulfill({json:{data}});
  });
}
async function select(page:Page,label:string,option:string|RegExp){await page.getByRole('combobox',{name:new RegExp('^'+label)}).click();await page.getByRole('option',{name:option,exact:true}).click();}
async function bookingReview(page:Page,staff=false){
  await page.goto(`${host}/${staff?'admin/appointments':'client'}`);await page.getByRole('button',{name:'Book appointment',exact:true}).click();
  if(staff){await page.getByLabel('Find an active client').fill('Test');await page.getByRole('button',{name:'Select Test Client',exact:true}).click();}
  await select(page,'Visit type','In clinic');await select(page,'Service','Massage');await select(page,'Practitioner','Esther');await select(page,'Duration',/60 minutes/);
  await page.getByRole('button',{name:'Find a time',exact:true}).click();await page.getByLabel('Appointment date').fill('2099-10-01');await page.getByRole('button',{name:'Find times',exact:true}).click();await page.getByRole('button',{name:/Oct.*1.*2099/}).click();await page.getByRole('button',{name:'Review appointment'}).click();
  await select(page,'Repeat appointment','Weekly');await page.getByLabel('Number of appointments (including the first)').fill('2');
}
test('client previews dates and confirms an entire series explicitly',async({page})=>{
  await fixture(page);let saves=0;let reviewed:Record<string,unknown>;
  await page.route('**/api/v1/customer/recurring-series/preview',route=>{reviewed=route.request().postDataJSON();expect(reviewed.client_id).toBeUndefined();expect(reviewed.recurrence).toEqual({frequency:'weekly',count:2});return route.fulfill({json:{data:preview}});});
  await page.route('**/api/v1/customer/recurring-series',route=>{saves++;expect(route.request().postDataJSON()).toEqual({...reviewed,preview_token:'review-proof'});return route.fulfill({json:{data:{...preview,applied:true,series_id:17,items:preview.items.map((item,index)=>({...item,appointment_id:41+index}))}}});});
  await bookingReview(page);await page.getByRole('button',{name:'Preview all dates'}).click();await expect(page.getByText(/All dates passed validation/)).toBeVisible();expect(saves).toBe(0);
  await page.getByRole('button',{name:'Confirm entire series'}).click();await expect(page.getByText('All appointments in this review were saved.')).toBeVisible();expect(saves).toBe(1);
  await page.getByRole('button',{name:'Done',exact:true}).click();await expect(page.getByText('Recurring series #17 confirmed with 2 appointments.')).toBeVisible();
});
test('a conflicting date blocks confirmation and editing the pattern clears its preview',async({page})=>{
  await fixture(page);let saves=0;
  await page.route('**/api/v1/customer/recurring-series/preview',route=>route.fulfill({json:{data:{...preview,ready:false,items:[preview.items[0],{...preview.items[1],ok:false,message:'The practitioner is unavailable.'}]}}}));
  await page.route('**/api/v1/customer/recurring-series',route=>{saves++;return route.fulfill({status:500});});
  await bookingReview(page);await page.getByRole('button',{name:'Preview all dates'}).click();await expect(page.getByText('The practitioner is unavailable.')).toBeVisible();await expect(page.getByRole('button',{name:'Confirm entire series'})).toHaveCount(0);expect(saves).toBe(0);
  await select(page,'Series ends','On an end date');await expect(page.getByText('The practitioner is unavailable.')).toHaveCount(0);await expect(page.getByLabel('Series end date')).toBeVisible();
});
test('an ambiguous confirmation retries the same key and locks edits',async({page})=>{
  await fixture(page);const requests:Record<string,unknown>[]=[];
  await page.route('**/api/v1/customer/recurring-series/preview',route=>route.fulfill({json:{data:preview}}));
  await page.route('**/api/v1/customer/recurring-series',route=>{requests.push(route.request().postDataJSON());return requests.length===1?route.fulfill({status:503,json:{error:{code:'service_unavailable',message:'Try again'}}}):route.fulfill({json:{data:{...preview,applied:true,series_id:17,items:preview.items.map((item,index)=>({...item,appointment_id:41+index}))}}});});
  await bookingReview(page);await page.getByRole('button',{name:'Preview all dates'}).click();await page.getByRole('button',{name:'Confirm entire series'}).click();await expect(page.getByRole('combobox',{name:/^Repeat appointment/})).toBeDisabled();await expect(page.getByRole('button',{name:'Back',exact:true})).toBeDisabled();
  await page.getByRole('button',{name:'Retry series confirmation'}).click();await expect(page.getByText('All appointments in this review were saved.')).toBeVisible();expect(requests).toHaveLength(2);expect(requests[0]).toEqual(requests[1]);
});
async function seriesManager(page:Page){
  await fixture(page);await page.route('**/api/v1/customer/appointments?**',route=>route.fulfill({json:{data:{items:[appointment]}}}));await page.route('**/api/v1/customer/recurring-series/17',route=>route.fulfill({json:{data:{series_id:17,timezone:'America/Toronto',items}}}));
  await page.goto(`${host}/client`);await page.getByRole('button',{name:'View or change'}).click();await expect(page.getByText(/Ordinary reschedule and cancel actions change only this appointment/)).toBeVisible();
}
test('ordinary cancellation changes only one occurrence',async({page})=>{
  await seriesManager(page);let changes=0;
  await page.route('**/api/v1/customer/appointments/41/cancellation-preview',route=>route.fulfill({json:{data:{fee_cents:2500}}}));
  await page.route('**/api/v1/customer/appointments/41',route=>{changes++;expect(route.request().postDataJSON()).toMatchObject({action:'cancel',version:1,expected_cancellation_fee_cents:2500});return route.fulfill({json:{data:{}}});});
  await page.getByRole('button',{name:'Cancel appointment',exact:true}).click();await page.getByRole('button',{name:'Confirm cancellation'}).click();await expect(page.getByText('Appointment #41 was canceled.')).toBeVisible();expect(changes).toBe(1);
});
test('series cancellation reviews every version and fee before one confirmation',async({page})=>{
  await seriesManager(page);let saves=0;
  await page.route('**/api/v1/customer/recurring-series/17/preview',route=>{expect(route.request().postDataJSON().items).toEqual(items.map(item=>({appointment_id:item.appointment_id,version:1,expected_cancellation_fee_cents:item.cancellation_fee_cents})));return route.fulfill({json:{data:{...preview,series_id:17,items:items.map(item=>({...item,ok:true,fee_cents:item.cancellation_fee_cents}))}}});});
  await page.route('**/api/v1/customer/recurring-series/17',route=>{if(route.request().method()==='GET')return route.fulfill({json:{data:{series_id:17,timezone:'America/Toronto',items}}});saves++;return route.fulfill({json:{data:{...preview,series_id:17,applied:true,items:items.map(item=>({...item,ok:true,fee_cents:item.cancellation_fee_cents}))}}});});
  await page.getByRole('button',{name:'Manage future series'}).click();await expect(page.getByText('Cancellation fee: $25.00')).toBeVisible();await page.getByRole('button',{name:'Preview series changes'}).click();expect(saves).toBe(0);
  await page.getByRole('button',{name:'Confirm entire series'}).click();await page.getByRole('button',{name:'Done',exact:true}).click();await expect(page.getByText('All 2 future appointments in series #17 were updated.')).toBeVisible();
});
test('administrator uses the same preview with the selected client',async({page})=>{
  await fixture(page,true);let previewed=0;
  await page.route('**/api/v1/recurring-series/preview',route=>{previewed++;expect(route.request().postDataJSON()).toMatchObject({client_id:8,recurrence:{frequency:'weekly',count:2}});return route.fulfill({json:{data:preview}});});
  await bookingReview(page,true);await page.getByRole('button',{name:'Preview all dates'}).click();await expect(page.getByText(/All dates passed validation/)).toBeVisible();expect(previewed).toBe(1);
});
test('series rescheduling requires a new available time for each occurrence',async({page})=>{
  await seriesManager(page);let changes:Record<string,unknown>|null=null;
  for(const [index,item] of items.entries())await page.route(`**/api/v1/customer/appointments/${item.appointment_id}/availability?**`,route=>route.fulfill({json:{data:{availability:[{duration_option_id:99,starts_at:'2099-10-02T09:00:00-04:00',available_room_ids:[]},{duration_option_id:8,starts_at:`2099-10-${index===0?'02':'09'}T11:00:00-04:00`,available_room_ids:[]}]}}}));
  await page.route('**/api/v1/customer/recurring-series/17/preview',route=>{changes=route.request().postDataJSON();return route.fulfill({json:{data:{...preview,series_id:17,items:items.map((item,index)=>({appointment_id:item.appointment_id,starts_at:`2099-10-${index===0?'02':'09'}T11:00:00-04:00`,ok:true}))}}});});
  await page.getByRole('button',{name:'Manage future series'}).click();await select(page,'Series action','Reschedule all listed appointments');await expect(page.getByRole('button',{name:'Preview series changes'})).toBeDisabled();
  for(const index of [0,1]){await page.getByLabel('Appointment date').nth(index).fill(`2099-10-${index===0?'02':'09'}`);await page.getByRole('button',{name:'Find times'}).nth(index).click();await page.getByRole('combobox',{name:/New appointment time/}).nth(index).click();await expect(page.getByRole('option',{name:/9:00/})).toHaveCount(0);await page.getByRole('option',{name:/11:00/}).click();}
  await page.getByRole('button',{name:'Preview series changes'}).click();await expect(page.getByText(/All dates passed validation/)).toBeVisible();expect(changes).toMatchObject({action:'reschedule',items:[{appointment_id:41,version:1,starts_at:'2099-10-02T11:00:00-04:00'},{appointment_id:42,version:1,starts_at:'2099-10-09T11:00:00-04:00'}]});
});
test('changed cancellation fees require reloading the series and a new review',async({page})=>{
  await seriesManager(page);let saves=0;
  await page.route('**/api/v1/customer/recurring-series/17/preview',route=>route.fulfill({status:409,json:{error:{code:'cancellation_fee_changed',message:'Cancellation fees changed. Reload the series.'}}}));
  await page.route('**/api/v1/customer/recurring-series/17',route=>{if(route.request().method()==='GET')return route.fulfill({json:{data:{series_id:17,timezone:'America/Toronto',items:items.map(item=>({...item,cancellation_fee_cents:3000}))}}});saves++;return route.fulfill({status:500});});
  await page.getByRole('button',{name:'Manage future series'}).click();await page.getByRole('button',{name:'Preview series changes'}).click();await expect(page.getByRole('button',{name:'Reload series'})).toBeVisible();await expect(page.getByRole('button',{name:'Confirm entire series'})).toHaveCount(0);expect(saves).toBe(0);await page.getByRole('button',{name:'Reload series'}).click();await expect(page.getByText('Cancellation fee: $30.00')).toHaveCount(2);
});
test('an incomplete confirmation keeps the review and the same safe retry',async({page})=>{
  await fixture(page);const requests:unknown[]=[];
  await page.route('**/api/v1/customer/recurring-series/preview',route=>route.fulfill({json:{data:preview}}));
  await page.route('**/api/v1/customer/recurring-series',route=>{requests.push(route.request().postDataJSON());return requests.length===1?route.fulfill({json:{data:{applied:true}}}):route.fulfill({json:{data:{...preview,applied:true,series_id:17,items:preview.items.map((item,index)=>({...item,appointment_id:41+index}))}}});});
  await bookingReview(page);await page.getByRole('button',{name:'Preview all dates'}).click();await page.getByRole('button',{name:'Confirm entire series'}).click();await expect(page.getByText('The server returned an incomplete series response. Retry the same request.')).toBeVisible();await expect(page.getByRole('button',{name:'My appointments',exact:true})).toBeDisabled();await page.getByRole('button',{name:'Retry series confirmation'}).click();await expect(page.getByText('All appointments in this review were saved.')).toBeVisible();expect(requests).toHaveLength(2);expect(requests[0]).toEqual(requests[1]);
});
