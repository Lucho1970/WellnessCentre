import { expect, test } from '@playwright/test'
const services = [{ slug: 'synthetic-massage', name: 'Synthetic massage', public_summary: 'Published service information', category: 'Wellness', durations: [{ minutes: '60', price_cents: '12000', client_id: 98 }], private_note: 'Private clinical note' }]
const practitioners = [{ slug: 'synthetic-clinician', public_name: 'Synthetic clinician', public_title: 'Wellness practitioner', summary: 'Published practitioner information', credentials: 'Synthetic credential', booking_practitioner_id: '7', services: [{ slug: 'synthetic-massage', name: 'Synthetic massage' }], has_image: '1', image_version: 'photo-v2', home_address: 'Private home address' }]
test.beforeEach(async ({ page }) => {
  await page.route('https://wellnexa.copihue.ca/api/v1/team/**/image?*', route => route.fulfill({ status: 404, body: '' }))
})
test('published catalogue displays public cards and clinic-specific booking links', async ({ page }) => {
  const requests: string[] = []
  await page.route('**/api/public/**', route => { requests.push(route.request().url()); return route.fulfill({ json: { data: route.request().url().endsWith('/services') ? services : practitioners } }) })
  await page.route('https://wellnexa.copihue.ca/api/v1/team/**/image?*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="128" height="128" fill="#a8f2d6"/><circle cx="64" cy="44" r="22" fill="#0f4c5c"/><ellipse cx="64" cy="115" rx="45" ry="42" fill="#0f4c5c"/></svg>' }))
  await page.goto('http://localhost:5177/')
  await expect(page.getByRole('heading', { name: 'Synthetic massage' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Synthetic clinician' })).toBeVisible()
  const photo = page.getByRole('img', { name: 'Synthetic clinician — practitioner portrait' })
  await photo.scrollIntoViewIfNeeded()
  await expect(photo).toHaveAttribute('src', 'https://wellnexa.copihue.ca/api/v1/team/synthetic-clinician/image?v=photo-v2')
  await expect.poll(() => photo.evaluate(image => (image as HTMLImageElement).naturalWidth)).toBe(128)
  await expect(page.getByText(/From \$120\.00 CAD/)).toBeVisible()
  await expect(page.getByRole('link', { name: 'About this service' })).toHaveAttribute('href', '/services/synthetic-massage')
  await expect(page.getByRole('link', { name: 'Meet Synthetic clinician →', exact: true })).toHaveAttribute('href', '/practitioners/synthetic-clinician')
  await expect(page.getByText(/Private clinical note|Private home address/)).toHaveCount(0)
  expect(requests.every(url => new URL(url).search === '')).toBe(true)
  await page.evaluate(() => window.scrollTo(0, 0))
  await page.screenshot({ path: 'test-results/wellnexa-theme-desktop.png', fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(page.getByRole('link', { name: /Visit the clinic portal/ })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'test-results/wellnexa-theme-mobile.png', fullPage: true })
})

test('missing and unavailable practitioner photos show initials without hiding profiles', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  const people = [{ ...practitioners[0], has_image: '0' }, { ...practitioners[0], slug: 'another-clinician', public_name: 'Another clinician', has_image: '1' }]
  const photoRequests: string[] = []
  await page.route('**/api/public/**', route => route.fulfill({ json: { data: route.request().url().endsWith('/services') ? services : people } }))
  await page.route('https://wellnexa.copihue.ca/api/v1/team/**/image?*', route => { photoRequests.push(route.request().url()); return route.fulfill({ status: 404, body: '' }) })
  await page.goto('http://localhost:5177/')
  await page.getByRole('heading', { name: 'Another clinician' }).scrollIntoViewIfNeeded()
  await expect(page.locator('.practitioner-photo').nth(0)).toHaveText('SC')
  await expect(page.locator('.practitioner-photo').nth(1)).toHaveText('AC')
  await expect(page.locator('#practitioners img')).toHaveCount(0)
  expect(photoRequests).toHaveLength(1)
  expect(photoRequests[0]).toContain('/another-clinician/image?v=photo-v2')
  await expect(page.getByRole('link', { name: /^Meet .* →$/ })).toHaveCount(2)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
test('empty published lists show truthful empty states', async ({ page }) => {
  await page.route('**/api/public/**', route => route.fulfill({ json: { data: [] } }))
  await page.goto('http://localhost:5177/')
  await expect(page.getByText('Services will appear here as they are published.')).toBeVisible()
  await expect(page.getByText('Practitioner profiles will appear here as they are published.')).toBeVisible()
  await expect(page.locator('.catalogue-card')).toHaveCount(0)
})
test('a failed section does not hide the other and an explicit retry recovers', async ({ page }) => {
  let fail = true
  await page.route('**/api/public/**', route => {
    if (route.request().url().endsWith('/services')) return route.fulfill(fail ? { status: 503, json: { error: 'Unavailable' } } : { json: { data: services } })
    return route.fulfill({ json: { data: practitioners } })
  })
  await page.goto('http://localhost:5177/')
  await expect(page.getByRole('alert')).toContainText('We couldn’t load our services right now.')
  await expect(page.getByRole('heading', { name: 'Synthetic clinician' })).toBeVisible()
  fail = false
  await page.getByRole('button', { name: 'Try again' }).click()
  await expect(page.getByRole('heading', { name: 'Synthetic massage' })).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)
})
test('malformed data is rejected and mobile cards remain within the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.route('**/api/public/**', route => route.fulfill({ json: { data: route.request().url().endsWith('/services') ? [{ ...services[0], slug: '../private' }] : practitioners } }))
  await page.goto('http://localhost:5177/')
  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Synthetic clinician' })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
