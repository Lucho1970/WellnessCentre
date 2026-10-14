import { expect, test } from '@playwright/test'
const services = [{ slug: 'synthetic-massage', name: 'Synthetic massage', public_summary: 'Published service information', category: 'Wellness', durations: [{ minutes: '60', price_cents: '12000', client_id: 98 }], private_note: 'Private clinical note' }]
const practitioners = [{ slug: 'synthetic-clinician', public_name: 'Synthetic clinician', public_title: 'Wellness practitioner', summary: 'Published practitioner information', credentials: 'Synthetic credential', booking_practitioner_id: '7', services: [{ slug: 'synthetic-massage', name: 'Synthetic massage' }], home_address: 'Private home address' }]
test('published catalogue displays public cards and clinic-specific booking links', async ({ page }) => {
  const requests: string[] = []
  await page.route('**/api/public/**', route => { requests.push(route.request().url()); return route.fulfill({ json: { data: route.request().url().endsWith('/services') ? services : practitioners } }) })
  await page.goto('http://localhost:5177/')
  await expect(page.getByRole('heading', { name: 'Synthetic massage' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Synthetic clinician' })).toBeVisible()
  await expect(page.getByText(/From \$120\.00 CAD/)).toBeVisible()
  await expect(page.getByRole('link', { name: 'Explore availability' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/services/synthetic-massage/book')
  await expect(page.getByRole('link', { name: 'Find an appointment' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/?practitioner_id=7')
  await expect(page.getByText(/Private clinical note|Private home address/)).toHaveCount(0)
  expect(requests.every(url => new URL(url).search === '')).toBe(true)
  await page.screenshot({ path: 'test-results/wellnexa-theme-desktop.png', fullPage: true })
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(page.getByRole('link', { name: /Visit the clinic portal/ })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'test-results/wellnexa-theme-mobile.png', fullPage: true })
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
