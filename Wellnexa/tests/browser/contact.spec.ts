import { expect, test } from '@playwright/test'

test('direct contact entry preserves clinic destination and French across a refresh', async ({ page }) => {
  await page.route('**/api/public/contact', route => route.fulfill({ json: { data: { email: 'clinic@example.test', phone: '+1 416 555 0100' } } }))
  await page.goto('http://localhost:5177/contact?lang=en')
  await expect(page.getByRole('heading', { name: 'Contact Wellnexa' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'clinic@example.test' })).toHaveAttribute('href', 'mailto:clinic@example.test')
  await expect(page.getByRole('link', { name: 'My appointments' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/client?lang=en')
  await page.goto('http://localhost:5177/contact/?lang=fr')
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Contactez Wellnexa' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
  await expect(page.getByRole('link', { name: 'Mes rendez-vous' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/client?lang=fr')
  await page.setViewportSize({ width: 390, height: 844 })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: 'test-results/contact-mobile.png', fullPage: true })
})

for (const unavailable of [false, true]) {
  test(`contact offers a portal fallback when public details are ${unavailable ? 'unavailable' : 'unpublished'}`, async ({ page }) => {
    await page.route('**/api/public/contact', route => route.fulfill(unavailable ? { status: 503, json: { error: 'Unavailable' } } : { json: { data: { email: null, phone: null } } }))
    await page.goto('http://localhost:5177/contact?lang=en')
    await expect(page.getByText(/Clinic contact details are unavailable/)).toBeVisible()
    await expect(page.getByRole('link', { name: 'Browse treatments and practitioners' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/?lang=en')
  })
}
