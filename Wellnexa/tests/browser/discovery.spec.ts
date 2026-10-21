import { expect, test } from '@playwright/test'
const service={slug:'massage',name:'Massage',public_summary:'Short summary',description:'Full published service description',preparation_instructions:'Please bring comfortable clothing.',offers_mobile:1,offers_clinic:0,durations:[{minutes:60,price_cents:7500}]}
const person={slug:'esther',public_name:'Esther',public_title:'Massage therapist',summary:'Full published biography',credentials:'RMT',booking_practitioner_id:8,has_image:false,services:[{slug:'massage',name:'Massage'}],social_links:[{label:'Instagram',url:'https://www.instagram.com/synthetic'}]}
test.beforeEach(async({page})=>{
  await page.route('**/api/public/**',route=>route.fulfill({json:{data:route.request().url().endsWith('/services')?[service]:[person]}}))
})
test('services open locally with detail, preparation and price before booking',async({page})=>{
  await page.goto('http://localhost:5177/')
  await page.getByRole('link',{name:'About this service'}).click()
  await expect(page).toHaveURL('http://localhost:5177/services/massage')
  await expect(page.getByRole('navigation').getByRole('link',{name:'Services',exact:true})).toHaveAttribute('aria-current','page')
  await expect(page.getByText('Full published service description')).toBeVisible()
  await expect(page.getByText('Please bring comfortable clothing.')).toBeVisible()
  await expect(page.getByText(/From \$75.00 CAD/)).toBeVisible()
  await expect(page.getByRole('link',{name:'Explore availability'})).toHaveAttribute('href','https://wellnexa.copihue.ca/services/massage/book')
  await page.reload()
  await expect(page.getByRole('heading',{name:'Massage',exact:true})).toBeVisible()
})
test('compact profiles open full biography with social links and practitioner booking',async({page})=>{
  await page.goto('http://localhost:5177/')
  await expect(page.getByText('Full published biography')).toHaveCount(0)
  await page.getByRole('link',{name:'Meet Esther →'}).click()
  await expect(page.getByText('Full published biography')).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('link',{name:'Practitioners',exact:true})).toHaveAttribute('aria-current','page')
  await expect(page.getByRole('link',{name:'Book with me'})).toHaveAttribute('href','https://wellnexa.copihue.ca/availability?practitioner_id=8')
  await expect(page.getByRole('link',{name:'Esther on Instagram (opens in a new tab)'})).toHaveAttribute('rel','noopener noreferrer')
  await page.setViewportSize({width:390,height:844})
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true)
  await page.screenshot({path:'test-results/practitioner-detail-mobile.png',fullPage:true})
})
test('sticky translucent menu remains visible and join page describes current offer',async({page})=>{
  await page.goto('http://localhost:5177/')
  await page.getByRole('heading',{name:'How booking works'}).scrollIntoViewIfNeeded()
  const header=page.locator('.site-header')
  await expect.poll(async()=>Math.round((await header.boundingBox())!.y)).toBe(0)
  expect(await header.evaluate(el=>getComputedStyle(el).backgroundColor)).toMatch(/0\.86/)
  await page.getByRole('navigation',{name:'Main navigation'}).getByRole('link',{name:'Join the collective'}).click()
  await expect(page).toHaveURL('http://localhost:5177/join')
  await expect(page.getByRole('navigation').getByRole('link',{name:'Join the collective'})).toHaveAttribute('aria-current','page')
  await expect(page.getByRole('link',{name:'Ask about joining'})).toHaveAttribute('href','#enquiry-form')
})

for (const width of [1280, 390]) {
  test(`section navigation aligns below the measured header and follows scrolling at ${width}px`,async({page})=>{
    await page.setViewportSize({width,height:800})
    await page.goto('http://localhost:5177/')
    await expect(page.getByRole('link',{name:'Meet Esther →'})).toBeVisible()
    const nav=page.getByRole('navigation',{name:'Main navigation'})
    await expect(nav.locator('[aria-current]')).toHaveCount(0)
    await nav.getByRole('link',{name:'Services',exact:true}).click()
    await expect.poll(()=>page.evaluate(()=>Math.round(document.getElementById('services')!.getBoundingClientRect().top-document.querySelector('header')!.getBoundingClientRect().bottom))).toBe(8)
    await expect(nav.getByRole('link',{name:'Services',exact:true})).toHaveAttribute('aria-current','location')
    // Scroll without clicking a menu item: the current section follows in both directions.
    for (const id of ['practitioners','faq','services']) {
      await page.locator(`#${id}`).evaluate(el=>el.scrollIntoView({behavior:'instant',block:'start'}))
      await expect(nav.locator('[aria-current]')).toHaveCount(1)
      await expect(nav.locator(`[href="/#${id}"]`)).toHaveAttribute('aria-current','location')
      await expect(page).toHaveURL(/#services$/)
    }
    await page.evaluate(()=>window.scrollTo({top:0,behavior:'instant'}))
    await expect(nav.locator('[aria-current]')).toHaveCount(0)
    // Resizing to a wrapped menu must update the offset as well.
    await page.setViewportSize({width:width===1280?390:1280,height:800})
    await nav.getByRole('link',{name:'Services',exact:true}).click()
    await expect.poll(()=>page.evaluate(()=>Math.round(document.getElementById('services')!.getBoundingClientRect().top-document.querySelector('header')!.getBoundingClientRect().bottom))).toBe(8)
    await page.screenshot({path:`test-results/section-navigation-${width}.png`})
  })
}

test('direct section links remain aligned after catalogue loading with reduced motion',async({page})=>{
  await page.emulateMedia({reducedMotion:'reduce'})
  await page.route('**/api/public/services',async route=>{
    await new Promise(resolve=>setTimeout(resolve,500))
    await route.fulfill({json:{data:Array.from({length:12},(_,i)=>({...service,slug:`massage-${i}`}))}})
  })
  await page.goto('http://localhost:5177/#practitioners')
  await expect(page.getByRole('link',{name:'Meet Esther →'})).toBeVisible()
  await expect.poll(()=>page.evaluate(()=>Math.round(document.getElementById('practitioners')!.getBoundingClientRect().top-document.querySelector('header')!.getBoundingClientRect().bottom))).toBe(8)
  await expect(page.getByRole('navigation').getByRole('link',{name:'Practitioners',exact:true})).toHaveAttribute('aria-current','location')
  expect(await page.evaluate(()=>getComputedStyle(document.documentElement).scrollBehavior)).toBe('auto')
})
test('unpublished or unknown detail has a useful recovery instead of a blank screen',async({page})=>{
  await page.goto('http://localhost:5177/practitioners/unpublished')
  await expect(page.getByRole('heading',{name:'Profile or service unavailable'})).toBeVisible()
  await expect(page.getByRole('link',{name:'Return to Wellnexa'})).toHaveAttribute('href','/')
})
