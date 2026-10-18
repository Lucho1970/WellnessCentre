import {expect,test} from '@playwright/test'
const services=[{slug:'massage',name:'Massage',durations:[{minutes:60,price_cents:7500}]},{slug:'nutrition',name:'Nutrition',durations:[{minutes:60,price_cents:9500}]}]
const practitioners=[{slug:'nearby',public_name:'Nearby Practitioner',services:[{slug:'massage',name:'Massage'}]},{slug:'extended',public_name:'Extended Practitioner',services:[{slug:'nutrition',name:'Nutrition'}]}]
const matches=[{practitioner_id:8,service_id:13,location_id:8,practitioner_slug:'nearby',service_slug:'massage',status:'within_area',token:'bmVhcmJ5',starting_price_cents:8500},{practitioner_id:9,service_id:14,location_id:8,practitioner_slug:'extended',service_slug:'nutrition',status:'enquiry_only',token:'ZXh0ZW5kZWQ=',starting_price_cents:9500}]
test.beforeEach(async({page})=>{
  await page.route('**/api/public/**',route=>{
    const url=route.request().url()
    if(url.endsWith('/options'))return route.fulfill({json:{data:{extra_km:25,cap_km:100,map_browser_key:''}}})
    if(url.endsWith('/search'))return route.fulfill({json:{data:{point:{latitude:43.65,longitude:-79.38},label:'Toronto, Ontario',settings:{extra_km:25,cap_km:100},matches}}})
    return route.fulfill({json:{data:url.endsWith('/services')?services:practitioners.map(p=>({...p,social_links:[]}))}})
  })
})
test('area filtering updates services and practitioners, offers enquiry handoff and retains the normal-only toggle',async({page})=>{
  await page.goto('http://localhost:5177/')
  await page.getByLabel('City, postal code or address').fill('Toronto')
  await page.getByRole('button',{name:'Search this area',exact:true}).click()
  await expect(page.locator('#services')).toContainText('From $85.00 CAD')
  await expect(page.locator('#services').getByRole('link',{name:'Explore availability',exact:true})).toHaveAttribute('href','https://wellnexa.copihue.ca/services/massage/book?practitioner_id=8&location_id=8')
  await expect(page.locator('#practitioners')).toContainText('Travel approval required')
  await expect(page.getByRole('link',{name:'Ask about travel',exact:true}).first()).toHaveAttribute('href','https://wellnexa.copihue.ca/client#travel=ZXh0ZW5kZWQ%3D')
  await page.getByLabel('Only show practitioners within their normal travel limit').check()
  await expect(page.locator('#services').getByRole('heading',{name:'Nutrition',exact:true})).toHaveCount(0)
  await expect(page.locator('#practitioners').getByRole('heading',{name:'Extended Practitioner',exact:true})).toHaveCount(0)
  await page.reload()
  await expect(page.getByLabel('Only show practitioners within their normal travel limit')).toBeChecked()
  await expect(page.locator('#practitioners').getByRole('heading',{name:'Extended Practitioner',exact:true})).toHaveCount(0)
  await page.getByRole('button',{name:'Clear location filter'}).click()
  await expect(page.locator('#practitioners').getByRole('heading',{name:'Extended Practitioner',exact:true})).toBeVisible()
})
test('denied GPS and failed searches remain usable and preserve the previous filter',async({page})=>{
  await page.addInitScript(()=>Object.defineProperty(navigator,'geolocation',{value:{getCurrentPosition:(_ok:unknown,fail:()=>void)=>fail()}}))
  await page.goto('http://localhost:5177/')
  await page.getByRole('button',{name:'Use my current location',exact:true}).click()
  await expect(page.getByRole('alert')).toContainText('Location access was denied')
  await page.getByLabel('City, postal code or address').fill('Toronto')
  await page.getByRole('button',{name:'Search this area',exact:true}).click()
  await page.route('**/api/public/discovery/search',route=>route.fulfill({status:503,json:{error:{message:'Synthetic maps unavailable'}}}))
  await page.getByLabel('City, postal code or address').fill('Ottawa')
  await page.getByRole('button',{name:'Search this area',exact:true}).click()
  await expect(page.getByRole('alert')).toContainText('Synthetic maps unavailable')
  await expect(page.getByText(/Showing results for:/)).toContainText('Toronto, Ontario')
  await page.getByRole('button',{name:'Choose on map'}).click()
  await expect(page.getByText('Map selection is not configured yet.',{exact:false})).toBeVisible()
})
test('GPS is requested only on click and an empty area gives an explicit result on mobile',async({page})=>{
  await page.setViewportSize({width:390,height:844})
  await page.addInitScript(()=>Object.defineProperty(navigator,'geolocation',{value:{getCurrentPosition:(ok:(position:unknown)=>void)=>{(window as unknown as {gpsCalled:boolean}).gpsCalled=true;ok({coords:{latitude:45,longitude:-75}})}}}))
  await page.route('**/api/public/discovery/search',route=>{
    expect(route.request().postDataJSON()).toEqual({point:{latitude:45,longitude:-75}})
    return route.fulfill({json:{data:{point:{latitude:45,longitude:-75},label:'Selected search point',settings:{extra_km:25,cap_km:100},matches:[]}}})
  })
  await page.goto('http://localhost:5177/')
  expect(await page.evaluate(()=>(window as unknown as {gpsCalled?:boolean}).gpsCalled)).toBeUndefined()
  await page.getByRole('button',{name:'Use my current location',exact:true}).click()
  await expect(page.getByText('No matching On-Site practitioners in this area. Try another search area.')).toBeVisible()
  await expect(page.locator('#practitioners article')).toHaveCount(0)
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true)
  await page.screenshot({path:'test-results/discovery-mobile.png',fullPage:true})
})

test('map centre selection submits the chosen point without exposing practitioner locations',async({page})=>{
  await page.route('**/*googlemaps*js-api-loader*',route=>route.fulfill({contentType:'application/javascript',body:`
    export function setOptions(){}
    export async function importLibrary(){window.google={maps:{Map:class{constructor(el){el.textContent='Synthetic map';}addListener(){return {remove(){}}}getCenter(){return {lat:()=>45,lng:()=>-76}}panTo(){}},Circle:class{setMap(){}setCenter(){}}}};return {};}
  `}))
  await page.route('**/api/public/discovery/options',route=>route.fulfill({json:{data:{extra_km:25,cap_km:100,map_browser_key:'synthetic-browser-key'}}}))
  await page.goto('http://localhost:5177/')
  await page.getByRole('button',{name:'Choose on map'}).click()
  await expect(page.getByText('Synthetic map',{exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Use map centre'}).click()
  const search=page.waitForRequest(request=>request.url().endsWith('/discovery/search'))
  await page.getByRole('button',{name:'Search selected point'}).click()
  expect((await search).postDataJSON()).toEqual({point:{latitude:45,longitude:-76}})
})
