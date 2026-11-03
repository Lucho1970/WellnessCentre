import {test,expect} from '@playwright/test';
import {ServerSessionBrowser} from './helpers/serverSessionBrowser';
test.afterEach(async({context})=>{await ServerSessionBrowser.teardown(context);});
for(const [realm,profile] of [['workforce','workforce'],['practitioner','google'],['practitioner','microsoft'],['client','client'],['central','workforce']]){
  test(`${realm} ${profile}: popup, existing route, memory-only cache and reload`,async({page,context,request})=>{
    const fixture=new ServerSessionBrowser(request);fixture.profile=profile;await fixture.install(context);await fixture.visit(page,realm);await fixture.login(page);
    await page.getByRole('button',{name:'Protected request'}).click();await expect(page.locator('pre')).toContainText('"data"');
    await page.getByRole('button',{name:'Prepare private intent'}).click();
    const stored=await page.evaluate(()=>[JSON.stringify({...localStorage}),JSON.stringify({...sessionStorage})].join(' '));
    expect(stored).not.toMatch(/synthetic-private-intent|synthetic-refresh|eyJ[A-Za-z0-9_-]+\.|code_verifier|access_token|id_token/);
    const cookies=await context.cookies();expect(cookies.some(c=>c.name.startsWith('__Host-')&&c.secure&&c.httpOnly&&c.sameSite==='Lax')).toBe(true);
    await page.reload();await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeEnabled();
    expect(fixture.unexpected).toEqual([]);
  });
}
test('held popup does not persist credentials or private intent; cancellation is recoverable',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);fixture.hold=true;await fixture.install(context);await fixture.visit(page);
  const popupPromise=page.waitForEvent('popup');await page.getByRole('button',{name:'Sign in',exact:true}).click();const popup=await popupPromise;
  await expect(popup.getByText('Complete synthetic sign-in')).toBeVisible();
  expect(await page.evaluate(()=>JSON.stringify({...sessionStorage}))).not.toMatch(/eyJ|synthetic-refresh|code_verifier|request.params|nonce/i);
  await popup.close();await page.getByRole('button',{name:'Cancel sign-in',exact:true}).click();await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeEnabled();
  fixture.hold=false;await fixture.login(page);
});
test('scoped revoke-all requires fresh popup and clears mounted workspace',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);await fixture.install(context);await fixture.visit(page);await fixture.login(page);
  const trigger=page.getByRole('button',{name:'Sign out all sessions for this portal'});await trigger.click();const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);await expect(trigger).toBeFocused();
  await trigger.click();await dialog.getByRole('button',{name:'Confirm sign-out'}).click();await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);await expect(page.getByTestId('state')).toHaveText('ended');
  expect(fixture.responses.some(r=>r.path.endsWith('/revoke-all')&&r.status===200)).toBe(true);
});
test('server idle expiry denies retained bearer and removes mounted content',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);await fixture.install(context);await fixture.visit(page);await fixture.login(page);await page.getByRole('button',{name:'Prepare private intent'}).click();
  // Advancing only server time leaves the browser timer and memory bearer intact.
  const {writeFileSync,unlinkSync}=await import('node:fs');const {join}=await import('node:path');const runtime=process.env.SERVER_SESSION_BROWSER_RUNTIME;if(!runtime)throw new Error('Missing fixture');const clock=join(runtime,'clock');
  try{writeFileSync(clock,'901');await page.getByRole('button',{name:'Protected request'}).dispatchEvent('click');await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);await expect(page.getByTestId('state')).toHaveText('ended');}finally{unlinkSync(clock);}
});
test('French notice, mobile dialog and explicit sign-out',async({page,context,request})=>{
  await page.setViewportSize({width:390,height:844});const fixture=new ServerSessionBrowser(request);await fixture.install(context);await fixture.visit(page,'workforce','fr');await fixture.login(page);
  await page.getByRole('button',{name:'Fermer toutes les sessions de ce portail'}).click();await expect(page.getByRole('dialog')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.getByRole('button',{name:'Annuler',exact:true}).click();await page.getByRole('button',{name:'Sign out',exact:true}).click();await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);
  await expect.poll(()=>fixture.responses.some(r=>r.path.endsWith('/logout')&&r.status===200)).toBe(true);
  await expect(page.getByRole('button',{name:'Sign in',exact:true})).toBeEnabled();await fixture.login(page);
});
test('real Staff Inactive save removes a mounted practitioner workspace and never revives its cookie',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);fixture.profile='microsoft';await fixture.install(context);await fixture.visit(page,'practitioner');await fixture.login(page);
  try{await fixture.microsoftStatus('inactive');await page.getByRole('button',{name:'Protected request'}).dispatchEvent('click');await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);await expect(page.getByTestId('state')).toHaveText('ended');}finally{await fixture.microsoftStatus('active');}
  expect(fixture.responses.some(r=>r.path==='/api/v1/auth/me'&&r.status>=400)).toBe(true);
});
test('a delayed protected response cannot repopulate the workspace after sign-out',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);await fixture.install(context);await fixture.visit(page);await fixture.login(page);
  let release:()=>void=()=>{throw new Error('Missing delay release')};fixture.delayProtected=new Promise<void>(resolve=>{release=resolve;});
  try{await page.getByRole('button',{name:'Protected request'}).click();await expect.poll(()=>fixture.responses.some(r=>r.path==='/api/v1/auth/me')).toBe(true);await page.getByRole('button',{name:'Sign out',exact:true}).click();await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);}finally{release();}
  await expect(page.getByTestId('state')).toHaveText('ended');await expect(page.locator('pre')).toHaveCount(0);
});
test('a new tab cannot recover access from its shared cookie; page restoration clears memory UI',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);await fixture.install(context);await fixture.visit(page);await fixture.login(page);
  const other=await context.newPage();await fixture.visit(other);await expect(other.getByRole('region',{name:'Private workspace'})).toHaveCount(0);
  await page.evaluate(()=>{dispatchEvent(new PageTransitionEvent('pagehide'));dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));});
  await expect(page.getByRole('region',{name:'Private workspace'})).toHaveCount(0);await expect(page.getByTestId('state')).toHaveText('ended');
  expect(new URL(page.url()).searchParams.has('code')).toBe(false);expect(new URL(page.url()).searchParams.has('error')).toBe(false);
});
test('real practitioner portal bootstrap and AuthProvider remove the dashboard after Staff Inactive',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);fixture.profile='microsoft';await fixture.install(context);
  await page.goto('https://clinic-a.session.test/staff/external');
  const signIn=page.getByRole('button',{name:'Continue with personal Microsoft account',exact:true});await expect(signIn).toBeEnabled();await signIn.click();
  await expect(page.getByRole('heading',{name:'Dashboard',exact:true})).toBeVisible().catch(error=>{throw new Error(`Real portal did not open: ${JSON.stringify(fixture.responses)}; blocked origins: ${JSON.stringify(fixture.unexpected)}`,{cause:error});});
  const refresh=page.getByRole('button',{name:'Customize dashboard',exact:true}).locator('..').getByRole('button',{name:'Refresh',exact:true});
  const trigger=await refresh.elementHandle();if(!trigger)throw new Error('Missing dashboard toolbar');const before=fixture.responses.length;
  try{await fixture.microsoftStatus('inactive');
    // A normal protected poll may already have removed the toolbar. Do not wait
    // for a correctly removed element and prevent the fixture's cleanup.
    await trigger.evaluate(element=>{if(element.isConnected)element.dispatchEvent(new MouseEvent('click',{bubbles:true}));});
    await expect(page.getByRole('heading',{name:'Dashboard',exact:true})).toHaveCount(0);await expect(signIn).toBeVisible();
  }finally{await fixture.microsoftStatus('active');await trigger.dispose();}
  expect(fixture.responses.slice(before).some(r=>r.status>=400)).toBe(true);expect(fixture.unexpected).toEqual([]);
});
test('real client portal bootstrap keeps its independent identity client and requires sign-in after reload',async({page,context,request})=>{
  const fixture=new ServerSessionBrowser(request);fixture.profile='client';await fixture.install(context);
  await page.goto('https://clinic-a.session.test/client');const signIn=page.getByRole('button',{name:'Sign in or create client account',exact:true});await expect(signIn).toBeEnabled();await signIn.click();
  await expect(page.getByText('No appointments in this view.',{exact:true})).toBeVisible().catch(error=>{throw new Error(`Real client portal did not open: ${JSON.stringify(fixture.responses)}; blocked origins: ${JSON.stringify(fixture.unexpected)}`,{cause:error});});
  expect(fixture.responses.some(r=>r.path==='/api/v1/customer/auth/me'&&r.status===200)).toBe(true);expect(fixture.unexpected).toEqual([]);
  await page.reload();await expect(signIn).toBeVisible();await expect(page.getByText('No appointments in this view.',{exact:true})).toHaveCount(0);
});
